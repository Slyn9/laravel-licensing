<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los estados dejan de ser ENUM de MySQL (agregar un estado exigía ALTER TABLE) y pasan
 * a string(20). Los valores existentes se conservan tal cual. Además cada cuota guarda
 * cuánto lleva pagado, para poder distinguir una cuota parcialmente pagada de una pagada.
 */
return new class extends Migration
{
    private const LICENSE_STATUSES = ['trial', 'active', 'grace', 'expired', 'blocked', 'cancelled'];

    private const INSTALLMENT_STATUSES = ['pending', 'paid', 'overdue'];

    public function up(): void
    {
        $this->preservingChildRows(function () {
            Schema::table('company_licenses', function (Blueprint $table) {
                $table->string('status', 20)->default('active')->change();
            });

            Schema::table('license_installments', function (Blueprint $table) {
                $table->string('status', 20)->default('pending')->change();
            });
        });

        Schema::table('license_installments', function (Blueprint $table) {
            $table->decimal('paid_amount', 10, 2)->default(0)->after('amount_due');
        });

        // Las cuotas hoy marcadas como pagadas se consideran cubiertas completas.
        // `licenses:repair` las recalcula contra los pagos reales.
        DB::table('license_installments')
            ->where('status', 'paid')
            ->update(['paid_amount' => DB::raw('amount_due')]);
    }

    public function down(): void
    {
        Schema::table('license_installments', function (Blueprint $table) {
            $table->dropColumn('paid_amount');
        });

        // Un ENUM no admite valores fuera de su lista: se normalizan antes de volver.
        DB::table('company_licenses')
            ->whereNotIn('status', self::LICENSE_STATUSES)
            ->update(['status' => 'active']);
        DB::table('license_installments')
            ->whereNotIn('status', self::INSTALLMENT_STATUSES)
            ->update(['status' => 'pending']);

        $this->preservingChildRows(function () {
            Schema::table('company_licenses', function (Blueprint $table) {
                $table->enum('status', self::LICENSE_STATUSES)->default('active')->change();
            });

            Schema::table('license_installments', function (Blueprint $table) {
                $table->enum('status', self::INSTALLMENT_STATUSES)->default('pending')->change();
            });
        });
    }

    /**
     * En MySQL `ALTER TABLE … MODIFY` conserva todo. SQLite reconstruye la tabla y su
     * DROP dispara ON DELETE CASCADE / SET NULL sobre las tablas hijas, así que allí se
     * respaldan y restauran cuotas y pagos alrededor del cambio.
     */
    private function preservingChildRows(callable $change): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $change();

            return;
        }

        $installments = DB::table('license_installments')->get()->map(fn ($r) => (array) $r)->all();
        $payments = DB::table('license_payments')->get()->map(fn ($r) => (array) $r)->all();

        $change();

        DB::table('license_payments')->delete();
        DB::table('license_installments')->delete();

        // Las columnas respaldadas pueden no existir ya (p. ej. paid_amount al volver atrás).
        $installmentColumns = Schema::getColumnListing('license_installments');

        foreach (array_chunk($installments, 100) as $chunk) {
            DB::table('license_installments')->insert(
                array_map(fn ($row) => array_intersect_key($row, array_flip($installmentColumns)), $chunk)
            );
        }
        foreach (array_chunk($payments, 100) as $chunk) {
            DB::table('license_payments')->insert($chunk);
        }
    }
};
