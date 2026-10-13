<?php

namespace Slyn9\Licensing\Access;

use Illuminate\Database\Eloquent\Model;
use Slyn9\Licensing\Contracts\CurrentTenantResolver;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Support\ResolvesContext;

class CallableTenantResolver implements CurrentTenantResolver
{
    /** @param  string|array{0:string,1:string}  $callable */
    public function __construct(private readonly string|array $callable) {}

    public function tenant(): ?Model
    {
        return ResolvesContext::toModel(Licensing::tenantQuery(), ResolvesContext::invoke($this->callable));
    }
}
