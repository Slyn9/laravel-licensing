<?php

namespace Slyn9\Licensing\Database\Seeders;

use Illuminate\Database\Seeder;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Models\CompanyLicense;
use Slyn9\Licensing\Models\LicenseType;
use Slyn9\Licensing\Services\LicenseService;

/**
 * Catálogo de tipos + Trial a todo tenant (o sede) que aún no tiene licencia. Para cargar SOLO el catálogo
 * (sin asignar nada) usa `LicenseTypeCatalogSeeder`.
 */
class LicenseTypeSeeder extends Seeder
{
    public function run(): void
    {
        // Catálogo de tipos (idempotente). Es el mismo que carga `LicenseTypeCatalogSeeder`.
        $this->call(LicenseTypeCatalogSeeder::class);

        // Asignar Trial a las sedes (o, sin sedes, a los tenants) que aún no tienen licencia
        if (! LicenseType::where('code', Licensing::trialCode())->exists()) {
            return;
        }

        $service = app(LicenseService::class);

        if (Licensing::branchesEnabled()) {
            $branchKey = Licensing::branchKey();

            $withoutLicense = Licensing::branchQuery()
                ->whereNotIn('id', CompanyLicense::query()->whereNotNull($branchKey)->select($branchKey))
                ->get();

            foreach ($withoutLicense as $sede) {
                $tenant = Licensing::tenantQuery()->find($sede->getAttribute(Licensing::branchTenantKey()));

                if ($tenant) {
                    $service->assignTrial($tenant, $sede, 'Trial asignado automáticamente al activar el módulo de licencias.');
                }
            }

            $this->command?->info('LicenseTypeSeeder: tipos creados y trials asignados a '.$withoutLicense->count().' sedes.');

            return;
        }

        $withoutLicense = Licensing::tenantQuery()
            ->whereNotIn('id', CompanyLicense::query()->select(Licensing::tenantKey()))
            ->get();

        foreach ($withoutLicense as $tenant) {
            $service->assignTrial($tenant, null, 'Trial asignado automáticamente al activar el módulo de licencias.');
        }

        $this->command?->info('LicenseTypeSeeder: tipos creados y trials asignados a '.$withoutLicense->count().' tenants.');
    }
}
