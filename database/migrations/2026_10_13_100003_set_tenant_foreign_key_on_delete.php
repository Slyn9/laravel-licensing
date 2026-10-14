<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `tenant.on_delete` — qué pasa con las licencias, cuotas y pagos cuando se borra DE VERDAD (forceDelete) a su
 * tenant.
 *
 * Por defecto (`cascade`) esta migración no hace nada: la llave foránea queda como la crearon las migraciones
 * originales (ON DELETE CASCADE), igual que en 1.1.0. Con `licensing.tenant.on_delete = 'restrict'` la reemplaza
 * por ON DELETE RESTRICT: un tenant con licencias no se puede borrar de verdad (el registro financiero no se
 * pierde por accidente). El borrado lógico del tenant no se ve afectado.
 *
 * Las migraciones ya publicadas no se editan: por eso es una migración nueva y no un cambio a
 * `create_company_licenses_table`.
 *
 * SQLite (solo pruebas/desarrollo): no puede cambiar una llave foránea sin reconstruir la tabla, y con las
 * llaves foráneas activas el `DROP TABLE` de la reconstrucción borra en cascada las cuotas y los pagos. Por eso,
 * en SQLite la migración solo actúa si `company_licenses` está vacía (nada que perder); con datos la omite y la
 * llave queda como la crearon las migraciones originales. En MySQL/MariaDB/PostgreSQL el cambio no toca datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('licensing.tenant.on_delete', 'cascade') !== 'restrict') {
            return;
        }

        $this->replaceForeignKey(restrict: true);
    }

    public function down(): void
    {
        if (config('licensing.tenant.on_delete', 'cascade') !== 'restrict') {
            return;
        }

        $this->replaceForeignKey(restrict: false);
    }

    private function replaceForeignKey(bool $restrict): void
    {
        if (! Schema::hasTable('company_licenses')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite' && DB::table('company_licenses')->exists()) {
            return;
        }

        $tenantTable = (string) config('licensing.tenant.table', 'companies');
        $tenantKey = (string) config('licensing.tenant.foreign_key', 'company_id');

        Schema::table('company_licenses', function (Blueprint $table) use ($tenantKey) {
            $table->dropForeign([$tenantKey]);
        });

        Schema::table('company_licenses', function (Blueprint $table) use ($tenantTable, $tenantKey, $restrict) {
            $foreign = $table->foreign($tenantKey)->references('id')->on($tenantTable);

            $restrict ? $foreign->restrictOnDelete() : $foreign->cascadeOnDelete();
        });
    }
};
