<?php

/*
|--------------------------------------------------------------------------
| Licensing — configuración v1 (valores por defecto del paquete)
|--------------------------------------------------------------------------
| El anfitrión sobreescribe estas claves en su propio config/licensing.php
| (php artisan vendor:publish --tag=licensing-config). La fusión es RECURSIVA:
| basta con declarar las subclaves que cambian. Las listas (payment_methods,
| routes.middleware, ui.banner_views, *.unscoped_scopes) se reemplazan completas.
|
| Nada aquí puede ser una closure: debe sobrevivir a `config:cache`.
*/

return [

    // Versión del esquema de ESTE archivo (no cambiar).
    'schema' => 1,

    /*
    |--------------------------------------------------------------------------
    | Producto (C2) — OBLIGATORIO
    |--------------------------------------------------------------------------
    | Identifica al producto frente a otros sistemas (p. ej. FARMACY, CONTAPE,
    | AIRSYS). Patrón: ^[A-Z0-9_]{2,20}$. Se valida al primer uso, no en boot.
    */
    'product' => [
        'code' => env('LICENSING_PRODUCT_CODE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Modo de gestión (C3)
    |--------------------------------------------------------------------------
    | standalone → este producto gestiona sus licencias (único modo implementado).
    | managed    → reservado: las escrituras comerciales se rechazan con
    |              ManagedModeException; el control de acceso sigue leyendo las
    |              tablas locales (nunca hace llamadas de red).
    */
    'mode' => env('LICENSING_MODE', 'standalone'),

    /*
    |--------------------------------------------------------------------------
    | Tenant (empresa / cliente)
    |--------------------------------------------------------------------------
    */
    'tenant' => [
        'model' => null,                // OBLIGATORIO: modelo Eloquent del tenant
        'table' => 'companies',         // tabla a la que apunta la FK de company_licenses
        'foreign_key' => 'company_id',  // columna en company_licenses
        'name_attribute' => 'name',     // atributo que se muestra como nombre
    ],

    /*
    |--------------------------------------------------------------------------
    | Sedes (característica opcional)
    |--------------------------------------------------------------------------
    | Con enabled=false no se crea la columna de sede y no hay resolución por sede.
    | Decídelo ANTES del primer `migrate`.
    */
    'branches' => [
        'enabled' => false,
        'model' => null,                // requerido si enabled
        'table' => null,                // null = tabla del modelo
        'foreign_key' => 'sede_id',     // columna en company_licenses
        'tenant_key' => null,           // columna de la sede hacia su tenant (null = tenant.foreign_key)
        'name_attribute' => 'name',
        'unscoped_scopes' => [],        // scopes globales a quitar al consultar sedes en administración
    ],

    /*
    |--------------------------------------------------------------------------
    | Usuario
    |--------------------------------------------------------------------------
    */
    'user' => [
        'model' => null,                // OBLIGATORIO
        'table' => 'users',             // destino de license_payments.recorded_by
        'unscoped_scopes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Relación usuario → tenant (contrato UserTenantAccess)
    |--------------------------------------------------------------------------
    */
    'access' => [
        'driver' => 'column',           // column (1:1) | relation (N:M)
        'tenant_column' => 'company_id',// driver=column: columna del usuario hacia su tenant
        'branch_column' => 'sede_id',   // driver=column: columna del usuario hacia su sede (ignorada sin sedes)
        'relation' => 'companies',      // driver=relation: método del usuario que devuelve sus tenants
    ],

    /*
    |--------------------------------------------------------------------------
    | Superadmin (contrato SuperAdminCheck) — nunca se bloquea
    |--------------------------------------------------------------------------
    */
    'superadmin' => [
        'driver' => 'role',             // role | attribute
        'role' => 'superadmin',         // driver=role (usa hasRole; spatie solo si existe la clase)
        'team_id' => null,              // driver=role con spatie teams: team global (p. ej. 0)
        'attribute' => 'is_super_admin',// driver=attribute
    ],

    /*
    |--------------------------------------------------------------------------
    | Contexto del request (contratos CurrentTenantResolver / CurrentBranchResolver)
    |--------------------------------------------------------------------------
    | Valores admitidos: null | 'funcion_global' | [Clase::class, 'metodo']
    | | 'session:CLAVE' | Clase::class (implementa el contrato).
    | Pueden devolver Model, id o null; un método no estático se resuelve del contenedor.
    */
    'resolvers' => [
        'tenant' => null,
        'branch' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Auditoría (contrato AuditLogger) — nula por defecto
    |--------------------------------------------------------------------------
    | legacy_logger: clase con log($action,$module,$description,$subject,$properties,$user).
    */
    'audit' => [
        'logger' => null,
        'legacy_logger' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Sustitución de contratos por clase (sin closures)
    |--------------------------------------------------------------------------
    */
    'bindings' => [
        'tenant_resolver' => null,
        'branch_resolver' => null,
        'user_tenant_access' => null,
        'super_admin_check' => null,
        'audit_logger' => null,
        'tenant_identity' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | UI Blade/Bootstrap opcional
    |--------------------------------------------------------------------------
    | blade=false → el núcleo no carga vistas, rutas ni controladores; la UI la
    | construye el anfitrión sobre LicenseService (Inertia, Livewire, API...).
    */
    'ui' => [
        'blade' => false,
        'layout' => 'layouts.app',      // debe exponer @yield('title'), @yield('content'), @stack('scripts')
        'auth_layout' => 'layouts.auth',
        'login_route' => 'login',
        'banner_views' => [],           // vistas del anfitrión que reciben $licenseWarning
    ],

    // Solo con ui.blade = true. Los nombres admin.licenses.* son contrato con el anfitrión.
    'routes' => [
        'enabled' => true,
        'prefix' => 'admin',
        'name' => 'admin.',
        'middleware' => ['web', 'auth'],
        'branch_license_uri' => 'companies/{company}/branches/{branch}/licenses',
        'branch_license_name' => 'companies.branches.licenses.store',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sincronización programada (licenses:sync)
    |--------------------------------------------------------------------------
    | Se agenda en el Schedule de la aplicación. Requiere `schedule:run` cada minuto.
    */
    'schedule' => [
        'enabled' => true,
        'cron' => '0 0 * * *',
    ],

    /*
    |--------------------------------------------------------------------------
    | Reglas de negocio
    |--------------------------------------------------------------------------
    */

    // allow → sin licencia hay acceso libre | block → 402 y rechazo en el login.
    'missing_license' => env('LICENSING_MISSING_LICENSE', 'allow'),

    // El código se guarda en cada licencia al crearla (C6); el símbolo es solo de presentación.
    'currency' => [
        'code' => env('LICENSING_CURRENCY_CODE', 'PEN'),
        'symbol' => env('LICENSING_CURRENCY_SYMBOL', 'S/'),
    ],

    'payment_methods' => [
        'efectivo' => 'Efectivo',
        'transferencia' => 'Transferencia',
        'tarjeta' => 'Tarjeta',
        'otro' => 'Otro',
    ],

    // Código del tipo de prueba y días de gracia si el tipo no define los suyos.
    'trial_code' => env('LICENSING_TRIAL_CODE', 'TRIAL'),
    'default_grace_days' => (int) env('LICENSING_DEFAULT_GRACE_DAYS', 7),

];
