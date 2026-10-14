# Guía de actualización

## A 1.0.0 desde el módulo `app/Modules/Licensing` (farmacy)

El paquete conserva tablas, migraciones, rutas, nombres de comando, alias de middleware y vistas
(`licensing::`). Lo que cambia es el namespace, el esquema del config y el origen del código.

> **Producción.** No ejecutes `migrate:fresh` ni `db:wipe`. Estos pasos solo agregan columnas.

### 1. Instalar el paquete

Desarrollo: repositorio `path` + `composer require slyn9/laravel-licensing:@dev`.
Producción: repositorio VCS + `composer require slyn9/laravel-licensing:^1.0` (ver README, sección 1).

### 2. Quitar el provider viejo

Elimina `App\Modules\Licensing\Providers\LicensingServiceProvider` de `bootstrap/providers.php`.
El del paquete se registra solo. **No los dejes ambos**: duplican rutas, comandos y alias.

### 3. Reescribir imports

Reemplazo literal en tu código, seeders y tests:

```
App\Modules\Licensing\   →   Slyn9\Licensing\
```

Los subnamespaces se mantienen (`Models`, `Enums`, `Services`, `Exceptions`, `Concerns`,
`Database\Seeders`). Se elimina `Support\Tenancy`: usa `Slyn9\Licensing\Licensing` si necesitas leer la
config tipada.

### 4. Pasar el config al esquema v1

| Clave anterior | Clave v1 |
|---|---|
| (nuevo, obligatorio) | `product.code` |
| (nuevo) | `mode` = `standalone` |
| `tenant_model` / `tenant_name_attribute` / `tenant_foreign_key` | `tenant.model` / `tenant.name_attribute` / `tenant.foreign_key` (+ `tenant.table`) |
| (implícito) | `branches.enabled` = `true` |
| `branch_model` / `branch_name_attribute` / `branch_foreign_key` / `branch_tenant_key` / `unscoped_branch_scopes` | `branches.model` / `branches.name_attribute` / `branches.foreign_key` / `branches.tenant_key` / `branches.unscoped_scopes` |
| `user_model` / `unscoped_user_scopes` | `user.model` / `user.unscoped_scopes` |
| `user_tenant_key` / `user_branch_key` | `access.tenant_column` / `access.branch_column` (con `access.driver = 'column'`) |
| `superadmin_role` | `superadmin.role` (con `superadmin.driver = 'role'`) |
| `resolvers` | `resolvers` (igual) |
| `activity_logger` | `audit.legacy_logger` |
| `layout` / `auth_layout` / `login_route` / `banner_views` | `ui.layout` / `ui.auth_layout` / `ui.login_route` / `ui.banner_views` y `ui.blade = true` |
| `routes`, `schedule`, `missing_license`, `currency`, `payment_methods`, `trial_code`, `default_grace_days` | sin cambio |

La fusión ahora es recursiva: declara solo las subclaves que cambian.

### 5. Conservar el historial de auditoría

Si tu logger guarda `get_class($subject)` (como `ActivityLogService` de farmacy), los registros viejos
dicen `App\Modules\Licensing\Models\CompanyLicense`. Para que viejos y nuevos sigan siendo consultables
por el mismo `subject_type`:

```php
// AppServiceProvider::boot()
Relation::morphMap([
    'App\Modules\Licensing\Models\CompanyLicense' => \Slyn9\Licensing\Models\CompanyLicense::class,
    'App\Modules\Licensing\Models\LicenseType' => \Slyn9\Licensing\Models\LicenseType::class,
    'App\Modules\Licensing\Models\LicenseInstallment' => \Slyn9\Licensing\Models\LicenseInstallment::class,
    'App\Modules\Licensing\Models\LicensePayment' => \Slyn9\Licensing\Models\LicensePayment::class,
], merge: true);

// y en el logger: 'subject_type' => $subject->getMorphClass()
```

### 6. Verificar antes de migrar

```bash
php artisan migrate:status     # las 6 migraciones originales deben figurar como Ran
php artisan migrate --pretend  # debe proponer SOLO:
                               #   2026_10_13_100000_add_uuid_to_license_tables
                               #   2026_10_13_100001_add_currency_to_company_licenses_table
```

Si `--pretend` propone alguna migración original, **detente**: algún nombre de archivo no coincide.

### 7. Migrar y comprobar

```bash
php artisan migrate
php artisan licenses:repair         # simulación: debe terminar con 0 inconsistencias
php artisan licenses:sync --dry-run
```

La migración de `uuid` rellena cada tabla por lotes de 500; la de `currency` rellena con
`currency.code` al migrar (un `ADD COLUMN … DEFAULT`).

### 8. Cambios de comportamiento a tener en cuenta

- Un resolvedor (`resolvers.*`) que apunta a una función inexistente ahora lanza `LogicException`
  (antes dejaba pasar a todos en silencio).
- Con `product.code` ausente, `assign`, los eventos y el snapshot lanzan `LogicException`; el control de
  acceso no se ve afectado.
- Sin UI Blade (`ui.blade = false`) el middleware lanza `LicenseBlockedException` (402) en vez de
  devolver una vista.

## A 1.2.0 desde 1.1.0

**Un paso obligatorio: migrar.** Los modelos usan `SoftDeletes`; sin la columna `deleted_at`, las consultas a
las licencias fallan.

- Anfitrión con `migrations.enabled = true` (farmacy): `composer update slyn9/laravel-licensing` y
  `php artisan migrate`.
- Anfitrión con `migrations.enabled = false` (ContaPE, Air-System): `composer update slyn9/laravel-licensing`,
  `php artisan vendor:publish --tag=licensing-migrations` y copia **solo** las migraciones nuevas
  (`…add_soft_deletes_to_license_tables` y `…set_tenant_foreign_key_on_delete`; las demás ya están publicadas),
  y `php artisan migrate`. Si tu aplicación ya agregó `deleted_at` a las cuatro tablas por su cuenta, la migración
  es un no-op: no hace falta publicarla, pero el paquete la ve como existente.
- `tenant.on_delete` es opcional. Por defecto (`cascade`) nada cambia. Con `restrict` el `forceDelete` de un tenant
  con licencias se rechaza; el borrado lógico no se ve afectado.
- Si tu código usaba `CompanyLicense::query()` esperando ver filas borradas, usa `withTrashed()`.

### 1.2.1

Sin pasos. Corrige `set_tenant_foreign_key_on_delete` en SQLite con datos (ver CHANGELOG). Si ya migraste con 1.2.0
en MySQL/MariaDB/PostgreSQL no hay nada que rehacer.

## A 1.1.0 desde 1.0.0

Sin pasos obligatorios: las tres novedades (`messages.*`, `LicenseTypeCatalogSeeder`, `migrations.enabled`)
son aditivas y su valor por defecto es el comportamiento de 1.0.0. `composer update slyn9/laravel-licensing`
y listo.

- Para tratar de «tú» (u otro texto) en el rechazo del login, sobreescribe las claves `messages.*`.
- Para un anfitrión **nuevo** cuya tabla de tenants se crea después del 2026-05-25, usa
  `migrations.enabled = false` y `php artisan vendor:publish --tag=licensing-migrations` (README, sección 12).
  Un anfitrión que ya corrió las migraciones originales (farmacy) **no** debe hacerlo.

## Política de versiones

- **PATCH**: correcciones sin cambios de contrato.
- **MINOR**: funciones nuevas compatibles (claves de config nuevas con valor por defecto, eventos o
  campos opcionales nuevos, migraciones aditivas).
- **MAJOR**: cambios incompatibles en config, contratos, payload de eventos, `schema_version` del
  snapshot o migraciones no aditivas.

Las migraciones ya publicadas nunca se editan ni se renombran; los cambios de esquema llegan siempre
como migraciones nuevas y aditivas, con `down()`.
