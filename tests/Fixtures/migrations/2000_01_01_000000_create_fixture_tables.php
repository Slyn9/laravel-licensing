<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Esquema del anfitrión simulado para cada topología de prueba:
|   t1: empresa + sedes + usuario 1:1 (farmacy)
|   t2: empresa sin sedes + usuarios N:M + superadmin por atributo (ContaPE)
|   t3: tenant sin sedes + usuarios N:M + spatie teams (Air-System)
*/
return new class extends Migration
{
    public function up(): void
    {
        $topology = config('licensing_test.topology');

        Schema::create('users', function (Blueprint $table) use ($topology) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            if ($topology === 't1') {
                $table->unsignedBigInteger('company_id')->nullable();
                $table->unsignedBigInteger('sede_id')->nullable();
            }
            if ($topology === 't2') {
                $table->boolean('is_super_admin')->default(false);
            }
            $table->rememberToken();
            $table->timestamps();
        });

        if ($topology === 't3') {
            Schema::create('tenants', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
                $table->softDeletes();
            });
            Schema::create('tenant_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id');
                $table->foreignId('user_id');
            });
        } else {
            Schema::create('companies', function (Blueprint $table) {
                $table->id();
                $table->string('name')->nullable();
                $table->string('trade_name')->nullable();
                $table->timestamps();
            });
        }

        if ($topology === 't1') {
            Schema::create('branches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id');
                $table->string('name');
                $table->string('code')->nullable();
                $table->timestamps();
            });
        }

        if ($topology === 't2') {
            Schema::create('company_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id');
                $table->foreignId('user_id');
            });
        }

        if (in_array($topology, ['t1', 't3'], true)) {
            $stub = dirname((new ReflectionClass(\Spatie\Permission\PermissionServiceProvider::class))->getFileName(), 2)
                .'/database/migrations/create_permission_tables.php.stub';

            (include $stub)->up();
        }
    }

    public function down(): void {}
};
