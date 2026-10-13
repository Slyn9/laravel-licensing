<?php

namespace Slyn9\Licensing\Tests\Behavior;

use Slyn9\Licensing\Services\LicenseSnapshot;
use Slyn9\Licensing\Tests\Support\MiniJsonSchema;

/** C5 — El snapshot cumple el esquema v1 (resources/schemas/license-snapshot-v1.json). */
trait SnapshotBehavior
{
    private function schema(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/schemas/license-snapshot-v1.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function richLicense()
    {
        $license = $this->service()->assign($this->tenant, $this->scopeA(), $this->types['MON'], [
            'starts_at' => '2026-10-01', 'total_amount' => 100, 'installments_count' => 3,
            'notes' => 'Alta de prueba',
        ], $this->superadmin());

        $first = $license->installments->first();
        $this->service()->registerPayment($license, 20, [
            'license_installment_id' => $first->id, 'paid_at' => now(), 'payment_method' => 'transferencia', 'reference' => 'OP-1',
        ], $this->superadmin());
        $this->service()->registerPayment($license, 30, ['paid_at' => now(), 'payment_method' => 'efectivo'], $this->superadmin());

        return $license->fresh();
    }

    public function test_the_snapshot_matches_the_v1_schema(): void
    {
        $snapshot = $this->richLicense()->toSnapshot();

        $errors = MiniJsonSchema::validate(json_decode(json_encode($snapshot), true), $this->schema());

        $this->assertSame([], $errors, implode("\n", $errors));
        $this->assertSame(LicenseSnapshot::SCHEMA_VERSION, $snapshot['schema_version']);
        $this->assertSame(1, $snapshot['schema_version']);
    }

    public function test_the_snapshot_identifies_everything_by_uuid_and_references_consistently(): void
    {
        $license = $this->richLicense();
        $snapshot = $license->toSnapshot();

        $this->assertSame($license->uuid, $snapshot['license']['uuid']);
        $this->assertSame($license->licenseType->uuid, $snapshot['license']['type']['uuid']);
        $this->assertSame(config('licensing.product.code'), $snapshot['product']);
        $this->assertSame(config('licensing.product.code').':'.$this->tenant->getKey(), $snapshot['tenant_ref']);

        $this->assertCount(3, $snapshot['installments']);
        $this->assertCount(2, $snapshot['payments']);

        $installmentUuids = array_column($snapshot['installments'], 'uuid');
        $paymentUuids = array_column($snapshot['payments'], 'uuid');
        $all = array_merge($installmentUuids, $paymentUuids, [$snapshot['license']['uuid']]);
        $this->assertSame(count($all), count(array_unique($all)), 'Los uuid del snapshot deben ser únicos.');

        // Los pagos apuntan a cuotas del propio snapshot (o a ninguna: pago libre repartido).
        foreach ($snapshot['payments'] as $payment) {
            $this->assertTrue($payment['installment_uuid'] === null || in_array($payment['installment_uuid'], $installmentUuids, true));
        }

        $this->assertSame('100.00', $snapshot['license']['total_amount']);
        $this->assertSame('50.00', $snapshot['license']['paid_amount']);
        $this->assertSame('50.00', $snapshot['license']['pending_amount']);
        $this->assertSame('PEN', $snapshot['license']['currency']);
        $this->assertSame(['1', '2', '3'], array_map('strval', array_column($snapshot['installments'], 'number')));
    }

    public function test_the_snapshot_never_exposes_personal_data_or_local_ids(): void
    {
        $snapshot = $this->richLicense()->toSnapshot();
        $json = json_encode($snapshot);

        $this->assertStringNotContainsString('recorded_by', $json);
        $this->assertStringNotContainsString('email', $json);
        $this->assertArrayNotHasKey('id', $snapshot['license']);
        $this->assertArrayNotHasKey('id', $snapshot['installments'][0]);
    }

    public function test_effective_status_follows_the_dates_while_status_is_the_persisted_one(): void
    {
        $license = $this->makeLicense($this->scopeA(), -2, ['grace' => 5]);

        $snapshot = $license->toSnapshot();

        $this->assertSame('active', $snapshot['license']['status']);
        $this->assertSame('grace', $snapshot['license']['effective_status']);
    }

    public function test_a_permanent_license_has_null_dates_and_still_validates(): void
    {
        $license = $this->service()->assign($this->tenant, null, $this->types['PERM'], ['total_amount' => 2500]);

        $snapshot = $license->toSnapshot();

        $this->assertNull($snapshot['license']['expires_at']);
        $this->assertNull($snapshot['license']['grace_until']);
        $this->assertSame([], MiniJsonSchema::validate(json_decode(json_encode($snapshot), true), $this->schema()));
    }

    public function test_the_snapshot_is_built_without_touching_the_database_writes_when_uuids_exist(): void
    {
        $license = $this->richLicense();
        $before = $license->fresh()->updated_at;

        $license->toSnapshot();

        $this->assertEquals($before, $license->fresh()->updated_at);
    }

    public function test_rows_inserted_without_uuid_are_repaired_lazily_by_the_snapshot(): void
    {
        $license = $this->makeLicense($this->scopeA(), 30, ['total' => 10]);
        \Illuminate\Support\Facades\DB::table('company_licenses')->where('id', $license->id)->update(['uuid' => null]);

        $snapshot = $license->fresh()->toSnapshot();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $snapshot['license']['uuid']);
        $this->assertSame($snapshot['license']['uuid'], $license->fresh()->uuid);
    }
}
