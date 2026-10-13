<?php

namespace Slyn9\Licensing\Tests\Suites;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Slyn9\Licensing\Database\Seeders\LicenseTypeCatalogSeeder;
use Slyn9\Licensing\Database\Seeders\LicenseTypeSeeder;
use Slyn9\Licensing\LicensingServiceProvider;
use Slyn9\Licensing\Models\CompanyLicense;
use Slyn9\Licensing\Models\LicenseType;
use Slyn9\Licensing\Tests\Topologies\T2TestCase;

/**
 * 1.1.0 — mensajes configurables, seeder de solo catálogo y migraciones publicables.
 * Los tres cambios son aditivos: sin tocar nada, el comportamiento de 1.0.0 es el mismo.
 */
class V11AdditionsTest extends T2TestCase
{
    // ── Mensajes configurables ───────────────────────────────────────────────

    public function test_default_denial_messages_are_the_v1_texts(): void
    {
        $this->makeLicense(null, -40, ['grace' => 5]);

        $this->assertSame(
            'La licencia del sistema ha vencido. Contacte al administrador para renovarla.',
            $this->service()->denialMessageFor($this->makeUser()),
        );
    }

    public function test_the_host_can_override_a_single_message_and_the_rest_keep_their_default(): void
    {
        config(['licensing.messages.denied_expired' => 'Tu licencia venció. Escríbenos para renovarla.']);

        $expired = $this->makeUser();
        $this->makeLicense(null, -40, ['grace' => 5]);

        $this->assertSame('Tu licencia venció. Escríbenos para renovarla.', $this->service()->denialMessageFor($expired));

        // Bloqueada: la clave no se tocó, conserva el texto del paquete.
        $blocked = $this->createTenant(['name' => 'Otra']);
        $this->makeLicense(null, 30, ['tenant' => $blocked, 'status' => 'blocked']);
        $user = $this->makeUser();
        $this->detachUserFromAllTenants($user);
        $this->attachUserToTenant($user, $blocked);

        $this->assertSame('El acceso ha sido bloqueado por el administrador. Contacte a soporte.', $this->service()->denialMessageFor($user));
    }

    public function test_the_missing_license_message_is_configurable(): void
    {
        config([
            'licensing.missing_license' => 'block',
            'licensing.messages.denied_missing_tenant' => 'Tu empresa todavía no tiene licencia.',
        ]);

        $this->assertSame('Tu empresa todavía no tiene licencia.', $this->service()->denialMessageFor($this->makeUser()));
    }

    public function test_the_many_tenants_message_is_configurable(): void
    {
        config(['licensing.messages.denied_many' => 'Ninguna de tus empresas tiene licencia vigente.']);

        $first = $this->tenant;
        $second = $this->createTenant(['name' => 'Segunda']);
        $user = $this->makeUser();
        $this->attachUserToTenant($user, $second);

        // Una vencida y otra bloqueada: motivos distintos → mensaje genérico.
        $this->makeLicense(null, -40, ['grace' => 5, 'tenant' => $first]);
        $this->makeLicense(null, 30, ['status' => 'blocked', 'tenant' => $second]);

        $this->assertSame('Ninguna de tus empresas tiene licencia vigente.', $this->service()->denialMessageFor($user));
    }

    // ── Seeder de solo catálogo ──────────────────────────────────────────────

    public function test_the_catalog_seeder_loads_the_types_and_assigns_nothing(): void
    {
        LicenseType::query()->delete();
        $tenantWithoutLicense = $this->createTenant(['name' => 'Sin licencia']);

        $this->seed(LicenseTypeCatalogSeeder::class);

        $this->assertEqualsCanonicalizing(['TRIAL', 'MON', 'ANN', 'PERM'], LicenseType::query()->pluck('code')->all());
        $this->assertSame(0, CompanyLicense::query()->where($this->tenantKey(), $tenantWithoutLicense->getKey())->count());
        $this->assertSame(0, CompanyLicense::query()->count());
    }

    public function test_the_catalog_seeder_is_idempotent(): void
    {
        $this->seed(LicenseTypeCatalogSeeder::class);
        $this->seed(LicenseTypeCatalogSeeder::class);

        $this->assertSame(4, LicenseType::query()->count());
    }

    public function test_the_full_seeder_still_assigns_a_trial_to_tenants_without_license(): void
    {
        $tenant = $this->createTenant(['name' => 'Sin licencia']);

        $this->seed(LicenseTypeSeeder::class);

        $license = CompanyLicense::query()->where($this->tenantKey(), $tenant->getKey())->sole();
        $this->assertSame('TRIAL', $license->licenseType->code);
    }

    // ── Migraciones publicables ──────────────────────────────────────────────

    public function test_publishable_migrations_keep_their_order_and_get_a_current_date(): void
    {
        $paths = ServiceProvider::pathsToPublish(LicensingServiceProvider::class, 'licensing-migrations');
        $source = glob(dirname(__DIR__, 2).'/database/migrations/*.php');
        sort($source);

        $this->assertCount(count($source), $paths);

        $targets = array_values($paths);
        $names = array_map(fn (string $path) => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path)), $targets);
        $originals = array_map(fn (string $path) => preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path)), $source);

        // Mismo orden relativo y mismos nombres: solo cambia la fecha.
        $this->assertSame($originals, $names);

        $stamps = array_map(fn (string $path) => substr(basename($path), 0, 17), $targets);
        $sorted = $stamps;
        sort($sorted);
        $this->assertSame($sorted, $stamps);
        $this->assertGreaterThanOrEqual(date('Y_m_d_').'000000', $stamps[0]);
    }
}

/** El anfitrión con `migrations.enabled = false` no recibe las migraciones del paquete por carga automática. */
class MigrationsDisabledTest extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [LicensingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('licensing.product.code', 'CONTAPE');
        $app['config']->set('licensing.migrations.enabled', false);
    }

    public function test_the_package_does_not_load_its_migrations(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('license_types'));
        $this->assertFalse(Schema::hasTable('company_licenses'));
    }
}
