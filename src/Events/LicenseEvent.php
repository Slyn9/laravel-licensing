<?php

namespace Slyn9\Licensing\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Str;
use Slyn9\Licensing\Contracts\TenantIdentity;
use Slyn9\Licensing\Licensing;
use Slyn9\Licensing\Models\CompanyLicense;

/**
 * Base de los eventos de dominio (C4). El payload es solo datos serializables:
 * uuids, código de producto, referencias externas y estados. Nunca modelos.
 *
 * Los eventos se despachan DESPUÉS del commit (y no si hay rollback).
 */
abstract class LicenseEvent implements ShouldDispatchAfterCommit
{
    /** Identificador único del evento (idempotencia para quien lo consuma). */
    public readonly string $eventId;

    /** Momento en que ocurrió, ISO 8601. */
    public readonly string $occurredAt;

    public function __construct(
        public readonly string $product,
        public readonly string $licenseUuid,
        public readonly string $tenantRef,
        public readonly ?string $branchRef,
        public readonly ?string $previousStatus,
        public readonly string $newStatus,
    ) {
        $this->eventId = (string) Str::uuid();
        $this->occurredAt = now()->toIso8601String();
    }

    /** Nombre estable del evento, p. ej. "license.assigned". */
    abstract public function name(): string;

    /** Campos propios del evento (todos escalares). */
    abstract protected function payload(): array;

    /** @return array<string, scalar|null> */
    public function toArray(): array
    {
        return array_merge([
            'event_id' => $this->eventId,
            'event' => $this->name(),
            'occurred_at' => $this->occurredAt,
            'product' => $this->product,
            'license_uuid' => $this->licenseUuid,
            'tenant_ref' => $this->tenantRef,
            'branch_ref' => $this->branchRef,
            'previous_status' => $this->previousStatus,
            'new_status' => $this->newStatus,
        ], $this->payload());
    }

    /**
     * Campos comunes construidos desde la licencia, para spread en los constructores.
     *
     * @return array{product:string,licenseUuid:string,tenantRef:string,branchRef:?string,previousStatus:?string,newStatus:string}
     */
    protected static function common(CompanyLicense $license, ?string $previousStatus, ?string $newStatus = null): array
    {
        $identity = app(TenantIdentity::class);

        $branchRef = null;
        if (Licensing::branchesEnabled() && $license->getAttribute(Licensing::branchKey())) {
            $branch = $license->branch;
            $branchRef = $branch ? $identity->branchRef($branch) : null;
        }

        return [
            'product' => Licensing::productCode(),
            'licenseUuid' => $license->ensureUuid(),
            'tenantRef' => $identity->externalRef($license->tenant),
            'branchRef' => $branchRef,
            'previousStatus' => $previousStatus,
            'newStatus' => $newStatus ?? $license->status->value,
        ];
    }

    protected static function date(mixed $value): ?string
    {
        return $value?->toIso8601String();
    }

    protected static function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
