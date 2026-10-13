<?php

namespace Slyn9\Licensing\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Slyn9\Licensing\Concerns\TenantHasLicenses;

/** T1 (farmacy): empresa con sedes y usuarios 1:1. */
class T1Company extends Model
{
    use TenantHasLicenses;

    protected $table = 'companies';

    protected $guarded = [];
}
