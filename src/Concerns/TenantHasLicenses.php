<?php

namespace Slyn9\Licensing\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Models\CompanyLicense;

/**
 * Para el modelo de tenant: `$tenant->licenses` y `$tenant->activeLicense`.
 */
trait TenantHasLicenses
{
    public function licenses(): HasMany
    {
        return $this->hasMany(CompanyLicense::class, Licensing::tenantKey())->orderByDesc('id');
    }

    /** La licencia no cancelada más reciente del tenant (cualquier sede). */
    public function activeLicense(): HasOne
    {
        return $this->hasOne(CompanyLicense::class, Licensing::tenantKey())
            ->where('status', '<>', 'cancelled')
            ->orderByDesc('id');
    }
}
