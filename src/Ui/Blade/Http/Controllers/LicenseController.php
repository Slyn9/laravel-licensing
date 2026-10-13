<?php

namespace Slyn9\Licensing\Ui\Blade\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Slyn9\Licensing\Exceptions\LicenseException;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Models\CompanyLicense;
use Slyn9\Licensing\Models\LicenseType;
use Slyn9\Licensing\Rules\LicenseRules;
use Slyn9\Licensing\Services\LicenseService;

class LicenseController extends Controller
{
    // La protección (auth, rol superadmin...) la define config('licensing.routes.middleware').

    public function __construct(private readonly LicenseService $licenses) {}

    // ── Index: lista de tenants con su licencia vigente ──────────────────────

    public function index()
    {
        $tenants = Licensing::tenantQuery()
            ->orderBy((string) config('licensing.tenant.name_attribute', 'name'))
            ->get();

        // La licencia no cancelada más reciente de cada tenant.
        $latest = $this->licenses->latestPerTenant();

        $licenseTypes = LicenseType::where('is_active', true)->orderBy('name')->get();

        return view('licensing::index', compact('tenants', 'latest', 'licenseTypes'));
    }

    // ── Show: detalle de licencias de un tenant ───────────────────────────────

    public function show(string $company)
    {
        $tenant = $this->tenant($company);

        $licenses = $this->licenses->licensesOf($tenant);

        $licenseTypes = LicenseType::where('is_active', true)->orderBy('name')->get();
        $branches = Licensing::branchesEnabled()
            ? Licensing::branchQuery()
                ->where(Licensing::branchTenantKey(), $tenant->getKey())
                ->orderBy((string) config('licensing.branches.name_attribute', 'name'))
                ->get()
            : collect();

        return view('licensing::show', compact('tenant', 'licenses', 'licenseTypes', 'branches'));
    }

    // ── Store: asignar nueva licencia a un tenant (o a una de sus sedes) ──────

    public function store(Request $request, string $company): RedirectResponse
    {
        $tenant = $this->tenant($company);

        $data = $request->validate(LicenseRules::assign($tenant));

        $branch = null;
        if (Licensing::branchesEnabled() && ! empty($data[Licensing::branchKey()])) {
            $branch = Licensing::branchQuery()->find($data[Licensing::branchKey()]);
        }

        try {
            $this->licenses->assign(
                $tenant,
                $branch,
                LicenseType::findOrFail($data['license_type_id']),
                $data,
                $request->user(),
            );
        } catch (LicenseException $e) {
            return back()->withErrors([$e->field => $e->getMessage()])->withInput();
        }

        return redirect()->route(config('licensing.routes.name').'licenses.show', $tenant)
            ->with('success', 'Licencia asignada correctamente a '.Licensing::tenantName($tenant).'.');
    }

    // ── RegisterPayment: registrar un pago sobre una licencia ─────────────────

    public function registerPayment(Request $request, CompanyLicense $license): RedirectResponse
    {
        $data = $request->validate(LicenseRules::payment($license));

        try {
            $this->licenses->registerPayment($license, $data['amount'], $data, $request->user());
        } catch (LicenseException $e) {
            return back()->withErrors([$e->field => $e->getMessage()])->withInput();
        }

        return redirect()->route(config('licensing.routes.name').'licenses.show', $license->tenant)
            ->with('success', 'Pago registrado correctamente.');
    }

    // ── Block / Unblock / Cancel (motivo obligatorio, queda en el log) ────────

    public function block(Request $request, CompanyLicense $license): RedirectResponse
    {
        $data = $request->validate(LicenseRules::reason());

        return $this->run(fn () => $this->licenses->block($license, $request->user(), $data['reason']), 'Licencia bloqueada.');
    }

    public function unblock(Request $request, CompanyLicense $license): RedirectResponse
    {
        $data = $request->validate(LicenseRules::unblock());

        return $this->run(
            fn () => $this->licenses->unblock($license, $request->user(), $data['reason'], [
                'expires_at' => $data['expires_at'] ?? null,
                'renew' => (bool) ($data['renew'] ?? false),
                'total_amount' => $data['total_amount'] ?? null,
                'installments_count' => $data['installments_count'] ?? null,
            ]),
            'Licencia reactivada.'
        );
    }

    public function cancel(Request $request, CompanyLicense $license): RedirectResponse
    {
        $data = $request->validate(LicenseRules::reason());

        return $this->run(fn () => $this->licenses->cancel($license, $request->user(), $data['reason']), 'Licencia cancelada.');
    }

    // ── Renovación ────────────────────────────────────────────────────────────

    public function renew(Request $request, CompanyLicense $license): RedirectResponse
    {
        $data = $request->validate(LicenseRules::renew());

        return $this->run(fn () => $this->licenses->renew($license, $request->user(), $data), 'Licencia renovada.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function tenant(string $id): Model
    {
        return Licensing::tenantQuery()->findOrFail($id);
    }

    private function run(callable $action, string $success): RedirectResponse
    {
        try {
            $action();
        } catch (LicenseException $e) {
            return back()->withErrors([$e->field => $e->getMessage()])->withInput();
        }

        return back()->with('success', $success);
    }
}
