<?php

namespace Slyn9\Licensing\Tests\Behavior;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Slyn9\Licensing\Enums\LicenseStatus;

/** D4 — computeStatus() puro y comando licenses:sync que persiste. */
trait StatusSyncBehavior
{
    public function test_compute_status_is_pure(): void
    {
        $license = $this->makeLicense($this->scopeA(), -10, ['grace' => 5]);
        $before = $license->updated_at;

        $this->assertSame(LicenseStatus::EXPIRED, $license->computeStatus());

        $fresh = $license->fresh();
        $this->assertSame('active', $fresh->status->value);
        $this->assertEquals($before, $fresh->updated_at);
    }

    public function test_compute_status_covers_every_stage(): void
    {
        $s = $this->scopeA();

        $this->assertSame(LicenseStatus::ACTIVE, $this->makeLicense($s, 10)->computeStatus());
        $this->assertSame(LicenseStatus::GRACE, $this->makeLicense($s, -2, ['grace' => 5])->computeStatus());
        $this->assertSame(LicenseStatus::EXPIRED, $this->makeLicense($s, -9, ['grace' => 5])->computeStatus());
        $this->assertSame(LicenseStatus::ACTIVE, $this->makeLicense($s, null, ['type' => 'PERM'])->computeStatus());
        $this->assertSame(LicenseStatus::TRIAL, $this->makeLicense($s, 5, ['type' => 'TRIAL', 'status' => 'trial'])->computeStatus());
        $this->assertSame(LicenseStatus::BLOCKED, $this->makeLicense($s, 30, ['status' => 'blocked'])->computeStatus());
        $this->assertSame(LicenseStatus::CANCELLED, $this->makeLicense($s, 30, ['status' => 'cancelled'])->computeStatus());
    }

    public function test_middleware_decides_by_dates_without_writing_to_the_database(): void
    {
        $license = $this->makeLicense($this->scopeA(), -10, ['grace' => 5]);

        $this->probe($this->makeUser($this->scopeA()), null, $this->scopeA())->assertStatus(402);

        $this->assertSame('active', $license->fresh()->status->value);
    }

    public function test_login_does_not_write_license_status(): void
    {
        $license = $this->makeLicense($this->scopeA(), -2, ['grace' => 5]);

        $this->login($this->makeUser($this->scopeA()))->assertSessionHasNoErrors();

        $this->assertSame('active', $license->fresh()->status->value);
    }

    public function test_sync_persists_computed_status(): void
    {
        $grace = $this->makeLicense($this->scopeA(), -2, ['grace' => 5]);
        $expired = $this->makeLicense($this->scopeA(), -20, ['grace' => 5]);
        $blocked = $this->makeLicense(null, -20, ['grace' => 5, 'status' => 'blocked']);

        $this->artisan('licenses:sync')->assertSuccessful();

        $this->assertSame('grace', $grace->fresh()->status->value);
        $this->assertSame('expired', $expired->fresh()->status->value);
        $this->assertSame('blocked', $blocked->fresh()->status->value);
    }

    public function test_sync_marks_overdue_installments_and_leaves_paid_ones(): void
    {
        $license = $this->makeLicense($this->scopeA(), 60, ['total' => 90, 'installments' => 3]);
        [$past, $today, $future] = $this->makeInstallments($license, 3, 90, -40);
        $past->update(['status' => 'paid', 'paid_at' => now()]);
        $today->update(['due_date' => now()->subDay()->toDateString()]);
        $future->update(['due_date' => now()->addDays(10)->toDateString()]);

        $this->artisan('licenses:sync')->assertSuccessful();

        $this->assertSame('paid', $past->fresh()->status);
        $this->assertSame('overdue', $today->fresh()->status);
        $this->assertSame('pending', $future->fresh()->status);
    }

    public function test_sync_is_idempotent(): void
    {
        $license = $this->makeLicense($this->scopeA(), -20, ['grace' => 5]);
        $this->artisan('licenses:sync')->assertSuccessful();
        $stamp = $license->fresh()->updated_at;

        Carbon::setTestNow(now()->addHour());
        $this->artisan('licenses:sync')->assertSuccessful();

        $this->assertEquals($stamp, $license->fresh()->updated_at);
    }

    public function test_sync_dry_run_does_not_write(): void
    {
        $license = $this->makeLicense($this->scopeA(), -20, ['grace' => 5]);

        $this->artisan('licenses:sync', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('active', $license->fresh()->status->value);
    }

    public function test_sync_is_scheduled_daily_on_the_application_scheduler(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringContainsString('licenses:sync', $output);
        $this->assertMatchesRegularExpression('/0\s+0\s+\*\s+\*\s+\*\s+.*licenses:sync/', $output);
    }
}
