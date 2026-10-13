<?php

namespace Slyn9\Licensing\Services;

use Illuminate\Support\Facades\DB;
use Slyn9\Licensing\Events\LicenseEvent;

/**
 * Despacha un evento de dominio solo cuando la transacción activa (si la hay) confirma.
 * Sin transacción se despacha de inmediato; en rollback se descarta.
 */
class EventPublisher
{
    public function publish(LicenseEvent $event): void
    {
        DB::afterCommit(static fn () => event($event));
    }
}
