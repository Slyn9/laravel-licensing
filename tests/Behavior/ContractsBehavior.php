<?php

namespace Slyn9\Licensing\Tests\Behavior;

use LogicException;
use Slyn9\Licensing\Contracts\AuditLogger;
use Slyn9\Licensing\Contracts\CurrentBranchResolver;
use Slyn9\Licensing\Contracts\CurrentTenantResolver;
use Slyn9\Licensing\Contracts\SuperAdminCheck;
use Slyn9\Licensing\Contracts\TenantIdentity;
use Slyn9\Licensing\Contracts\UserTenantAccess;
use Slyn9\Licensing\Licensing;

/** Contratos del paquete: identidad, configuración inválida y fail-fast. */
trait ContractsBehavior
{
    public function test_every_contract_is_resolvable_from_the_container(): void
    {
        foreach ([
            CurrentTenantResolver::class, CurrentBranchResolver::class, UserTenantAccess::class,
            SuperAdminCheck::class, AuditLogger::class, TenantIdentity::class,
        ] as $contract) {
            $this->assertInstanceOf($contract, app($contract));
        }
    }

    public function test_external_ref_is_product_and_primary_key(): void
    {
        $identity = app(TenantIdentity::class);

        $this->assertSame(config('licensing.product.code').':'.$this->tenant->getKey(), $identity->externalRef($this->tenant));
    }

    public function test_product_code_is_mandatory_and_validated_on_first_use(): void
    {
        config(['licensing.product.code' => null]);

        try {
            Licensing::productCode();
            $this->fail('Debió exigir product.code.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        config(['licensing.product.code' => 'not valid']);
        $this->expectException(LogicException::class);
        app(TenantIdentity::class)->externalRef($this->tenant);
    }

    public function test_a_missing_product_code_does_not_block_access_control(): void
    {
        config(['licensing.product.code' => null]);
        $this->makeLicense(null, 30);

        $this->probe($this->makeUser($this->scopeA()), null, $this->scopeA())->assertOk();
        $this->assertTrue($this->service()->canEnter($this->makeUser($this->scopeA()), $this->tenant));
    }

    public function test_a_misconfigured_tenant_resolver_fails_loudly_instead_of_letting_everyone_in(): void
    {
        config(['licensing.resolvers.tenant' => 'function_that_does_not_exist']);
        $this->makeLicense(null, -40, ['grace' => 5]);

        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);

        $this->probe($this->makeUser($this->scopeA()), null, $this->scopeA());
    }

    public function test_unknown_drivers_fail_loudly(): void
    {
        config(['licensing.access.driver' => 'magic']);

        $this->expectException(LogicException::class);
        app(UserTenantAccess::class);
    }

    public function test_unknown_superadmin_driver_fails_loudly(): void
    {
        config(['licensing.superadmin.driver' => 'magic']);

        $this->expectException(LogicException::class);
        app(SuperAdminCheck::class);
    }

    public function test_the_default_audit_logger_is_null_when_nothing_is_configured(): void
    {
        config(['licensing.audit' => ['logger' => null, 'legacy_logger' => null], 'licensing.bindings.audit_logger' => null]);

        $this->assertInstanceOf(\Slyn9\Licensing\Audit\NullAuditLogger::class, app(AuditLogger::class));
    }

    public function test_host_models_and_keys_come_from_the_config(): void
    {
        $this->assertSame(config('licensing.tenant.model'), Licensing::tenantModel());
        $this->assertSame(config('licensing.tenant.foreign_key'), Licensing::tenantKey());
        $this->assertSame(config('licensing.tenant.table'), Licensing::tenantTable());
        $this->assertSame(config('licensing.user.model'), Licensing::userModel());
    }

    public function test_nested_config_is_merged_recursively_and_lists_are_replaced(): void
    {
        // Solo se declararon algunas subclaves de `ui` y `routes`: el resto viene de los defaults.
        $this->assertSame('layouts.app', config('licensing.ui.layout'));
        $this->assertSame('admin.', config('licensing.routes.name'));
        $this->assertSame('0 0 * * *', config('licensing.schedule.cron'));
        $this->assertArrayHasKey('transferencia', config('licensing.payment_methods'));
    }
}
