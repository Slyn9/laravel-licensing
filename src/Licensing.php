<?php

namespace Slyn9\Licensing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Acceso tipado y de solo lectura a la configuración `licensing.*`. Es el único lugar
 * que traduce la config del anfitrión (modelos, tablas, llaves) a valores del paquete.
 */
final class Licensing
{
    public const MODE_STANDALONE = 'standalone';

    public const MODE_MANAGED = 'managed';

    // ── Producto y modo (C2, C3) ─────────────────────────────────────────────

    /** Código del producto (FARMACY, CONTAPE...). Lanza si falta o no cumple el patrón. */
    public static function productCode(): string
    {
        $code = config('licensing.product.code');

        if (! is_string($code) || ! preg_match('/^[A-Z0-9_]{2,20}$/', $code)) {
            throw new LogicException(
                "config('licensing.product.code') es obligatorio y debe cumplir ^[A-Z0-9_]{2,20}$ (p. ej. FARMACY)."
            );
        }

        return $code;
    }

    /** Modo de gestión validado. Un valor desconocido lanza: nunca abre las escrituras. */
    public static function mode(): string
    {
        $mode = config('licensing.mode', self::MODE_STANDALONE);

        if (! in_array($mode, [self::MODE_STANDALONE, self::MODE_MANAGED], true)) {
            throw new LogicException("config('licensing.mode') debe ser 'standalone' o 'managed'.");
        }

        return $mode;
    }

    /** Las UIs usan este método para ocultar acciones de escritura. Un modo inválido oculta. */
    public static function allowsLocalManagement(): bool
    {
        return config('licensing.mode', self::MODE_STANDALONE) === self::MODE_STANDALONE;
    }

    // ── Características ──────────────────────────────────────────────────────

    public static function branchesEnabled(): bool
    {
        return (bool) config('licensing.branches.enabled', false);
    }

    public static function bladeUi(): bool
    {
        return (bool) config('licensing.ui.blade', false);
    }

    // ── Modelos ──────────────────────────────────────────────────────────────

    /** @return class-string<Model> */
    public static function tenantModel(): string
    {
        return self::model('tenant.model');
    }

    /** @return class-string<Model> */
    public static function branchModel(): string
    {
        self::requireBranches();

        return self::model('branches.model');
    }

    /** @return class-string<Model> */
    public static function userModel(): string
    {
        return self::model('user.model');
    }

    // ── Llaves, tablas y atributos ───────────────────────────────────────────

    /** Columna de company_licenses que apunta al tenant. */
    public static function tenantKey(): string
    {
        return (string) config('licensing.tenant.foreign_key', 'company_id');
    }

    public static function tenantTable(): string
    {
        return (string) config('licensing.tenant.table', 'companies');
    }

    /** Columna de company_licenses que apunta a la sede. */
    public static function branchKey(): string
    {
        return (string) config('licensing.branches.foreign_key', 'sede_id');
    }

    /** Columna del modelo de sede que apunta a su tenant. */
    public static function branchTenantKey(): string
    {
        return (string) (config('licensing.branches.tenant_key') ?: self::tenantKey());
    }

    public static function branchTable(): string
    {
        self::requireBranches();

        $table = config('licensing.branches.table');

        return (string) ($table ?: (new (self::branchModel()))->getTable());
    }

    public static function userTable(): string
    {
        return (string) config('licensing.user.table', 'users');
    }

    public static function tenantName(?Model $tenant): string
    {
        return $tenant ? (string) $tenant->getAttribute((string) config('licensing.tenant.name_attribute', 'name')) : '';
    }

    public static function branchName(?Model $branch): string
    {
        return $branch ? (string) $branch->getAttribute((string) config('licensing.branches.name_attribute', 'name')) : '';
    }

    public static function trialCode(): string
    {
        return (string) config('licensing.trial_code', 'TRIAL');
    }

    public static function currencyCode(): string
    {
        return (string) config('licensing.currency.code', 'PEN');
    }

    // ── Consultas sin filtros de contexto ────────────────────────────────────

    public static function tenantQuery(): Builder
    {
        return self::tenantModel()::query();
    }

    /** Sedes sin los scopes globales que el anfitrión filtra por contexto de request. */
    public static function branchQuery(): Builder
    {
        return self::branchModel()::query()
            ->withoutGlobalScopes((array) config('licensing.branches.unscoped_scopes', []));
    }

    public static function userQuery(): Builder
    {
        return self::userModel()::query()
            ->withoutGlobalScopes((array) config('licensing.user.unscoped_scopes', []));
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    private static function requireBranches(): void
    {
        if (! self::branchesEnabled()) {
            throw new LogicException("Las sedes están desactivadas (licensing.branches.enabled = false).");
        }
    }

    private static function model(string $key): string
    {
        $class = config("licensing.{$key}");

        if (! is_string($class) || ! class_exists($class)) {
            throw new LogicException("config('licensing.{$key}') debe apuntar a un modelo Eloquent existente.");
        }

        return $class;
    }
}
