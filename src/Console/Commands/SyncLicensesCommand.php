<?php

namespace Slyn9\Licensing\Console\Commands;

use Illuminate\Console\Command;
use Slyn9\Licensing\Services\LicenseService;

class SyncLicensesCommand extends Command
{
    protected $signature = 'licenses:sync {--dry-run : Solo informa qué cambiaría, sin escribir}';

    protected $description = 'Guarda el estado calculado de las licencias y marca como vencidas las cuotas atrasadas';

    public function handle(LicenseService $licenses): int
    {
        $dry = (bool) $this->option('dry-run');
        $result = $licenses->syncAll($dry);

        $this->line(sprintf(
            '%sLicencias con estado actualizado: %d · Cuotas marcadas como vencidas: %d',
            $dry ? '[dry-run] ' : '',
            $result['licenses'],
            $result['installments'],
        ));

        return self::SUCCESS;
    }
}
