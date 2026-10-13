<?php

namespace Slyn9\Licensing\Tests\Suites;

use Slyn9\Licensing\Contracts\SuperAdminCheck;
use Slyn9\Licensing\Tests\Fixtures\Models\T3User;
use Slyn9\Licensing\Tests\Topologies\T3TestCase;
use Spatie\Permission\PermissionRegistrar;

/** SuperAdminCheck por rol con spatie teams (Air-System): team global 0, sin contaminar el team activo. */
class T3SuperAdminTest extends T3TestCase
{
    public function test_a_super_admin_is_detected_from_inside_a_tenant_team(): void
    {
        $super = $this->superadmin();
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->tenant->getKey());

        // Sin el cambio de team, hasRole('super-admin') daría false en el team del tenant.
        $this->assertFalse($super->hasRole('super-admin'));
        $super->unsetRelation('roles');

        $this->assertTrue(app(SuperAdminCheck::class)->isSuperAdmin($super));
    }

    public function test_the_team_and_cached_relations_are_restored_after_the_check(): void
    {
        $user = $this->makeUser(null, 'admin');
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($this->tenant->getKey());

        $this->assertTrue($user->hasRole('admin'));
        $this->assertFalse(app(SuperAdminCheck::class)->isSuperAdmin($user));

        $this->assertSame($this->tenant->getKey(), $registrar->getPermissionsTeamId());
        // La relación cacheada no quedó con los roles del team global.
        $this->assertTrue(T3User::find($user->id)->hasRole('admin') && $user->hasRole('admin'));
    }

    public function test_a_tenant_admin_with_the_same_role_name_in_another_team_is_not_a_super_admin(): void
    {
        $user = $this->makeUser(null, 'super-admin'); // rol con el mismo nombre, pero en el team del tenant

        $this->assertFalse(app(SuperAdminCheck::class)->isSuperAdmin($user));
    }

    public function test_the_superadmin_is_never_blocked_even_when_the_active_tenant_is_expired(): void
    {
        $this->makeLicense(null, -40, ['grace' => 5]);

        $this->probe($this->superadmin(), $this->tenant)->assertOk();
    }
}
