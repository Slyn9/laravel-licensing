<?php

namespace Slyn9\Licensing\Tests\Fixtures\Support;

use Illuminate\Database\Eloquent\Model;
use Slyn9\Licensing\Contracts\AuditLogger;

/**
 * Registra las entradas de auditoría en memoria. Implementa el contrato AuditLogger
 * (T2/T3) y la firma histórica del módulo (T1, vía LegacyActivityLogger).
 */
class AuditSink implements AuditLogger
{
    /** @var array<int, array{action:string,module:?string,description:string,subject:?Model,properties:array,by:?Model}> */
    public static array $entries = [];

    public static function reset(): void
    {
        self::$entries = [];
    }

    // Contrato nuevo
    public function log(string $action, string $description, Model $license, array $properties, ?Model $by): void
    {
        self::$entries[] = compact('action', 'description', 'properties', 'by') + ['module' => null, 'subject' => $license];
    }

    // Firma histórica de farmacy: ActivityLogService::log(...)
    public function legacy(string $action, string $module, string $description, ?Model $subject = null, array $properties = [], ?Model $asUser = null): void
    {
        self::$entries[] = ['action' => $action, 'module' => $module, 'description' => $description,
            'subject' => $subject, 'properties' => $properties, 'by' => $asUser];
    }
}
