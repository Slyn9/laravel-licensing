<?php

namespace Slyn9\Licensing\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** T2: relación N:M `companies` y superadmin por atributo `is_super_admin`. */
class T2User extends Authenticatable
{
    protected $table = 'users';

    protected $guarded = [];

    protected $casts = ['is_super_admin' => 'boolean'];

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(T2Company::class, 'company_user', 'user_id', 'company_id');
    }
}
