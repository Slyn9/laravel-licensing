<?php

namespace Slyn9\Licensing\Tests\Suites;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Slyn9\Licensing\Models\CompanyLicense;
use Slyn9\Licensing\Models\LicenseInstallment;
use Slyn9\Licensing\Models\LicensePayment;
use Slyn9\Licensing\Models\LicenseType;
use Slyn9\Licensing\Tests\Topologies\T1TestCase;

/** C1/C6 — Las migraciones aditivas rellenan uuid y currency en datos que ya existían. */
class BackfillTest extends T1TestCase
{
    private function migrationsPath(): string
    {
        return dirname(__DIR__, 2).'/database/migrations';
    }

    /** Vuelve al esquema previo a C1/C6 (el que tiene producción hoy). */
    private function rollbackAdditiveMigrations(): void
    {
        $this->artisan('migrate:rollback', ['--path' => $this->migrationsPath(), '--realpath' => true, '--step' => 2, '--force' => true])
            ->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('company_licenses', 'currency'));
        foreach (['license_types', 'company_licenses', 'license_installments', 'license_payments'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'uuid'), "$table todavía tiene uuid");
        }
    }

    private function migrateAdditive(): void
    {
        $this->artisan('migrate', ['--path' => $this->migrationsPath(), '--realpath' => true, '--force' => true])->assertSuccessful();
    }

    /** @return array{license:int, installment:int, payment:int, user:int} */
    private function insertLegacyRows(): array
    {
        $user = $this->makeUser(null)->id;
        $license = DB::table('company_licenses')->insertGetId([
            'company_id' => $this->tenant->id, 'sede_id' => $this->branchA->id, 'license_type_id' => $this->types['MON']->id,
            'status' => 'active', 'starts_at' => now(), 'expires_at' => now()->addDays(30), 'grace_until' => now()->addDays(35),
            'total_amount' => 100, 'paid_amount' => 20, 'installments_count' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $installment = DB::table('license_installments')->insertGetId([
            'company_license_id' => $license, 'installment_number' => 1, 'amount_due' => 100, 'paid_amount' => 20,
            'due_date' => '2026-11-01', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payment = DB::table('license_payments')->insertGetId([
            'company_license_id' => $license, 'license_installment_id' => $installment, 'amount' => 20,
            'paid_at' => now(), 'payment_method' => 'efectivo', 'recorded_by' => $user,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('license', 'installment', 'payment', 'user');
    }

    public function test_uuid_and_currency_are_backfilled_on_existing_rows(): void
    {
        $this->rollbackAdditiveMigrations();
        config(['licensing.currency.code' => 'USD']);
        $ids = $this->insertLegacyRows();

        $this->migrateAdditive();

        foreach (['company_licenses' => $ids['license'], 'license_installments' => $ids['installment'], 'license_payments' => $ids['payment']] as $table => $id) {
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) DB::table($table)->where('id', $id)->value('uuid'), $table);
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) LicenseType::first()->uuid);
        $this->assertSame('USD', DB::table('company_licenses')->where('id', $ids['license'])->value('currency'));

        // Nada quedó sin uuid y todos son únicos.
        foreach (['license_types', 'company_licenses', 'license_installments', 'license_payments'] as $table) {
            $this->assertSame(0, DB::table($table)->whereNull('uuid')->count(), "$table tiene filas sin uuid");
            $this->assertSame(DB::table($table)->count(), DB::table($table)->distinct()->count('uuid'), "$table tiene uuid repetidos");
        }
    }

    public function test_the_backfill_keeps_the_original_data_and_ids_untouched(): void
    {
        $this->rollbackAdditiveMigrations();
        $ids = $this->insertLegacyRows();
        $before = DB::table('company_licenses')->where('id', $ids['license'])->first();

        $this->migrateAdditive();

        $after = DB::table('company_licenses')->where('id', $ids['license'])->first();
        foreach (['id', 'company_id', 'sede_id', 'status', 'total_amount', 'paid_amount', 'installments_count'] as $column) {
            $this->assertEquals($before->{$column}, $after->{$column}, $column);
        }
        $this->assertSame(1, DB::table('license_payments')->count());
    }

    public function test_models_work_with_backfilled_rows_and_new_rows_get_their_own_uuid(): void
    {
        $this->rollbackAdditiveMigrations();
        $ids = $this->insertLegacyRows();
        $this->migrateAdditive();

        $legacy = CompanyLicense::findOrFail($ids['license']);
        $this->assertNotEmpty($legacy->uuid);
        $this->assertSame('PEN', $legacy->currency);

        $new = $this->service()->assign($this->tenant, $this->branchA, $this->types['ANN'], ['total_amount' => 990]);
        $this->assertNotSame($legacy->uuid, $new->uuid);
        $this->assertSame($legacy->licenseType->uuid, LicenseType::find($legacy->license_type_id)->uuid);
        $this->assertNotNull(LicenseInstallment::find($ids['installment'])->uuid);
        $this->assertNotNull(LicensePayment::find($ids['payment'])->uuid);
    }

    public function test_the_additive_migrations_are_reversible_and_rerunnable(): void
    {
        $this->rollbackAdditiveMigrations();
        $this->migrateAdditive();
        $this->assertTrue(Schema::hasColumn('company_licenses', 'uuid'));

        $this->rollbackAdditiveMigrations();
        $this->migrateAdditive();

        $this->assertTrue(Schema::hasColumn('company_licenses', 'currency'));
        $this->assertTrue(Schema::hasColumn('license_payments', 'uuid'));
    }

    public function test_ordered_uuids_sort_by_creation_time(): void
    {
        $a = $this->makeLicense(null, 30);
        \Illuminate\Support\Carbon::setTestNow(now()->addSeconds(5));
        $b = $this->makeLicense(null, 30);

        $this->assertTrue(strcmp($a->uuid, $b->uuid) < 0, 'Los uuid ordenados deben crecer con el tiempo.');
    }
}
