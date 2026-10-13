<?php

namespace Slyn9\Licensing\Services;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Slyn9\Licensing\Contracts\AuditLogger;
use Slyn9\Licensing\Contracts\SuperAdminCheck;
use Slyn9\Licensing\Contracts\UserTenantAccess;
use Slyn9\Licensing\Enums\LicenseStatus;
use Slyn9\Licensing\Events\LicenseAssigned;
use Slyn9\Licensing\Events\LicenseBlocked;
use Slyn9\Licensing\Events\LicenseCancelled;
use Slyn9\Licensing\Events\LicensePaymentRegistered;
use Slyn9\Licensing\Events\LicenseRenewed;
use Slyn9\Licensing\Events\LicenseStatusChanged;
use Slyn9\Licensing\Events\LicenseUnblocked;
use Slyn9\Licensing\Exceptions\LicenseException;
use Slyn9\Licensing\Exceptions\ManagedModeException;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Models\CompanyLicense;
use Slyn9\Licensing\Models\LicenseInstallment;
use Slyn9\Licensing\Models\LicensePayment;
use Slyn9\Licensing\Models\LicenseType;

/**
 * Único punto de entrada para crear, resolver, cobrar y cambiar de estado licencias.
 *
 * Todas las operaciones comerciales verifican primero `Licensing::allowsLocalManagement()`
 * (modo managed → ManagedModeException). Las lecturas y el control de acceso solo usan
 * tablas locales: nunca hacen llamadas de red.
 */
class LicenseService
{
    public function __construct(
        private readonly UserTenantAccess $access,
        private readonly SuperAdminCheck $superAdmin,
        private readonly AuditLogger $audit,
        private readonly EventPublisher $events,
    ) {}

    // ── Resolución ───────────────────────────────────────────────────────────

    /**
     * Qué licencia manda.
     *
     *  - Con sede: la última licencia no cancelada de esa sede; si no tiene, la última
     *    de nivel tenant (sede NULL).
     *  - Sin sede: entre las licencias no canceladas del tenant, una que permita
     *    acceso; si ninguna lo permite, la más reciente.
     *
     * "Más reciente" se decide por id (created_at empata dentro del mismo segundo).
     * Con branches.enabled = false la sede se ignora siempre.
     */
    public function resolveFor(Model $tenant, ?Model $branch = null): ?CompanyLicense
    {
        $branch = Licensing::branchesEnabled() ? $branch : null;

        $base = CompanyLicense::query()
            ->with('licenseType')
            ->where(Licensing::tenantKey(), $tenant->getKey())
            ->where('status', '<>', LicenseStatus::CANCELLED->value);

        if ($branch) {
            return (clone $base)->where(Licensing::branchKey(), $branch->getKey())->orderByDesc('id')->first()
                ?? (clone $base)->whereNull(Licensing::branchKey())->orderByDesc('id')->first();
        }

        $all = $base->orderByDesc('id')->get();

        return $all->first(fn (CompanyLicense $l) => $l->computeStatus()->isAccessAllowed())
            ?? $all->first();
    }

    /** Evalúa el acceso aplicando la política `licensing.missing_license` cuando no hay licencia. */
    public function accessFor(Model $tenant, ?Model $branch = null): LicenseAccess
    {
        $license = $this->resolveFor($tenant, $branch);

        if (! $license) {
            return new LicenseAccess(null, null, config('licensing.missing_license') !== 'block');
        }

        $status = $license->computeStatus();

        return new LicenseAccess($license, $status, $status->isAccessAllowed());
    }

    /**
     * Hook de cambio de tenant: ¿puede el usuario trabajar en $tenant según su licencia?
     * Solo evalúa licencia; verificar la pertenencia del usuario al tenant es del anfitrión.
     */
    public function canEnter(Authenticatable $user, Model $tenant): bool
    {
        if ($this->superAdmin->isSuperAdmin($user)) {
            return true;
        }

        return $this->accessFor($tenant, $this->access->branchFor($user, $tenant))->allowed;
    }

    /**
     * Motivo por el que un usuario no puede iniciar sesión por su licencia, o null si puede.
     *
     * Entra si al menos uno de sus tenants permite acceso. El bloqueo real por tenant lo
     * aplica el middleware sobre el tenant activo.
     */
    public function denialMessageFor(Authenticatable $user): ?string
    {
        if ($this->superAdmin->isSuperAdmin($user)) {
            return null;
        }

        $tenants = $this->access->tenantsFor($user);

        if ($tenants->isEmpty()) {
            return null;
        }

        $reasons = [];

        foreach ($tenants as $tenant) {
            $access = $this->accessFor($tenant, $this->access->branchFor($user, $tenant));

            if ($access->allowed) {
                return null;
            }

            $reasons[] = match (true) {
                $access->isMissing() => 'missing',
                $access->status === LicenseStatus::BLOCKED => 'blocked',
                default => 'expired',
            };
        }

        $reasons = array_values(array_unique($reasons));

        if (count($reasons) > 1) {
            return $this->message('denied_many');
        }

        return match ($reasons[0]) {
            'missing' => $this->message(Licensing::branchesEnabled() ? 'denied_missing_branch' : 'denied_missing_tenant'),
            'blocked' => $this->message('denied_blocked'),
            default => $this->message('denied_expired'),
        };
    }

    /** Texto configurable (`licensing.messages.*`); sin la clave, el del paquete. */
    private function message(string $key): string
    {
        // El config del anfitrión se fusiona con los defaults del paquete: la clave siempre existe.
        return (string) config("licensing.messages.{$key}");
    }

    // ── Consultas para pantallas de administración ───────────────────────────

    /**
     * La licencia no cancelada más reciente de cada tenant, indexada por id de tenant.
     *
     * @return Collection<int|string, CompanyLicense>
     */
    public function latestPerTenant(): Collection
    {
        return CompanyLicense::query()
            ->with('licenseType')
            ->where('status', '<>', LicenseStatus::CANCELLED->value)
            ->orderByDesc('id')
            ->get()
            ->unique(fn (CompanyLicense $l) => $l->getAttribute(Licensing::tenantKey()))
            ->keyBy(fn (CompanyLicense $l) => $l->getAttribute(Licensing::tenantKey()));
    }

    /**
     * Todas las licencias de un tenant, con tipo, cuotas, pagos (y sede si está activada).
     *
     * @return Collection<int, CompanyLicense>
     */
    public function licensesOf(Model $tenant): Collection
    {
        $with = ['licenseType', 'installments', 'payments.recorder'];

        if (Licensing::branchesEnabled()) {
            $with[] = 'branch';
        }

        return CompanyLicense::query()
            ->where(Licensing::tenantKey(), $tenant->getKey())
            ->with($with)
            ->orderByDesc('id')
            ->get();
    }

    // ── Alta ─────────────────────────────────────────────────────────────────

    /**
     * @param  array{starts_at?:mixed,expires_at?:mixed,total_amount?:mixed,installments_count?:mixed,notes?:?string}  $data
     */
    public function assign(Model $tenant, ?Model $branch, LicenseType $type, array $data = [], Model|int|null $by = null): CompanyLicense
    {
        $this->assertLocalManagement('assign');
        Licensing::productCode();

        if ($branch && ! Licensing::branchesEnabled()) {
            throw LicenseException::forField('branch', 'Las sedes están desactivadas: la licencia se asigna al tenant.');
        }

        if ($branch && (string) $branch->getAttribute(Licensing::branchTenantKey()) !== (string) $tenant->getKey()) {
            throw LicenseException::forField(Licensing::branchKey(), 'La sede no pertenece a la empresa.');
        }

        $startsAt = Carbon::parse($data['starts_at'] ?? now());
        $expiresAt = ! empty($data['expires_at'])
            ? Carbon::parse($data['expires_at'])
            : ($type->duration_days ? $startsAt->copy()->addDays($type->duration_days) : null);

        $total = max(0, $this->toCents($data['total_amount'] ?? $type->base_price));
        $count = max(1, (int) ($data['installments_count'] ?? 1));
        $trial = $type->code === Licensing::trialCode();

        $license = DB::transaction(function () use ($tenant, $branch, $type, $data, $startsAt, $expiresAt, $total, $count, $trial) {
            $attributes = [
                Licensing::tenantKey() => $tenant->getKey(),
                'license_type_id' => $type->id,
                'status' => $trial ? LicenseStatus::TRIAL : LicenseStatus::ACTIVE,
                'starts_at' => $startsAt,
                'expires_at' => $expiresAt,
                'grace_until' => $expiresAt?->copy()->addDays($this->graceDays($type)),
                'total_amount' => $total / 100,
                'paid_amount' => 0,
                'installments_count' => $count,
                'currency' => Licensing::currencyCode(),
                'notes' => $data['notes'] ?? null,
            ];

            if (Licensing::branchesEnabled()) {
                $attributes[Licensing::branchKey()] = $branch?->getKey();
            }

            $license = CompanyLicense::create($attributes);

            // Si se asigna con fechas ya vencidas, el estado guardado debe reflejarlo.
            $computed = $license->computeStatus();
            if ($computed !== $license->status) {
                $license->update(['status' => $computed]);
            }

            $this->generateInstallments($license, $count, $total, $startsAt);

            return $license;
        });

        $this->events->publish(LicenseAssigned::for($license->load('licenseType')));

        $actor = $by instanceof Model ? $by : ($by ? Licensing::userModel()::find($by) : null);

        $this->log('created', "Licencia {$type->name} asignada a ".Licensing::tenantName($tenant)
            .($branch ? ' (sede '.Licensing::branchName($branch).')' : ' (empresa)'), $license, [
                'type' => $type->code,
                'expires_at' => $license->expires_at?->toDateString(),
                'total' => $total / 100,
            ], $actor);

        return $license->load('installments');
    }

    /**
     * Asigna la licencia de prueba a un tenant/sede que aún no tiene ninguna vigente.
     * Devuelve la existente si ya la tiene; null si el tipo de prueba no está cargado
     * o si el modo es managed (no escribe, no lanza: no debe romper altas de sedes).
     */
    public function assignTrial(Model $tenant, ?Model $branch = null, ?string $notes = null): ?CompanyLicense
    {
        if (Licensing::mode() !== Licensing::MODE_STANDALONE) {
            return null;
        }

        $type = LicenseType::where('code', Licensing::trialCode())->first();

        if (! $type) {
            return null;
        }

        $branch = Licensing::branchesEnabled() ? $branch : null;

        $existing = CompanyLicense::query()
            ->where('status', '<>', LicenseStatus::CANCELLED->value)
            ->when(
                $branch,
                fn ($q) => $q->where(Licensing::branchKey(), $branch->getKey()),
                function ($q) use ($tenant) {
                    $q->where(Licensing::tenantKey(), $tenant->getKey());

                    if (Licensing::branchesEnabled()) {
                        $q->whereNull(Licensing::branchKey());
                    }
                }
            )
            ->orderByDesc('id')
            ->first();

        return $existing ?? $this->assign($tenant, $branch, $type, [
            'starts_at' => now(),
            'total_amount' => 0,
            'installments_count' => 1,
            'notes' => $notes ?? (Licensing::branchesEnabled()
                ? 'Trial asignado automáticamente al crear la sede.'
                : 'Trial asignado automáticamente.'),
        ]);
    }

    // ── Pagos ────────────────────────────────────────────────────────────────

    /**
     * Registra un pago en una transacción, con lock sobre la licencia. Sin cuota indicada
     * se aplica a las cuotas impagas por orden de vencimiento; con cuota indicada, solo a ella.
     *
     * @param  array{paid_at?:mixed,payment_method?:?string,reference?:?string,notes?:?string,license_installment_id?:?int,installment_id?:?int}  $data
     */
    public function registerPayment(CompanyLicense $license, float|int|string $amount, array $data, Model|int $by): LicensePayment
    {
        $this->assertLocalManagement('registerPayment');

        $actor = $by instanceof Model ? $by : Licensing::userModel()::find($by);
        $cents = $this->toCents($amount);

        [$payment, $previousStatus] = DB::transaction(function () use ($license, $cents, $data, $actor, $by) {
            $locked = $this->lock($license);

            if ($locked->status === LicenseStatus::CANCELLED) {
                throw LicenseException::forField('amount', 'No se pueden registrar pagos en una licencia cancelada.');
            }
            if ($cents <= 0) {
                throw LicenseException::forField('amount', 'El monto debe ser mayor a cero.');
            }

            $pending = $this->toCents($locked->total_amount) - $this->toCents($locked->paid_amount);
            if ($cents > $pending) {
                throw LicenseException::forField('amount', sprintf(
                    'El monto excede el saldo pendiente (%s %s).',
                    config('licensing.currency.symbol'),
                    number_format(max(0, $pending) / 100, 2)
                ));
            }

            $installmentId = $data['license_installment_id'] ?? $data['installment_id'] ?? null;
            $allocations = $this->allocate($locked, $cents, $installmentId ? (int) $installmentId : null);

            $paidAt = $data['paid_at'] ?? now();
            $payment = $locked->payments()->create([
                'license_installment_id' => $installmentId ?: (array_key_first($allocations) ?: null),
                'amount' => $cents / 100,
                'paid_at' => $paidAt,
                'payment_method' => $data['payment_method'] ?? 'efectivo',
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $actor?->getKey() ?? $by,
            ]);

            foreach ($allocations as $targetId => $applied) {
                $installment = LicenseInstallment::findOrFail($targetId);
                $paidCents = $this->toCents($installment->paid_amount) + $applied;
                $covered = $paidCents >= $this->toCents($installment->amount_due);

                $installment->update([
                    'paid_amount' => $paidCents / 100,
                    'status' => $covered ? 'paid' : $installment->status,
                    'paid_at' => $covered ? $payment->paid_at : null,
                ]);
            }

            $previousStatus = $locked->status->value;

            $locked->paid_amount = ($this->toCents($locked->paid_amount) + $cents) / 100;
            $locked->save();

            // El pago nunca levanta un bloqueo manual ni cambia fechas: solo se re-deriva el estado.
            $this->persistComputedStatus($locked);

            return [$payment, $previousStatus];
        });

        $license->refresh();

        $this->events->publish(LicensePaymentRegistered::for($license, $payment->load('installment'), $previousStatus));

        $this->log('payment_registered', sprintf(
            'Pago de %s %s registrado en licencia #%d',
            config('licensing.currency.symbol'),
            number_format($cents / 100, 2),
            $license->id
        ), $license, [
            'amount' => $cents / 100,
            'payment_method' => $payment->payment_method,
            'payment_id' => $payment->id,
        ], $actor);

        return $payment;
    }

    // ── Ciclo de vida ────────────────────────────────────────────────────────

    public function block(CompanyLicense $license, Model $by, string $reason): CompanyLicense
    {
        $this->assertLocalManagement('block');
        $reason = $this->requireReason($reason);

        [$locked, $previous] = DB::transaction(function () use ($license) {
            $locked = $this->lock($license);

            if ($locked->status === LicenseStatus::CANCELLED) {
                throw new LicenseException('No se puede bloquear una licencia cancelada.');
            }
            if ($locked->status === LicenseStatus::BLOCKED) {
                throw new LicenseException('La licencia ya está bloqueada.');
            }

            $previous = $locked->status->value;
            $locked->update(['status' => LicenseStatus::BLOCKED]);

            return [$locked, $previous];
        });

        $this->events->publish(LicenseBlocked::for($locked, $previous, $reason));
        $this->logTransition('blocked', 'bloqueada', $locked, $previous, $reason, $by);

        return $license->refresh();
    }

    /**
     * Desbloquea recalculando el estado según las fechas. Si la licencia está vencida hay
     * que indicar en la misma acción una nueva fecha de vencimiento o una renovación.
     *
     * @param  array{expires_at?:mixed,renew?:bool,total_amount?:mixed,installments_count?:mixed}  $options
     */
    public function unblock(CompanyLicense $license, Model $by, string $reason, array $options = []): CompanyLicense
    {
        $this->assertLocalManagement('unblock');
        $reason = $this->requireReason($reason);

        [$locked, $previous, $renewal] = DB::transaction(function () use ($license, $options) {
            $locked = $this->lock($license);

            if ($locked->status !== LicenseStatus::BLOCKED) {
                throw new LicenseException('La licencia no está bloqueada.');
            }

            $previous = $locked->status->value;
            $previousExpiry = $locked->expires_at?->toIso8601String();
            $trial = $locked->isTrialType();
            $renew = (bool) ($options['renew'] ?? false);
            $newDate = ! empty($options['expires_at']) ? Carbon::parse($options['expires_at']) : null;
            $renewal = null;

            if ($newDate && $newDate->lte(now())) {
                throw LicenseException::forField('expires_at', 'La nueva fecha de vencimiento debe ser futura.');
            }

            if ($renew) {
                $renewal = $this->applyRenewal($locked, $options) + ['previous_expires_at' => $previousExpiry];
            } elseif ($newDate) {
                $locked->expires_at = $newDate;
                $locked->grace_until = $newDate->copy()->addDays($this->graceDays($locked->licenseType));
            }

            $status = $locked->statusByDates(null, $trial);

            if ($status === LicenseStatus::EXPIRED) {
                throw LicenseException::forField(
                    'expires_at',
                    'La licencia está vencida: indique una nueva fecha de vencimiento o renuévela para desbloquearla.'
                );
            }

            $locked->status = $status;
            $locked->save();

            return [$locked, $previous, $renewal];
        });

        $this->events->publish(LicenseUnblocked::for($locked, $previous, $reason, (bool) ($options['renew'] ?? false)));

        if ($renewal) {
            $this->events->publish(LicenseRenewed::for(
                $locked, $previous, $renewal['previous_expires_at'], $renewal['amount'] / 100, $renewal['generated']
            ));
        }

        $this->logTransition('unblocked', 'desbloqueada', $locked, $previous, $reason, $by, [
            'new_expires_at' => $locked->expires_at?->toDateString(),
            'renewed' => (bool) ($options['renew'] ?? false),
        ]);

        return $license->refresh();
    }

    public function cancel(CompanyLicense $license, Model $by, string $reason): CompanyLicense
    {
        $this->assertLocalManagement('cancel');
        $reason = $this->requireReason($reason);

        [$locked, $previous] = DB::transaction(function () use ($license) {
            $locked = $this->lock($license);

            if ($locked->status === LicenseStatus::CANCELLED) {
                throw new LicenseException('La licencia ya está cancelada.');
            }

            $previous = $locked->status->value;
            $locked->update(['status' => LicenseStatus::CANCELLED]);

            return [$locked, $previous];
        });

        $this->events->publish(LicenseCancelled::for($locked, $previous, $reason));
        $this->logTransition('cancelled', 'cancelada', $locked, $previous, $reason, $by);

        return $license->refresh();
    }

    /**
     * Renueva: la nueva vigencia empieza en max(ahora, vencimiento actual), recalcula la
     * gracia y genera las cuotas del nuevo período continuando la numeración.
     *
     * @param  array{total_amount?:mixed,installments_count?:mixed}  $data
     */
    public function renew(CompanyLicense $license, ?Model $by = null, array $data = []): CompanyLicense
    {
        $this->assertLocalManagement('renew');

        $previousExpiry = $license->expires_at?->toDateString();

        [$locked, $previous, $renewal] = DB::transaction(function () use ($license, $data) {
            $locked = $this->lock($license);
            $previous = $locked->status->value;
            $previousExpiryAt = $locked->expires_at?->toIso8601String();

            $renewal = $this->applyRenewal($locked, $data) + ['previous_expires_at' => $previousExpiryAt];
            $locked->save();

            return [$locked, $previous, $renewal];
        });

        $this->events->publish(LicenseRenewed::for(
            $locked, $previous, $renewal['previous_expires_at'], $renewal['amount'] / 100, $renewal['generated']
        ));

        $this->log('renewed', "Licencia #{$locked->id} renovada hasta {$locked->expires_at->toDateString()}", $locked, [
            'previous_expires_at' => $previousExpiry,
            'new_expires_at' => $locked->expires_at->toDateString(),
        ], $by);

        return $license->refresh()->load('installments');
    }

    // ── Sincronización (persistencia del estado calculado) ───────────────────

    /**
     * Guarda el estado calculado de cada licencia y marca como `overdue` las cuotas
     * vencidas. Idempotente: una segunda corrida no escribe nada. No es una operación
     * comercial (solo persiste estado derivado de fechas locales): corre también en managed.
     *
     * @return array{licenses:int, installments:int}
     */
    public function syncAll(bool $dryRun = false): array
    {
        if (! $dryRun) {
            Licensing::productCode();
        }

        $licenses = 0;

        CompanyLicense::query()
            ->with('licenseType')
            ->whereNotIn('status', [LicenseStatus::BLOCKED->value, LicenseStatus::CANCELLED->value])
            ->chunkById(200, function ($chunk) use (&$licenses, $dryRun) {
                foreach ($chunk as $license) {
                    $computed = $license->computeStatus();

                    if ($computed !== $license->status) {
                        $licenses++;

                        if (! $dryRun) {
                            $previous = $license->status->value;
                            $license->update(['status' => $computed]);
                            $this->events->publish(LicenseStatusChanged::for($license, $previous));
                        }
                    }
                }
            });

        $overdue = LicenseInstallment::query()
            ->where('status', 'pending')
            ->whereDate('due_date', '<', today())
            ->whereHas('license', fn ($q) => $q->where('status', '<>', LicenseStatus::CANCELLED->value));

        $installments = (clone $overdue)->count();

        if (! $dryRun && $installments > 0) {
            $overdue->update(['status' => 'overdue']);
        }

        return ['licenses' => $licenses, 'installments' => $installments];
    }

    // ── Corrección de datos ──────────────────────────────────────────────────

    /**
     * Recalcula, a partir de los pagos reales, el `paid_amount` de la licencia y la
     * cobertura de cada cuota: primero los pagos vinculados a su cuota (hasta su monto) y
     * el resto por orden de vencimiento. Una cuota es `paid` solo si queda cubierta entera.
     * No modifica pagos ni estados bloqueada/cancelada. Idempotente.
     *
     * @return array{license: array{from:string,to:string}|null, installments: array<int, array{id:int,number:int,from:string,to:string,paid_from:string,paid_to:string}>}
     */
    public function reconcile(CompanyLicense $license, bool $apply = false): array
    {
        if ($apply) {
            $this->assertLocalManagement('reconcile');
        }

        $payments = $license->payments()->reorder('paid_at')->orderBy('id')->get();
        $installments = LicenseInstallment::where('company_license_id', $license->id)
            ->orderBy('due_date')->orderBy('installment_number')->get();

        $coverage = $installments->mapWithKeys(fn ($i) => [$i->id => 0])->all();
        $due = $installments->mapWithKeys(fn ($i) => [$i->id => $this->toCents($i->amount_due)])->all();
        $lastPaid = [];
        $pool = 0;

        foreach ($payments as $payment) {
            $cents = $this->toCents($payment->amount);
            $id = $payment->license_installment_id;

            if ($id && isset($coverage[$id])) {
                $take = min($cents, max(0, $due[$id] - $coverage[$id]));
                $coverage[$id] += $take;
                $pool += $cents - $take;
                $take > 0 && $lastPaid[$id] = $payment->paid_at;
            } else {
                $pool += $cents;
            }
        }

        $lastPayment = $payments->last()?->paid_at;

        foreach ($installments as $installment) {
            $take = min($pool, max(0, $due[$installment->id] - $coverage[$installment->id]));

            if ($take > 0) {
                $coverage[$installment->id] += $take;
                $pool -= $take;
                $lastPaid[$installment->id] = $lastPayment;
            }
        }

        $changes = ['license' => null, 'installments' => []];

        DB::transaction(function () use ($license, $installments, $coverage, $due, $lastPaid, $payments, $apply, &$changes) {
            foreach ($installments as $installment) {
                $covered = $coverage[$installment->id] >= $due[$installment->id] && $due[$installment->id] > 0;
                $status = $covered
                    ? 'paid'
                    : ($installment->due_date->lt(today()) ? 'overdue' : 'pending');
                $paidAt = $covered ? ($installment->status === 'paid' && $installment->paid_at ? $installment->paid_at : ($lastPaid[$installment->id] ?? now())) : null;
                $paid = $coverage[$installment->id] / 100;

                $same = $installment->status === $status
                    && $this->toCents($installment->paid_amount) === $coverage[$installment->id]
                    && ($installment->paid_at?->timestamp === $paidAt?->timestamp);

                if ($same) {
                    continue;
                }

                $changes['installments'][] = [
                    'id' => $installment->id,
                    'number' => $installment->installment_number,
                    'from' => $installment->status,
                    'to' => $status,
                    'paid_from' => number_format((float) $installment->paid_amount, 2, '.', ''),
                    'paid_to' => number_format($paid, 2, '.', ''),
                ];

                if ($apply) {
                    $installment->update(['status' => $status, 'paid_amount' => $paid, 'paid_at' => $paidAt]);
                }
            }

            $real = $payments->sum(fn ($p) => $this->toCents($p->amount));

            if ($this->toCents($license->paid_amount) !== $real) {
                $changes['license'] = [
                    'from' => number_format((float) $license->paid_amount, 2, '.', ''),
                    'to' => number_format($real / 100, 2, '.', ''),
                ];

                if ($apply) {
                    $license->update(['paid_amount' => $real / 100]);
                }
            }
        });

        if ($apply && ($changes['license'] || $changes['installments'])) {
            $this->log('repaired', "Licencia #{$license->id}: cobranza reconciliada con los pagos registrados", $license, [
                'license' => $changes['license'],
                'installments' => $changes['installments'],
            ], null);
        }

        return $changes;
    }

    /**
     * Conteo de inconsistencias de cobranza (deben ser 0 tras `licenses:repair --apply`).
     *
     * @return array{paid_installments_underpaid:int, covered_installments_not_paid:int, paid_amount_mismatch:int}
     */
    public function inconsistencies(): array
    {
        return [
            'paid_installments_underpaid' => LicenseInstallment::where('status', 'paid')
                ->whereColumn('paid_amount', '<', 'amount_due')->count(),
            'covered_installments_not_paid' => LicenseInstallment::where('status', '<>', 'paid')
                ->where('amount_due', '>', 0)
                ->whereColumn('paid_amount', '>=', 'amount_due')->count(),
            'paid_amount_mismatch' => CompanyLicense::query()
                ->whereRaw('ROUND(paid_amount, 2) <> ROUND(COALESCE((SELECT SUM(p.amount) FROM license_payments p WHERE p.company_license_id = company_licenses.id), 0), 2)')
                ->count(),
        ];
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    /**
     * Genera cuotas mensuales. El último absorbe el resto de centavos.
     *
     * @return int cuotas creadas
     */
    public function generateInstallments(
        CompanyLicense $license,
        int $count,
        int $totalCents,
        CarbonInterface $firstDue,
        int $firstNumber = 1,
    ): int {
        $this->assertLocalManagement('generateInstallments');

        if ($count <= 0 || $totalCents <= 0) {
            return 0;
        }

        $base = intdiv($totalCents, $count);
        $last = $totalCents - $base * ($count - 1);

        for ($i = 1; $i <= $count; $i++) {
            LicenseInstallment::create([
                'company_license_id' => $license->id,
                'installment_number' => $firstNumber + $i - 1,
                'amount_due' => ($i === $count ? $last : $base) / 100,
                'paid_amount' => 0,
                'due_date' => $firstDue->copy()->addMonthsNoOverflow($i - 1)->toDateString(),
                'status' => 'pending',
            ]);
        }

        return $count;
    }

    /**
     * Aplica la renovación sobre el modelo (ya bloqueado) sin guardarlo.
     *
     * @return array{amount:int, generated:int} centavos agregados y cuotas creadas
     */
    private function applyRenewal(CompanyLicense $license, array $data): array
    {
        $type = $license->licenseType;

        if ($license->status === LicenseStatus::CANCELLED) {
            throw new LicenseException('No se puede renovar una licencia cancelada.');
        }
        if (! $type || $type->isPermanent()) {
            throw new LicenseException('Una licencia permanente no se renueva: no tiene vencimiento.');
        }

        $start = $license->expires_at && $license->expires_at->gt(now())
            ? $license->expires_at->copy()
            : now();

        $expires = $start->copy()->addDays($type->duration_days);
        $amount = max(0, $this->toCents($data['total_amount'] ?? $type->base_price));
        $count = max(1, (int) ($data['installments_count'] ?? 1));
        $trial = $license->status === LicenseStatus::TRIAL || $license->isTrialType();

        $first = (int) LicenseInstallment::where('company_license_id', $license->id)->max('installment_number');
        $generated = $this->generateInstallments($license, $count, $amount, $start, $first + 1);

        $license->expires_at = $expires;
        $license->grace_until = $expires->copy()->addDays($this->graceDays($type));
        $license->total_amount = ($this->toCents($license->total_amount) + $amount) / 100;
        $license->installments_count = (int) $license->installments_count + $generated;

        // Un bloqueo manual no se levanta renovando: requiere "desbloquear".
        if ($license->status !== LicenseStatus::BLOCKED) {
            $license->status = $license->statusByDates(null, $trial);
        }

        return ['amount' => $amount, 'generated' => $generated];
    }

    /**
     * Reparte $cents entre cuotas. Con cuota indicada solo a ella (y no puede excederla);
     * sin ella, por orden de vencimiento. Lo que no quepa en cuotas queda como pago libre.
     *
     * @return array<int, int> installment_id => centavos aplicados
     */
    private function allocate(CompanyLicense $license, int $cents, ?int $installmentId): array
    {
        $open = LicenseInstallment::query()
            ->where('company_license_id', $license->id)
            ->where('status', '<>', 'paid')
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->lockForUpdate()
            ->get();

        if ($installmentId) {
            $target = $open->firstWhere('id', $installmentId);

            if (! $target) {
                throw LicenseException::forField(
                    'license_installment_id',
                    'La cuota indicada no existe en esta licencia o ya está pagada.'
                );
            }

            $remaining = $this->toCents($target->amount_due) - $this->toCents($target->paid_amount);

            if ($cents > $remaining) {
                throw LicenseException::forField('amount', sprintf(
                    'El monto excede el saldo de la cuota (%s %s).',
                    config('licensing.currency.symbol'),
                    number_format($remaining / 100, 2)
                ));
            }

            return [$target->id => $cents];
        }

        $allocations = [];
        $left = $cents;

        foreach ($open as $installment) {
            if ($left <= 0) {
                break;
            }

            $remaining = $this->toCents($installment->amount_due) - $this->toCents($installment->paid_amount);
            $take = min($left, max(0, $remaining));

            if ($take > 0) {
                $allocations[$installment->id] = $take;
                $left -= $take;
            }
        }

        return $allocations;
    }

    private function persistComputedStatus(CompanyLicense $license): void
    {
        $computed = $license->computeStatus();

        if ($computed !== $license->status) {
            $license->update(['status' => $computed]);
        }
    }

    private function lock(CompanyLicense $license): CompanyLicense
    {
        return CompanyLicense::query()
            ->with('licenseType')
            ->whereKey($license->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** Las escrituras comerciales solo existen en modo standalone (C3). */
    private function assertLocalManagement(string $operation): void
    {
        if (Licensing::mode() !== Licensing::MODE_STANDALONE) {
            throw ManagedModeException::forOperation($operation);
        }
    }

    private function graceDays(?LicenseType $type): int
    {
        return (int) ($type?->grace_period_days ?? config('licensing.default_grace_days', 7));
    }

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw LicenseException::forField('reason', 'El motivo es obligatorio.');
        }

        return $reason;
    }

    private function toCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function logTransition(
        string $action,
        string $verb,
        CompanyLicense $license,
        string $previous,
        string $reason,
        Model $by,
        array $extra = [],
    ): void {
        $this->log($action, "Licencia #{$license->id} {$verb}: {$reason}", $license, array_merge([
            'reason' => $reason,
            'previous_status' => $previous,
            'new_status' => $license->status->value,
        ], $extra), $by);
    }

    /** Rastro de auditoría vía el contrato AuditLogger (nulo por defecto). */
    private function log(string $action, string $description, CompanyLicense $license, array $properties, ?Model $by): void
    {
        $base = [
            'license_id' => $license->id,
            Licensing::tenantKey() => $license->getAttribute(Licensing::tenantKey()),
        ];

        if (Licensing::branchesEnabled()) {
            $base[Licensing::branchKey()] = $license->getAttribute(Licensing::branchKey());
        }

        $this->audit->log($action, $description, $license, array_merge($base, $properties), $by);
    }
}
