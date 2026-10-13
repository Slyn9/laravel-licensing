<?php

namespace Slyn9\Licensing\Tests\Fixtures\Support;

use Illuminate\Database\Eloquent\Model;
use Slyn9\Licensing\Contracts\TenantIdentity;

class FixedIdentity implements TenantIdentity
{
    public function externalRef(Model $tenant): string
    {
        return 'GLOBAL-'.$tenant->getKey();
    }

    public function branchRef(Model $branch): string
    {
        return 'GLOBAL-BRANCH-'.$branch->getKey();
    }
}
