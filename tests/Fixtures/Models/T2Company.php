<?php

namespace Slyn9\Licensing\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Slyn9\Licensing\Concerns\TenantHasLicenses;

/** T2 (ContaPE): empresa sin sedes, usuarios N:M. */
class T2Company extends Model
{
    use TenantHasLicenses;

    protected $table = 'companies';

    protected $guarded = [];
}
