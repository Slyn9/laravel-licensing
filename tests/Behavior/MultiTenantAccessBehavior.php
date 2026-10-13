<?php

namespace Slyn9\Licensing\Tests\Behavior;

/** Usuario con varios tenants (N:M): entra si alguno permite; el bloqueo es del tenant activo. */
trait MultiTenantAccessBehavior
{
    private function twoTenants(): array
    {
        $second = $this->createTenant(['name' => 'Segunda empresa']);
        $user = $this->makeUser();
        $this->attachUserToTenant($user, $second);

        return [$this->tenant, $second, $user];
    }

    public function test_login_enters_if_at_least_one_tenant_allows_access(): void
    {
        [$first, $second, $user] = $this->twoTenants();
        $this->makeLicense(null, -40, ['grace' => 5, 'tenant' => $first]);
        $this->makeLicense(null, 30, ['tenant' => $second]);

        $this->login($user)->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_login_is_rejected_when_no_tenant_allows_access(): void
    {
        [$first, $second, $user] = $this->twoTenants();
        $this->makeLicense(null, -40, ['grace' => 5, 'tenant' => $first]);
        $this->makeLicense(null, -50, ['grace' => 5, 'tenant' => $second]);

        $this->login($user)->assertSessionHasErrors(['email_user' => 'La licencia del sistema ha vencido. Contacte al administrador para renovarla.']);
        $this->assertGuest();
    }

    public function test_login_with_mixed_denial_reasons_uses_the_neutral_message(): void
    {
        [$first, $second, $user] = $this->twoTenants();
        $this->makeLicense(null, -40, ['grace' => 5, 'tenant' => $first]);
        $this->makeLicense(null, 30, ['status' => 'blocked', 'tenant' => $second]);

        $this->login($user)->assertSessionHasErrors(['email_user' => 'Ninguna de sus empresas tiene una licencia vigente. Contacte al administrador.']);
    }

    public function test_login_with_every_tenant_blocked_uses_the_blocked_message(): void
    {
        [$first, $second, $user] = $this->twoTenants();
        $this->makeLicense(null, 30, ['status' => 'blocked', 'tenant' => $first]);
        $this->makeLicense(null, 30, ['status' => 'blocked', 'tenant' => $second]);

        $this->login($user)->assertSessionHasErrors(['email_user' => 'El acceso ha sido bloqueado por el administrador. Contacte a soporte.']);
    }

    public function test_a_missing_license_counts_as_a_tenant_that_denies_when_policy_is_block(): void
    {
        config(['licensing.missing_license' => 'block']);
        [$first, $second, $user] = $this->twoTenants();
        $this->makeLicense(null, 30, ['tenant' => $second]);

        // $first no tiene licencia, $second sí: entra.
        $this->login($user)->assertSessionHasNoErrors();
    }

    public function test_middleware_blocks_only_the_active_tenant(): void
    {
        [$first, $second, $user] = $this->twoTenants();
        $this->makeLicense(null, -40, ['grace' => 5, 'tenant' => $first]);
        $this->makeLicense(null, 30, ['tenant' => $second]);

        $this->probe($user, $first)->assertStatus(402);
        $this->probe($user, $second)->assertOk();
    }

    public function test_can_enter_is_evaluated_per_tenant(): void
    {
        [$first, $second, $user] = $this->twoTenants();
        $this->makeLicense(null, -40, ['grace' => 5, 'tenant' => $first]);
        $this->makeLicense(null, 30, ['tenant' => $second]);

        $this->assertFalse($this->service()->canEnter($user, $first));
        $this->assertTrue($this->service()->canEnter($user, $second));
    }

    public function test_can_enter_is_always_true_for_a_superadmin(): void
    {
        $this->makeLicense(null, -40, ['grace' => 5]);

        $this->assertTrue($this->service()->canEnter($this->superadmin(), $this->tenant));
    }

    public function test_without_an_active_tenant_a_multi_tenant_user_passes_the_middleware(): void
    {
        [$first, $second, $user] = $this->twoTenants();
        $this->makeLicense(null, -40, ['grace' => 5, 'tenant' => $first]);
        $this->makeLicense(null, -40, ['grace' => 5, 'tenant' => $second]);

        // Sin tenant activo lo corta el middleware del anfitrión, no la licencia.
        $this->actingAs($user)->get('__probe')->assertOk();
    }

    public function test_without_an_active_tenant_a_single_tenant_user_falls_back_to_their_tenant(): void
    {
        $this->makeLicense(null, -40, ['grace' => 5]);
        $user = $this->makeUser();

        $this->actingAs($user)->get('__probe')->assertStatus(402);
    }

    public function test_user_without_tenants_is_not_blocked_by_license(): void
    {
        $user = $this->makeUser();
        $this->detachUserFromAllTenants($user);

        $this->assertNull($this->service()->denialMessageFor($user));
    }
}
