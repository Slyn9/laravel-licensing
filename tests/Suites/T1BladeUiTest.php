<?php

namespace Slyn9\Licensing\Tests\Suites;

use Slyn9\Licensing\Models\CompanyLicense;
use Slyn9\Licensing\Tests\Fixtures\Support\SetFixtureContext;
use Slyn9\Licensing\Tests\Topologies\T1TestCase;

/** Capa UI Blade/Bootstrap opcional (licensing.ui.blade = true): las pantallas de farmacy. */
class T1BladeUiTest extends T1TestCase
{
    protected function licensingConfig(): array
    {
        return array_replace_recursive(parent::licensingConfig(), [
            'ui' => ['blade' => true, 'banner_views' => ['banner-host']],
        ]);
    }

    protected function defineRoutes($router): void
    {
        parent::defineRoutes($router);

        $router->middleware(['web', 'auth', SetFixtureContext::class])
            ->get('c/{tenant}/__banner', fn () => view('banner-host'));
    }

    private function payload(string $type, array $over = []): array
    {
        return array_merge([
            'license_type_id' => $this->types[$type]->id,
            'starts_at' => '2026-10-12',
            'total_amount' => 99,
            'installments_count' => 3,
        ], $over);
    }

    // ── Alta (D1) ────────────────────────────────────────────────────────────

    public function test_company_form_saves_the_selected_branch(): void
    {
        $this->actingAs($this->superadmin())
            ->post(route('admin.licenses.store', $this->tenant), $this->payload('MON', ['sede_id' => $this->branchA->id]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('company_licenses', ['company_id' => $this->tenant->id, 'sede_id' => $this->branchA->id]);
    }

    public function test_company_form_rejects_a_branch_of_another_company(): void
    {
        $other = $this->createTenant(['name' => 'Otra']);
        $foreign = $this->createBranch($other, ['name' => 'Ajena']);

        $this->actingAs($this->superadmin())
            ->post(route('admin.licenses.store', $this->tenant), $this->payload('MON', ['sede_id' => $foreign->id]))
            ->assertSessionHasErrors('sede_id');

        $this->assertSame(0, CompanyLicense::count());
    }

    public function test_branch_form_with_trial_type_creates_a_trial_license(): void
    {
        $this->actingAs($this->superadmin())
            ->postJson(
                route('admin.companies.branches.licenses.store', [$this->tenant, $this->branchA]),
                $this->payload('TRIAL', ['total_amount' => 0, 'installments_count' => 1])
            )
            ->assertOk();

        $this->assertSame('trial', CompanyLicense::firstOrFail()->status->value);
    }

    public function test_branch_form_generates_installments(): void
    {
        $this->actingAs($this->superadmin())
            ->postJson(route('admin.companies.branches.licenses.store', [$this->tenant, $this->branchA]), $this->payload('MON'))
            ->assertOk();

        $license = CompanyLicense::firstOrFail();

        $this->assertCount(3, $license->installments);
        $this->assertSame('99.00', number_format((float) $license->installments->sum('amount_due'), 2, '.', ''));
    }

    public function test_both_entry_points_build_equivalent_licenses(): void
    {
        $admin = $this->superadmin();

        $this->actingAs($admin)->post(route('admin.licenses.store', $this->tenant), $this->payload('MON', ['sede_id' => $this->branchA->id]));
        $this->actingAs($admin)->postJson(route('admin.companies.branches.licenses.store', [$this->tenant, $this->branchB]), $this->payload('MON'));

        $a = CompanyLicense::where('sede_id', $this->branchA->id)->firstOrFail();
        $b = CompanyLicense::where('sede_id', $this->branchB->id)->firstOrFail();

        $this->assertSame($a->status, $b->status);
        $this->assertEquals($a->expires_at, $b->expires_at);
        $this->assertEquals($a->grace_until, $b->grace_until);
        $this->assertSame($a->installments()->count(), $b->installments()->count());
        $this->assertSame((string) $a->total_amount, (string) $b->total_amount);
    }

    // ── Pagos, estados y auditoría por HTTP ──────────────────────────────────

    public function test_payment_validation_follows_the_configured_methods(): void
    {
        config(['licensing.payment_methods' => ['yape' => 'Yape']]);
        $license = $this->makeLicense($this->branchA, 30, ['total' => 100]);
        $admin = $this->superadmin();

        $this->actingAs($admin)->post(route('admin.licenses.payment', $license), [
            'amount' => 10, 'paid_at' => '2026-10-12', 'payment_method' => 'efectivo',
        ])->assertSessionHasErrors('payment_method');

        $this->actingAs($admin)->post(route('admin.licenses.payment', $license), [
            'amount' => 10, 'paid_at' => '2026-10-12', 'payment_method' => 'yape',
        ])->assertSessionHasNoErrors();
    }

    public function test_overpayment_over_http_shows_the_amount_error(): void
    {
        $license = $this->makeLicense($this->branchA, 30, ['total' => 100]);

        $this->actingAs($this->superadmin())->post(route('admin.licenses.payment', $license), [
            'amount' => 150, 'paid_at' => '2026-10-12', 'payment_method' => 'efectivo',
        ])->assertSessionHasErrors('amount');

        $this->assertSame(0, $license->payments()->count());
    }

    public function test_block_requires_a_reason_over_http(): void
    {
        $license = $this->makeLicense($this->branchA, 30);

        $this->actingAs($this->superadmin())
            ->post(route('admin.licenses.block', $license), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame('active', $license->fresh()->status->value);
    }

    public function test_block_unblock_cancel_and_renew_work_over_http(): void
    {
        $license = $this->makeLicense($this->branchA, 30);
        $admin = $this->superadmin();

        $this->actingAs($admin)->post(route('admin.licenses.block', $license), ['reason' => 'Falta de pago'])->assertSessionHasNoErrors();
        $this->assertSame('blocked', $license->fresh()->status->value);

        $this->actingAs($admin)->post(route('admin.licenses.unblock', $license), ['reason' => 'Pagó'])->assertSessionHasNoErrors();
        $this->assertSame('active', $license->fresh()->status->value);

        $this->actingAs($admin)->post(route('admin.licenses.renew', $license), ['total_amount' => 99, 'installments_count' => 1])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('admin.licenses.cancel', $license), ['reason' => 'Cierra'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $license->fresh()->status->value);
    }

    public function test_unblocking_an_expired_license_over_http_asks_for_a_date(): void
    {
        $license = $this->makeLicense($this->branchA, -40, ['status' => 'blocked', 'grace' => 5]);

        $this->actingAs($this->superadmin())
            ->post(route('admin.licenses.unblock', $license), ['reason' => 'Regularizó'])
            ->assertSessionHasErrors('expires_at');
    }

    // ── Pantallas ────────────────────────────────────────────────────────────

    public function test_index_shows_the_effective_status_and_currency(): void
    {
        $this->makeLicense($this->branchA, -40, ['grace' => 5, 'total' => 99]);

        $this->actingAs($this->superadmin())
            ->get(route('admin.licenses.index'))
            ->assertOk()
            ->assertSee('Empresa Licencias')
            ->assertSee('Vencida')
            ->assertSee('S/ 99.00');
    }

    public function test_show_renders_actions_installments_and_payment_methods(): void
    {
        $license = $this->makeLicense($this->branchA, 30, ['total' => 100, 'installments' => 2]);
        $this->makeInstallments($license, 2, 100);
        $this->service()->registerPayment($license, 20, ['paid_at' => now(), 'payment_method' => 'transferencia'], $this->superadmin());
        $this->makeLicense($this->branchB, -40, ['grace' => 5, 'status' => 'blocked']);

        $this->actingAs($this->superadmin())
            ->get(route('admin.licenses.show', $this->tenant))
            ->assertOk()
            ->assertSee('Sede A')
            ->assertSee('Renovar')
            ->assertSee('Bloquear')
            ->assertSee('Reactivar')
            ->assertSee('Motivo')
            ->assertSee('Transferencia')
            ->assertSee('Las fechas de esta licencia ya vencieron.');
    }

    public function test_managed_mode_hides_the_commercial_actions(): void
    {
        $this->makeLicense($this->branchA, 30, ['total' => 100]);
        config(['licensing.mode' => 'managed']);

        $this->actingAs($this->superadmin())
            ->get(route('admin.licenses.show', $this->tenant))
            ->assertOk()
            ->assertSee('Sede A')
            ->assertDontSee('Asignar nueva licencia')
            ->assertDontSee('Renovar')
            ->assertDontSee('Bloquear')
            ->assertDontSee('REGISTRAR PAGO');
    }

    public function test_managed_mode_rejects_writes_made_over_http(): void
    {
        $license = $this->makeLicense($this->branchA, 30);
        config(['licensing.mode' => 'managed']);

        $this->actingAs($this->superadmin())
            ->post(route('admin.licenses.block', $license), ['reason' => 'Falta de pago'])
            ->assertSessionHasErrors('license');

        $this->assertSame('active', $license->fresh()->status->value);
    }

    // ── 402, banner y login ──────────────────────────────────────────────────

    public function test_blocked_page_for_a_branch_without_license_when_policy_is_block(): void
    {
        config(['licensing.missing_license' => 'block']);

        $this->probe($this->makeUser($this->branchA), null, $this->branchA)
            ->assertStatus(402)
            ->assertSee('Sin licencia asignada');
    }

    public function test_blocked_page_keeps_its_texts_for_expired_and_blocked_licenses(): void
    {
        $this->makeLicense($this->branchA, -40, ['grace' => 5]);
        $this->makeLicense($this->branchB, 30, ['status' => 'blocked']);
        $admin = $this->makeUser(null);

        $this->probe($admin, null, $this->branchA)
            ->assertStatus(402)->assertSee('Licencia')->assertSee('vencida')
            ->assertSee('Realice el pago correspondiente para restablecer el acceso.');

        $this->probe($admin, null, $this->branchB)
            ->assertStatus(402)->assertSee('bloqueada manualmente')
            ->assertSee('El acceso al sistema ha sido bloqueado por el administrador.');
    }

    public function test_blocked_login_messages_are_unchanged(): void
    {
        $this->makeLicense($this->branchA, -40, ['grace' => 5]);

        $this->login($this->makeUser($this->branchA))
            ->assertSessionHasErrors(['email_user' => 'La licencia del sistema ha vencido. Contacte al administrador para renovarla.']);
    }

    public function test_the_banner_warns_about_the_grace_period_and_stays_quiet_otherwise(): void
    {
        $license = $this->makeLicense($this->branchA, -2, ['grace' => 5]);
        $user = $this->makeUser($this->branchA);

        $this->actingAs($user)->get("c/{$this->tenant->id}/__banner")
            ->assertOk()->assertSee('Período de gracia activo.');

        $license->update(['expires_at' => now()->addDays(60), 'grace_until' => now()->addDays(65)]);

        $this->actingAs($user)->get("c/{$this->tenant->id}/__banner")
            ->assertOk()->assertDontSee('Período de gracia activo.');
    }
}
