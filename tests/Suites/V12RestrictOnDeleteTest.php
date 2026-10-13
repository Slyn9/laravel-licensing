<?php

namespace Slyn9\Licensing\Tests\Suites;

use Illuminate\Database\QueryException;
use Slyn9\Licensing\Models\CompanyLicense;
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
}
