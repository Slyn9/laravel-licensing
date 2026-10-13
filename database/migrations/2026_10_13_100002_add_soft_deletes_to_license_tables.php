<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Borrado lógico (`deleted_at`) en las cuatro tablas: los modelos usan `SoftDeletes` desde 1.2.0.
 *
 * Aditiva y con guarda: no toca datos ni columnas existentes, y es un no-op si el anfitrión ya agregó la
 * columna por su cuenta (p. ej. por una regla propia de «toda entidad lleva softDeletes»). El paquete no
 * borra licencias (se cancelan): esto permite que el anfitrión las oculte sin perder el rastro de cobranza.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['license_types', 'company_licenses', 'license_installments', 'license_payments'];

    public function up(): void
    {
        foreach ($this->tables as $name) {
            if (! Schema::hasTable($name) || Schema::hasColumn($name, 'deleted_at')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $name) {
            if (! Schema::hasTable($name) || ! Schema::hasColumn($name, 'deleted_at')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
