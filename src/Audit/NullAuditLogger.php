<?php

namespace Slyn9\Licensing\Audit;

use Illuminate\Database\Eloquent\Model;
use Slyn9\Licensing\Contracts\AuditLogger;

class NullAuditLogger implements AuditLogger
{
    public function log(string $action, string $description, Model $license, array $properties, ?Model $by): void {}
}
