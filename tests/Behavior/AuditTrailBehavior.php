<?php

namespace Slyn9\Licensing\Tests\Behavior;

use Slyn9\Licensing\Exceptions\LicenseException;

/** D7 — Bloquear, desbloquear y cancelar exigen motivo y dejan rastro. */
trait AuditTrailBehavior
{
    public function test_block_requires_a_reason(): void
    {
        $license = $this->makeLicense($this->scopeA(), 30);

        try {
            $this->service()->block($license, $this->superadmin(), '');
            $this->fail('Debió exigir motivo.');
        } catch (LicenseException $e) {
            $this->assertSame('reason', $e->field);
        }

        $this->assertSame('active', $license->fresh()->status->value);
        $this->assertCount(0, $this->auditFor($license, 'blocked'));
    }

    public function test_block_records_who_when_and_why(): void
    {
        $license = $this->makeLicense($this->scopeA(), 30);
        $admin = $this->superadmin();

        $this->service()->block($license, $admin, 'Falta de pago de dos meses');

        $this->assertSame('blocked', $license->fresh()->status->value);

        [$entry] = $this->auditFor($license, 'blocked');
        $this->assertTrue($entry['by']->is($admin));
        $this->assertSame('Falta de pago de dos meses', $entry['properties']['reason']);
        $this->assertSame('active', $entry['properties']['previous_status']);
        $this->assertSame('blocked', $entry['properties']['new_status']);
        $this->assertSame($this->tenant->getKey(), $entry['properties'][$this->tenantKey()]);
        $this->assertSame($license->id, $entry['properties']['license_id']);

        if ($this->usesBranches()) {
            // La firma histórica de farmacy conserva el módulo "licencias".
            $this->assertSame('licencias', $entry['module']);
        }
    }

    public function test_unblock_records_reason(): void
    {
        $license = $this->makeLicense($this->scopeA(), 30, ['status' => 'blocked']);

        $this->service()->unblock($license, $this->superadmin(), 'Pagó la deuda');

        [$entry] = $this->auditFor($license, 'unblocked');
        $this->assertSame('Pagó la deuda', $entry['properties']['reason']);
        $this->assertSame('blocked', $entry['properties']['previous_status']);
    }

    public function test_cancel_requires_a_reason_and_records_it(): void
    {
        $license = $this->makeLicense($this->scopeA(), 30);
        $admin = $this->superadmin();

        try {
            $this->service()->cancel($license, $admin, '');
            $this->fail('Debió exigir motivo.');
        } catch (LicenseException $e) {
            $this->assertSame('reason', $e->field);
        }
        $this->assertSame('active', $license->fresh()->status->value);

        $this->service()->cancel($license, $admin, 'Cliente cerró la sede');

        $this->assertSame('cancelled', $license->fresh()->status->value);
        $this->assertSame('Cliente cerró la sede', $this->auditFor($license, 'cancelled')[0]['properties']['reason']);
    }

    public function test_renew_and_payment_leave_a_trace_too(): void
    {
        $license = $this->makeLicense($this->scopeA(), 10, ['total' => 99, 'installments' => 1]);
        $admin = $this->superadmin();

        $this->service()->registerPayment($license, 10, ['paid_at' => '2026-10-12', 'payment_method' => 'efectivo'], $admin);
        $this->service()->renew($license, $admin, ['total_amount' => 99, 'installments_count' => 1]);

        $this->assertCount(1, $this->auditFor($license, 'payment_registered'));
        $this->assertCount(1, $this->auditFor($license, 'renewed'));
    }

    public function test_assign_leaves_a_trace(): void
    {
        $license = $this->service()->assign($this->tenant, $this->scopeA(), $this->types['MON'], ['total_amount' => 99], $this->superadmin());

        $this->assertCount(1, $this->auditFor($license, 'created'));
    }
}
