<?php

namespace Slyn9\Licensing\Identity;

use Illuminate\Database\Eloquent\Model;
use Slyn9\Licensing\Contracts\TenantIdentity;
use Slyn9\Licensing\Licensing;

class DefaultTenantIdentity implements TenantIdentity
{
    public function externalRef(Model $tenant): string
    {
        return Licensing::productCode().':'.$tenant->getKey();
    }

    public function branchRef(Model $branch): string
    {
        return Licensing::productCode().':branch:'.$branch->getKey();
    }
}
