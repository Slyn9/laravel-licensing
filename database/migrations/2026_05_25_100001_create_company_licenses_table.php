<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| La tabla y la columna del tenant salen de config('licensing.tenant.*'). Con la
| configuración de farmacy (companies / company_id) el esquema es el histórico.
*/
return new class extends Migration
{
    public function up(): void
    {
        $tenantTable = (string) config('licensing.tenant.table', 'companies');
        $tenantKey = (string) config('licensing.tenant.foreign_key', 'company_id');

        Schema::create('company_licenses', function (Blueprint $table) use ($tenantTable, $tenantKey) {
            $table->id();
            $table->foreignId($tenantKey)->constrained($tenantTable)->cascadeOnDelete();
            $table->foreignId('license_type_id')->constrained();

            $table->enum('status', [
                'trial', 'active', 'grace', 'expired', 'blocked', 'cancelled',
            ])->default('active');

            $table->timestamp('starts_at');
            $table->timestamp('expires_at')->nullable();   // null = permanente
            $table->timestamp('grace_until')->nullable();  // calculado al vencer

            $table->decimal('total_amount', 10, 2)->default(0);
            $table->decimal('paid_amount',  10, 2)->default(0);
            $table->unsignedTinyInteger('installments_count')->default(1); // 1 = pago único

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index([$tenantKey, 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_licenses');
    }
};
