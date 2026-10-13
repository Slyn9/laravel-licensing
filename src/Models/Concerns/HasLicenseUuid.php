<?php

namespace Slyn9\Licensing\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

/**
 * Identidad global (C1): columna `uuid` única y ordenada. La PK entera sigue siendo la
 * clave local; el uuid es lo que un sistema externo usa para referirse al registro.
 */
trait HasLicenseUuid
{
    use HasUuids;

    /** Solo `uuid` es generado: la PK sigue siendo un entero autoincremental. */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function newUniqueId(): string
    {
        return (string) Str::orderedUuid();
    }

    /**
     * Garantiza que el registro tenga uuid (filas insertadas en crudo, sin Eloquent).
     */
    public function ensureUuid(): string
    {
        if (empty($this->uuid)) {
            $this->uuid = $this->newUniqueId();
            $this->saveQuietly();
        }

        return (string) $this->uuid;
    }
}
