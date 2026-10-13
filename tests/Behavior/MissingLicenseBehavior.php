<?php

namespace Slyn9\Licensing\Tests\Behavior;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** D8 — Sin licencia (allow|block). D9 — Configuración y columnas string. */
trait MissingLicenseBehavior
{
    public function test_policy_is_one_of_the_two_known_values(): void
    {
        $this->assertContains(config('licensing.missing_license'), ['allow', 'block']);
    }

    public function test_allow_lets_a_scope_without_license_work(): void
    {
        config(['licensing.missing_license' => 'allow']);

        $this->probe($this->makeUser($this->scopeA()), null, $this->scopeA())->assertOk();

        $this->login($this->makeUser($this->scopeA(), 'vendedor'))->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_block_rejects_a_scope_without_license_in_the_middleware(): void
    {
        config(['licensing.missing_license' => 'block']);

        $this->probe($this->makeUser($this->scopeA()), null, $this->scopeA())->assertStatus(402);
    }

    public function test_block_rejects_the_login_without_license(): void
    {
        config(['licensing.missing_license' => 'block']);

        $this->login($this->makeUser($this->scopeA()))->assertSessionHasErrors('email_user');
        $this->assertGuest();
    }

    public function test_block_still_lets_a_licensed_scope_in(): void
    {
        config(['licensing.missing_license' => 'block']);
        $this->makeLicense($this->scopeA(), 30);

        $this->probe($this->makeUser($this->scopeA()), null, $this->scopeA())->assertOk();
    }

    public function test_block_accepts_a_tenant_level_license_as_coverage(): void
    {
        config(['licensing.missing_license' => 'block']);
        $this->makeLicense(null, 30);

        $this->probe($this->makeUser($this->scopeA()), null, $this->scopeA())->assertOk();
    }

    public function test_block_never_affects_the_superadmin(): void
    {
        config(['licensing.missing_license' => 'block']);

        $this->probe($this->superadmin(), null, $this->scopeA())->assertOk();
    }

    public function test_unknown_policy_value_falls_back_to_allow(): void
    {
        config(['licensing.missing_license' => 'whatever']);

        $this->probe($this->makeUser($this->scopeA()), null, $this->scopeA())->assertOk();
    }

    public function test_config_exposes_payment_methods_and_currency(): void
    {
        $this->assertArrayHasKey('efectivo', config('licensing.payment_methods'));
        $this->assertArrayHasKey('transferencia', config('licensing.payment_methods'));
        $this->assertNotEmpty(config('licensing.currency.symbol'));
        $this->assertNotEmpty(config('licensing.currency.code'));
    }

    public function test_status_columns_are_plain_strings(): void
    {
        $license = $this->makeLicense($this->scopeA(), 30, ['total' => 50, 'installments' => 1]);
        [$installment] = $this->makeInstallments($license, 1, 50);

        // Fuera del conjunto histórico del ENUM: debe aceptarse.
        DB::table('company_licenses')->where('id', $license->id)->update(['status' => 'suspended']);
        DB::table('license_installments')->where('id', $installment->id)->update(['status' => 'partial']);

        $this->assertSame('suspended', DB::table('company_licenses')->where('id', $license->id)->value('status'));
        $this->assertSame('partial', DB::table('license_installments')->where('id', $installment->id)->value('status'));
        $this->assertTrue(Schema::hasColumn('license_installments', 'paid_amount'));
    }

    public function test_conversion_migration_preserves_existing_data(): void
    {
        $path = dirname(__DIR__, 2).'/database/migrations/2026_10_12_100000_convert_license_statuses_to_string.php';

        // El rollback deshace 1.2.0, C1/C6 y la conversión; después se reaplica todo.
        $this->artisan('migrate:rollback', ['--path' => dirname($path), '--realpath' => true, '--step' => 5, '--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('company_licenses', 'currency'));

        $statuses = ['trial', 'active', 'grace', 'expired', 'blocked', 'cancelled'];
        $ids = [];
        foreach ($statuses as $status) {
            $ids[$status] = DB::table('company_licenses')->insertGetId([
                $this->tenantKey() => $this->tenant->getKey(),
                'license_type_id' => $this->types['MON']->id,
                'status' => $status,
                'starts_at' => now(), 'expires_at' => now()->addDays(30), 'grace_until' => now()->addDays(35),
                'total_amount' => 60, 'paid_amount' => 0, 'installments_count' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $paid = DB::table('license_installments')->insertGetId([
            'company_license_id' => $ids['active'], 'installment_number' => 1, 'amount_due' => 60,
            'due_date' => '2026-10-01', 'status' => 'paid', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $pending = DB::table('license_installments')->insertGetId([
            'company_license_id' => $ids['active'], 'installment_number' => 2, 'amount_due' => 40,
            'due_date' => '2026-11-01', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('migrate', ['--path' => dirname($path), '--realpath' => true, '--force' => true])->assertSuccessful();

        foreach ($statuses as $status) {
            $this->assertSame($status, DB::table('company_licenses')->where('id', $ids[$status])->value('status'));
        }
        $this->assertSame('paid', DB::table('license_installments')->where('id', $paid)->value('status'));
        $this->assertSame('60.00', number_format((float) DB::table('license_installments')->where('id', $paid)->value('paid_amount'), 2, '.', ''));
        $this->assertSame('0.00', number_format((float) DB::table('license_installments')->where('id', $pending)->value('paid_amount'), 2, '.', ''));
    }
}
