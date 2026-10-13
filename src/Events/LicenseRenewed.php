<?php

namespace Slyn9\Licensing\Events;

use Slyn9\Licensing\Models\CompanyLicense;

/** Se renovó una licencia (renew, o unblock con renovación). */
final class LicenseRenewed extends LicenseEvent
{
    public function __construct(
        string $product,
        string $licenseUuid,
        string $tenantRef,
        ?string $branchRef,
        ?string $previousStatus,
        string $newStatus,
        public readonly ?string $previousExpiresAt,
        public readonly ?string $newExpiresAt,
        public readonly string $addedAmount,
        public readonly string $newTotalAmount,
        public readonly int $installmentsCreated,
    ) {
        parent::__construct($product, $licenseUuid, $tenantRef, $branchRef, $previousStatus, $newStatus);
    }

    public static function for(
        CompanyLicense $license,
        string $previousStatus,
        ?string $previousExpiresAt,
        float $addedAmount,
        int $installmentsCreated,
    ): self {
        return new self(
            ...self::common($license, $previousStatus),
            previousExpiresAt: $previousExpiresAt,
            newExpiresAt: self::date($license->expires_at),
            addedAmount: self::money($addedAmount),
            newTotalAmount: self::money($license->total_amount),
            installmentsCreated: $installmentsCreated,
        );
    }

    public function name(): string
    {
        return 'license.renewed';
    }

    protected function payload(): array
    {
        return [
            'previous_expires_at' => $this->previousExpiresAt,
            'new_expires_at' => $this->newExpiresAt,
            'added_amount' => $this->addedAmount,
            'new_total_amount' => $this->newTotalAmount,
            'installments_created' => $this->installmentsCreated,
        ];
    }
}
