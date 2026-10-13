<?php

namespace Slyn9\Licensing\Access;

use Illuminate\Database\Eloquent\Model;
use Slyn9\Licensing\Contracts\CurrentTenantResolver;

/** Sin resolvedor configurado: el tenant sale del usuario (UserTenantAccess::defaultTenantFor). */
class NullTenantResolver implements CurrentTenantResolver
{
    public function tenant(): ?Model
    {
        return null;
    }
}
