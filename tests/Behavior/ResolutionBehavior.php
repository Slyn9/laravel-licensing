<?php

namespace Slyn9\Licensing\Tests\Behavior;

/** D2 — Una sola regla para decidir qué licencia manda (válida en las 3 topologías). */
trait ResolutionBehavior
{
    public function test_tenant_scope_prefers_a_license_that_allows_access(): void
    {
        $valid = $this->makeLicense(null, 60);
        $this->makeLicense(null, -40, ['grace' => 5]); // vencida y más reciente

        $resolved = $this->service()->resolveFor($this->tenant, null);

        $this->assertTrue($resolved->is($valid));
        $this->assertTrue($resolved->computeStatus()->isAccessAllowed());
    }

    public function test_tenant_scope_blocks_only_when_every_license_blocks(): void
    {
        $this->makeLicense(null, -40, ['grace' => 5]);
        $this->makeLicense(null, 30, ['status' => 'blocked']);

        $resolved = $this->service()->resolveFor($this->tenant, null);

        $this->assertFalse($resolved->computeStatus()->isAccessAllowed());
    }

    public function test_cancelled_licenses_are_ignored(): void
    {
        $this->makeLicense($this->scopeA(), 60, ['status' => 'cancelled']);

        $this->assertNull($this->service()->resolveFor($this->tenant, $this->scopeA()));
    }

    public function test_tenant_level_license_is_the_fallback_for_a_scope_without_its_own(): void
    {
        $tenantLevel = $this->makeLicense(null, 60);

        $this->assertTrue($this->service()->resolveFor($this->tenant, $this->scopeA())->is($tenantLevel));
    }

    // ── Middleware ───────────────────────────────────────────────────────────

    public function test_middleware_allows_a_valid_license_and_blocks_an_expired_one(): void
    {
        $user = $this->makeUser($this->scopeA());
        $license = $this->makeLicense(null, 30);

        $this->probe($user, null, $this->scopeA())->assertOk();

        $license->update(['expires_at' => now()->subDays(40), 'grace_until' => now()->subDays(35)]);

        $this->probe($user, null, $this->scopeA())->assertStatus(402);
    }

    public function test_superadmin_is_never_blocked(): void
    {
        $this->makeLicense(null, -40, ['grace' => 5]);

        $this->probe($this->superadmin(), null, $this->scopeA())->assertOk();
    }

    public function test_denied_access_without_blade_ui_throws_a_renderable_402_and_exposes_the_access(): void
    {
        $this->makeLicense(null, -40, ['grace' => 5]);
        $user = $this->makeUser($this->scopeA());

        $response = $this->probe($user, null, $this->scopeA());

        $response->assertStatus(402);
        $this->assertFalse(config('licensing.ui.blade'));
    }

    // ── Login ────────────────────────────────────────────────────────────────

    public function test_login_is_allowed_with_a_valid_license(): void
    {
        $this->makeLicense(null, 60);

        $this->login($this->makeUser($this->scopeA()))->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_login_is_rejected_with_an_expired_license(): void
    {
        $this->makeLicense(null, -40, ['grace' => 5]);

        $this->login($this->makeUser($this->scopeA()))
            ->assertSessionHasErrors(['email_user' => 'La licencia del sistema ha vencido. Contacte al administrador para renovarla.']);
        $this->assertGuest();
    }

    public function test_login_message_for_a_blocked_license_is_unchanged(): void
    {
        $this->makeLicense(null, 30, ['status' => 'blocked']);

        $this->login($this->makeUser($this->scopeA()))
            ->assertSessionHasErrors(['email_user' => 'El acceso ha sido bloqueado por el administrador. Contacte a soporte.']);
    }

    public function test_superadmin_login_ignores_licenses(): void
    {
        $this->makeLicense(null, -40, ['grace' => 5]);

        $this->login($this->superadmin())->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }
}
