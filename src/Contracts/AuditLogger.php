<?php

namespace Slyn9\Licensing\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Rastro de auditoría local (quién, cuándo, por qué). Distinto de los eventos de dominio,
 * que son para sistemas externos. El paquete no interrumpe la operación por un fallo de log:
 * la implementación decide qué hacer si falla.
 */
interface AuditLogger
{
    /**
     * @param  array<string, mixed>  $properties  incluye license_id y las llaves de tenant/sede
     */
    public function log(string $action, string $description, Model $license, array $properties, ?Model $by): void;
}
