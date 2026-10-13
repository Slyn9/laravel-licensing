<?php

namespace Slyn9\Licensing\Audit;

use Illuminate\Database\Eloquent\Model;
use Slyn9\Licensing\Contracts\AuditLogger;

/**
 * Adapta un logger con la firma histórica del módulo:
 * log(string $action, string $module, string $description, ?Model $subject, array $properties, $asUser).
 */
class LegacyActivityLogger implements AuditLogger
{
    public const MODULE = 'licencias';

    /** @param  class-string  $logger */
    public function __construct(private readonly string $logger) {}

    public function log(string $action, string $description, Model $license, array $properties, ?Model $by): void
    {
        app($this->logger)->log($action, self::MODULE, $description, $license, $properties, $by);
    }
}
