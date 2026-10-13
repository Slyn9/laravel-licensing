<?php

namespace Slyn9\Licensing\Tests\Behavior;

use Illuminate\Support\Facades\DB;
use Slyn9\Licensing\Models\CompanyLicense;
use Slyn9\Licensing\Models\LicensePayment;

/** Comando de corrección de datos (idempotente). */
trait RepairBehavior
{
    private function payment(CompanyLicense $license, float $amount, ?int $installmentId = null): LicensePayment
    {
        return $license->payments()->create([
            'license_installment_id' => $installmentId,
            'amount' => $amount,
            'paid_at' => now()->subDays(3),
            'payment_method' => 'efectivo',
            'recorded_by' => $this->superadmin()->id,
        ]);
    }

    /** Datos como los dejaba el código anterior: pagos desde la UI nunca vinculados a cuota. */
    private function legacyUnlinkedPayments(): CompanyLicense
    {
        $license = $this->makeLicense($this->scopeA(), 30, ['total' => 100, 'paid' => 70, 'installments' => 2]);
        $this->makeInstallments($license, 2, 100);
        $this->payment($license, 70);

        return $license;
    }

    public function test_dry_run_reports_but_does_not_write(): void
    {
        $license = $this->legacyUnlinkedPayments();

        $this->artisan('licenses:repair')->assertSuccessful();

        $this->assertSame('pending', $license->installments()->first()->status);
        $this->assertSame('0.00', (string) $license->installments()->first()->paid_amount);
    }

    public function test_apply_reallocates_unlinked_payments_in_due_date_order(): void
    {
        $license = $this->legacyUnlinkedPayments();

        $this->artisan('licenses:repair', ['--apply' => true])->assertSuccessful();

        [$c1, $c2] = $license->installments()->get();
        $this->assertSame('paid', $c1->status);
        $this->assertSame('50.00', (string) $c1->paid_amount);
        $this->assertNotNull($c1->paid_at);
        $this->assertNotSame('paid', $c2->status);
        $this->assertSame('20.00', (string) $c2->paid_amount);
    }

    public function test_apply_downgrades_installments_marked_paid_without_enough_payments(): void
    {
        $license = $this->makeLicense($this->scopeA(), 30, ['total' => 100, 'paid' => 20, 'installments' => 2]);
        [$c1] = $this->makeInstallments($license, 2, 100);
        $c1->update(['status' => 'paid', 'paid_amount' => 50, 'paid_at' => now()]);
        $this->payment($license, 20, $c1->id);

        $this->artisan('licenses:repair')
            ->expectsOutputToContain('Licencias con diferencias: 1')
            ->assertSuccessful();

        $this->artisan('licenses:repair', ['--apply' => true])->assertSuccessful();

        $c1 = $c1->fresh();
        $this->assertNotSame('paid', $c1->status);
        $this->assertSame('20.00', (string) $c1->paid_amount);
        $this->assertNull($c1->paid_at);
    }

    public function test_apply_fixes_paid_amount_that_differs_from_the_sum_of_payments(): void
    {
        $license = $this->makeLicense($this->scopeA(), 30, ['total' => 100, 'paid' => 999, 'installments' => 1]);
        $this->makeInstallments($license, 1, 100);
        $this->payment($license, 40);

        $this->assertSame(1, $this->service()->inconsistencies()['paid_amount_mismatch']);

        $this->artisan('licenses:repair', ['--apply' => true])->assertSuccessful();

        $this->assertSame('40.00', (string) $license->fresh()->paid_amount);
    }

    public function test_apply_leaves_every_inconsistency_at_zero_and_is_idempotent(): void
    {
        $this->legacyUnlinkedPayments();
        $other = $this->makeLicense(null, 30, ['total' => 60, 'paid' => 500, 'installments' => 1]);
        $this->makeInstallments($other, 1, 60);
        $this->payment($other, 60);

        $this->artisan('licenses:repair', ['--apply' => true])->assertSuccessful();

        $this->assertSame(
            ['paid_installments_underpaid' => 0, 'covered_installments_not_paid' => 0, 'paid_amount_mismatch' => 0],
            $this->service()->inconsistencies()
        );

        $snapshot = DB::table('license_installments')->get()->toJson().DB::table('company_licenses')->get()->toJson();

        $this->artisan('licenses:repair', ['--apply' => true])
            ->expectsOutputToContain('Licencias con diferencias: 0')
            ->assertSuccessful();

        $this->assertSame($snapshot, DB::table('license_installments')->get()->toJson().DB::table('company_licenses')->get()->toJson());
    }

    public function test_repair_never_touches_payments_or_manual_statuses(): void
    {
        $license = $this->makeLicense($this->scopeA(), 30, ['total' => 100, 'paid' => 70, 'installments' => 2, 'status' => 'blocked']);
        $this->makeInstallments($license, 2, 100);
        $this->payment($license, 70);

        $before = DB::table('license_payments')->get()->toJson();

        $this->artisan('licenses:repair', ['--apply' => true])->assertSuccessful();

        $this->assertSame($before, DB::table('license_payments')->get()->toJson());
        $this->assertSame('blocked', $license->fresh()->status->value);
    }

    public function test_repair_can_be_limited_to_one_tenant(): void
    {
        $license = $this->legacyUnlinkedPayments();
        $otherTenant = $this->createTenant(['name' => 'Otra']);

        $this->artisan('licenses:repair', ['--apply' => true, '--company' => $otherTenant->getKey()])->assertSuccessful();

        $this->assertSame('pending', $license->installments()->first()->status);
    }

    public function test_service_payments_leave_no_inconsistencies(): void
    {
        $license = $this->makeLicense($this->scopeA(), 30, ['total' => 100, 'installments' => 2]);
        $this->makeInstallments($license, 2, 100);
        $service = $this->service();

        $service->registerPayment($license, 70, ['paid_at' => now(), 'payment_method' => 'efectivo'], $this->superadmin());
        $service->registerPayment($license, 30, ['paid_at' => now(), 'payment_method' => 'efectivo'], $this->superadmin());

        $this->assertSame(0, array_sum($service->inconsistencies()));
    }
}
