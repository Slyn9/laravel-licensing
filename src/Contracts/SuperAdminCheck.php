<?php

namespace Slyn9\Licensing\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/** Un superadmin nunca se bloquea por licencia. */
interface SuperAdminCheck
{
    public function isSuperAdmin(Authenticatable $user): bool;
}
