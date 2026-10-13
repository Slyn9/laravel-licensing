<?php

namespace Slyn9\Licensing;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Slyn9\Licensing\Access\AttributeSuperAdminCheck;
use Slyn9\Licensing\Access\CallableBranchResolver;
use Slyn9\Licensing\Access\CallableTenantResolver;
use Slyn9\Licensing\Access\ColumnUserTenantAccess;
use Slyn9\Licensing\Access\NullBranchResolver;
use Slyn9\Licensing\Access\NullTenantResolver;
use Slyn9\Licensing\Access\RelationUserTenantAccess;
use Slyn9\Licensing\Access\RoleSuperAdminCheck;
use Slyn9\Licensing\Access\SessionTenantResolver;
use Slyn9\Licensing\Audit\LegacyActivityLogger;
use Slyn9\Licensing\Audit\NullAuditLogger;
use Slyn9\Licensing\Console\Commands\RepairLicensesCommand;
use Slyn9\Licensing\Console\Commands\SyncLicensesCommand;
use Slyn9\Licensing\Contracts\AuditLogger;
use Slyn9\Licensing\Contracts\CurrentBranchResolver;
use Slyn9\Licensing\Contracts\CurrentTenantResolver;
use Slyn9\Licensing\Contracts\SuperAdminCheck;
use Slyn9\Licensing\Contracts\TenantIdentity;
use Slyn9\Licensing\Contracts\UserTenantAccess;
use Slyn9\Licensing\Http\Middleware\CheckCompanyLicense;
use Slyn9\Licensing\Identity\DefaultTenantIdentity;
use Slyn9\Licensing\Ui\Blade\AccessHandler;
use Slyn9\Licensing\Ui\Blade\BannerComposer;

class LicensingServiceProvider extends ServiceProvider
{
    /**
     * Listas que el anfitrión reemplaza completas en vez de fusionarse con los defaults.
     */
    private const REPLACED_LISTS = [
        'payment_methods',
        'routes.middleware',
        'ui.banner_views',
        'branches.unscoped_scopes',
        'user.unscoped_scopes',
    ];

    private function path(string $relative = ''): string
    {
        return dirname(__DIR__).($relative ? DIRECTORY_SEPARATOR.$relative : '');
    }

    public function register(): void
    {
        $this->mergeConfigRecursively();
        $this->registerContracts();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom($this->path('database/migrations'));

        // Alias del middleware que el anfitrión aplica a sus rutas operativas.
        $this->app['router']->aliasMiddleware('license.check', CheckCompanyLicense::class);

        $this->registerCommands();
        $this->registerSchedule();
        $this->registerPublishing();

        // Capa opcional Blade/Bootstrap: sin ella el núcleo no carga vistas ni rutas.
        if (Licensing::bladeUi()) {
            $this->app->bind('licensing.ui.blade', AccessHandler::class);
            $this->loadViewsFrom($this->path('resources/views'), 'licensing');
            $this->registerRoutes();
            $this->registerBanner();
        }
    }

    // ── Configuración ────────────────────────────────────────────────────────

    /** Fusión recursiva de los defaults del paquete con el config del anfitrión. */
    private function mergeConfigRecursively(): void
    {
        $defaults = require $this->path('config/licensing.php');
        $host = (array) $this->app['config']->get('licensing', []);

        $this->app['config']->set('licensing', $this->merge($defaults, $host, ''));
    }

    private function merge(array $defaults, array $host, string $path): array
    {
        $result = $defaults;

        foreach ($host as $key => $value) {
            $dot = $path === '' ? (string) $key : $path.'.'.$key;

            if (
                is_array($value)
                && isset($defaults[$key]) && is_array($defaults[$key])
                && ! in_array($dot, self::REPLACED_LISTS, true)
            ) {
                $result[$key] = $this->merge($defaults[$key], $value, $dot);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    // ── Contratos ────────────────────────────────────────────────────────────

    /**
     * Cada contrato se resuelve en cada uso (bind, no singleton) para leer la config vigente.
     * Una configuración inválida lanza LogicException: nunca deja pasar en silencio.
     */
    private function registerContracts(): void
    {
        $this->app->bind(CurrentTenantResolver::class, fn (Application $app) => $this->override($app, 'tenant_resolver')
            ?? $this->tenantResolver());

        $this->app->bind(CurrentBranchResolver::class, fn (Application $app) => $this->override($app, 'branch_resolver')
            ?? $this->branchResolver());

        $this->app->bind(UserTenantAccess::class, fn (Application $app) => $this->override($app, 'user_tenant_access')
            ?? match (config('licensing.access.driver', 'column')) {
                'column' => new ColumnUserTenantAccess,
                'relation' => new RelationUserTenantAccess,
                default => throw new LogicException("licensing.access.driver debe ser 'column' o 'relation'."),
            });

        $this->app->bind(SuperAdminCheck::class, fn (Application $app) => $this->override($app, 'super_admin_check')
            ?? match (config('licensing.superadmin.driver', 'role')) {
                'role' => new RoleSuperAdminCheck(
                    config('licensing.superadmin.role'),
                    config('licensing.superadmin.team_id'),
                ),
                'attribute' => new AttributeSuperAdminCheck((string) config('licensing.superadmin.attribute', 'is_super_admin')),
                default => throw new LogicException("licensing.superadmin.driver debe ser 'role' o 'attribute'."),
            });

        $this->app->bind(AuditLogger::class, function (Application $app) {
            if ($custom = $this->override($app, 'audit_logger')) {
                return $custom;
            }

            if ($class = config('licensing.audit.logger')) {
                return $app->make($class);
            }

            if ($legacy = config('licensing.audit.legacy_logger')) {
                return new LegacyActivityLogger($legacy);
            }

            return new NullAuditLogger;
        });

        $this->app->bind(TenantIdentity::class, fn (Application $app) => $this->override($app, 'tenant_identity')
            ?? new DefaultTenantIdentity);
    }

    private function override(Application $app, string $key): ?object
    {
        $class = config("licensing.bindings.{$key}");

        return $class ? $app->make($class) : null;
    }

    private function tenantResolver(): CurrentTenantResolver
    {
        $resolver = config('licensing.resolvers.tenant');

        if ($resolver === null) {
            return new NullTenantResolver;
        }

        if (is_string($resolver) && str_starts_with($resolver, 'session:')) {
            return new SessionTenantResolver(substr($resolver, 8));
        }

        if (is_string($resolver) && class_exists($resolver) && is_subclass_of($resolver, CurrentTenantResolver::class)) {
            return $this->app->make($resolver);
        }

        return new CallableTenantResolver($resolver);
    }

    private function branchResolver(): CurrentBranchResolver
    {
        $resolver = config('licensing.resolvers.branch');

        if ($resolver === null || ! Licensing::branchesEnabled()) {
            return new NullBranchResolver;
        }

        if (is_string($resolver) && class_exists($resolver) && is_subclass_of($resolver, CurrentBranchResolver::class)) {
            return $this->app->make($resolver);
        }

        return new CallableBranchResolver($resolver);
    }

    // ── Rutas, comandos, schedule, banner, publicación ───────────────────────

    private function registerRoutes(): void
    {
        if ($this->app->routesAreCached() || ! config('licensing.routes.enabled', true)) {
            return;
        }

        Route::middleware((array) config('licensing.routes.middleware', ['web', 'auth']))
            ->prefix((string) config('licensing.routes.prefix', 'admin'))
            ->name((string) config('licensing.routes.name', 'admin.'))
            ->group($this->path('routes/blade.php'));
    }

    private function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SyncLicensesCommand::class,
                RepairLicensesCommand::class,
            ]);
        }
    }

    /**
     * licenses:sync va en el Schedule de la aplicación y NO en un registro configurable
     * por tenant: un cliente no debe poder desactivar su propio control de licencia.
     */
    private function registerSchedule(): void
    {
        if (! config('licensing.schedule.enabled', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('licenses:sync')
                ->cron((string) config('licensing.schedule.cron', '0 0 * * *'))
                ->withoutOverlapping();
        });
    }

    /**
     * Comparte $licenseWarning (gracia / prueba) con las vistas del anfitrión que incluyen
     * `licensing::partials.banner`. Solo informa: nunca bloquea el acceso.
     */
    private function registerBanner(): void
    {
        $views = array_filter((array) config('licensing.ui.banner_views', []));

        if ($views) {
            View::composer($views, BannerComposer::class);
        }
    }

    private function registerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([$this->path('config/licensing.php') => config_path('licensing.php')], 'licensing-config');
        $this->publishes([$this->path('resources/views') => resource_path('views/vendor/licensing')], 'licensing-views');
    }
}
