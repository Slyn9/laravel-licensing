<?php

namespace Slyn9\Licensing\Tests\Suites;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Slyn9\Licensing\Database\Seeders\LicenseTypeCatalogSeeder;
use Slyn9\Licensing\Models\CompanyLicense;
use Slyn9\Licensing\Models\LicenseInstallment;
use Slyn9\Licensing\Models\LicensePayment;
use Slyn9\Licensing\Models\LicenseType;
use Slyn9\Licensing\Tests\Topologies\T3TestCase;

/**
 * 1.2.0 — borrado lógico en las cuatro tablas, `latestPerTenant()` sin tenants eliminados lógicamente y
 * `tenant.on_delete`. Topología T3 (Air-System): su tenant usa SoftDeletes.
 */
class V12AdditionsTest extends T3TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // SQLite solo hace cumplir las llaves foráneas con el pragma activo (Testbench usa la conexión «testing»).
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
    }

    // ── Borrado lógico ───────────────────────────────────────────────────────

    public function test_the_four_tables_have_deleted_at_and_the_models_use_soft_deletes(): void
    {
        $models = [
            'license_types' => LicenseType::class,
            'company_licenses' => CompanyLicense::class,
            'license_installments' => LicenseInstallment::class,
            'license_payments' => LicensePayment::class,
        ];

        foreach ($models as $table => $model) {
            $this->assertTrue(Schema::hasColumn($table, 'deleted_at'), "{$table} sin deleted_at");
            $this->assertContains(SoftDeletes::class, class_uses_recursive($model), "{$model} sin SoftDeletes");
        }
    }

    public function test_a_soft_deleted_license_is_hidden_but_kept_and_can_be_restored(): void
    {
        $license = $this->makeLicense(null, 30);

        $this->assertSame($license->id, $this->service()->resolveFor($this->tenant)?->id);

        $license->delete();

        $this->assertNull($this->service()->resolveFor($this->tenant));
        $this->assertCount(0, $this->service()->licensesOf($this->tenant));
        $this->assertSame(1, CompanyLicense::onlyTrashed()->count());

        CompanyLicense::onlyTrashed()->first()->restore();

        $this->assertSame($license->id, $this->service()->resolveFor($this->tenant)?->id);
    }

    public function test_a_soft_deleted_license_no_longer_grants_access(): void
    {
        config(['licensing.missing_license' => 'block']);

        $user = $this->makeUser();
        $license = $this->makeLicense(null, 30);

        $this->assertSame(200, $this->probe($user)->getStatusCode());

        $license->delete();

        $this->assertSame(402, $this->probe($user)->getStatusCode());
    }

    public function test_the_migration_is_idempotent_when_the_host_already_has_the_column(): void
    {
        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_13_100002_add_soft_deletes_to_license_tables.php';

        $migration->up();   // las columnas ya existen: no-op, sin excepción
        $migration->up();

        $this->assertTrue(Schema::hasColumn('company_licenses', 'deleted_at'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('company_licenses', 'deleted_at'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('company_licenses', 'deleted_at'));
    }

    // ── latestPerTenant sin tenants eliminados ───────────────────────────────

    public function test_latest_per_tenant_skips_soft_deleted_tenants_but_keeps_their_license(): void
    {
        $alive = $this->createTenant(['name' => 'Viva']);
        $gone = $this->createTenant(['name' => 'Eliminada']);

        $this->makeLicense(null, 30, ['tenant' => $alive]);
        $this->makeLicense(null, 30, ['tenant' => $gone]);

        $this->assertEqualsCanonicalizing([$alive->id, $gone->id], $this->service()->latestPerTenant()->keys()->all());

        $gone->delete();

        $this->assertSame([$alive->id], $this->service()->latestPerTenant()->keys()->all());
        // La licencia de la empresa eliminada sigue en la base: es rastro de cobranza.
        $this->assertSame(1, CompanyLicense::query()->where($this->tenantKey(), $gone->id)->count());

        $gone->restore();

        $this->assertEqualsCanonicalizing([$alive->id, $gone->id], $this->service()->latestPerTenant()->keys()->all());
    }

    // ── Catálogo con tipos eliminados ────────────────────────────────────────

    public function test_the_catalog_seeder_does_not_recreate_or_crash_on_a_soft_deleted_type(): void
    {
        LicenseType::query()->where('code', 'MON')->first()->delete();

        $this->seed(LicenseTypeCatalogSeeder::class);

        $this->assertSame(3, LicenseType::query()->count());
        $this->assertSame(1, LicenseType::onlyTrashed()->where('code', 'MON')->count());
    }

    // ── tenant.on_delete (por omisión: cascade, igual que 1.1.0) ─────────────

    public function test_by_default_a_forced_tenant_delete_cascades_to_the_licenses(): void
    {
        $this->assertSame('cascade', config('licensing.tenant.on_delete'));

        $tenant = $this->createTenant(['name' => 'Con licencia']);
        $this->makeLicense(null, 30, ['tenant' => $tenant]);

        $tenant->forceDelete();

        $this->assertSame(0, CompanyLicense::withTrashed()->where($this->tenantKey(), $tenant->id)->count());
    }
}
