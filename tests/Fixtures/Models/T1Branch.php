<?php

namespace Slyn9\Licensing\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Slyn9\Licensing\Concerns\BranchHasLicenses;

class T1Branch extends Model
{
    use BranchHasLicenses;

    protected $table = 'branches';

    protected $guarded = [];
}
