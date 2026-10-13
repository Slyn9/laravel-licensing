<?php

namespace Slyn9\Licensing\Tests\Behavior;

use RuntimeException;
use Slyn9\Licensing\Exceptions\LicenseException;
use Slyn9\Licensing\Models\CompanyLicense;
use Slyn9\Licensing\Models\LicenseInstallment;

/** D3 — Pagos: transacción, tope de saldo y cuotas cubiertas solo cuando están completas. */
trait PaymentBehavior
{
    /** @return array{0: CompanyLicense, 1: array<int, LicenseInstallment>} */
    private function licenseWithTwoInstallments(): array
    {
        $license = $this->makeLicense($this->scopeA(), 30, ['total' => 100, 'installments' => 2]);

        return [$license, $this->makeInstallments($license, 2, 100)];
    }

    private function pay(CompanyLicense $license, float $amount, array $extra = [])
    {
        return $this->service()->registerPayment($license, $amount, array_merge([
            'paid_at' => '2026-10-12',
            'payment_method' => 'efectivo',
        ], $extra), $this->superadmin());
    }

    private function assertPaymentRejected(string $field, callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('Debió rechazar el pago.');
        } catch (LicenseException $e) {
            $this->assertSame($field, $e->field);
        }
    }

    public function test_payment_above_pending_balance_is_rejected(): void
    {
        [$license] = $this->licenseWithTwoInstallments();

        $this->assertPaymentRejected('amount', fn () => $this->pay($license, 150));

        $this->assertSame(0, $license->payments()->count());
        $this->assertSame('0.00', (string) $license->fresh()->paid_amount);
    }

    public function test_partial_payment_does_not_mark_the_installment_as_paid(): void
    {
        [$license, [$c1]] = $this->licenseWithTwoInstallments();

        $this->pay($license, 20, ['license_installment_id' => $c1->id]);

        $c1 = $c1->fresh();
        $this->assertSame('pending', $c1->status);
        $this->assertSame('20.00', (string) $c1->paid_amount);
        $this->assertNull($c1->paid_at);
        $this->assertSame($c1->id, $license->payments()->first()->license_installment_id);
        $this->assertSame('20.00', (string) $license->fresh()->paid_amount);
    }

    public function test_installment_becomes_paid_only_when_fully_covered(): void
    {
        [$license, [$c1]] = $this->licenseWithTwoInstallments();

        $this->pay($license, 20, ['license_installment_id' => $c1->id]);
        $this->pay($license, 30, ['license_installment_id' => $c1->id]);

        $c1 = $c1->fresh();
        $this->assertSame('paid', $c1->status);
        $this->assertSame('50.00', (string) $c1->paid_amount);
        $this->assertNotNull($c1->paid_at);
    }

    public function test_free_payment_is_applied_to_installments_in_due_date_order(): void
    {
        [$license, [$c1, $c2]] = $this->licenseWithTwoInstallments();

        $this->pay($license, 70);

        $this->assertSame('paid', $c1->fresh()->status);
        $this->assertSame('pending', $c2->fresh()->status);
        $this->assertSame('20.00', (string) $c2->fresh()->paid_amount);
        $this->assertSame('70.00', (string) $license->fresh()->paid_amount);
    }

    public function test_payment_above_the_indicated_installment_balance_is_rejected(): void
    {
        [$license, [$c1]] = $this->licenseWithTwoInstallments();

        $this->assertPaymentRejected('amount', fn () => $this->pay($license, 60, ['license_installment_id' => $c1->id]));

        $this->assertSame(0, $license->payments()->count());
    }

    public function test_installment_of_another_license_is_rejected(): void
    {
        [$license] = $this->licenseWithTwoInstallments();
        $other = $this->makeLicense(null, 30, ['total' => 50]);
        [$foreign] = $this->makeInstallments($other, 1, 50);

        $this->assertPaymentRejected('license_installment_id', fn () => $this->pay($license, 10, ['license_installment_id' => $foreign->id]));

        $this->assertSame(0, $license->payments()->count());
    }

    public function test_paying_the_exact_balance_settles_everything(): void
    {
        [$license, [$c1, $c2]] = $this->licenseWithTwoInstallments();

        $this->pay($license, 100);

        $this->assertSame('paid', $c1->fresh()->status);
        $this->assertSame('paid', $c2->fresh()->status);
        $this->assertSame(0.0, $license->fresh()->pending_amount);
    }

    public function test_payment_on_a_cancelled_license_is_rejected(): void
    {
        [$license] = $this->licenseWithTwoInstallments();
        $license->update(['status' => 'cancelled']);

        $this->assertPaymentRejected('amount', fn () => $this->pay($license, 10));

        $this->assertSame(0, $license->payments()->count());
    }

    public function test_paying_the_total_does_not_lift_a_manual_block(): void
    {
        [$license] = $this->licenseWithTwoInstallments();
        $license->update(['status' => 'blocked']);

        $this->pay($license, 100);

        $this->assertSame('blocked', $license->fresh()->status->value);
    }

    public function test_payment_is_atomic(): void
    {
        [$license, [$c1]] = $this->licenseWithTwoInstallments();

        LicenseInstallment::updating(function () {
            throw new RuntimeException('fallo simulado');
        });

        try {
            $this->service()->registerPayment($license, 30, [
                'license_installment_id' => $c1->id,
                'paid_at' => now(),
                'payment_method' => 'efectivo',
            ], $this->superadmin());
            $this->fail('Debió lanzar la excepción simulada.');
        } catch (RuntimeException) {
            // esperado
        } finally {
            LicenseInstallment::flushEventListeners();
        }

        $this->assertSame(0, $license->payments()->count());
        $this->assertSame('0.00', (string) $license->fresh()->paid_amount);
    }

    public function test_service_throws_a_domain_exception_on_overpayment(): void
    {
        [$license] = $this->licenseWithTwoInstallments();

        $this->expectException(LicenseException::class);

        $this->pay($license, 100.01);
    }

    public function test_model_helper_still_works(): void
    {
        [$license, [$c1]] = $this->licenseWithTwoInstallments();

        $license->registerPayment(50, [
            'installment_id' => $c1->id,
            'paid_at' => now(),
            'payment_method' => 'transferencia',
        ], $this->superadmin()->id);

        $this->assertSame('paid', $c1->fresh()->status);
        $this->assertSame('50.00', (string) $license->fresh()->paid_amount);
    }
}
