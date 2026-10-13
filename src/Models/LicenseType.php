<?php

namespace Slyn9\Licensing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Slyn9\Licensing\Models\Concerns\HasLicenseUuid;

class LicenseType extends Model
{
    use HasLicenseUuid;
    use SoftDeletes;

    protected $fillable = [
        'name', 'code', 'duration_days', 'base_price',
        'grace_period_days', 'is_active', 'description',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'base_price' => 'decimal:2',
        'duration_days' => 'integer',
        'grace_period_days' => 'integer',
    ];

    public function isPermanent(): bool
    {
        return is_null($this->duration_days);
    }

    public function licenses()
    {
        return $this->hasMany(CompanyLicense::class);
    }
}
