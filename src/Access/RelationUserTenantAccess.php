<?php

namespace Slyn9\Licensing\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use LogicException;
use Slyn9\Licensing\Contracts\UserTenantAccess;

/** Relación N:M: el usuario pertenece a varios tenants por una relación Eloquent. */
class RelationUserTenantAccess implements UserTenantAccess
{
    public function tenantsFor(Authenticatable $user): Collection
    {
        $relation = (string) config('licensing.access.relation', 'companies');

        if (! method_exists($user, $relation)) {
            throw new LogicException("El usuario no define la relación '{$relation}' (licensing.access.relation).");
        }

        return $user->{$relation}()->get()->values();
    }

    public function defaultTenantFor(Authenticatable $user): ?Model
    {
        $tenants = $this->tenantsFor($user);

        return $tenants->count() === 1 ? $tenants->first() : null;
    }

    /** En N:M la sede por usuario no se soporta de serie: usa un UserTenantAccess propio. */
    public function branchFor(Authenticatable $user, Model $tenant): ?Model
    {
        return null;
    }
}
