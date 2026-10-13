<?php

namespace Slyn9\Licensing\Events;

use Slyn9\Licensing\Models\CompanyLicense;
use Slyn9\Licensing\Models\LicensePayment;

/** Se registró un pago sobre una licencia. */
final class LicensePaymentRegistered extends LicenseEvent
{
    public function __construct(
        string $product,
        string $licenseUuid,
        string $tenantRef,
        ?string $branchRef,
        ?string $previousStatus,
        string $newStatus,
        public readonly string $paymentUuid,
        public readonly ?string $installmentUuid,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $paidAt,
        public readonly string $paymentMethod,
        public readonly string $licensePaidAmount,
        public readonly string $licensePendingAmount,
    ) {
        parent::__construct($product, $licenseUuid, $tenantRef, $branchRef, $previousStatus, $newStatus);
    }

    public static function for(CompanyLicense $license, LicensePayment $payment, string $previousStatus): self
    {
        $installment = $payment->license_installment_id ? $payment->installment : null;

        return new self(
            ...self::common($license, $previousStatus),
            paymentUuid: $payment->ensureUuid(),
            installmentUuid: $installment?->ensureUuid(),
            amount: self::money($payment->amount),
            currency: (string) $license->currency,
            paidAt: (string) self::date($payment->paid_at),
            paymentMethod: (string) $payment->payment_method,
            licensePaidAmount: self::money($license->paid_amount),
            licensePendingAmount: self::money($license->pending_amount),
        );
    }

    public function name(): string
    {
        return 'license.payment_registered';
    }

    protected function payload(): array
    {
        return [
            'payment_uuid' => $this->paymentUuid,
            'installment_uuid' => $this->installmentUuid,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'paid_at' => $this->paidAt,
            'payment_method' => $this->paymentMethod,
            'license_paid_amount' => $this->licensePaidAmount,
            'license_pending_amount' => $this->licensePendingAmount,
        ];
    }
}
