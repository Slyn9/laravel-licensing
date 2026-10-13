<?php

namespace Slyn9\Licensing\Tests\Behavior;

use Illuminate\Support\Facades\Schema;
use LogicException;
use Slyn9\Licensing\Database\Seeders\LicenseTypeSeeder;
use Slyn9\Licensing\Exceptions\LicenseException;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Models\CompanyLicense;
use Slyn9\Licensing\Tests\Fixtures\Support\FixtureContext;

/** branches.enabled = false (T2/T3): sin columna de sede y sin resolución por sede. */
trait NoBranchesBehavior
{
    public function test_the_branch_column_is_not_created(): void
    {
        $this->assertFalse(Licensing::branchesEnabled());
        $this->assertFalse(Schema::hasColumn('company_licenses', 'sede_id'));
        $this->assertTrue(Schema::hasColumn('company_licenses', $this->tenantKey()));
    }

    public function test_assign_with_a_branch_is_rejected(): void
    {
        $branch = $this->createTenant(['name' => 'Cualquier modelo']); // cualquier Model sirve como "sede"

        try {
            $this->service()->assign($this->tenant, $branch, $this->types['MON'], []);
            $this->fail('Debió rechazar la sede.');
        } catch (LicenseException $e) {
            $this->assertSame('branch', $e->field);
        }

        $this->assertSame(0, CompanyLicense::count());
    }

    public function test_resolution_ignores_the_branch_argument(): void
    {
        $license = $this->makeLicense(null, 30);
        $anything = $this->createTenant(['name' => 'X']);

        $this->assertTrue($this->service()->resolveFor($this->tenant, $anything)->is($license));
        $this->assertTrue($this->service()->accessFor($this->tenant, $anything)->allowed);
    }

    public function test_branch_relation_and_model_are_unavailable(): void
    {
        $license = $this->makeLicense(null, 30);

        try {
            $license->branch();
            $this->fail('branch() debe lanzar sin sedes.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);
        Licensing::branchModel();
    }

    public function test_the_branch_resolver_is_never_consulted(): void
    {
        config(['licensing.resolvers.branch' => 'function_that_does_not_exist']);
        $this->makeLicense(null, 30);

        $this->probe($this->makeUser())->assertOk();
    }

    public function test_assign_trial_works_per_tenant_and_is_idempotent(): void
    {
        $first = $this->service()->assignTrial($this->tenant);
        $second = $this->service()->assignTrial($this->tenant);

        $this->assertSame('trial', $first->status->value);
        $this->assertTrue($first->is($second));
        $this->assertSame(1, CompanyLicense::count());
    }

    public function test_seeder_assigns_a_trial_to_each_tenant_without_license(): void
    {
        $covered = $this->createTenant(['name' => 'Con licencia']);
        $uncovered = $this->createTenant(['name' => 'Sin licencia']);
        $this->makeLicense(null, 30, ['tenant' => $covered]);

        $this->artisan('db:seed', ['--class' => LicenseTypeSeeder::class])->assertSuccessful();

        $this->assertSame(1, CompanyLicense::where($this->tenantKey(), $covered->getKey())->count());
        $this->assertSame(1, CompanyLicense::where($this->tenantKey(), $uncovered->getKey())->count());
        $this->assertSame('trial', CompanyLicense::where($this->tenantKey(), $uncovered->getKey())->first()->status->value);
    }

    public function test_the_missing_license_message_mentions_the_company(): void
    {
        config(['licensing.missing_license' => 'block']);

        $this->login($this->makeUser())
            ->assertSessionHasErrors(['email_user' => 'No hay una licencia asignada para su empresa. Contacte al administrador.']);
    }

    public function test_snapshot_and_events_are_tenant_scoped(): void
    {
        $license = $this->service()->assign($this->tenant, null, $this->types['MON'], ['total_amount' => 10]);

        $snapshot = $license->toSnapshot();

        $this->assertSame('tenant', $snapshot['scope']['level']);
        $this->assertNull($snapshot['scope']['branch_ref']);
    }
}
