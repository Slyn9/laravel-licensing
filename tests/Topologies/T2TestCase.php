<?php

namespace Slyn9\Licensing\Tests\Topologies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\TestResponse;
use Slyn9\Licensing\Tests\Fixtures\Models\T2Company;
use Slyn9\Licensing\Tests\Fixtures\Models\T2User;
use Slyn9\Licensing\Tests\Fixtures\Support\AuditSink;
use Slyn9\Licensing\Tests\LicensingTestCase;

/**
 * T2 (ContaPE): N:M por relación + sin sedes + superadmin por atributo.
 * El tenant activo sale de la sesión (session:current_company_id).
 */
abstract class T2TestCase extends LicensingTestCase
{
    protected function topology(): string
    {
        return 't2';
    }

    protected function userModel(): string
    {
        return T2User::class;
    }

    protected function licensingConfig(): array
    {
        return [
            'product' => ['code' => 'CONTAPE'],
            'tenant' => ['model' => T2Company::class, 'table' => 'companies', 'foreign_key' => 'company_id', 'name_attribute' => 'name'],
            'branches' => ['enabled' => false],
            'user' => ['model' => T2User::class, 'table' => 'users'],
            'access' => ['driver' => 'relation', 'relation' => 'companies'],
            'superadmin' => ['driver' => 'attribute', 'attribute' => 'is_super_admin'],
            'resolvers' => ['tenant' => 'session:current_company_id'],
            'bindings' => ['audit_logger' => AuditSink::class],
            'ui' => ['blade' => false],
        ];
    }

    protected function createTenant(array $attributes = []): Model
    {
        return T2Company::create($attributes + ['name' => 'Empresa']);
    }

    protected function createUser(?Model $branch = null, string $role = 'admin', array $attributes = [], ?Model $tenant = null): Authenticatable
    {
        $user = T2User::create(array_merge([
            'name' => 'Usuario',
            'email' => 'u'.uniqid().'@test.pe',
            'password' => $this->password(),
        ], $attributes));

        $user->companies()->attach(($tenant ?? $this->tenant)->getKey());

        return $user;
    }

    protected function attachUserToTenant(Authenticatable $user, Model $tenant): void
    {
        $user->companies()->attach($tenant->getKey());
    }

    protected function detachUserFromAllTenants(Authenticatable $user): void
    {
        $user->companies()->detach();
    }

    protected function createSuperadmin(): Authenticatable
    {
        return T2User::create([
            'name' => 'Super',
            'email' => 'super'.uniqid().'@test.pe',
            'password' => $this->password(),
            'is_super_admin' => true,
        ]);
    }

    protected function probe(Authenticatable $user, ?Model $tenant = null, ?Model $branch = null): TestResponse
    {
        $tenant ??= $this->tenant;

        return $this->actingAs($user)
            ->withSession(['current_company_id' => $tenant->getKey()])
            ->get('__probe');
    }
}
