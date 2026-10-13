# Changelog

Todos los cambios relevantes del paquete. Formato basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.1.0/)
y versionado semántico.

## [1.2.0] — 2026-10-13

Pedido por Air-System al instalar el paquete en producción. **Requiere una migración** (`deleted_at`): ver
UPGRADING. Sin la columna, las consultas a las licencias fallan hasta migrar.

### Agregado

- **Borrado lógico**: `CompanyLicense`, `LicenseType`, `LicenseInstallment` y `LicensePayment` usan `SoftDeletes`.
  Migración aditiva `2026_10_13_100002_add_soft_deletes_to_license_tables` (con guarda: no-op si el anfitrión ya
  tiene la columna). El paquete sigue sin borrar licencias: se cancelan.
- **`tenant.on_delete`** (`cascade` | `restrict`, por defecto `cascade`): con `restrict`, la migración
  `2026_10_13_100003_set_tenant_foreign_key_on_delete` reemplaza el `ON DELETE CASCADE` de la llave foránea hacia el
  tenant por `RESTRICT`: un tenant con licencias no se puede borrar de verdad. Con el valor por defecto es un no-op.

### Cambiado

- `LicenseService::latestPerTenant()` omite los tenants eliminados lógicamente cuando el modelo de tenant usa
  `SoftDeletes` (su licencia permanece en la base, no aparece en las pantallas de administración).
- `LicenseTypeCatalogSeeder` no recrea un tipo eliminado lógicamente (su `code` es único).

## [1.1.0] — 2026-10-13

Cambios aditivos pedidos por ContaPE al instalar el paquete. Sin cambios de contrato, de esquema ni de
comportamiento por defecto.

### Agregado

- **`messages.*`** en el config: los 5 textos que `denialMessageFor` le muestra al usuario final
  (`denied_many`, `denied_missing_tenant`, `denied_missing_branch`, `denied_blocked`, `denied_expired`) son
  configurables. Por defecto conservan el texto de 1.0.0.
- **`LicenseTypeCatalogSeeder`**: carga solo el catálogo de tipos, sin asignar licencias. `LicenseTypeSeeder`
  lo reutiliza y conserva su comportamiento (catálogo + Trial a tenants sin licencia).
- **`migrations.enabled`** (por defecto `true`) y la etiqueta de publicación **`licensing-migrations`**: un
  anfitrión cuya tabla de tenants se crea después del 2026-05-25 puede publicar las migraciones con la fecha de
  hoy en vez de cargarlas, y así la llave foránea a `companies` existe cuando corren.

## [1.0.0] — 2026-10-12

Primera versión publicada. Extrae a un paquete Composer el módulo de licencias que corre en producción
en farmacy (`app/Modules/Licensing`), sin cambiar sus reglas de negocio.

### Agregado

- **Paquete** `slyn9/laravel-licensing` (namespace `Slyn9\Licensing`, PHP `^8.2`, `illuminate/*` `^12.0|^13.0`),
  con auto-discovery del provider.
- **Contratos** en `Slyn9\Licensing\Contracts`, con implementación por defecto:
  `CurrentTenantResolver`, `CurrentBranchResolver`, `UserTenantAccess` (1:1 por columna y N:M por relación),
  `SuperAdminCheck` (por rol y por atributo), `AuditLogger` (nulo por defecto; adaptador de la firma histórica)
  y `TenantIdentity`.
- **Config v1** (`schema = 1`) con fusión recursiva; claves `product.code`, `mode`, `tenant.*`, `branches.*`,
  `user.*`, `access.*`, `superadmin.*`, `resolvers.*`, `audit.*`, `bindings.*`, `ui.*`.
- **Sedes opcionales** (`branches.enabled`): sin ellas no se crea la columna ni hay resolución por sede.
- **Regla de acceso con varios tenants**: el login entra si al menos un tenant permite acceso; el middleware
  bloquea sobre el tenant activo; `LicenseService::canEnter($user, $tenant)` como hook de cambio de tenant.
- **Núcleo sin vistas** y **UI Blade/Bootstrap opcional** (`ui.blade`). Con la UI apagada el middleware lanza
  `LicenseBlockedException` (402) y deja el `LicenseAccess` en `$request->attributes`.
- **API para UIs propias**: `LicenseService::latestPerTenant()`, `licensesOf()` y `Rules\LicenseRules`
  (extraídos de los controladores).
- **C1 — Identidad global**: columna `uuid` única y ordenada en `license_types`, `company_licenses`,
  `license_installments` y `license_payments` (migración aditiva con relleno).
- **C2 — Producto y tenant**: `product.code` obligatorio y `TenantIdentity::externalRef()`
  (`{PRODUCT}:{pk}`; `branchRef()` para sedes).
- **C3 — Modo de gestión**: `mode = standalone|managed`; en `managed` las escrituras comerciales lanzan
  `ManagedModeException` y el control de acceso sigue leyendo tablas locales (sin red).
- **C4 — Eventos de dominio** después del commit: `LicenseAssigned`, `LicensePaymentRegistered`,
  `LicenseRenewed`, `LicenseBlocked`, `LicenseUnblocked`, `LicenseCancelled`, `LicenseStatusChanged`.
- **C5 — Snapshot v1**: `CompanyLicense::toSnapshot()` y su JSON Schema
  (`resources/schemas/license-snapshot-v1.json`).
- **C6 — Moneda por licencia**: columna `currency` (migración aditiva con relleno), guardada al crear.
- Suite de pruebas con Orchestra Testbench en tres topologías (T1 farmacy, T2 ContaPE, T3 Air-System).

### Compatibilidad

- Las seis migraciones originales conservan **exactamente** sus nombres de archivo: una instalación
  existente las ve como ya ejecutadas. Solo se agregan dos migraciones aditivas, ambas con `down()`.
- Reglas de negocio sin cambios: estados, cálculo por fechas, cuotas, pagos con lock, renovación,
  auditoría, política `missing_license`, `licenses:sync` y `licenses:repair`.

### Cambios respecto del módulo original

- `Tenancy` (estático) se reemplaza por contratos resueltos del contenedor y `Licensing` (config tipada).
- `LicenseService::assignTrial()` recibe la sede como opcional.
- `assignTrial()` devuelve `null` (sin escribir) en modo `managed`.
- Login con varios tenants y motivos de rechazo distintos: mensaje neutro nuevo
  («Ninguna de sus empresas tiene una licencia vigente…»). Los mensajes de un solo tenant no cambian.
- Un resolvedor mal configurado ahora lanza `LogicException` en vez de dejar pasar en silencio.
