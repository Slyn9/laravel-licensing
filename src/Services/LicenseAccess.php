<?php

namespace Slyn9\Licensing\Services;

use Slyn9\Licensing\Enums\LicenseStatus;
use Slyn9\Licensing\Models\CompanyLicense;

/**
 * Resultado de evaluar el acceso de un tenant/sede: qué licencia manda, en qué
 * estado está hoy y si permite trabajar.
 */
final class LicenseAccess
{
    public function __construct(
        public readonly ?CompanyLicense $license,
        public readonly ?LicenseStatus $status,
        public readonly bool $allowed,
    ) {}

    /** No hay ninguna licencia aplicable (la política `missing_license` decide el acceso). */
    public function isMissing(): bool
    {
        return $this->license === null;
    }

    /** Hay licencia y conviene avisar al usuario (gracia o prueba). */
    public function shouldWarn(): bool
    {
        return $this->allowed
            && in_array($this->status, [LicenseStatus::GRACE, LicenseStatus::TRIAL], true);
    }
}
