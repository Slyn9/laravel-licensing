<?php

namespace Slyn9\Licensing\Events;

use Slyn9\Licensing\Models\CompanyLicense;

/** Se bloqueó manualmente una licencia. */
final class LicenseBlocked extends LicenseEvent
{
    public function __construct(
        string $product,
        string $licenseUuid,
        string $tenantRef,
        ?string $branchRef,
        ?string $previousStatus,
        string $newStatus,
        public readonly string $reason,
    ) {
        parent::__construct($product, $licenseUuid, $tenantRef, $branchRef, $previousStatus, $newStatus);
    }

    public static function for(CompanyLicense $license, string $previousStatus, string $reason): self
    {
        return new self(...self::common($license, $previousStatus), reason: $reason);
    }

    public function name(): string
    {
        return 'license.blocked';
    }

    protected function payload(): array
    {
        return ['reason' => $this->reason];
    }
}
