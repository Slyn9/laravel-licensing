<?php

namespace Slyn9\Licensing\Tests\Fixtures\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Simula el contexto de tenant/sede que fija el middleware del anfitrión
 * (SetTenantContext en farmacy, SetActiveTenant en Air-System).
 */
class FixtureContext
{
    public int|string|null $tenantId = null;

    public int|string|null $branchId = null;

    public ?Model $tenantModel = null;

    /** Devuelve un id: el paquete lo convierte en modelo. */
    public function tenant(): int|string|null
    {
        return $this->tenantId;
    }

    public function branch(): int|string|null
    {
        return $this->branchId;
    }

    /** Estilo TenantService de Air-System: devuelve el modelo o null. */
    public function currentOrNull(): ?Model
    {
        return $this->tenantModel;
    }

    public function reset(): void
    {
        $this->tenantId = $this->branchId = null;
        $this->tenantModel = null;
    }
}
