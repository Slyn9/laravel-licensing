<?php

namespace Slyn9\Licensing\Events;

use Slyn9\Licensing\Models\CompanyLicense;

/** `licenses:sync` persistió un cambio de estado derivado de las fechas. */
final class LicenseStatusChanged extends LicenseEvent
{
    public function __construct(
        string $product,
        string $licenseUuid,
        string $tenantRef,
        ?string $branchRef,
        ?string $previousStatus,
        string $newStatus,
        public readonly string $source,
    ) {
        parent::__construct($product, $licenseUuid, $tenantRef, $branchRef, $previousStatus, $newStatus);
    }

    public static function for(CompanyLicense $license, string $previousStatus, string $source = 'sync'): self
    {
        return new self(...self::common($license, $previousStatus), source: $source);
    }

    public function name(): string
    {
        return 'license.status_changed';
    }

    protected function payload(): array
    {
        return ['source' => $this->source];
    }
}
