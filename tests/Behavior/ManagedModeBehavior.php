<?php

namespace Slyn9\Licensing\Tests\Behavior;

use Illuminate\Support\Facades\Http;
use LogicException;
use Slyn9\Licensing\Exceptions\LicenseException;
use Slyn9\Licensing\Exceptions\ManagedModeException;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Models\CompanyLicense;
use Slyn9\Licensing\Models\LicensePayment;

/** C3 — licensing.mode = managed: las escrituras se rechazan y el acceso sigue funcionando. */
trait ManagedModeBehavior
{
    private function managed(): void
    {
        config(['licensing.mode' => 'managed']);
    }

    private function assertManagedRejection(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Debió lanzar ManagedModeException.');
        } catch (ManagedModeException $e) {
            $this->assertInstanceOf(LicenseException::class, $e);
        }
    }

    public function test_every_commercial_write_is_rejected_in_managed_mode(): void
    {
        $license = $this->makeLicense($this->scopeA(), 30, ['total' => 100, 'installments' => 1]);
        $this->makeInstallments($license, 1, 100);
        $blocked = $this->makeLicense(null, 30, ['status' => 'blocked']);
        $admin = $this->superadmin();
        $before = [CompanyLicense::count(), LicensePayment::count(), (string) $license->fresh()->paid_amount];
        $this->managed();

        $this->assertFalse(Licensing::allowsLocalManagement());

        $this->assertManagedRejection(fn () => $this->service()->assign($this->tenant, $this->scopeA(), $this->types['MON'], []));
        $this->assertManagedRejection(fn () => $this->service()->registerPayment($license, 10, ['payment_method' => 'efectivo'], $admin));
        $this->assertManagedRejection(fn () => $this->service()->renew($license, $admin, []));
        $this->assertManagedRejection(fn () => $this->service()->block($license, $admin, 'motivo'));
        $this->assertManagedRejection(fn () => $this->service()->unblock($blocked, $admin, 'motivo'));
        $this->assertManagedRejection(fn () => $this->service()->cancel($license, $admin, 'motivo'));
        $this->assertManagedRejection(fn () => $this->service()->reconcile($license, true));

        $this->assertSame($before, [CompanyLicense::count(), LicensePayment::count(), (string) $license->fresh()->paid_amount]);
        $this->assertSame('active', $license->fresh()->status->value);
        $this->assertSame('blocked', $blocked->fresh()->status->value);
    }

    public function test_managed_rejection_happens_before_touching_the_database(): void
    {
        $license = $this->makeLicense($this->scopeA(), 30, ['total' => 100]);
        $blocked = $this->makeLicense(null, 30, ['status' => 'blocked']);
        $admin = $this->superadmin();
        $this->managed();

        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();

        $this->assertManagedRejection(fn () => $this->service()->assign($this->tenant, $this->scopeA(), $this->types['MON'], []));
        $this->assertManagedRejection(fn () => $this->service()->registerPayment($license, 10, ['payment_method' => 'efectivo'], $admin));
        $this->assertManagedRejection(fn () => $this->service()->renew($license, $admin, []));
        $this->assertManagedRejection(fn () => $this->service()->block($license, $admin, 'motivo'));
        $this->assertManagedRejection(fn () => $this->service()->unblock($blocked, $admin, 'motivo'));
        $this->assertManagedRejection(fn () => $this->service()->cancel($license, $admin, 'motivo'));
        $this->assertManagedRejection(fn () => $this->service()->reconcile($license, true));

        $this->assertSame([], \Illuminate\Support\Facades\DB::getQueryLog(), 'Una escritura rechazada no debe tocar la BD.');
    }
    public function test_the_managed_check_runs_before_any_validation(): void
    {
        $license = $this->makeLicense($this->scopeA(), 30);
        $this->managed();

        // Sin motivo: en standalone sería un error de campo `reason`; en managed manda el modo.
        $this->assertManagedRejection(fn () => $this->service()->block($license, $this->superadmin(), ''));
    }

    public function test_assign_trial_returns_null_without_writing_in_managed_mode(): void
    {
        $this->managed();

        $this->assertNull($this->service()->assignTrial($this->tenant, $this->scopeA()));
        $this->assertSame(0, CompanyLicense::count());
    }

    public function test_reconcile_dry_run_is_still_available_in_managed_mode(): void
    {
        $license = $this->makeLicense($this->scopeA(), 30, ['total' => 100, 'paid' => 40]);
        $this->managed();

        $changes = $this->service()->reconcile($license, false);

        $this->assertSame('40.00', $changes['license']['from']);
        $this->assertSame('40.00', (string) $license->fresh()->paid_amount);
    }

    public function test_access_control_keeps_working_in_managed_mode_without_network(): void
    {
        Http::fake();
        $this->makeLicense(null, -40, ['grace' => 5]);
        $expiredUser = $this->makeUser($this->scopeA());
        $this->managed();

        $this->probe($expiredUser, null, $this->scopeA())->assertStatus(402);
        $this->login($expiredUser)->assertSessionHasErrors('email_user');

        CompanyLicense::query()->delete();
        $this->makeLicense(null, 30);

        $this->probe($expiredUser, null, $this->scopeA())->assertOk();
        $this->login($expiredUser)->assertSessionHasNoErrors();
        $this->assertTrue($this->service()->canEnter($expiredUser, $this->tenant));

        Http::assertNothingSent();
    }

    public function test_sync_is_not_a_commercial_write_and_still_runs_in_managed_mode(): void
    {
        $license = $this->makeLicense($this->scopeA(), -2, ['grace' => 5]);
        $this->managed();

        $this->artisan('licenses:sync')->assertSuccessful();

        $this->assertSame('grace', $license->fresh()->status->value);
    }

    public function test_an_unknown_mode_never_opens_writes(): void
    {
        config(['licensing.mode' => 'hybrid']);

        $this->assertFalse(Licensing::allowsLocalManagement());

        $this->expectException(LogicException::class);
        $this->service()->assign($this->tenant, $this->scopeA(), $this->types['MON'], []);
    }
}
