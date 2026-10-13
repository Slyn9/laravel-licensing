<?php

namespace Slyn9\Licensing\Rules;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Models\CompanyLicense;

/**
 * Reglas de validación por operación, reutilizables por cualquier UI (Blade, Inertia,
 * Livewire). Los nombres de campo son los que consumen los métodos de LicenseService.
 */
final class LicenseRules
{
    /** @return array<string, array<int, mixed>> */
    public static function assign(Model $tenant): array
    {
        $rules = [
            'license_type_id' => ['required', 'exists:license_types,id'],
            'starts_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after:starts_at'],
            'total_amount' => ['required', 'numeric', 'min:0'],
            'installments_count' => ['required', 'integer', 'min:1', 'max:36'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];

        if (Licensing::branchesEnabled()) {
            $rules[Licensing::branchKey()] = [
                'nullable',
                Rule::exists(Licensing::branchTable(), 'id')->where(Licensing::branchTenantKey(), $tenant->getKey()),
            ];
        }

        return $rules;
    }

    /** @return array<string, array<int, mixed>> */
    public static function payment(CompanyLicense $license): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'paid_at' => ['required', 'date'],
            'payment_method' => ['required', Rule::in(array_keys((array) config('licensing.payment_methods')))],
            'reference' => ['nullable', 'string', 'max:100'],
            'license_installment_id' => ['nullable', Rule::exists('license_installments', 'id')->where('company_license_id', $license->id)],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, array<int, mixed>> motivo obligatorio (bloquear, cancelar) */
    public static function reason(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:500']];
    }

    /** @return array<string, array<int, mixed>> */
    public static function unblock(): array
    {
        return self::reason() + [
            'expires_at' => ['nullable', 'date', 'after:today'],
            'renew' => ['nullable', 'boolean'],
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'installments_count' => ['nullable', 'integer', 'min:1', 'max:36'],
        ];
    }

    /** @return array<string, array<int, mixed>> */
    public static function renew(): array
    {
        return [
            'total_amount' => ['nullable', 'numeric', 'min:0'],
            'installments_count' => ['nullable', 'integer', 'min:1', 'max:36'],
        ];
    }
}
