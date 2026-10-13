<?php

namespace Slyn9\Licensing\Events;

use Slyn9\Licensing\Models\CompanyLicense;

/** Se desbloqueó una licencia (el estado se recalcula por fechas). */
final class LicenseUnblocked extends LicenseEvent
{
    public function __construct(
        string $product,
        string $licenseUuid,
        string $tenantRef,
        ?string $branchRef,
        ?string $previousStatus,
        string $newStatus,
        public readonly string $reason,
        public readonly ?string $newExpiresAt,
        public readonly bool $renewed,
    ) {
        parent::__construct($product, $licenseUuid, $tenantRef, $branchRef, $previousStatus, $newStatus);
    }

    public static function for(CompanyLicense $license, string $previousStatus, string $reason, bool $renewed): self
    {
        return new self(
            ...self::common($license, $previousStatus),
            reason: $reason,
            newExpiresAt: self::date($license->expires_at),
            renewed: $renewed,
        );
    }

    public function name(): string
    {
        return 'license.unblocked';
    }

    protected function payload(): array
    {
        return [
            'reason' => $this->reason,
            'new_expires_at' => $this->newExpiresAt,
            'renewed' => $this->renewed,
        ];
    }
}
