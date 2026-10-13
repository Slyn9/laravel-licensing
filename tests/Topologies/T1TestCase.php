<?php

namespace Slyn9\Licensing\Tests\Topologies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\TestResponse;
use Slyn9\Licensing\Tests\Fixtures\Models\T1Branch;
use Slyn9\Licensing\Tests\Fixtures\Models\T1Company;
use Slyn9\Licensing\Tests\Fixtures\Models\T1User;
use Slyn9\Licensing\Tests\Fixtures\Support\FixtureContext;
use Slyn9\Licensing\Tests\Fixtures\Support\LegacyLogService;
use Slyn9\Licensing\Tests\LicensingTestCase;
use Spatie\Permission\Models\Role;

/**
 * T1 (farmacy): usuario 1:1 con su tenant por columna + sedes + superadmin por rol.
 * AuditorÃ­a con la firma histÃ³rica (LegacyActivityLogger). Sin UI Blade por defecto.
 */
abstract class T1TestCase extends LicensingTestCase
{
    protected Model $branchA;

    protected Model $branchB;

    protected function topology(): string
    {
        return 't1';
    }

    protected function usesBranches(): bool
    {
        return true;
    }

    protected function usesSpatie(): bool
    {
        return true;
    }

    protected function userModel(): string
    {
        return T1User::class;
    }

    protected function licensingConfig(): array
    {
        return [
            'product' => ['code' => 'FARMACY'],
            'tenant' => ['model' => T1Company::class, 'table' => 'companies', 'foreign_key' => 'company_id', 'name_attribute' => 'trade_name'],
            'branches' => ['enabled' => true, 'model' => T1Branch::class, 'table' => 'branches', 'foreign_key' => 'sede_id', 'tenant_key' => 'company_id'],
            'user' => ['model' => T1User::class, 'table' => 'users'],
            'access' => ['driver' => 'column', 'tenant_column' => 'company_id', 'branch_column' => 'sede_id'],
            'superadmin' => ['driver' => 'role', 'role' => 'superadmin'],
            'resolvers' => ['tenant' => [FixtureContext::class, 'tenant'], 'branch' => [FixtureContext::class, 'branch']],
            'audit' => ['legacy_logger' => LegacyLogService::class],
            'ui' => ['blade' => false],
        ];
    }

    protected function afterSchema(): void
    {
        foreach (['superadmin', 'admin', 'vendedor'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->branchA = $this->createBranch($this->tenant, ['name' => 'Sede A', 'code' => 'A']);
        $this->branchB = $this->createBranch($this->tenant, ['name' => 'Sede B', 'code' => 'B']);
    }

    protected function createTenant(array $attributes = []): Model
    {
        $name = $attributes['name'] ?? 'Empresa';

        return T1Company::create($attributes + ['name' => $name, 'trade_name' => $name]);
    }

    protected function createBranch(Model $tenant, array $attributes = []): Model
    {
        return T1Branch::create($attributes + ['company_id' => $tenant->getKey(), 'name' => 'Sede']);
    }

    protected function createUser(?Model $branch = null, string $role = 'admin', array $attributes = [], ?Model $tenant = null): Authenticatable
    {
        $user = T1User::create(array_merge([
            'name' => 'Usuario',
            'email' => 'u'.uniqid().'@test.pe',
            'password' => $this->password(),
            'company_id' => ($tenant ?? $this->tenant)->getKey(),
            'sede_id' => $branch?->getKey(),
        ], $attributes));

        $user->assignRole($role);

        return $user;
    }

    protected function createSuperadmin(): Authenticatable
    {
        $user = T1User::create([
            'name' => 'Super',
            'email' => 'super'.uniqid().'@test.pe',
            'password' => $this->password(),
        ]);
        $user->assignRole('superadmin');

        return $user;
    }

    protected function probe(Authenticatable $user, ?Model $tenant = null, ?Model $branch = null): TestResponse
    {
        $tenant ??= $this->tenant;
        $path = $branch
            ? "c/{$tenant->getKey()}/{$branch->getKey()}/__probe"
            : "c/{$tenant->getKey()}/__probe";

        return $this->actingAs($user)->get($path);
    }
}
