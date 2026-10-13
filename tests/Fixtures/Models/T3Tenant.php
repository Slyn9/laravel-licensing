<?php

namespace Slyn9\Licensing\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Slyn9\Licensing\Concerns\TenantHasLicenses;

/** T3 (Air-System): tenant en la tabla `tenants`, FK `tenant_id`. */
class T3Tenant extends Model
{
    use TenantHasLicenses;

    protected $table = 'tenants';

    protected $guarded = [];
}
