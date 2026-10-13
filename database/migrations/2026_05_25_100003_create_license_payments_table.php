<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| La tabla de usuarios sale de config('licensing.user.table').
*/
return new class extends Migration
{
    public function up(): void
    {
        $userTable = (string) config('licensing.user.table', 'users');

        Schema::create('license_payments', function (Blueprint $table) use ($userTable) {
            $table->id();
            $table->foreignId('company_license_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('license_installment_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->decimal('amount', 10, 2);
            $table->timestamp('paid_at');
            $table->string('payment_method', 30)->default('efectivo'); // efectivo, transferencia, tarjeta, otro
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('recorded_by')->constrained($userTable);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_payments');
    }
};
