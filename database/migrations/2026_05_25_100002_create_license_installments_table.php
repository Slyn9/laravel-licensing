<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_license_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedTinyInteger('installment_number');
            $table->decimal('amount_due', 10, 2);
            $table->date('due_date');
            $table->timestamp('paid_at')->nullable();

            $table->enum('status', ['pending', 'paid', 'overdue'])->default('pending');

            $table->timestamps();

            $table->index(['company_license_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_installments');
    }
};
