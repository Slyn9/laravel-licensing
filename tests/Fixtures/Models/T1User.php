<?php

namespace Slyn9\Licensing\Tests\Fixtures\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

/** T1: pertenece a una empresa y (opcionalmente) a una sede por columnas. */
class T1User extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    protected $guarded = [];
}
