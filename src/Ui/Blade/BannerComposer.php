<?php

namespace Slyn9\Licensing\Ui\Blade;

use Illuminate\Contracts\View\View;
use Slyn9\Licensing\Contracts\CurrentBranchResolver;
use Slyn9\Licensing\Contracts\CurrentTenantResolver;
use Slyn9\Licensing\Contracts\SuperAdminCheck;
use Slyn9\Licensing\Contracts\UserTenantAccess;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Services\LicenseService;

/**
 * Comparte $licenseWarning (gracia / prueba) con las vistas del anfitrión que incluyen
 * `licensing::partials.banner`. Solo informa: nunca bloquea el acceso.
 */
class BannerComposer
{
    public function __construct(
        private readonly LicenseService $licenses,
        private readonly CurrentTenantResolver $tenants,
        private readonly CurrentBranchResolver $branches,
        private readonly UserTenantAccess $access,
        private readonly SuperAdminCheck $superAdmin,
    ) {}

    public function compose(View $view): void
    {
        $user = auth()->user();

        if (! $user || $this->superAdmin->isSuperAdmin($user)) {
            return;
        }

        $tenant = $this->tenants->tenant() ?? $this->access->defaultTenantFor($user);

        if (! $tenant) {
            return;
        }

        $branch = Licensing::branchesEnabled()
            ? ($this->branches->branch() ?? $this->access->branchFor($user, $tenant))
            : null;

        $access = $this->licenses->accessFor($tenant, $branch);

        if ($access->shouldWarn()) {
            $view->with('licenseWarning', $access->license);
        }
    }
}
