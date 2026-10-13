<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C6 — Moneda por licencia. Agrega `currency` (ISO 4217, 3 letras). Las filas existentes
 * se rellenan con la moneda configurada al migrar (un ADD COLUMN con DEFAULT las cubre sin
 * reconstruir la tabla). Las licencias nuevas guardan la moneda de la config al crearse.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('company_licenses', 'currency')) {
            return;
        }

        $code = strtoupper(substr((string) config('licensing.currency.code', 'PEN'), 0, 3)) ?: 'PEN';

        Schema::table('company_licenses', function (Blueprint $table) use ($code) {
            $table->string('currency', 3)->default($code)->after('installments_count');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('company_licenses', 'currency')) {
            return;
        }

        Schema::table('company_licenses', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
