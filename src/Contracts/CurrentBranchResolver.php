<?php

namespace Slyn9\Licensing\Contracts;

use Illuminate\Database\Eloquent\Model;

/** Sede activa del request actual. Opcional: sin sedes se usa NullBranchResolver. */
interface CurrentBranchResolver
{
    public function branch(): ?Model;
}
