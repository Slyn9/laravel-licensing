<?php

namespace Slyn9\Licensing\Tests\Behavior;

/** Verifica el arnés: alta, bloqueo por política y acceso. Corre en las tres topologías. */
trait SmokeBehavior
{
    public function test_smoke_assign_and_probe(): void
    {
        $branch = $this->usesBranches() ? $this->branchA : null;
        $user = $this->makeUser($branch);
        config(['licensing.missing_license' => 'block']);

        $this->probe($user, null, $branch)->assertStatus(402);

        $license = $this->service()->assign($this->tenant, $branch, $this->types['MON'], ['total_amount' => 99]);
        $this->assertSame('active', $license->status->value);
        $this->assertNotEmpty($license->uuid);
        $this->assertSame('PEN', $license->currency);

        $this->probe($user, null, $branch)->assertOk();
    }
}
