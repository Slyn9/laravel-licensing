<?php

namespace Slyn9\Licensing\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Slyn9\Licensing\Contracts\SuperAdminCheck;
use Spatie\Permission\PermissionRegistrar;

/**
 * Superadmin por rol (`hasRole`). Con spatie teams, `team_id` fija el team global bajo el
 * que vive el rol (p. ej. 0) mientras se evalúa, y luego restaura el team y las relaciones
 * cacheadas del modelo. spatie solo se toca si la clase existe.
 */
class RoleSuperAdminCheck implements SuperAdminCheck
{
    public function __construct(
        private readonly ?string $role,
        private readonly int|string|null $teamId = null,
    ) {}

    public function isSuperAdmin(Authenticatable $user): bool
    {
        if (! $this->role || ! method_exists($user, 'hasRole')) {
            return false;
        }

        if ($this->teamId === null || ! class_exists(PermissionRegistrar::class)) {
            return (bool) $user->hasRole($this->role);
        }

        $registrar = app(PermissionRegistrar::class);
        $saved = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId($this->teamId);

        try {
            return (bool) $user->hasRole($this->role);
        } finally {
            $registrar->setPermissionsTeamId($saved);

            // hasRole() cachea `roles` bajo el team global; sin esto el siguiente chequeo
            // de permisos (ya con el team del tenant) reutilizaría esa colección.
            if (method_exists($user, 'unsetRelation')) {
                $user->unsetRelation('roles')->unsetRelation('permissions');
            }
        }
    }
}
