<?php

namespace Slyn9\Licensing\Console\Commands;

use Illuminate\Console\Command;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Models\CompanyLicense;
use Slyn9\Licensing\Services\LicenseService;

class RepairLicensesCommand extends Command
{
    protected $signature = 'licenses:repair
                            {--apply : Escribe los cambios (sin esta opción solo informa)}
                            {--company= : Limita a un tenant (id)}';

    protected $description = 'Reconcilia paid_amount y la cobertura de cuotas con los pagos registrados (idempotente)';

    public function handle(LicenseService $licenses): int
    {
        $apply = (bool) $this->option('apply');

        $query = CompanyLicense::query()->orderBy('id');
        if ($company = $this->option('company')) {
            $query->where(Licensing::tenantKey(), (int) $company);
        }

        $this->components->info($apply ? 'Aplicando correcciones…' : 'Simulación (use --apply para escribir).');

        $touched = 0;
        $rows = [];

        $query->chunkById(100, function ($chunk) use ($licenses, $apply, &$touched, &$rows) {
            foreach ($chunk as $license) {
                $changes = $licenses->reconcile($license, $apply);

                if (! $changes['license'] && ! $changes['installments']) {
                    continue;
                }

                $touched++;

                if ($changes['license']) {
                    $rows[] = [$license->id, 'licencia', 'paid_amount', $changes['license']['from'], $changes['license']['to']];
                }
                foreach ($changes['installments'] as $c) {
                    $rows[] = [$license->id, "cuota {$c['number']}", 'estado / pagado',
                        "{$c['from']} / {$c['paid_from']}", "{$c['to']} / {$c['paid_to']}"];
                }
            }
        });

        if ($rows) {
            $this->table(['Licencia', 'Objeto', 'Campo', 'Antes', 'Después'], $rows);
        }

        $this->line("Licencias con diferencias: {$touched}");

        $remaining = $licenses->inconsistencies();
        $this->table(['Inconsistencia', 'Casos'], collect($remaining)->map(fn ($n, $k) => [$k, $n])->values()->all());

        if ($apply && array_sum($remaining) > 0) {
            $this->components->warn('Quedan inconsistencias tras aplicar; revise el detalle.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
