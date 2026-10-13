<?php

namespace Slyn9\Licensing\Contracts;

use Illuminate\Database\Eloquent\Model;

/** Referencia estable de un tenant (y sede) fuera del producto. */
interface TenantIdentity
{
    /** Por defecto "{PRODUCT}:{clave primaria}". */
    public function externalRef(Model $tenant): string;

    /** Por defecto "{PRODUCT}:branch:{clave primaria}". */
    public function branchRef(Model $branch): string;
}
