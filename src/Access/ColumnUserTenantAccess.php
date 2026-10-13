<?php

namespace Slyn9\Licensing\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Slyn9\Licensing\Contracts\UserTenantAccess;
use Slyn9\Licensing\Licensing;

/** Relación 1:1: el usuario guarda su tenant (y su sede) en columnas propias. */
class ColumnUserTenantAccess implements UserTenantAccess
{
    public function tenantsFor(Authenticatable $user): Collection
    {
        $tenant = $this->defaultTenantFor($user);

        return $tenant ? collect([$tenant]) : collect();
    }

    public function defaultTenantFor(Authenticatable $user): ?Model
    {
        $id = $user->{(string) config('licensing.access.tenant_column', 'company_id')} ?? null;

        return $id ? Licensing::tenantQuery()->find($id) : null;
    }

    public function branchFor(Authenticatable $user, Model $tenant): ?Model
    {
        if (! Licensing::branchesEnabled()) {
            return null;
        }

        $id = $user->{(string) config('licensing.access.branch_column', 'sede_id')} ?? null;

        if (! $id) {
            return null;
        }

        $branch = Licensing::branchQuery()->find($id);

        // Una sede ajena al tenant evaluado no aplica.
        if ($branch && (string) $branch->getAttribute(Licensing::branchTenantKey()) !== (string) $tenant->getKey()) {
            return null;
        }

        return $branch;
    }
}
