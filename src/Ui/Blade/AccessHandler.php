<?php

namespace Slyn9\Licensing\Ui\Blade;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\View;
use Slyn9\Licensing\Services\LicenseAccess;
use Symfony\Component\HttpFoundation\Response;

/**
 * Respuesta del middleware `license.check` cuando la UI Blade está activa:
 * la página 402 y el aviso de gracia/prueba para las vistas del anfitrión.
 */
class AccessHandler
{
    public function blocked(LicenseAccess $access, ?Model $tenant, ?Model $branch): Response
    {
        return response()->view('licensing::errors.blocked', [
            'license' => $access->license,
            'status' => $access->status,
            'tenant' => $tenant,
            'branch' => $branch,
        ], 402);
    }

    public function warn(LicenseAccess $access): void
    {
        View::share('licenseWarning', $access->license);
    }
}
