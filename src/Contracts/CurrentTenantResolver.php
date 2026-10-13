<?php

namespace Slyn9\Licensing\Contracts;

use Illuminate\Database\Eloquent\Model;

/** Tenant activo del request actual (null si no hay contexto de tenant). */
interface CurrentTenantResolver
{
    public function tenant(): ?Model;
}
