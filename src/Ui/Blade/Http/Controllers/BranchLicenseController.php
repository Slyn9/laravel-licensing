<?php

namespace Slyn9\Licensing\Ui\Blade\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Slyn9\Licensing\Exceptions\LicenseException;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Models\LicenseType;
use Slyn9\Licensing\Services\LicenseService;

/** Alta de licencia desde la ficha de una sede (respuesta JSON para el modal). */
class BranchLicenseController extends Controller
{
    public function store(Request $request, string $company, string $branch, LicenseService $licenses): JsonResponse
    {
        abort_unless(Licensing::branchesEnabled(), 404);

        $tenant = Licensing::tenantQuery()->findOrFail($company);
        $branchModel = Licensing::branchQuery()->findOrFail($branch);

        abort_unless(
            (string) $branchModel->getAttribute(Licensing::branchTenantKey()) === (string) $tenant->getKey(),
            404
        );

        $validated = $request->validate([
            'license_type_id' => ['required', 'exists:license_types,id'],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'installments_count' => ['required', 'integer', 'min:1', 'max:36'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $licenses->assign(
                $tenant,
                $branchModel,
                LicenseType::findOrFail($validated['license_type_id']),
                $validated,
                $request->user(),
            );
        } catch (LicenseException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }
}
