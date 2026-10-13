<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 10)->unique();   // PERM, ANN, MON, TRIAL
            $table->unsignedSmallInteger('duration_days')->nullable(); // null = permanente
            $table->decimal('base_price', 10, 2)->default(0);
            $table->unsignedTinyInteger('grace_period_days')->default(7);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_types');
    }
};
