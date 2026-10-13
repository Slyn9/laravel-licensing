<?php

namespace Slyn9\Licensing\Access;

use Illuminate\Database\Eloquent\Model;
use Slyn9\Licensing\Contracts\CurrentBranchResolver;

class NullBranchResolver implements CurrentBranchResolver
{
    public function branch(): ?Model
    {
        return null;
    }
}
