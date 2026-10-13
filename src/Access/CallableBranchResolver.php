<?php

namespace Slyn9\Licensing\Access;

use Illuminate\Database\Eloquent\Model;
use Slyn9\Licensing\Contracts\CurrentBranchResolver;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Support\ResolvesContext;

class CallableBranchResolver implements CurrentBranchResolver
{
    /** @param  string|array{0:string,1:string}  $callable */
    public function __construct(private readonly string|array $callable) {}

    public function branch(): ?Model
    {
        return ResolvesContext::toModel(Licensing::branchQuery(), ResolvesContext::invoke($this->callable));
    }
}
