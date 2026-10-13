<?php

namespace Slyn9\Licensing\Tests\Behavior;

use Slyn9\Licensing\Models\CompanyLicense;

/** D1 — Una sola forma de crear licencias (válida en las 3 topologías). */
trait AssignmentBehavior
{
    public function test_assign_computes_dates_status_and_installments(): void
    {
        $license = $this->service()->assign($this->tenant, $this->scopeA(), $this->types['MON'], [
            'starts_at' => '2026-10-01',
            'total_amount' => 100,
            'installments_count' => 3,
        ]);

        $this->assertSame('active', $license->status->value);
        $this->assertSame('2026-10-31', $license->expires_at->toDateString());
        $this->assertSame('2026-11-05', $license->grace_until->toDateString());

        $amounts = $license->installments->pluck('amount_due')->map(fn ($v) => (float) $v)->all();
        $this->assertSame([33.33, 33.33, 33.34], $amounts);
        $this->assertSame(
            ['2026-10-01', '2026-11-01', '2026-12-01'],
            $license->installments->map(fn ($i) => $i->due_date->toDateString())->all()
        );
    }

    public function test_assign_with_dates_already_past_stores_the_real_status(): void
    {
        $license = $this->service()->assign($this->tenant, $this->scopeA(), $this->types['MON'], [
            'starts_at' => '2026-01-01',
        ]);

        $this->assertSame('expired', $license->fresh()->status->value);
    }

    public function test_assign_permanent_type_has_no_expiry(): void
    {
        $license = $this->service()->assign($this->tenant, null, $this->types['PERM'], [
            'starts_at' => '2026-01-01', 'total_amount' => 2500,
        ]);

        $this->assertNull($license->expires_at);
        $this->assertNull($license->grace_until);
    }

    public function test_assign_honours_explicit_expiry_and_grace_of_the_type(): void
    {
        $license = $this->service()->assign($this->tenant, $this->scopeA(), $this->types['MON'], [
            'starts_at' => '2026-01-01',
            'expires_at' => '2026-03-01',
        ]);

        $this->assertSame('2026-03-01', $license->expires_at->toDateString());
        $this->assertSame('2026-03-06', $license->grace_until->toDateString());
    }

    public function test_assign_with_the_trial_type_creates_a_trial_license(): void
    {
        $license = $this->service()->assign($this->tenant, $this->scopeA(), $this->types['TRIAL'], [
            'total_amount' => 0, 'installments_count' => 1,
        ]);

        $this->assertSame('trial', $license->status->value);
    }

    public function test_assign_stores_uuid_and_the_configured_currency(): void
    {
        config(['licensing.currency.code' => 'USD']);

        $license = $this->service()->assign($this->tenant, $this->scopeA(), $this->types['MON'], ['total_amount' => 50]);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $license->uuid);
        $this->assertSame('USD', $license->fresh()->currency);
        $this->assertSame($license->installments->first()->uuid !== null, true);
    }

    public function test_assign_trial_is_idempotent(): void
    {
        $first = $this->service()->assignTrial($this->tenant, $this->scopeA());
        $second = $this->service()->assignTrial($this->tenant, $this->scopeA());

        $this->assertSame('trial', $first->status->value);
        $this->assertTrue($first->is($second));
        $this->assertSame(1, CompanyLicense::count());
    }

    public function test_assign_trial_returns_null_when_the_trial_type_is_not_loaded(): void
    {
        $this->types['TRIAL']->delete();

        $this->assertNull($this->service()->assignTrial($this->tenant, $this->scopeA()));
    }
}
