<?php

namespace Slyn9\Licensing\Tests\Suites;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Portabilidad: el paquete no conoce a ningún anfitrión ni arrastra dependencias de UI.
 * No usa la base de datos.
 */
class IsolationTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return array<string, string> ruta relativa => contenido */
    private function files(array $dirs, array $extensions = ['php']): array
    {
        $found = [];

        foreach ($dirs as $dir) {
            $path = $this->root().'/'.$dir;

            if (is_file($path)) {
                $found[$dir] = file_get_contents($path);

                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                if (in_array($file->getExtension(), $extensions, true)) {
                    $relative = ltrim(str_replace(str_replace('\\', '/', $this->root()), '', str_replace('\\', '/', $file->getPathname())), '/');
                    $found[$relative] = file_get_contents($file->getPathname());
                }
            }
        }

        return $found;
    }

    public function test_the_package_does_not_reference_host_namespaces(): void
    {
        $offenders = [];

        foreach ($this->files(['src', 'database', 'resources', 'routes', 'config']) as $file => $code) {
            // Cualquier `App\...` (o `App\\...` en strings) es una referencia al anfitrión.
            if (preg_match_all('/(?<![A-Za-z0-9_])App\\\\{1,2}[A-Za-z]/', $code, $m)) {
                $offenders[] = $file.' → '.implode(', ', array_unique($m[0]));
            }
        }

        $this->assertSame([], $offenders, "El paquete referencia clases del anfitrión:\n".implode("\n", $offenders));
    }

    public function test_the_package_does_not_call_host_helpers(): void
    {
        $offenders = [];

        foreach ($this->files(['src', 'database', 'resources', 'routes', 'config'], ['php']) as $file => $code) {
            $code = preg_replace('/function\s+\w+\s*\(/', 'function _(', $code);

            if (preg_match('/(?<![\w:>$\'"])(tenant|branch|tenant_id|branch_id|home_route|tenant_route)\(/', $code, $m)) {
                $offenders[] = $file.' → '.$m[0];
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    public function test_the_core_does_not_depend_on_bootstrap_inertia_or_livewire(): void
    {
        $offenders = [];

        foreach ($this->files(['src']) as $file => $code) {
            if (str_starts_with($file, 'src/Ui/')) {
                continue;
            }

            if (preg_match('/^use [^;]*(Inertia|Livewire)|(Inertia|Livewire)::|\\\\(Inertia|Livewire)\\\\|data-bs-|fas fa-/m', $code, $m)) {
                $offenders[] = $file.' → '.$m[0];
            }
        }

        $this->assertSame([], $offenders, implode("\n", $offenders));
    }

    public function test_spatie_is_only_referenced_by_the_role_based_superadmin_check(): void
    {
        $users = [];

        foreach ($this->files(['src', 'database', 'config']) as $file => $code) {
            if (str_contains($code, 'Spatie\\')) {
                $users[] = $file;
            }
        }

        $this->assertSame(['src/Access/RoleSuperAdminCheck.php'], $users);
    }

    public function test_the_core_does_not_use_blade_or_the_ui_namespace(): void
    {
        foreach ($this->files(['src']) as $file => $code) {
            if (str_starts_with($file, 'src/Ui/') || $file === 'src/LicensingServiceProvider.php') { // el provider es quien carga la capa opcional
                continue;
            }

            $this->assertStringNotContainsString('Slyn9\\Licensing\\Ui\\', $code, $file);
            $this->assertStringNotContainsString('licensing::', $code, $file);
        }
    }

    public function test_composer_json_only_requires_illuminate_and_php(): void
    {
        $composer = json_decode(file_get_contents($this->root().'/composer.json'), true);

        $runtime = array_keys($composer['require']);
        $foreign = array_values(array_filter($runtime, fn ($p) => $p !== 'php' && ! str_starts_with($p, 'illuminate/')));

        $this->assertSame([], $foreign, 'Dependencias de runtime ajenas a illuminate/*: '.implode(', ', $foreign));
        $this->assertSame('^8.2', $composer['require']['php']);
        $this->assertSame('slyn9/laravel-licensing', $composer['name']);

        $dev = array_keys($composer['require-dev']);
        sort($dev);
        $this->assertSame(['orchestra/testbench', 'spatie/laravel-permission'], $dev);

        foreach ($composer['require'] as $package => $constraint) {
            if (str_starts_with($package, 'illuminate/')) {
                $this->assertSame('^12.0|^13.0', $constraint, $package);
            }
        }
    }

    public function test_default_config_names_no_host_classes_and_has_no_closures(): void
    {
        $defaults = require $this->root().'/config/licensing.php';

        $this->assertNull($defaults['tenant']['model']);
        $this->assertNull($defaults['branches']['model']);
        $this->assertNull($defaults['user']['model']);
        $this->assertNull($defaults['audit']['logger']);
        $this->assertNull($defaults['audit']['legacy_logger']);
        $this->assertFalse($defaults['branches']['enabled']);
        $this->assertFalse($defaults['ui']['blade']);
        $this->assertSame('standalone', $defaults['mode']);
        $this->assertSame(1, $defaults['schema']);

        array_walk_recursive($defaults, function ($value, $key) {
            $this->assertNotInstanceOf(\Closure::class, $value, "licensing.{$key} no puede ser una closure (config:cache)");
        });

        $this->assertNotFalse(@serialize($defaults));
        $this->assertSame(var_export($defaults, true), var_export(unserialize(serialize($defaults)), true));
    }

    public function test_original_migration_filenames_are_intact_and_the_new_ones_are_additive(): void
    {
        $names = array_map('basename', glob($this->root().'/database/migrations/*.php'));
        sort($names);

        // farmacy ya ejecutó estas seis: renombrarlas las haría volver a correr en producción.
        $original = [
            '2026_05_25_100000_create_license_types_table.php',
            '2026_05_25_100001_create_company_licenses_table.php',
            '2026_05_25_100002_create_license_installments_table.php',
            '2026_05_25_100003_create_license_payments_table.php',
            '2026_05_26_100002_add_sede_id_to_company_licenses_table.php',
            '2026_10_12_100000_convert_license_statuses_to_string.php',
        ];
        $additive = [
            '2026_10_13_100000_add_uuid_to_license_tables.php',
            '2026_10_13_100001_add_currency_to_company_licenses_table.php',
            '2026_10_13_100002_add_soft_deletes_to_license_tables.php',
            '2026_10_13_100003_set_tenant_foreign_key_on_delete.php',
        ];

        $expected = array_merge($original, $additive);
        sort($expected);

        $this->assertSame($expected, $names);

        // Las nuevas se ordenan después de todas las originales.
        foreach ($additive as $name) {
            $this->assertGreaterThan(end($original), $name);
        }
    }

    public function test_every_migration_defines_a_down_method(): void
    {
        foreach (glob($this->root().'/database/migrations/*.php') as $file) {
            $this->assertStringContainsString('function down()', file_get_contents($file), basename($file));
        }
    }

    public function test_the_shipped_snapshot_schema_is_valid_json_with_version_one(): void
    {
        $schema = json_decode(file_get_contents($this->root().'/resources/schemas/license-snapshot-v1.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $schema['properties']['schema_version']['const']);
        $this->assertContains('installments', $schema['required']);
        $this->assertContains('payments', $schema['required']);
    }
}
