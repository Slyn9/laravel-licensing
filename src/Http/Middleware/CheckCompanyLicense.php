<?php

namespace Slyn9\Licensing\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Slyn9\Licensing\Contracts\CurrentBranchResolver;
use Slyn9\Licensing\Contracts\CurrentTenantResolver;
use Slyn9\Licensing\Contracts\SuperAdminCheck;
use Slyn9\Licensing\Contracts\UserTenantAccess;
use Slyn9\Licensing\Exceptions\LicenseBlockedException;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Services\LicenseService;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `license.check`. Aplica el bloqueo real sobre el tenant ACTIVO del request.
 * Solo lee tablas locales: nunca hace llamadas de red.
 *
 * Aplícalo DESPUÉS del middleware del anfitrión que fija el tenant/sede del request.
 */
class CheckCompanyLicense
{
    public const ACCESS_ATTRIBUTE = 'licensing.access';

    public function __construct(
        private readonly LicenseService $licenses,
        private readonly CurrentTenantResolver $tenants,
        private readonly CurrentBranchResolver $branches,
        private readonly UserTenantAccess $access,
        private readonly SuperAdminCheck $superAdmin,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // Superadmin nunca se bloquea
        if ($this->superAdmin->isSuperAdmin($user)) {
            return $next($request);
        }

        // Tenant activo; si el anfitrión no lo resuelve, el único tenant del usuario.
        // Un usuario N:M sin tenant activo pasa: lo corta el middleware del anfitrión.
        $tenant = $this->tenants->tenant() ?? $this->access->defaultTenantFor($user);

        if (! $tenant) {
            return $next($request);
        }

        // Rutas de sede: manda la sede de la ruta. Si no, la sede del usuario; sin sede,
        // basta con que alguna licencia no cancelada del tenant permita acceso.
        $branch = Licensing::branchesEnabled()
            ? ($this->branches->branch() ?? $this->access->branchFor($user, $tenant))
            : null;

        $access = $this->licenses->accessFor($tenant, $branch);

        $request->attributes->set(self::ACCESS_ATTRIBUTE, $access);

        if (! $access->allowed) {
            if (! Licensing::bladeUi()) {
                throw new LicenseBlockedException($access, $tenant, $branch);
            }

            return response()->view('licensing::errors.blocked', [
                'license' => $access->license,
                'status' => $access->status,
                'tenant' => $tenant,
                'branch' => $branch,
            ], 402);
        }

        // Compartir datos de advertencia con todas las vistas Blade
        if (Licensing::bladeUi() && $access->shouldWarn()) {
            View::share('licenseWarning', $access->license);
        }

        return $next($request);
    }
}
