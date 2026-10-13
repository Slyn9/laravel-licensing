<?php

namespace Slyn9\Licensing\Tests\Suites;

use PHPUnit\Framework\TestCase;

/** La documentación es parte del contrato: README, CHANGELOG y UPGRADING no pueden quedarse atrás. */
class DocumentationTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** Claves hoja del config (las listas y los arreglos vacíos cuentan como una sola clave). */
    private function leafKeys(array $config, string $prefix = ''): array
    {
        $lists = ['payment_methods', 'routes.middleware', 'ui.banner_views', 'branches.unscoped_scopes', 'user.unscoped_scopes'];
        $keys = [];

        foreach ($config as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value) && $value !== [] && ! in_array($path, $lists, true)) {
                $keys = array_merge($keys, $this->leafKeys($value, $path));
            } else {
                $keys[] = $path;
            }
        }

        return $keys;
    }

    public function test_readme_documents_every_config_key(): void
    {
        $readme = file_get_contents($this->root().'/README.md');
        $defaults = require $this->root().'/config/licensing.php';

        $missing = array_values(array_filter(
            $this->leafKeys($defaults),
            fn (string $key) => ! str_contains($readme, "`{$key}`")
        ));

        $this->assertSame([], $missing, 'Claves de config sin documentar en el README: '.implode(', ', $missing));
    }

    public function test_readme_documents_every_contract_event_and_the_snapshot_contract(): void
    {
        $readme = file_get_contents($this->root().'/README.md');

        foreach (glob($this->root().'/src/Contracts/*.php') as $file) {
            $name = basename($file, '.php');
            $this->assertStringContainsString($name, $readme, "README sin el contrato {$name}");
        }

        foreach (glob($this->root().'/src/Events/License*.php') as $file) {
            $name = basename($file, '.php');
            if ($name === 'LicenseEvent') {
                continue;
            }
            $this->assertStringContainsString($name, $readme, "README sin el evento {$name}");
        }

        $this->assertStringContainsString('Snapshot v1', $readme);
        $this->assertStringContainsString('resources/schemas/license-snapshot-v1.json', $readme);
        $this->assertStringContainsString('contrato público', $readme);
        $this->assertStringContainsString('schema_version', $readme);
    }

    public function test_readme_documents_every_ui_screen_method_and_each_ui_stack(): void
    {
        $readme = file_get_contents($this->root().'/README.md');

        foreach (['Blade', 'Inertia', 'Livewire', 'latestPerTenant', 'licensesOf', 'registerPayment', 'canEnter', 'allowsLocalManagement'] as $needle) {
            $this->assertStringContainsString($needle, $readme, "README sin {$needle}");
        }
    }

    public function test_readme_lists_the_real_event_names(): void
    {
        $readme = file_get_contents($this->root().'/README.md');

        foreach (['license.assigned', 'license.payment_registered', 'license.renewed', 'license.blocked',
            'license.unblocked', 'license.cancelled', 'license.status_changed'] as $name) {
            $this->assertStringContainsString($name, $readme);
        }
    }

    public function test_changelog_and_upgrading_exist_and_cover_v1(): void
    {
        $changelog = file_get_contents($this->root().'/CHANGELOG.md');
        $upgrading = file_get_contents($this->root().'/UPGRADING.md');

        $this->assertStringContainsString('1.0.0', $changelog);
        $this->assertStringContainsString('1.0.0', $upgrading);
        $this->assertStringContainsString('App\\Modules\\Licensing', $upgrading);
        $this->assertStringContainsString('migrate --pretend', $upgrading);
    }
}
