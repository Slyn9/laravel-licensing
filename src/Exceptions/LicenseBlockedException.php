<?php

namespace Slyn9\Licensing\Exceptions;

use Illuminate\Database\Eloquent\Model;
use Slyn9\Licensing\Services\LicenseAccess;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Lanzada por el middleware `license.check` cuando el acceso se deniega y la UI Blade
 * del paquete está desactivada. El anfitrión la renderiza (Inertia, Livewire, JSON...).
 */
class LicenseBlockedException extends HttpException
{
    public function __construct(
        public readonly LicenseAccess $access,
        public readonly ?Model $tenant = null,
        public readonly ?Model $branch = null,
    ) {
        parent::__construct(402, 'Acceso suspendido por licencia.');
    }
}
