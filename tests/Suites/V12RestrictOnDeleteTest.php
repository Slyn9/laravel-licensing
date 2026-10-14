<?php

namespace Slyn9\Licensing\Tests\Suites;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Slyn9\Licensing\Models\CompanyLicense;
use Slyn9\Licensing\Models\LicenseInstallment;
use Slyn9\Licensing\Models\LicensePayment;
use Slyn9\Licensing\Tests\Topologies\T3TestCase;

/**
 * 1.2.0 — `tenant.on_delete = restrict`: un tenant con licencias no se puede borrar DE VERDAD.
 * El borrado lógico del tenant sigue funcionando.
 */
class V12RestrictOnDeleteTest extends T3TestCase
{
    protected function licensingConfig(): array
    {
        $config = parent::licensingConfig();
        $config['tenant']['on_delete'] = 'restrict';

        return $config;
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // SQLite solo hace cumplir las llaves foráneas con el pragma activo (Testbench usa la conexión «testing»).
        $app['config']->set('database.connections.testing.foreign_key_constraints', true);
    }

    public function test_a_tenant_with_licenses_cannot_be_force_deleted(): void
    {
        $this->assertSame('restrict', config('licensing.tenant.on_delete'));

        $tenant = $this->createTenant(['name' => 'Con licencia']);
        $this->makeLicense(null, 30, ['tenant' => $tenant]);

        try {
            $tenant->forceDelete();
            $this->fail('El borrado definitivo debió ser rechazado por la llave foránea.');
        } catch (QueryException) {
            // esperado
        }

        $this->assertSame(1, CompanyLicense::query()->where($this->tenantKey(), $tenant->id)->count());
    }

    public function test_a_tenant_with_licenses_can_still_be_soft_deleted(): void
    {
        $tenant = $this->createTenant(['name' => 'Con licencia']);
        $this->makeLicense(null, 30, ['tenant' => $tenant]);

        $tenant->delete();

        $this->assertSoftDeleted($tenant);
        $this->assertSame(1, CompanyLicense::query()->where($this->tenantKey(), $tenant->id)->count());
    }

    public function test_a_tenant_without_licenses_can_be_force_deleted(): void
    {
        $tenant = $this->createTenant(['name' => 'Sin licencia']);

        $tenant->forceDelete();

        $this->assertDatabaseMissing('tenants', ['id' => $tenant->id]);
    }

    public function test_rerunning_the_migration_on_sqlite_with_data_never_cascades_installments_or_payments(): void
    {
        $tenant = $this->createTenant(['name' => 'Con datos']);
        $license = $this->makeLicense(null, 30, ['tenant' => $tenant, 'total' => 100, 'installments' => 1]);
        $installment = $this->makeInstallments($license, 1, 100)[0];
        $user = $this->makeUser();
        $payment = LicensePayment::create([
            'company_license_id' => $license->id, 'license_installment_id' => $installment->id, 'amount' => 20,
            'paid_at' => now(), 'payment_method' => 'efectivo', 'recorded_by' => $user->id,
        ]);

        $migration = require dirname(__DIR__, 2).'/database/migrations/2026_10_13_100003_set_tenant_foreign_key_on_delete.php';

        // En SQLite reconstruir la tabla con datos y llaves foráneas activas borraría cuotas y pagos en cascada:
        // con datos la migración se omite, y las filas siguen ahí en ambos sentidos.
        $migration->down();
        $migration->up();

        $this->assertSame(1, CompanyLicense::query()->count());
        $this->assertNotNull(LicenseInstallment::query()->find($installment->id));
        $this->assertNotNull(LicensePayment::query()->find($payment->id));
        $this->assertSame(3, DB::table('company_licenses')->count() + DB::table('license_installments')->count() + DB::table('license_payments')->count());
    }
}
