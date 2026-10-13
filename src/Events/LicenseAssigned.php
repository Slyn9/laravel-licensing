<?php

namespace Slyn9\Licensing\Events;

use Slyn9\Licensing\Models\CompanyLicense;

/** Se asignó una licencia (assign / assignTrial). */
final class LicenseAssigned extends LicenseEvent
{
    public function __construct(
        string $product,
        string $licenseUuid,
        string $tenantRef,
        ?string $branchRef,
        ?string $previousStatus,
        string $newStatus,
        public readonly string $licenseTypeCode,
        public readonly string $startsAt,
        public readonly ?string $expiresAt,
        public readonly string $totalAmount,
        public readonly string $currency,
        public readonly int $installmentsCount,
    ) {
        parent::__construct($product, $licenseUuid, $tenantRef, $branchRef, $previousStatus, $newStatus);
    }

    public static function for(CompanyLicense $license): self
    {
        return new self(
            ...self::common($license, null),
            licenseTypeCode: (string) $license->licenseType->code,
            startsAt: (string) self::date($license->starts_at),
            expiresAt: self::date($license->expires_at),
            totalAmount: self::money($license->total_amount),
            currency: (string) $license->currency,
            installmentsCount: (int) $license->installments_count,
        );
    }

    public function name(): string
    {
        return 'license.assigned';
    }

    protected function payload(): array
    {
        return [
            'license_type_code' => $this->licenseTypeCode,
            'starts_at' => $this->startsAt,
            'expires_at' => $this->expiresAt,
            'total_amount' => $this->totalAmount,
            'currency' => $this->currency,
            'installments_count' => $this->installmentsCount,
        ];
    }
}
