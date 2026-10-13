<?php

namespace Slyn9\Licensing\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Slyn9\Licensing\Concerns\TenantHasLicenses;

/** T3 (Air-System): tenant en la tabla `tenants`, FK `tenant_id`, con borrado lógico. */
class T3Tenant extends Model
{
    use SoftDeletes;
    use TenantHasLicenses;

    protected $table = 'tenants';

    protected $guarded = [];
}
