<?php

namespace Slyn9\Licensing\Access;

use Illuminate\Database\Eloquent\Model;
use Slyn9\Licensing\Contracts\CurrentTenantResolver;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Support\ResolvesContext;

/** Tenant activo guardado en la sesión (p. ej. "session:current_company_id"). */
class SessionTenantResolver implements CurrentTenantResolver
{
    public function __construct(private readonly string $key) {}

    public function tenant(): ?Model
    {
        if (! app()->bound('session') || ! app('session')->isStarted()) {
            return null;
        }

        return ResolvesContext::toModel(Licensing::tenantQuery(), session($this->key));
    }
}
