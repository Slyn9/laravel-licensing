<?php

namespace Slyn9\Licensing\Database\Seeders;

use Illuminate\Database\Seeder;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Models\CompanyLicense;
use Slyn9\Licensing\Models\LicenseType;
use Slyn9\Licensing\Services\LicenseService;

class LicenseTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'name' => 'Trial',
                'code' => 'TRIAL',
                'duration_days' => 30,
                'base_price' => 0.00,
                'grace_period_days' => 0,
                'description' => 'Período de prueba gratuito de 30 días.',
            ],
            [
                'name' => 'Mensual',
                'code' => 'MON',
                'duration_days' => 30,
                'base_price' => 99.00,
                'grace_period_days' => 5,
                'description' => 'Licencia mensual con 5 días de gracia.',
            ],
            [
                'name' => 'Anual',
                'code' => 'ANN',
                'duration_days' => 365,
                'base_price' => 990.00,
                'grace_period_days' => 15,
                'description' => 'Licencia anual con 15 días de gracia.',
            ],
            [
                'name' => 'Permanente',
                'code' => 'PERM',
                'duration_days' => null,
                'base_price' => 2500.00,
                'grace_period_days' => 0,
                'description' => 'Licencia permanente sin vencimiento.',
            ],
        ];

        foreach ($types as $type) {
            LicenseType::firstOrCreate(['code' => $type['code']], $type);
        }

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
