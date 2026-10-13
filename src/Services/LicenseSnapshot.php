<?php

namespace Slyn9\Licensing\Services;

use Slyn9\Licensing\Contracts\TenantIdentity;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Models\CompanyLicense;

/**
 * Snapshot versionado (C5). Contrato público: ver README "Snapshot v1".
 * Importes como string con 2 decimales; fechas en ISO 8601; sin datos personales.
 */
class LicenseSnapshot
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private readonly TenantIdentity $identity) {}

    /** @return array<string, mixed> */
    public function build(CompanyLicense $license): array
    {
        $license->loadMissing(['licenseType', 'tenant']);
        $installments = $license->installments()->orderBy('installment_number')->get();
        $payments = $license->payments()->reorder('paid_at')->orderBy('id')->get();

        $byId = $installments->mapWithKeys(fn ($i) => [$i->id => $i->ensureUuid()]);

        $branch = null;
        if (Licensing::branchesEnabled() && $license->getAttribute(Licensing::branchKey())) {
            $branch = $license->branch;
        }

        $type = $license->licenseType;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => now()->toIso8601String(),
            'product' => Licensing::productCode(),
            'tenant_ref' => $this->identity->externalRef($license->tenant),
            'scope' => [
                'level' => $branch ? 'branch' : 'tenant',
                'branch_ref' => $branch ? $this->identity->branchRef($branch) : null,
            ],
            'license' => [
                'uuid' => $license->ensureUuid(),
                'type' => [
                    'uuid' => $type->ensureUuid(),
                    'code' => $type->code,
                    'name' => $type->name,
                    'duration_days' => $type->duration_days,
                    'grace_period_days' => (int) $type->grace_period_days,
                ],
                'status' => $license->status->value,
                'effective_status' => $license->computeStatus()->value,
                'starts_at' => $this->date($license->starts_at),
                'expires_at' => $this->date($license->expires_at),
                'grace_until' => $this->date($license->grace_until),
                'currency' => (string) $license->currency,
                'total_amount' => $this->money($license->total_amount),
                'paid_amount' => $this->money($license->paid_amount),
                'pending_amount' => $this->money($license->pending_amount),
                'installments_count' => (int) $license->installments_count,
                'notes' => $license->notes,
                'created_at' => $this->date($license->created_at),
                'updated_at' => $this->date($license->updated_at),
            ],
            'installments' => $installments->map(fn ($i) => [
                'uuid' => $i->ensureUuid(),
                'number' => (int) $i->installment_number,
                'amount_due' => $this->money($i->amount_due),
                'paid_amount' => $this->money($i->paid_amount),
                'due_date' => $i->due_date?->toDateString(),
                'paid_at' => $this->date($i->paid_at),
                'status' => $i->status,
            ])->values()->all(),
            'payments' => $payments->map(fn ($p) => [
                'uuid' => $p->ensureUuid(),
                'installment_uuid' => $p->license_installment_id ? ($byId[$p->license_installment_id] ?? null) : null,
                'amount' => $this->money($p->amount),
                'paid_at' => $this->date($p->paid_at),
                'method' => $p->payment_method,
                'reference' => $p->reference,
                'notes' => $p->notes,
            ])->values()->all(),
        ];
    }

    private function date(mixed $value): ?string
    {
        return $value?->toIso8601String();
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
