<?php

namespace Slyn9\Licensing\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Models\CompanyLicense;

/**
 * Para el modelo de sede: `$branch->licenses` y `$branch->activeLicense`.
 * Solo tiene sentido con licensing.branches.enabled = true.
 */
trait BranchHasLicenses
{
    public function licenses(): HasMany
    {
        $this->assertBranchesEnabled();

        return $this->hasMany(CompanyLicense::class, Licensing::branchKey())->orderByDesc('id');
    }

    /** La licencia no cancelada más reciente de la sede (la que manda en esa sede). */
    public function activeLicense(): HasOne
    {
        $this->assertBranchesEnabled();

        return $this->hasOne(CompanyLicense::class, Licensing::branchKey())
            ->where('status', '<>', 'cancelled')
            ->orderByDesc('id');
    }

    private function assertBranchesEnabled(): void
    {
        if (! Licensing::branchesEnabled()) {
            throw new LogicException('Las sedes están desactivadas (licensing.branches.enabled = false).');
        }
    }
}
