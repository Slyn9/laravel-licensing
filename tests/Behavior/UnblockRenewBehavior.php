<?php

namespace Slyn9\Licensing\Tests\Behavior;

use Slyn9\Licensing\Exceptions\LicenseException;

/** D5 — Desbloqueo coherente con las fechas. D6 — Renovación. */
trait UnblockRenewBehavior
{
    private function unblock($license, array $options = [], string $reason = 'Regularizó su situación')
    {
        return $this->service()->unblock($license, $this->superadmin(), $reason, $options);
    }

    private function assertRejectedOn(string $field, callable $attempt): void
    {
        try {
            $attempt();
            $this->fail('Debió rechazar la operación.');
        } catch (LicenseException $e) {
            $this->assertSame($field, $e->field);
        }
    }

    // ── D5 ───────────────────────────────────────────────────────────────────

    public function test_unblocking_an_in_date_license_makes_it_active(): void
    {
        $license = $this->makeLicense($this->scopeA(), 30, ['status' => 'blocked']);

        $this->unblock($license);

        $this->assertSame('active', $license->fresh()->status->value);
    }

    public function test_unblocking_an_expired_license_requires_a_new_date_or_renewal(): void
    {
        $license = $this->makeLicense($this->scopeA(), -40, ['status' => 'blocked', 'grace' => 5]);

        $this->assertRejectedOn('expires_at', fn () => $this->unblock($license));

        $this->assertSame('blocked', $license->fresh()->status->value);
    }

    public function test_unblocking_with_a_new_expiry_survives_sync_and_requests(): void
    {
        $license = $this->makeLicense($this->scopeA(), -40, ['status' => 'blocked', 'grace' => 5]);

        $this->unblock($license, ['expires_at' => now()->addDays(20)->toDateString()]);

        $fresh = $license->fresh();
        $this->assertSame('active', $fresh->status->value);
        $this->assertSame(now()->addDays(20)->toDateString(), $fresh->expires_at->toDateString());
        $this->assertSame(now()->addDays(25)->toDateString(), $fresh->grace_until->toDateString());

        $this->artisan('licenses:sync')->assertSuccessful();
        $this->assertSame('active', $license->fresh()->status->value);

        $this->probe($this->makeUser($this->scopeA()), null, $this->scopeA())->assertOk();
    }

    public function test_unblocking_with_a_past_expiry_is_rejected(): void
    {
        $license = $this->makeLicense($this->scopeA(), -40, ['status' => 'blocked', 'grace' => 5]);

        $this->assertRejectedOn('expires_at', fn () => $this->unblock($license, ['expires_at' => now()->subDay()->toDateString()]));

        $this->assertSame('blocked', $license->fresh()->status->value);
    }

    public function test_unblocking_with_renewal_extends_the_period(): void
    {
        $license = $this->makeLicense($this->scopeA(), -40, ['status' => 'blocked', 'grace' => 5, 'total' => 99, 'installments' => 1]);

        $this->unblock($license, ['renew' => true, 'installments_count' => 1]);

        $fresh = $license->fresh();
        $this->assertSame('active', $fresh->status->value);
        $this->assertSame(now()->addDays(30)->toDateString(), $fresh->expires_at->toDateString());
    }

    public function test_unblocking_requires_a_reason(): void
    {
        $license = $this->makeLicense($this->scopeA(), 30, ['status' => 'blocked']);

        $this->assertRejectedOn('reason', fn () => $this->unblock($license, [], ''));

        $this->assertSame('blocked', $license->fresh()->status->value);
    }

    // ── D6 ───────────────────────────────────────────────────────────────────

    public function test_renew_starts_from_the_current_expiry_when_still_in_date(): void
    {
        $license = $this->makeLicense($this->scopeA(), 10, ['total' => 99, 'paid' => 99, 'installments' => 1]);
        $oldExpiry = $license->expires_at->copy();

        $renewed = $this->service()->renew($license, $this->superadmin(), [
            'total_amount' => 99, 'installments_count' => 1,
        ]);

        $this->assertEquals($oldExpiry->copy()->addDays(30), $renewed->expires_at);
        $this->assertEquals($oldExpiry->copy()->addDays(35), $renewed->grace_until);
        $this->assertSame('active', $renewed->status->value);
        $this->assertSame('198.00', (string) $renewed->total_amount);
        $this->assertSame(2, (int) $renewed->installments_count);
    }

    public function test_renew_starts_from_now_when_already_expired(): void
    {
        $license = $this->makeLicense($this->scopeA(), -20, ['grace' => 5, 'status' => 'expired']);

        $renewed = $this->service()->renew($license, $this->superadmin(), [
            'total_amount' => 99, 'installments_count' => 1,
        ]);

        $this->assertSame(now()->addDays(30)->toDateString(), $renewed->expires_at->toDateString());
        $this->assertSame('active', $renewed->status->value);
    }

    public function test_renew_generates_installments_for_the_new_period_continuing_the_numbering(): void
    {
        $license = $this->makeLicense($this->scopeA(), 10, ['total' => 99, 'installments' => 1]);
        $this->makeInstallments($license, 1, 99);
        $oldExpiry = $license->expires_at->toDateString();

        $renewed = $this->service()->renew($license, $this->superadmin(), [
            'total_amount' => 90, 'installments_count' => 3,
        ]);

        $new = $renewed->installments()->where('installment_number', '>', 1)->get();

        $this->assertSame([2, 3, 4], $new->pluck('installment_number')->all());
        $this->assertSame(90.0, (float) $new->sum('amount_due'));
        // El primer vencimiento del nuevo período es el fin del anterior.
        $this->assertSame($oldExpiry, $new->first()->due_date->toDateString());
    }

    public function test_renew_rejects_permanent_and_cancelled_licenses(): void
    {
        foreach ([
            $this->makeLicense($this->scopeA(), null, ['type' => 'PERM']),
            $this->makeLicense(null, 10, ['status' => 'cancelled']),
        ] as $license) {
            try {
                $this->service()->renew($license, $this->superadmin(), []);
                $this->fail('Debió rechazar la renovación.');
            } catch (LicenseException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
