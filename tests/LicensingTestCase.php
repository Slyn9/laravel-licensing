<?php

namespace Slyn9\Licensing\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Orchestra\Testbench\TestCase as Orchestra;
use Slyn9\Licensing\LicensingServiceProvider;
use Slyn9\Licensing\Models\CompanyLicense;
use Slyn9\Licensing\Models\LicenseInstallment;
use Slyn9\Licensing\Models\LicenseType;
use Slyn9\Licensing\Services\LicenseService;
use Slyn9\Licensing\Tests\Fixtures\Support\AuditSink;
use Slyn9\Licensing\Tests\Fixtures\Support\FixtureContext;
use Slyn9\Licensing\Tests\Fixtures\Support\SetFixtureContext;
use Spatie\Permission\PermissionServiceProvider;

/**
 * Base de la suite. Cada topologÃ­a (T1/T2/T3) define su anfitriÃ³n simulado: modelos,
 * esquema, config de licensing y cÃ³mo se crean tenants/usuarios/superadmins.
 *
 * Fija el reloj para que las fechas sean deterministas y expone rutas "probe" protegidas
 * por `license.check` (equivalen a las rutas operativas del anfitriÃ³n).
 */
abstract class LicensingTestCase extends Orchestra
{
    use RefreshDatabase;

    protected Model $tenant;

    /** @var array<string, LicenseType> */
    protected array $types = [];

    // â”€â”€ DefiniciÃ³n de la topologÃ­a â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    /** t1 | t2 | t3 */
    abstract protected function topology(): string;

    /** @return array<string, mixed> config del anfitriÃ³n (licensing.*) */
    abstract protected function licensingConfig(): array;

    /** @return class-string<Authenticatable&Model> */
    abstract protected function userModel(): string;

    abstract protected function createTenant(array $attributes = []): Model;

    /** Usuario del tenant (y de la sede, si la topologÃ­a tiene sedes). */
    abstract protected function createUser(?Model $branch = null, string $role = 'admin', array $attributes = [], ?Model $tenant = null): Authenticatable;

    abstract protected function createSuperadmin(): Authenticatable;

    /** Hace una peticiÃ³n a una ruta protegida por license.check en el contexto indicado. */
    abstract protected function probe(Authenticatable $user, ?Model $tenant = null, ?Model $branch = null): TestResponse;

    /** Vincula un usuario a otro tenant (solo topologías N:M). */
    protected function attachUserToTenant(Authenticatable $user, Model $tenant): void
    {
        throw new \LogicException('La topología no soporta usuarios con varios tenants.');
    }

    protected function detachUserFromAllTenants(Authenticatable $user): void
    {
        throw new \LogicException('La topología no soporta usuarios con varios tenants.');
    }

    protected function usesBranches(): bool
    {
        return false;
    }

    /** Sede de trabajo de las pruebas compartidas: la sede A en T1; null en topologías sin sedes. */
    protected function scopeA(): ?Model
    {
        return $this->usesBranches() ? $this->branchA : null;
    }

    protected function usesSpatie(): bool
    {
        return false;
    }

    protected function usesTeams(): bool
    {
        return false;
    }

    // â”€â”€ Entorno â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    protected function getPackageProviders($app): array
    {
        $providers = [LicensingServiceProvider::class];

        if ($this->usesSpatie()) {
            $providers[] = PermissionServiceProvider::class;
        }

        return $providers;
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('hashing.bcrypt.rounds', 4);
        $app['config']->set('licensing_test.topology', $this->topology());
        // En Testbench el provider se registra antes que defineEnvironment: se fusiona sobre los defaults.
        $app['config']->set('licensing', array_replace_recursive((array) $app['config']->get('licensing', []), $this->licensingConfig()));
        $app['config']->set('auth.providers.users.model', $this->userModel());
        $app['config']->set('view.paths', [__DIR__.'/Fixtures/views']);

        if ($this->usesSpatie()) {
            $app['config']->set('permission.teams', $this->usesTeams());
            $app['config']->set('permission.cache.store', 'array');
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function defineRoutes($router): void
    {
        $router->get('login', fn () => 'login')->middleware('web')->name('login');

        $router->middleware(['web', 'auth', SetFixtureContext::class, 'license.check'])->group(function ($router) {
            $router->get('c/{tenant}/{branch}/__probe', fn () => response('probe-ok'));
            $router->get('c/{tenant}/__probe', fn () => response('probe-ok'));
            $router->get('__probe', fn () => response('probe-ok'));
        });

        // Hook de login del anfitriÃ³n: credenciales + denialMessageFor (como LoginRequest).
        $router->post('__login', function (Request $request) {
            $user = $this->userModel()::where('email', $request->input('email'))->first();

            abort_unless($user && Hash::check((string) $request->input('password'), $user->password), 401);

            Auth::login($user);

            $message = app(LicenseService::class)->denialMessageFor(Auth::user());

            if ($message) {
                Auth::logout();

                throw ValidationException::withMessages(['email_user' => $message]);
            }

            return 'logged-in';
        })->middleware('web');
    }

    // â”€â”€ Ciclo de vida â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 10:00:00');

        AuditSink::reset();
        $this->app->singleton(FixtureContext::class);
        $this->afterSchema();

        $this->types = [
            'TRIAL' => $this->makeType('Trial', 'TRIAL', 30, 0, 0),
            'MON' => $this->makeType('Mensual', 'MON', 30, 5, 99),
            'ANN' => $this->makeType('Anual', 'ANN', 365, 15, 990),
            'PERM' => $this->makeType('Permanente', 'PERM', null, 0, 2500),
        ];

        $this->tenant = $this->createTenant(['name' => 'Empresa Licencias']);
    }

    /** Gancho para sembrar roles u otros datos que dependen del esquema. */
    protected function afterSchema(): void {}

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // â”€â”€ Fixtures â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

    protected function tenantKey(): string
    {
        return (string) config('licensing.tenant.foreign_key');
    }

    protected function branchKey(): string
    {
        return (string) config('licensing.branches.foreign_key');
    }

    protected function makeType(string $name, string $code, ?int $days, int $grace, float $price): LicenseType
    {
        return LicenseType::create([
            'name' => $name,
            'code' => $code,
            'duration_days' => $days,
            'base_price' => $price,
            'grace_period_days' => $grace,
            'is_active' => true,
        ]);
    }

    /**
     * Crea una licencia directamente (sin pasar por el cÃ³digo bajo prueba).
     *
     * @param  array{type?:string,status?:string,grace?:int,total?:float,paid?:float,installments?:int,tenant?:Model,created_at?:mixed,expires_at?:mixed}  $o
     */
    protected function makeLicense(?Model $branch, ?int $expiresInDays, array $o = []): CompanyLicense
    {
        $type = $this->types[$o['type'] ?? 'MON'];
        $expires = $expiresInDays === null ? null : now()->addDays($expiresInDays);
        $grace = $o['grace'] ?? $type->grace_period_days;

        $attributes = [
            $this->tenantKey() => ($o['tenant'] ?? $this->tenant)->getKey(),
            'license_type_id' => $type->id,
            'status' => $o['status'] ?? 'active',
            'starts_at' => ($expires ?? now())->copy()->subDays(30),
            'expires_at' => $o['expires_at'] ?? $expires,
            'grace_until' => $expires?->copy()->addDays($grace),
            'total_amount' => $o['total'] ?? 0,
            'paid_amount' => $o['paid'] ?? 0,
            'installments_count' => $o['installments'] ?? 1,
        ];

        if ($this->usesBranches()) {
            $attributes[$this->branchKey()] = $branch?->getKey();
        }

        $license = CompanyLicense::create($attributes);

        if (isset($o['created_at'])) {
            $license->forceFill(['created_at' => $o['created_at']])->saveQuietly();
        }

        return $license->fresh();
    }

    /**
     * Crea $count cuotas mensuales que suman $total, la primera vence en $firstDueInDays.
     *
     * @return array<int, LicenseInstallment>
     */
    protected function makeInstallments(CompanyLicense $license, int $count, float $total, int $firstDueInDays = 0): array
    {
        $each = round($total / $count, 2);
        $rows = [];
        $first = now()->startOfDay()->addDays($firstDueInDays);

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = LicenseInstallment::create([
                'company_license_id' => $license->id,
                'installment_number' => $i,
                'amount_due' => $each,
                'due_date' => $first->copy()->addMonths($i - 1)->toDateString(),
                'status' => 'pending',
            ]);
        }

        return $rows;
    }

    protected function makeUser(?Model $branch = null, string $role = 'admin', array $attributes = []): Authenticatable
    {
        return $this->createUser($branch, $role, $attributes);
    }

    protected function superadmin(): Authenticatable
    {
        return $this->createSuperadmin();
    }

    protected function service(): LicenseService
    {
        return app(LicenseService::class);
    }

    /** Intento de login con el hook del anfitriÃ³n (credenciales + licencia). */
    protected function login(Authenticatable $user): TestResponse
    {
        return $this->post('/__login', ['email' => $user->email, 'password' => 'password']);
    }

    protected function password(): string
    {
        return Hash::make('password');
    }

    /**
     * @return array<int, array{action:string,module:?string,description:string,subject:?Model,properties:array,by:?Model}>
     */
    protected function auditFor(CompanyLicense $license, string $action): array
    {
        return array_values(array_filter(
            AuditSink::$entries,
            fn ($e) => $e['action'] === $action && $e['subject']?->getKey() === $license->getKey()
        ));
    }
}
