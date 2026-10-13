<?php

namespace Slyn9\Licensing\Tests\Suites;

use Illuminate\Support\Facades\Route;
use Slyn9\Licensing\Tests\Topologies\T2TestCase;

/** Con licensing.ui.blade = false el núcleo no carga vistas, rutas ni controladores. */
class UiDisabledTest extends T2TestCase
{
    public function test_no_routes_or_views_are_registered_without_the_blade_ui(): void
    {
        $this->assertFalse(config('licensing.ui.blade'));
        $this->assertFalse(Route::has('admin.licenses.index'));
        $this->assertFalse(Route::has('admin.licenses.store'));
        $this->assertFalse(view()->exists('licensing::index'));
        $this->assertFalse(view()->exists('licensing::errors.blocked'));
    }

    public function test_the_middleware_alias_and_commands_are_still_available(): void
    {
        $this->assertArrayHasKey('license.check', app('router')->getMiddleware());
        $this->artisan('licenses:sync', ['--dry-run' => true])->assertSuccessful();
    }

    public function test_the_php_api_exposes_what_a_custom_ui_needs(): void
    {
        $this->makeLicense(null, 30, ['total' => 50]);
        $other = $this->createTenant(['name' => 'Otra empresa']);
        $this->makeLicense(null, 60, ['tenant' => $other]);

        $latest = $this->service()->latestPerTenant();

        $this->assertCount(2, $latest);
        $this->assertTrue($latest->has($this->tenant->getKey()));
        $this->assertCount(1, $this->service()->licensesOf($this->tenant));
        $this->assertNotEmpty(\Slyn9\Licensing\Rules\LicenseRules::assign($this->tenant));
    }
}
