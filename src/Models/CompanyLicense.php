<?php

namespace Slyn9\Licensing\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;
use Slyn9\Licensing\Enums\LicenseStatus;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Models\Concerns\HasLicenseUuid;
use Slyn9\Licensing\Services\LicenseService;
use Slyn9\Licensing\Services\LicenseSnapshot;

class CompanyLicense extends Model
{
    use HasLicenseUuid;
    use SoftDeletes;

    protected $fillable = [
        'license_type_id', 'status',
        'starts_at', 'expires_at', 'grace_until',
        'total_amount', 'paid_amount', 'installments_count', 'currency', 'notes',
    ];

    protected $casts = [
        'status' => LicenseStatus::class,
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'grace_until' => 'datetime',
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    /** Las llaves hacia tenant y sede dependen de la configuración del anfitrión. */
    public function getFillable(): array
    {
        $keys = [Licensing::tenantKey()];

        if (Licensing::branchesEnabled()) {
            $keys[] = Licensing::branchKey();
        }

        return array_values(array_unique(array_merge(parent::getFillable(), $keys)));
    }

    // ── Relations ────────────────────────────────────────────────────────────

    public function tenant()
    {
        return $this->belongsTo(Licensing::tenantModel(), Licensing::tenantKey());
    }

    public function branch()
    {
        if (! Licensing::branchesEnabled()) {
            throw new LogicException('Las sedes están desactivadas (licensing.branches.enabled = false).');
        }

        return $this->belongsTo(Licensing::branchModel(), Licensing::branchKey());
    }

    public function licenseType()
    {
        return $this->belongsTo(LicenseType::class);
    }

    public function installments()
    {
        return $this->hasMany(LicenseInstallment::class)->orderBy('installment_number');
    }

    public function payments()
    {
        return $this->hasMany(LicensePayment::class)->latest('paid_at');
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function getPendingAmountAttribute(): float
    {
        return max(0, (float) $this->total_amount - (float) $this->paid_amount);
    }

    public function getDaysUntilExpiryAttribute(): ?int
    {
        if (! $this->expires_at) {
            return null;
        }

        return (int) now()->diffInDays($this->expires_at, false);
    }

    /** Estado real según las fechas de hoy; el guardado puede estar atrasado hasta `licenses:sync`. */
    public function getEffectiveStatusAttribute(): LicenseStatus
    {
        return $this->computeStatus();
    }

    // ── Estado ───────────────────────────────────────────────────────────────

    /**
     * Estado que corresponde a la licencia en $at. Función pura: no escribe en la BD ni
     * hace llamadas de red. Es la que usan el middleware y el login; `licenses:sync`
     * solo persiste su resultado.
     */
    public function computeStatus(?CarbonInterface $at = null): LicenseStatus
    {
        if (in_array($this->status, [LicenseStatus::BLOCKED, LicenseStatus::CANCELLED], true)) {
            return $this->status;
        }

        return $this->statusByDates($at, $this->status === LicenseStatus::TRIAL);
    }

    /**
     * Estado derivado solo de las fechas, ignorando un bloqueo manual. Sirve para saber
     * a qué estado volvería una licencia bloqueada al desbloquearla.
     */
    public function statusByDates(?CarbonInterface $at = null, bool $trial = false): LicenseStatus
    {
        // Permanente: nunca vence.
        if (! $this->expires_at) {
            return LicenseStatus::ACTIVE;
        }

        $at ??= now();

        if ($at->lte($this->expires_at)) {
            return $trial ? LicenseStatus::TRIAL : LicenseStatus::ACTIVE;
        }

        if ($this->grace_until && $at->lte($this->grace_until)) {
            return LicenseStatus::GRACE;
        }

        return LicenseStatus::EXPIRED;
    }

    public function isTrialType(): bool
    {
        return $this->licenseType?->code === Licensing::trialCode();
    }

    // ── Snapshot (C5) ────────────────────────────────────────────────────────

    /**
     * Representación versionada y serializable de la licencia, sus cuotas y pagos
     * (identificados por uuid). Ver README: "Snapshot v1".
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return app(LicenseSnapshot::class)->build($this);
    }

    // ── Compatibilidad ───────────────────────────────────────────────────────

    /** @see LicenseService::registerPayment() */
    public function registerPayment(float $amount, array $data, int $userId): LicensePayment
    {
        return app(LicenseService::class)->registerPayment($this, $amount, $data, $userId);
    }
}
