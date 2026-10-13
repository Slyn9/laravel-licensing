<?php

namespace Slyn9\Licensing\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** Cómo se relaciona un usuario con sus tenants (columna 1:1 o relación N:M). */
interface UserTenantAccess
{
    /** @return Collection<int, Model> todos los tenants a los que el usuario pertenece */
    public function tenantsFor(Authenticatable $user): Collection;

    /** Tenant "único" del usuario: el de la columna, o el único de la relación; null si hay varios. */
    public function defaultTenantFor(Authenticatable $user): ?Model;

    /** Sede propia del usuario en ese tenant; siempre null si branches.enabled = false. */
    public function branchFor(Authenticatable $user, Model $tenant): ?Model;
}
