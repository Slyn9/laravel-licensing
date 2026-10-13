<?php

namespace Slyn9\Licensing\Tests\Fixtures\Support;

use Illuminate\Database\Eloquent\Model;

/** Logger con la firma histórica del módulo (la de ActivityLogService de farmacy). */
class LegacyLogService
{
    public function log(string $action, string $module, string $description, ?Model $subject = null, array $properties = [], ?Model $asUser = null): void
    {
        (new AuditSink)->legacy($action, $module, $description, $subject, $properties, $asUser);
    }
}
