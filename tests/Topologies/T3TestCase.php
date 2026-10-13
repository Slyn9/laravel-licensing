<?php

namespace Slyn9\Licensing\Tests\Topologies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\TestResponse;
use Slyn9\Licensing\Tests\Fixtures\Models\T3Tenant;
use Slyn9\Licensing\Tests\Fixtures\Models\T3User;
use Slyn9\Licensing\Tests\Fixtures\Support\AuditSink;
use Slyn9\Licensing\Tests\Fixtures\Support\FixtureContext;
use Slyn9\Licensing\Tests\LicensingTestCase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * T3 (Air-System): N:M por relación + sin sedes + rol `super-admin` bajo el team global 0
 * (spatie teams). El tenant activo lo expone un servicio estilo TenantService.
 */
abstract class T3TestCase extends LicensingTestCase
{
    protected function topology(): string
    {
        return 't3';
    }

    protected function usesSpatie(): bool
    {
        return true;
    }

    protected function usesTeams(): bool
    {
        return true;
    }

    protected function userModel(): string
    {
        return T3User::class;
    }

    protected function licensingConfig(): array
    {
        return [
            'product' => ['code' => 'AIRSYS'],
            'tenant' => ['model' => T3Tenant::class, 'table' => 'tenants', 'foreign_key' => 'tenant_id', 'name_attribute' => 'name'],
            'branches' => ['enabled' => false],
            'user' => ['model' => T3User::class, 'table' => 'users'],
            'access' => ['driver' => 'relation', 'relation' => 'tenants'],
            'superadmin' => ['driver' => 'role', 'role' => 'super-admin', 'team_id' => 0],
            'resolvers' => ['tenant' => [FixtureContext::class, 'currentOrNull']],
            'bindings' => ['audit_logger' => AuditSink::class],
            'ui' => ['blade' => false],
        ];
    }

    protected function createTenant(array $attributes = []): Model
    {
        return T3Tenant::create($attributes + ['name' => 'Tenant']);
    }

    protected function createUser(?Model $branch = null, string $role = 'admin', array $attributes = [], ?Model $tenant = null): Authenticatable
    {
        $tenant ??= $this->tenant;

        $user = T3User::create(array_merge([
            'name' => 'Usuario',
            'email' => 'u'.uniqid().'@test.pe',
            'password' => $this->password(),
        ], $attributes));

        $user->tenants()->attach($tenant->getKey());

        $this->inTeam($tenant->getKey(), function () use ($user, $role) {
            Role::findOrCreate($role, 'web');
            $user->assignRole($role);
        });

        return $user;
    }

    protected function createSuperadmin(): Authenticatable
    {
        $user = T3User::create([
            'name' => 'Super',
            'email' => 'super'.uniqid().'@test.pe',
            'password' => $this->password(),
        ]);

        $this->inTeam(0, function () use ($user) {
            Role::findOrCreate('super-admin', 'web');
            $user->assignRole('super-admin');
        });

        return $user;
    }

    protected function probe(Authenticatable $user, ?Model $tenant = null, ?Model $branch = null): TestResponse
    {
        $tenant ??= $this->tenant;

        return $this->actingAs($user)->withHeader('X-Tenant', (string) $tenant->getKey())->get('__probe');
    }

    /** Ejecuta $callback bajo un team de spatie y restaura el anterior. */
    protected function inTeam(int|string $teamId, callable $callback): void
    {
        $registrar = app(PermissionRegistrar::class);
        $saved = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId($teamId);

        try {
            $callback();
        } finally {
            $registrar->setPermissionsTeamId($saved);
        }
    }
}
