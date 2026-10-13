<?php

namespace Slyn9\Licensing\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Slyn9\Licensing\Contracts\SuperAdminCheck;

/** Superadmin por atributo del usuario (p. ej. users.is_super_admin). */
class AttributeSuperAdminCheck implements SuperAdminCheck
{
    public function __construct(private readonly string $attribute) {}

    public function isSuperAdmin(Authenticatable $user): bool
    {
        return (bool) ($user->{$this->attribute} ?? false);
    }
}
