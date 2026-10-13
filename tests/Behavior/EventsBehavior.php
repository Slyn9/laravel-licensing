<?php

namespace Slyn9\Licensing\Tests\Behavior;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Slyn9\Licensing\Events\LicenseAssigned;
use Slyn9\Licensing\Events\LicenseBlocked;
use Slyn9\Licensing\Events\LicenseCancelled;
use Slyn9\Licensing\Events\LicenseEvent;
use Slyn9\Licensing\Events\LicensePaymentRegistered;
use Slyn9\Licensing\Events\LicenseRenewed;
use Slyn9\Licensing\Events\LicenseStatusChanged;
use Slyn9\Licensing\Events\LicenseUnblocked;
use Slyn9\Licensing\Exceptions\LicenseException;

/** C4 — Eventos de dominio despachados después del commit, con payload serializable. */
trait EventsBehavior
{
    /** @var array<int, LicenseEvent> */
    private array $captured = [];

    private function capture(): void
    {
        $this->captured = [];

        foreach ([
            LicenseAssigned::class, LicensePaymentRegistered::class, LicenseRenewed::class, LicenseBlocked::class,
            LicenseUnblocked::class, LicenseCancelled::class, LicenseStatusChanged::class,
        ] as $class) {
            Event::listen($class, function (LicenseEvent $e) {
                $this->captured[] = $e;
            });
        }
    }

    /** @return array<int, string> nombres de los eventos capturados, en orden */
    private function names(): array
    {
        return array_map(fn (LicenseEvent $e) => $e->name(), $this->captured);
    }

    public function test_assign_dispatches_license_assigned_with_a_serializable_payload(): void
    {
        $this->capture();

        $license = $this->service()->assign($this->tenant, $this->scopeA(), $this->types['MON'], [
            'starts_at' => '2026-10-01', 'total_amount' => 100, 'installments_count' => 2,
        ]);

        $this->assertSame(['license.assigned'], $this->names());

        $event = $this->captured[0];
        $payload = $event->toArray();

        $this->assertSame($license->uuid, $payload['license_uuid']);
        $this->assertSame(config('licensing.product.code'), $payload['product']);
        $this->assertSame(config('licensing.product.code').':'.$this->tenant->getKey(), $payload['tenant_ref']);
        $this->assertNull($payload['previous_status']);
        $this->assertSame('active', $payload['new_status']);
        $this->assertSame('MON', $payload['license_type_code']);
        $this->assertSame('100.00', $payload['total_amount']);
        $this->assertSame('PEN', $payload['currency']);
        $this->assertSame(2, $payload['installments_count']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $payload['event_id']);

        // Solo datos serializables: nada de modelos ni objetos.
        foreach ($payload as $value) {
            $this->assertTrue(is_scalar($value) || $value === null, 'El payload contiene un valor no escalar.');
        }
        $this->assertNotFalse(json_encode($payload));
        $this->assertEquals($payload, unserialize(serialize($payload)));
        foreach ((new \ReflectionObject($event))->getProperties() as $property) {
            $this->assertFalse(is_object($property->getValue($event)), "La propiedad {$property->getName()} no puede ser un objeto.");
        }

        if ($this->usesBranches()) {
            $this->assertSame(config('licensing.product.code').':branch:'.$this->scopeA()->getKey(), $payload['branch_ref']);
        } else {
            $this->assertNull($payload['branch_ref']);
        }
    }

    public function test_events_are_dispatched_only_after_the_outer_transaction_commits(): void
    {
        $this->capture();

        DB::transaction(function () {
            $this->service()->assign($this->tenant, $this->scopeA(), $this->types['MON'], ['total_amount' => 10]);

            $this->assertSame([], $this->names(), 'No debe despacharse antes del commit.');
        });

        $this->assertSame(['license.assigned'], $this->names());
    }

    public function test_events_are_not_dispatched_when_the_transaction_rolls_back(): void
    {
        $this->capture();

        try {
            DB::transaction(function () {
                $this->service()->assign($this->tenant, $this->scopeA(), $this->types['MON'], ['total_amount' => 10]);

                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame([], $this->names());
        $this->assertSame(0, \Slyn9\Licensing\Models\CompanyLicense::count());
    }

    public function test_a_failed_operation_dispatches_nothing(): void
    {
        $this->capture();
        $license = $this->makeLicense($this->scopeA(), 30, ['total' => 50]);

        try {
            $this->service()->registerPayment($license, 500, ['payment_method' => 'efectivo'], $this->superadmin());
        } catch (LicenseException) {
        }

        $this->assertSame([], $this->names());
    }

    public function test_every_operation_dispatches_its_event(): void
    {
        $license = $this->makeLicense($this->scopeA(), 30, ['total' => 100, 'installments' => 2]);
        $installments = $this->makeInstallments($license, 2, 100);
        $admin = $this->superadmin();
        $this->capture();

        $payment = $this->service()->registerPayment($license, 50, [
            'license_installment_id' => $installments[0]->id, 'paid_at' => now(), 'payment_method' => 'efectivo',
        ], $admin);
        $this->service()->block($license, $admin, 'mora');
        $this->service()->unblock($license, $admin, 'regularizó');
        $this->service()->renew($license, $admin, ['total_amount' => 99]);
        $this->service()->cancel($license, $admin, 'cierra');

        $this->assertSame([
            'license.payment_registered', 'license.blocked', 'license.unblocked', 'license.renewed', 'license.cancelled',
        ], $this->names());

        $paid = $this->captured[0]->toArray();
        $this->assertSame($payment->fresh()->uuid, $paid['payment_uuid']);
        $this->assertSame($installments[0]->fresh()->uuid, $paid['installment_uuid']);
        $this->assertSame('50.00', $paid['amount']);
        $this->assertSame('50.00', $paid['license_paid_amount']);
        $this->assertSame('50.00', $paid['license_pending_amount']);

        $blocked = $this->captured[1]->toArray();
        $this->assertSame('active', $blocked['previous_status']);
        $this->assertSame('blocked', $blocked['new_status']);
        $this->assertSame('mora', $blocked['reason']);

        $unblocked = $this->captured[2]->toArray();
        $this->assertSame('blocked', $unblocked['previous_status']);
        $this->assertSame('active', $unblocked['new_status']);
        $this->assertFalse($unblocked['renewed']);

        $renewed = $this->captured[3]->toArray();
        $this->assertSame('99.00', $renewed['added_amount']);
        $this->assertSame('199.00', $renewed['new_total_amount']);
        $this->assertNotSame($renewed['previous_expires_at'], $renewed['new_expires_at']);

        $this->assertSame('cancelled', $this->captured[4]->toArray()['new_status']);
    }

    public function test_unblock_with_renewal_dispatches_both_events(): void
    {
        $license = $this->makeLicense($this->scopeA(), -40, ['status' => 'blocked', 'grace' => 5]);
        $this->capture();

        $this->service()->unblock($license, $this->superadmin(), 'regularizó', ['renew' => true]);

        $this->assertSame(['license.unblocked', 'license.renewed'], $this->names());
        $this->assertTrue($this->captured[0]->toArray()['renewed']);
    }

    public function test_sync_dispatches_status_changed_and_dry_run_does_not(): void
    {
        $license = $this->makeLicense($this->scopeA(), -2, ['grace' => 5]);
        $this->capture();

        $this->artisan('licenses:sync', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame([], $this->names());

        $this->artisan('licenses:sync')->assertSuccessful();
        $this->assertSame(['license.status_changed'], $this->names());

        $payload = $this->captured[0]->toArray();
        $this->assertSame($license->fresh()->uuid, $payload['license_uuid']);
        $this->assertSame('active', $payload['previous_status']);
        $this->assertSame('grace', $payload['new_status']);
        $this->assertSame('sync', $payload['source']);

        // Idempotente: una segunda corrida no vuelve a emitir.
        $this->artisan('licenses:sync')->assertSuccessful();
        $this->assertCount(1, $this->captured);
    }

    public function test_tenant_identity_can_be_replaced_by_class(): void
    {
        config(['licensing.bindings.tenant_identity' => \Slyn9\Licensing\Tests\Fixtures\Support\FixedIdentity::class]);
        $this->capture();

        $this->service()->assign($this->tenant, null, $this->types['MON'], ['total_amount' => 10]);

        $this->assertSame('GLOBAL-'.$this->tenant->getKey(), $this->captured[0]->toArray()['tenant_ref']);
    }
}
