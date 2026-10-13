<?php

namespace Slyn9\Licensing\Database\Seeders;

use Illuminate\Database\Seeder;
use Slyn9\Licensing\Models\LicenseType;

/**
 * Solo el catálogo de tipos de licencia (Trial, Mensual, Anual, Permanente): idempotente por `code`.
 *
 * A diferencia de `LicenseTypeSeeder`, NO asigna ninguna licencia. Úsalo cuando el anfitrión ya tiene
 * tenants reales y decide él mismo qué licencia recibe cada uno (p. ej. con un comando propio): el
 * seeder completo les asignaría un Trial de 30 días a todos antes de que ese comando corra.
 */
class LicenseTypeCatalogSeeder extends Seeder
{
    /** @return list<array<string, mixed>> */
    public static function types(): array
    {
        return [
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
    }

    public function run(): void
    {
        foreach (self::types() as $type) {
            // Con borrado lógico: un tipo eliminado conserva su `code` (único). No se recrea ni se revive solo.
            if (! LicenseType::withTrashed()->where('code', $type['code'])->exists()) {
                LicenseType::create($type);
            }
        }

        $this->command?->info('LicenseTypeCatalogSeeder: tipos de licencia cargados (sin asignar licencias).');
    }
}
