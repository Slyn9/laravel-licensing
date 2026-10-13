# slyn9/laravel-licensing

Licencias, cobranza y control de acceso por **tenant** (empresa/cliente) y, opcionalmente, por **sede**
para aplicaciones Laravel multi-tenant. Es el mismo motor de licencias que corre en producción en
farmacy, empaquetado para compartirlo entre productos.

- Alta unificada de licencias con cuotas, vencimiento y gracia.
- Una sola regla para decidir qué licencia manda (login y middleware).
- Cobranza consistente: pagos transaccionales con lock, tope de saldo, reparto por vencimiento.
- Estados calculados por fechas (`computeStatus`, función pura) y persistidos por `licenses:sync`.
- Bloqueo, desbloqueo, cancelación y renovación con motivo y rastro de auditoría.
- Política para tenants/sedes sin licencia (`allow` / `block`).
- Preparado para un panel central: identidad global (`uuid`), código de producto, modo de gestión,
  eventos de dominio y snapshot versionado. **No** incluye el panel central ni clientes HTTP.

| | |
|---|---|
| Paquete | `slyn9/laravel-licensing` · namespace `Slyn9\Licensing` |
| PHP / Laravel | PHP `^8.2` · `illuminate/*` `^12.0\|^13.0` |
| Base de datos | MySQL 8 / SQLite (los estados son `string(20)`, no `ENUM`) |
| Dependencias de runtime | solo `illuminate/*`. `spatie/laravel-permission` es opcional (solo `RoleSuperAdminCheck`) |
| UI | Núcleo sin vistas. UI Blade/Bootstrap 5 opcional (`ui.blade = true`) |

---

## 1. Instalación

### Desarrollo local (repositorio `path`)

```jsonc
// composer.json del anfitrión
"repositories": [
    { "type": "path", "url": "../packages/laravel-licensing", "options": { "symlink": true } }
]
```

```bash
composer require slyn9/laravel-licensing:@dev
```

### Producción (repositorio VCS)

```jsonc
"repositories": [
    { "type": "vcs", "url": "git@github.com:slyn9/laravel-licensing.git" }
]
```

```bash
composer require slyn9/laravel-licensing:^1.0
```

### Pasos comunes

1. El provider se registra solo (auto-discovery).
2. Publica el config y complétalo (sección 2):
   ```bash
   php artisan vendor:publish --tag=licensing-config
   ```
   Mínimo: `product.code`, `tenant.model`, `user.model`, `access`, `superadmin`, `resolvers.tenant`.
   **Decide `branches.enabled` antes del primer `migrate`.**
3. `php artisan migrate`
4. Carga los tipos de licencia (y un Trial a las sedes —o tenants, sin sedes— sin licencia):
   ```bash
   php artisan db:seed --class="Slyn9\Licensing\Database\Seeders\LicenseTypeSeeder"
   ```
   Si el anfitrión **ya tiene tenants reales** y decide él qué licencia recibe cada uno, usa en su lugar
   `Slyn9\Licensing\Database\Seeders\LicenseTypeCatalogSeeder`: carga solo los tipos y no asigna ninguna licencia
   (el seeder completo les asignaría un Trial de 30 días a todos).
5. Aplica `license.check` a tus rutas operativas **después** del middleware que fija el tenant/sede
   (sección 5) y llama a `denialMessageFor` en tu login.
6. Scheduler: un cron que ejecute `php artisan schedule:run` cada minuto (agenda `licenses:sync`).
7. Si importas cobranza antigua: `php artisan licenses:repair` (simulación) y luego `--apply`.

---

## 2. Configuración (`config/licensing.php`, esquema v1)

La fusión con los valores por defecto del paquete es **recursiva**: declara solo lo que cambia. Las
listas (`payment_methods`, `routes.middleware`, `ui.banner_views`, `branches.unscoped_scopes`,
`user.unscoped_scopes`) se reemplazan completas. Nada puede ser una closure (debe sobrevivir a
`config:cache`).

| Clave | Para qué sirve | Por defecto |
|---|---|---|
| `schema` | Versión del esquema del archivo de config. No cambiar | `1` |
| `product.code` | **Obligatorio.** Código del producto (`^[A-Z0-9_]{2,20}$`: `FARMACY`, `CONTAPE`, `AIRSYS`). Se valida al primer uso | `env(LICENSING_PRODUCT_CODE)` |
| `mode` | `standalone` (el producto gestiona sus licencias) o `managed` (reservado: las escrituras se rechazan) | `standalone` |
| `tenant.model` | **Obligatorio.** Modelo Eloquent del tenant | `null` |
| `tenant.table` | Tabla del tenant (destino de la FK en las migraciones) | `companies` |
| `tenant.foreign_key` | Columna de `company_licenses` hacia el tenant | `company_id` |
| `tenant.name_attribute` | Atributo que se muestra como nombre del tenant | `name` |
| `branches.enabled` | Activa las sedes como nivel de licencia (columna, resolución y UI) | `false` |
| `branches.model` | Modelo de sede (requerido con `enabled`) | `null` |
| `branches.table` | Tabla de sede; `null` = la del modelo | `null` |
| `branches.foreign_key` | Columna de `company_licenses` hacia la sede | `sede_id` |
| `branches.tenant_key` | Columna de la sede hacia su tenant; `null` = `tenant.foreign_key` | `null` |
| `branches.name_attribute` | Atributo que se muestra como nombre de la sede | `name` |
| `branches.unscoped_scopes` | Scopes globales a quitar al consultar sedes en administración | `[]` |
| `user.model` | **Obligatorio.** Modelo de usuario | `null` |
| `user.table` | Tabla de usuarios (destino de `license_payments.recorded_by`) | `users` |
| `user.unscoped_scopes` | Scopes globales a quitar al consultar usuarios | `[]` |
| `access.driver` | Cómo se liga usuario y tenant: `column` (1:1) o `relation` (N:M) | `column` |
| `access.tenant_column` | `driver=column`: columna del usuario hacia su tenant | `company_id` |
| `access.branch_column` | `driver=column`: columna del usuario hacia su sede (ignorada sin sedes) | `sede_id` |
| `access.relation` | `driver=relation`: método del usuario que devuelve sus tenants | `companies` |
| `superadmin.driver` | `role` (`hasRole`) o `attribute` | `role` |
| `superadmin.role` | `driver=role`: nombre del rol que nunca se bloquea | `superadmin` |
| `superadmin.team_id` | `driver=role` con spatie teams: team global bajo el que vive el rol (p. ej. `0`) | `null` |
| `superadmin.attribute` | `driver=attribute`: atributo booleano del usuario | `is_super_admin` |
| `resolvers.tenant` | Tenant activo del request: `null`, `'funcion_global'`, `[Clase::class,'metodo']`, `'session:CLAVE'` o clase que implementa `CurrentTenantResolver`. Pueden devolver `Model`, id o `null` | `null` |
| `resolvers.branch` | Ídem para la sede activa (ignorado sin sedes) | `null` |
| `audit.logger` | Clase que implementa `AuditLogger` | `null` |
| `audit.legacy_logger` | Clase con la firma histórica `log($action,$module,$description,$subject,$properties,$user)` | `null` |
| `bindings.tenant_resolver` | Sustituye `CurrentTenantResolver` por clase | `null` |
| `bindings.branch_resolver` | Sustituye `CurrentBranchResolver` por clase | `null` |
| `bindings.user_tenant_access` | Sustituye `UserTenantAccess` por clase | `null` |
| `bindings.super_admin_check` | Sustituye `SuperAdminCheck` por clase | `null` |
| `bindings.audit_logger` | Sustituye `AuditLogger` por clase | `null` |
| `bindings.tenant_identity` | Sustituye `TenantIdentity` por clase | `null` |
| `ui.blade` | Carga la UI Blade/Bootstrap opcional (vistas, rutas, controladores, banner) | `false` |
| `ui.layout` | Layout Blade del panel (`@yield('title')`, `@yield('content')`, `@stack('scripts')`) | `layouts.app` |
| `ui.auth_layout` | Layout de la página 402 | `layouts.auth` |
| `ui.login_route` | Ruta con nombre del botón "volver al inicio de sesión" | `login` |
| `ui.banner_views` | Vistas del anfitrión que reciben `$licenseWarning` | `[]` |
| `routes.enabled` | Registra las rutas del panel (solo con `ui.blade`) | `true` |
| `routes.prefix` | Prefijo de URL del panel | `admin` |
| `routes.name` | Prefijo de nombre de ruta: resultan `admin.licenses.*` | `admin.` |
| `routes.middleware` | Middleware del panel (protégelo: superadmin, auth...) | `['web','auth']` |
| `routes.branch_license_uri` | URI del alta desde la ficha de una sede | `companies/{company}/branches/{branch}/licenses` |
| `routes.branch_license_name` | Nombre de esa ruta | `companies.branches.licenses.store` |
| `schedule.enabled` | Agenda `licenses:sync` en el Schedule de la aplicación | `true` |
| `schedule.cron` | Expresión cron de `licenses:sync` | `0 0 * * *` |
| `missing_license` | `allow`: sin licencia hay acceso libre. `block`: 402 y rechazo en el login. Cualquier otro valor = `allow` | `allow` |
| `currency.code` | Moneda que se guarda en cada licencia al crearla (C6) | `PEN` |
| `currency.symbol` | Símbolo con el que se muestran los importes | `S/` |
| `payment_methods` | Métodos de pago aceptados (`clave => etiqueta`) | efectivo, transferencia, tarjeta, otro |
| `messages.denied_many` | Mensaje del login cuando ninguno de sus tenants permite acceso y los motivos son distintos | «Ninguna de sus empresas tiene una licencia vigente. Contacte al administrador.» |
| `messages.denied_missing_tenant` | Mensaje del login: el tenant no tiene licencia (`missing_license = block`) | «No hay una licencia asignada para su empresa. Contacte al administrador.» |
| `messages.denied_missing_branch` | Ídem con sedes activadas | «No hay una licencia asignada para su sede. Contacte al administrador.» |
| `messages.denied_blocked` | Mensaje del login: licencia bloqueada | «El acceso ha sido bloqueado por el administrador. Contacte a soporte.» |
| `messages.denied_expired` | Mensaje del login: licencia vencida | «La licencia del sistema ha vencido. Contacte al administrador para renovarla.» |
| `migrations.enabled` | `true`: el paquete carga sus migraciones. `false`: no las carga; se publican con `--tag=licensing-migrations` (ver sección 12) | `true` |
| `trial_code` | Código del tipo de licencia de prueba | `TRIAL` |
| `default_grace_days` | Días de gracia si el tipo no define los suyos | `7` |

**Nombres de tabla fijos.** Las tablas del paquete son `license_types`, `company_licenses`,
`license_installments` y `license_payments` (contrato con los datos existentes). Lo que se configura
es la tabla/columna del tenant, de la sede y de los usuarios a las que apuntan sus llaves foráneas.

### Cableado típico por topología

| | Usuario 1:1 + sedes + rol (farmacy) | N:M + sin sedes + atributo (ContaPE) | N:M + sin sedes + rol con spatie teams (Air-System) |
|---|---|---|---|
| `tenant` | `Company` / `companies` / `company_id` | `Company` / `companies` / `company_id` | `Tenant` / `tenants` / `tenant_id` |
| `branches.enabled` | `true` | `false` | `false` |
| `access.driver` | `column` | `relation` (`companies`) | `relation` (`tenants`) |
| `superadmin` | `role` = `superadmin` | `attribute` = `is_super_admin` | `role` = `super-admin`, `team_id` = `0` |
| `resolvers.tenant` | `'tenant'` (helper) | `[CurrentCompany::class,'id']` | `[TenantService::class,'currentOrNull']` |
| `audit` | `legacy_logger` | `AuditLogger` propio | `AuditLogger` propio |
| `ui.blade` | `true` | `false` (Inertia/React) | `false` (Livewire) |

---

## 3. Contratos

Todos viven en `Slyn9\Licensing\Contracts`. Cada uno se resuelve del contenedor en cada uso; se
sustituyen por clase con `bindings.*`. Una configuración inválida (driver desconocido, resolvedor
que no existe) lanza `LogicException` de inmediato: **nunca deja pasar en silencio**.

| Contrato | Responsabilidad | Implementaciones incluidas | Por defecto |
|---|---|---|---|
| `CurrentTenantResolver` | Tenant activo del request | `CallableTenantResolver`, `SessionTenantResolver`, `NullTenantResolver` | `NullTenantResolver` (se usa el tenant del usuario) |
| `CurrentBranchResolver` | Sede activa del request (opcional) | `CallableBranchResolver`, `NullBranchResolver` | `NullBranchResolver` (siempre sin sedes) |
| `UserTenantAccess` | Tenants (y sede) del usuario | `ColumnUserTenantAccess` (1:1), `RelationUserTenantAccess` (N:M) | `column` |
| `SuperAdminCheck` | Quién nunca se bloquea | `RoleSuperAdminCheck`, `AttributeSuperAdminCheck` | `role` |
| `AuditLogger` | Rastro de auditoría local | `NullAuditLogger`, `LegacyActivityLogger` | `NullAuditLogger` |
| `TenantIdentity` | Referencia estable del tenant fuera del producto | `DefaultTenantIdentity` | `"{PRODUCT}:{pk}"` |

```php
interface CurrentTenantResolver { public function tenant(): ?Model; }
interface CurrentBranchResolver { public function branch(): ?Model; }
interface UserTenantAccess {
    public function tenantsFor(Authenticatable $user): Collection;       // todos sus tenants
    public function defaultTenantFor(Authenticatable $user): ?Model;     // el único; null si hay varios
    public function branchFor(Authenticatable $user, Model $tenant): ?Model;
}
interface SuperAdminCheck { public function isSuperAdmin(Authenticatable $user): bool; }
interface AuditLogger {
    public function log(string $action, string $description, Model $license, array $properties, ?Model $by): void;
}
interface TenantIdentity {
    public function externalRef(Model $tenant): string;   // "{PRODUCT}:{pk}"
    public function branchRef(Model $branch): string;     // "{PRODUCT}:branch:{pk}"
}
```

Notas:

- **`RoleSuperAdminCheck` con spatie teams.** Con `superadmin.team_id` fija el team global mientras
  evalúa y luego restaura el team y las relaciones `roles`/`permissions` cacheadas del usuario. spatie
  solo se toca si la clase existe.
- **`AuditLogger`** recibe `license_id` y las llaves de tenant/sede dentro de `$properties`, además de
  `reason`, `previous_status` y `new_status` en los cambios de estado. Acciones: `created`,
  `payment_registered`, `blocked`, `unblocked`, `cancelled`, `renewed`, `repaired`.
- **`RelationUserTenantAccess`** devuelve `null` en `branchFor`: con N:M y sedes por usuario escribe un
  `UserTenantAccess` propio y regístralo en `bindings.user_tenant_access`.

---

## 4. Núcleo y UI

**Núcleo (siempre cargado, sin vistas):** contratos, modelos, enum, excepciones, eventos,
`LicenseService`, `LicenseRules`, middleware `license.check`, comandos y seeder.

**UI Blade/Bootstrap (opcional, `ui.blade = true`):** controladores, rutas `admin.licenses.*`, vistas
`licensing::*` (publicables con `--tag=licensing-views`) y banner. Con `false` el paquete no carga
vistas, rutas ni controladores.

### Respuesta del middleware sin UI Blade

Cuando el acceso se deniega y `ui.blade = false`, `license.check` lanza
`Slyn9\Licensing\Exceptions\LicenseBlockedException` (HTTP 402) con `access`, `tenant` y `branch`.
Renderízala en el handler del anfitrión. Además deja el `LicenseAccess` en
`$request->attributes->get('licensing.access')` para mostrar avisos de gracia/prueba.

---

## 5. Reglas de acceso

| Contexto | Regla |
|---|---|
| Superadmin | Siempre entra |
| Rutas de sede (con sedes) | Manda la licencia de la sede de la ruta; si no tiene, la de nivel tenant |
| Sin sede | Entra si **al menos una** licencia no cancelada del tenant permite acceso |
| Login | Entra si **al menos uno** de los tenants del usuario permite acceso |
| Middleware | El bloqueo real se aplica sobre el tenant **activo** |
| Sin tenant activo | Usuario con un único tenant → se evalúa ese. Con varios → pasa (lo corta el middleware del anfitrión) |
| Varias licencias | Manda la de mayor `id` no cancelada |
| Estados | `trial`, `active` y `grace` permiten acceso; `expired` y `blocked` bloquean |

El control de acceso (`computeStatus`, middleware, login) **nunca** hace llamadas de red: solo lee
las tablas locales.

### Login

```php
$message = app(\Slyn9\Licensing\Services\LicenseService::class)->denialMessageFor(Auth::user());

if ($message) {
    Auth::logout();
    throw ValidationException::withMessages(['email' => $message]);
}
```

### Cambio de tenant (hook del anfitrión)

```php
if (! app(LicenseService::class)->canEnter($user, $tenant)) {
    // rechazar el cambio: licencia vencida/bloqueada en ese tenant
}
```

`canEnter` evalúa **solo licencia**; verificar que el usuario pertenece al tenant sigue siendo del
anfitrión.

### Middleware: dónde colocarlo

Aplica `license.check` **después** del middleware que fija el tenant (y la sede) del request:

| Anfitrión | Después de |
|---|---|
| farmacy | `tenant` (`SetTenantContext`) |
| ContaPE | `EnsureCompanyContext` y `EnsureActiveCompany` |
| Air-System | `SetActiveTenant` y `EnsureTenantIsSelected` |

---

## 6. API PHP (`LicenseService`)

```php
// Lectura (nunca toca la red)
resolveFor(Model $tenant, ?Model $branch = null): ?CompanyLicense
accessFor(Model $tenant, ?Model $branch = null): LicenseAccess      // ->license ->status ->allowed shouldWarn() isMissing()
canEnter(Authenticatable $user, Model $tenant): bool
denialMessageFor(Authenticatable $user): ?string
latestPerTenant(): Collection        // última licencia no cancelada por tenant (índice por id de tenant)
licensesOf(Model $tenant): Collection // licencias con tipo, cuotas, pagos y sede

// Escritura (gateada por modo: managed → ManagedModeException)
assign(Model $tenant, ?Model $branch, LicenseType $type, array $data = [], Model|int|null $by = null): CompanyLicense
assignTrial(Model $tenant, ?Model $branch = null, ?string $notes = null): ?CompanyLicense
registerPayment(CompanyLicense $license, float|int|string $amount, array $data, Model|int $by): LicensePayment
block(CompanyLicense $license, Model $by, string $reason): CompanyLicense
unblock(CompanyLicense $license, Model $by, string $reason, array $options = []): CompanyLicense
cancel(CompanyLicense $license, Model $by, string $reason): CompanyLicense
renew(CompanyLicense $license, ?Model $by = null, array $data = []): CompanyLicense

// Mantenimiento
syncAll(bool $dryRun = false): array
reconcile(CompanyLicense $license, bool $apply = false): array
inconsistencies(): array
```

Los errores de regla de negocio son `Slyn9\Licensing\Exceptions\LicenseException` con la propiedad
`field` (campo del formulario que debe mostrar el mensaje). `Slyn9\Licensing\Rules\LicenseRules`
expone las reglas de validación por operación (`assign($tenant)`, `payment($license)`, `reason()`,
`unblock()`, `renew()`).

Datos de `assign`: `starts_at`, `expires_at`, `total_amount`, `installments_count`, `notes`.
Datos de `registerPayment`: `paid_at`, `payment_method`, `reference`, `notes`, `license_installment_id`.
Opciones de `unblock`: `expires_at`, `renew`, `total_amount`, `installments_count`.

Relaciones opcionales en tus modelos: `Slyn9\Licensing\Concerns\TenantHasLicenses`
(`licenses()`, `activeLicense()`) y `BranchHasLicenses` (solo con sedes).

---

## 7. Guía de UI por pantalla

Las mismas llamadas sirven para Blade, Inertia/React y Livewire; solo cambia la capa de vista.

| Pantalla | Qué mostrar | Métodos |
|---|---|---|
| Listado de tenants | Tenant + licencia vigente + saldo | `Licensing::tenantQuery()`, `latestPerTenant()` |
| Ficha del tenant | Licencias, cuotas, pagos | `licensesOf($tenant)` |
| Alta de licencia | Formulario + tipos activos | `LicenseRules::assign($tenant)`, `assign(...)` |
| Registrar pago | Importe, fecha, método, cuota | `LicenseRules::payment($license)`, `registerPayment(...)` |
| Bloquear / cancelar | Motivo obligatorio | `LicenseRules::reason()`, `block(...)`, `cancel(...)` |
| Reactivar | Motivo + nueva fecha o renovar | `LicenseRules::unblock()`, `unblock(...)` |
| Renovar | Importe y cuotas | `LicenseRules::renew()`, `renew(...)` |
| Página 402 | Estado, vencimiento, saldo | `LicenseBlockedException::$access` |
| Banner | Gracia o prueba | `accessFor(...)->shouldWarn()` |

Oculta las acciones de escritura con `Licensing::allowsLocalManagement()` (en modo `managed` el
servicio las rechaza igualmente).

### Blade (`ui.blade = true`)

Incluye el banner en tu vista y agrega la vista a `ui.banner_views`:

```blade
@include('licensing::partials.banner')
```

### Inertia / React (ContaPE)

```php
class LicenseController extends Controller
{
    public function index(LicenseService $licenses)
    {
        return Inertia::render('admin/licenses/index', [
            'tenants' => Licensing::tenantQuery()->orderBy('legal_name')->get(['id', 'legal_name']),
            'latest' => $licenses->latestPerTenant()->map->toSnapshot(),   // o los campos que necesites
            'canManage' => Licensing::allowsLocalManagement(),
        ]);
    }

    public function block(Request $request, CompanyLicense $license, LicenseService $licenses)
    {
        $data = $request->validate(LicenseRules::reason());

        try {
            $licenses->block($license, $request->user(), $data['reason']);
        } catch (LicenseException $e) {
            return back()->withErrors([$e->field => $e->getMessage()]);
        }

        return back();
    }
}
```

Página 402 con el patrón de error de Inertia:

```php
// bootstrap/app.php
$exceptions->render(function (LicenseBlockedException $e, Request $request) {
    return Inertia::render('errors/license-blocked', [
        'status' => $e->access->status?->value,
        'expiresAt' => $e->access->license?->expires_at?->toDateString(),
        'pending' => $e->access->license?->pending_amount,
    ])->toResponse($request)->setStatusCode(402);
});
```

Aviso de gracia/prueba en `HandleInertiaRequests::share`:

```php
'licenseWarning' => fn () => ($a = $request->attributes->get('licensing.access'))?->shouldWarn()
    ? ['status' => $a->status->value, 'expiresAt' => $a->license->expires_at?->toDateString()]
    : null,
```

### Livewire (Air-System)

```php
class LicenseTable extends Component
{
    public function render(LicenseService $licenses)
    {
        return view('livewire.license-table', [
            'licenses' => $licenses->licensesOf(app(TenantService::class)->current()),
            'canManage' => Licensing::allowsLocalManagement(),
        ]);
    }

    public function block(int $licenseId, string $reason, LicenseService $licenses): void
    {
        $this->validate(['reason' => LicenseRules::reason()['reason']]);

        try {
            $licenses->block(CompanyLicense::findOrFail($licenseId), auth()->user(), $reason);
        } catch (LicenseException $e) {
            $this->addError($e->field, $e->getMessage());
        }
    }
}
```

Usa los tokens de tu sistema de diseño (el paquete no impone Bootstrap en el núcleo).

---

## 8. Modo de gestión (`mode`)

| Modo | Escrituras comerciales | Control de acceso |
|---|---|---|
| `standalone` | Permitidas | Lee tablas locales |
| `managed` | `assign`, `registerPayment`, `renew`, `block`, `unblock`, `cancel` y `reconcile(apply)` lanzan `ManagedModeException` (hereda de `LicenseException`) **antes** de tocar la BD. `assignTrial` devuelve `null` sin escribir | Igual: lee tablas locales, sin red |

`licenses:sync` no es una operación comercial (solo persiste el estado derivado de fechas) y sigue
corriendo en `managed`. Un valor de `mode` desconocido nunca abre las escrituras.

---

## 9. Eventos de dominio

Namespace `Slyn9\Licensing\Events`. Se despachan **después del commit**: sin transacción, de
inmediato; con rollback, nunca; dentro de una transacción externa del anfitrión, al confirmarla. El
payload solo contiene datos serializables (nunca modelos). `toArray()` devuelve el payload plano.

Campos comunes: `event_id` (uuid; idempotencia), `event`, `occurred_at` (ISO 8601), `product`,
`license_uuid`, `tenant_ref` (`TenantIdentity::externalRef`), `branch_ref` (`null` sin sede),
`previous_status` (`null` al crear), `new_status`.

| Evento (`event`) | Campos propios | Se dispara en |
|---|---|---|
| `LicenseAssigned` (`license.assigned`) | `license_type_code`, `starts_at`, `expires_at`, `total_amount`, `currency`, `installments_count` | `assign`, `assignTrial` |
| `LicensePaymentRegistered` (`license.payment_registered`) | `payment_uuid`, `installment_uuid`, `amount`, `currency`, `paid_at`, `payment_method`, `license_paid_amount`, `license_pending_amount` | `registerPayment` |
| `LicenseRenewed` (`license.renewed`) | `previous_expires_at`, `new_expires_at`, `added_amount`, `new_total_amount`, `installments_created` | `renew`, `unblock` con renovación |
| `LicenseBlocked` (`license.blocked`) | `reason` | `block` |
| `LicenseUnblocked` (`license.unblocked`) | `reason`, `new_expires_at`, `renewed` | `unblock` |
| `LicenseCancelled` (`license.cancelled`) | `reason` | `cancel` |
| `LicenseStatusChanged` (`license.status_changed`) | `source` (`sync`) | `licenses:sync` (no en `--dry-run`) |

`LicenseStatusChanged` solo sale de `licenses:sync`; los demás eventos ya llevan
`previous_status`/`new_status`. Un `unblock` con renovación emite `LicenseUnblocked` y
`LicenseRenewed`. Los importes son strings con 2 decimales.

```php
Event::listen(LicenseBlocked::class, fn (LicenseBlocked $e) => Log::info('license.blocked', $e->toArray()));
```

---

## 10. Snapshot v1 (contrato público)

`CompanyLicense::toSnapshot(): array` devuelve una representación versionada de la licencia con sus
cuotas y pagos, identificados por `uuid`. **Este esquema es un contrato público.** Su
JSON Schema vive en el paquete: `resources/schemas/license-snapshot-v1.json`.

```json
{
  "schema_version": 1,
  "generated_at": "2026-10-12T10:00:00-05:00",
  "product": "FARMACY",
  "tenant_ref": "FARMACY:12",
  "scope": { "level": "branch", "branch_ref": "FARMACY:branch:7" },
  "license": {
    "uuid": "0192f1c2-7d4e-7a31-9f8e-3a1b2c4d5e6f",
    "type": { "uuid": "0192f1c2-0000-7000-8000-000000000001", "code": "MON", "name": "Mensual", "duration_days": 30, "grace_period_days": 5 },
    "status": "active",
    "effective_status": "grace",
    "starts_at": "2026-09-12T00:00:00-05:00",
    "expires_at": "2026-10-12T00:00:00-05:00",
    "grace_until": "2026-10-17T00:00:00-05:00",
    "currency": "PEN",
    "total_amount": "99.00",
    "paid_amount": "0.00",
    "pending_amount": "99.00",
    "installments_count": 1,
    "notes": null,
    "created_at": "2026-09-12T08:00:00-05:00",
    "updated_at": "2026-09-12T08:00:00-05:00"
  },
  "installments": [
    { "uuid": "…", "number": 1, "amount_due": "99.00", "paid_amount": "0.00", "due_date": "2026-09-12", "paid_at": null, "status": "overdue" }
  ],
  "payments": [
    { "uuid": "…", "installment_uuid": "…", "amount": "20.00", "paid_at": "2026-10-01T10:00:00-05:00", "method": "efectivo", "reference": null, "notes": null }
  ]
}
```

| Campo | Significado |
|---|---|
| `schema_version` | Versión mayor del esquema (entero). Hoy `1` |
| `product` | `licensing.product.code` |
| `tenant_ref` | `TenantIdentity::externalRef($tenant)` |
| `scope` | `level` = `tenant` o `branch`; `branch_ref` solo con sede |
| `license.status` | Estado **persistido** |
| `license.effective_status` | Estado **calculado por fechas** al generar el snapshot |
| importes | Siempre string con 2 decimales |
| fechas | ISO 8601; `due_date` es `YYYY-MM-DD` |
| `payments[].method` | Clave de `payment_methods` |

**Reglas de evolución.** Agregar claves opcionales mantiene `schema_version`; los consumidores deben
ignorar las claves que no conozcan. Renombrar o eliminar una clave, o cambiar un tipo, incrementa
`schema_version`. Por diseño no se incluyen `recorded_by` ni datos personales ni ids locales.

> El paquete **no** implementa la importación de snapshots.

---

## 11. Comandos

| Comando | Qué hace |
|---|---|
| `licenses:sync [--dry-run]` | Persiste el estado calculado y marca cuotas vencidas. Idempotente. Se agenda solo (`schedule.*`) |
| `licenses:repair [--apply] [--company=ID]` | Reconcilia `paid_amount` y la cobertura de cuotas con los pagos. **Simula por defecto**; imprime 3 contadores que deben quedar en 0 |

El acceso **no** depende del cron: el middleware calcula por fechas. Sí dependen el marcado de cuotas
vencidas y el estado persistido que muestran los reportes.

---

## 12. Migraciones

| Migración | Qué hace |
|---|---|
| `2026_05_25_100000_create_license_types_table` | Tipos de licencia |
| `2026_05_25_100001_create_company_licenses_table` | Licencias (FK a `tenant.table`/`tenant.foreign_key`) |
| `2026_05_25_100002_create_license_installments_table` | Cuotas |
| `2026_05_25_100003_create_license_payments_table` | Pagos (`recorded_by` → `user.table`) |
| `2026_05_26_100002_add_sede_id_to_company_licenses_table` | Columna de sede. **No-op con `branches.enabled = false`** |
| `2026_10_12_100000_convert_license_statuses_to_string` | Estados a `string(20)` y `paid_amount` por cuota |
| `2026_10_13_100000_add_uuid_to_license_tables` | C1: `uuid` único en las 4 tablas, con relleno |
| `2026_10_13_100001_add_currency_to_company_licenses_table` | C6: `currency` por licencia, con relleno |

Los nombres de las seis primeras no se pueden cambiar: instalaciones existentes las tienen
registradas como ejecutadas. Todas tienen `down()`.

**Anfitrión cuya tabla de tenants se crea después del 2026-05-25.** Las migraciones del paquete llevan esa
fecha: en una instalación desde cero correrían antes de que exista `companies` y su llave foránea fallaría
(MySQL). Con `migrations.enabled = false` el paquete no las carga; publícalas y migra:

```bash
php artisan vendor:publish --tag=licensing-migrations   # las copia con la fecha de hoy, en el mismo orden
php artisan migrate
```

Al actualizar el paquete, vuelve a publicar solo las migraciones nuevas (las ya publicadas no se tocan).

**Activar las sedes después de migrar.** Con `branches.enabled = false` la migración de sede no hace
nada y queda registrada como ejecutada. Para activarlas más adelante **no revierta migraciones**: ponga
`branches.enabled = true` y agregue en su aplicación una migración aditiva equivalente:

```php
Schema::table('company_licenses', function (Blueprint $table) {
    $table->foreignId('sede_id')->nullable()->after('company_id')
        ->constrained('branches')->nullOnDelete();
    $table->index(['company_id', 'sede_id', 'status']);
});
```

Use los nombres de columna y tabla de su config (`branches.foreign_key`, `tenant.foreign_key`,
`branches.table`).
---

## 13. Pruebas del paquete

```bash
composer test
```

Orchestra Testbench con modelos de prueba en tres topologías, que ejecutan los mismos comportamientos:

| | Topología | Usuario↔tenant | Sedes | Superadmin |
|---|---|---|---|---|
| T1 | farmacy | columna 1:1 | sí | rol |
| T2 | ContaPE | N:M por relación + sesión | no | atributo |
| T3 | Air-System | N:M por relación | no | rol con spatie teams (team `0`) |

---

## 14. Limitaciones conocidas

- **PKs enteras.** Las llaves hacia tenant, sede y usuario son `foreignId` (bigint). Tenants con UUID
  como PK no están soportados en v1.
- **Moneda.** `currency` se guarda por licencia, pero la UI muestra `currency.symbol` de la config.
  Si cambias el código de moneda, las licencias antiguas conservan el suyo.
- **`uuid` nullable en BD.** El modelo y la migración lo garantizan; MySQL permite varios `NULL` en el
  índice único. `toSnapshot()` repara filas insertadas sin Eloquent.
- **Concurrencia.** Pagos y cambios de estado usan `lockForUpdate`; SQLite lo ignora, así que la
  exclusión solo se verifica en MySQL.
- **Idioma.** Los textos del servicio y de las vistas están en español.
- **Sin panel central.** Ni clientes HTTP, ni endpoints de sincronización, ni importación de snapshots.
