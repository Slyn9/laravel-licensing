<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Solo con licensing.branches.enabled = true. Sin sedes no se crea columna, FK ni índice.
| Nombres de columna y tabla salen de config('licensing.branches.*' / 'licensing.tenant.*').
*/
return new class extends Migration
{
    public function up(): void
    {
        if (! config('licensing.branches.enabled', false)) {
            return;
        }

        $tenantKey = (string) config('licensing.tenant.foreign_key', 'company_id');
        $branchKey = (string) config('licensing.branches.foreign_key', 'sede_id');
        $branchTable = (string) (config('licensing.branches.table') ?: $this->branchTableFromModel());

        Schema::table('company_licenses', function (Blueprint $table) use ($tenantKey, $branchKey, $branchTable) {
            $table->foreignId($branchKey)
                ->nullable()
                ->after($tenantKey)
                ->constrained($branchTable)
                ->nullOnDelete();

            $table->index([$tenantKey, $branchKey, 'status']);
        });
    }

    public function down(): void
    {
        $tenantKey = (string) config('licensing.tenant.foreign_key', 'company_id');
        $branchKey = (string) config('licensing.branches.foreign_key', 'sede_id');

        if (! Schema::hasColumn('company_licenses', $branchKey)) {
            return;
        }

        Schema::table('company_licenses', function (Blueprint $table) use ($tenantKey, $branchKey) {
            $table->dropIndex([$tenantKey, $branchKey, 'status']);
            $table->dropForeign([$branchKey]);
            $table->dropColumn($branchKey);
        });
    }

    private function branchTableFromModel(): string
    {
        $model = config('licensing.branches.model');

        if (! is_string($model) || ! class_exists($model)) {
            throw new LogicException(
                "Con licensing.branches.enabled = true define licensing.branches.table o licensing.branches.model."
            );
        }

        return (new $model)->getTable();
    }
};
