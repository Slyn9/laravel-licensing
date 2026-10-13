<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * C1 — Identidad global. Agrega una columna `uuid` única y ordenada a las cuatro tablas
 * y rellena los registros existentes. Los ids enteros siguen siendo la PK local.
 *
 * Aditiva: solo agrega una columna nullable (con índice único tras el relleno). Se deja
 * nullable a propósito: pasarla a NOT NULL reconstruye la tabla en SQLite y dispara los
 * ON DELETE CASCADE. El modelo garantiza el uuid al crear y esta migración lo hace con
 * lo existente; MySQL permite varios NULL en un índice único.
 */
return new class extends Migration
{
    private const TABLES = ['license_types', 'company_licenses', 'license_installments', 'license_payments'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasColumn($name, 'uuid')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->uuid('uuid')->nullable()->after('id');
                });
            }

            DB::table($name)
                ->whereNull('uuid')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($name) {
                    foreach ($rows as $row) {
                        DB::table($name)->where('id', $row->id)->update(['uuid' => (string) Str::orderedUuid()]);
                    }
                });

            Schema::table($name, function (Blueprint $table) {
                $table->unique('uuid');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            if (! Schema::hasColumn($name, 'uuid')) {
                continue;
            }

            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropUnique($name.'_uuid_unique');
            });

            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('uuid');
            });
        }
    }
};
