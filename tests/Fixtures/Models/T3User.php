<?php

namespace Slyn9\Licensing\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

/** T3: N:M `tenants` y rol `super-admin` bajo el team global 0 (spatie teams). */
class T3User extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    protected $guarded = [];

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(T3Tenant::class, 'tenant_user', 'user_id', 'tenant_id');
    }
}
