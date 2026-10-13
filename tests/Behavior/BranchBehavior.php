<?php

namespace Slyn9\Licensing\Tests\Behavior;

use Slyn9\Licensing\Exceptions\LicenseException;
use Slyn9\Licensing\Models\CompanyLicense;

/** Reglas por sede (solo T1: branches.enabled = true). Portadas de farmacy. */
trait BranchBehavior
{
    private function seedActiveAndExpired(): array
    {
        $a = $this->makeLicense($this->branchA, 60);
        $b = $this->makeLicense($this->branchB, -40, ['grace' => 5]); // vencida y fuera de gracia

        return [$a, $b];
    }

    public function test_branch_scope_returns_the_license_of_that_branch(): void
    {
        [$a, $b] = $this->seedActiveAndExpired();

        $this->assertTrue($this->service()->resolveFor($this->tenant, $this->branchA)->is($a));
        $this->assertTrue($this->service()->resolveFor($this->tenant, $this->branchB)->is($b));
    }

    public function test_company_scope_prefers_a_license_that_allows_access_across_branches(): void
    {
        [$a] = $this->seedActiveAndExpired();

        $resolved = $this->service()->resolveFor($this->tenant, null);

        $this->assertTrue($resolved->is($a));
        $this->assertTrue($resolved->computeStatus()->isAccessAllowed());
    }

    public function test_company_scope_blocks_only_when_every_branch_license_blocks(): void
    {
        $this->makeLicense($this->branchA, -40, ['grace' => 5]);
        $this->makeLicense($this->branchB, 30, ['status' => 'blocked']);

        $this->assertFalse($this->service()->resolveFor($this->tenant, null)->computeStatus()->isAccessAllowed());
    }

    public function test_branch_license_wins_over_company_level_license(): void
    {
        $this->makeLicense(null, 60);
        $own = $this->makeLicense($this->branchA, -40, ['grace' => 5]);

        $this->assertTrue($this->service()->resolveFor($this->tenant, $this->branchA)->is($own));
    }

    public function test_latest_license_of_a_branch_is_chosen_by_id(): void
    {
        $this->makeLicense($this->branchA, 60);
        $newer = $this->makeLicense($this->branchA, -40, ['grace' => 5]);

        $this->assertTrue($this->service()->resolveFor($this->tenant, $this->branchA)->is($newer));
    }

    // ── Middleware ───────────────────────────────────────────────────────────

    public function test_admin_of_a_valid_branch_is_blocked_on_an_expired_branch_route(): void
    {
        $this->seedActiveAndExpired();
        $admin = $this->makeUser($this->branchA);

        $this->probe($admin, null, $this->branchB)->assertStatus(402);
        $this->probe($admin, null, $this->branchA)->assertOk();
    }

    public function test_admin_of_an_expired_branch_can_work_on_a_valid_branch_route(): void
    {
        $this->seedActiveAndExpired();
        $admin = $this->makeUser($this->branchB);

        $this->probe($admin, null, $this->branchA)->assertOk();
        $this->probe($admin, null, $this->branchB)->assertStatus(402);
    }

    public function test_admin_without_branch_is_blocked_on_an_expired_branch_route(): void
    {
        $this->seedActiveAndExpired();
        $admin = $this->makeUser(null);

        $this->probe($admin, null, $this->branchB)->assertStatus(402);
        $this->probe($admin, null, $this->branchA)->assertOk();
    }

    public function test_admin_without_branch_on_company_route_needs_one_valid_license(): void
    {
        $this->makeLicense($this->branchA, -40, ['grace' => 5]);
        $this->makeLicense($this->branchB, -40, ['grace' => 5]);
        $admin = $this->makeUser(null);

        $this->probe($admin)->assertStatus(402);

        $this->makeLicense($this->branchB, 30);

        $this->probe($admin)->assertOk();
    }

    public function test_user_with_branch_on_company_route_uses_own_branch_license(): void
    {
        $this->seedActiveAndExpired();

        $this->probe($this->makeUser($this->branchB, 'vendedor'))->assertStatus(402);
        $this->probe($this->makeUser($this->branchA, 'vendedor'))->assertOk();
    }

    // ── Login ────────────────────────────────────────────────────────────────

    public function test_login_uses_the_license_of_the_users_branch_not_the_latest_of_the_company(): void
    {
        $this->makeLicense($this->branchA, 60, ['created_at' => now()->subDays(10)]);
        $this->makeLicense($this->branchB, -40, ['grace' => 5, 'created_at' => now()->subDay()]);

        $this->login($this->makeUser($this->branchA))->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_login_rejects_a_user_whose_branch_is_expired_even_if_the_latest_license_is_valid(): void
    {
        $this->makeLicense($this->branchB, -40, ['grace' => 5, 'created_at' => now()->subDays(10)]);
        $this->makeLicense($this->branchA, 60, ['created_at' => now()->subDay()]);

        $this->login($this->makeUser($this->branchB))->assertSessionHasErrors('email_user');
        $this->assertGuest();
    }

    public function test_login_of_a_user_without_branch_needs_one_valid_license(): void
    {
        $this->makeLicense($this->branchA, -40, ['grace' => 5]);
        $this->makeLicense($this->branchB, 60, ['created_at' => now()->subDays(5)]);

        $this->login($this->makeUser(null))->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_login_message_for_a_branch_without_license_mentions_the_branch(): void
    {
        config(['licensing.missing_license' => 'block']);

        $this->login($this->makeUser($this->branchA))
            ->assertSessionHasErrors(['email_user' => 'No hay una licencia asignada para su sede. Contacte al administrador.']);
    }

    // ── Alta con sede ────────────────────────────────────────────────────────

    public function test_assign_stores_the_branch(): void
    {
        $license = $this->service()->assign($this->tenant, $this->branchA, $this->types['MON'], ['total_amount' => 99]);

        $this->assertSame($this->branchA->id, $license->sede_id);
        $this->assertTrue($license->branch->is($this->branchA));
    }

    public function test_assign_rejects_a_branch_from_another_company(): void
    {
        $other = $this->createTenant(['name' => 'Otra']);
        $otherBranch = $this->createBranch($other, ['name' => 'X']);

        try {
            $this->service()->assign($this->tenant, $otherBranch, $this->types['MON'], []);
            $this->fail('Debió rechazar la sede ajena.');
        } catch (LicenseException $e) {
            $this->assertSame('sede_id', $e->field);
        }
    }

    public function test_assign_trial_for_a_branch_is_idempotent_per_branch(): void
    {
        $a = $this->service()->assignTrial($this->tenant, $this->branchA);
        $b = $this->service()->assignTrial($this->tenant, $this->branchB);

        $this->assertFalse($a->is($b));
        $this->assertTrue($this->service()->assignTrial($this->tenant, $this->branchA)->is($a));
        $this->assertSame(2, CompanyLicense::count());
    }

    public function test_seeder_assigns_a_trial_to_every_branch_without_license(): void
    {
        $this->makeLicense($this->branchA, 30);

        $this->artisan('db:seed', ['--class' => \Slyn9\Licensing\Database\Seeders\LicenseTypeSeeder::class])->assertSuccessful();

        $this->assertSame(1, CompanyLicense::where('sede_id', $this->branchA->id)->count());
        $this->assertSame(1, CompanyLicense::where('sede_id', $this->branchB->id)->count());
        $this->assertSame('trial', CompanyLicense::where('sede_id', $this->branchB->id)->first()->status->value);
    }

    public function test_tenant_and_branch_concerns_expose_licenses(): void
    {
        $this->makeLicense($this->branchA, 30);
        $this->makeLicense(null, 30, ['status' => 'cancelled']);

        $this->assertCount(1, $this->branchA->licenses);
        $this->assertTrue($this->branchA->activeLicense->is($this->branchA->licenses->first()));
        $this->assertCount(2, $this->tenant->licenses);
        $this->assertSame('active', $this->tenant->activeLicense->status->value);
    }

    public function test_a_foreign_branch_in_the_users_column_is_ignored_for_the_evaluated_tenant(): void
    {
        $other = $this->createTenant(['name' => 'Otra']);
        $foreign = $this->createBranch($other, ['name' => 'Ajena']);
        $this->makeLicense($foreign, -40, ['grace' => 5, 'tenant' => $other]);
        $this->makeLicense(null, 30);

        $user = $this->makeUser($foreign);

        $this->assertTrue($this->service()->canEnter($user, $this->tenant));
    }
}
