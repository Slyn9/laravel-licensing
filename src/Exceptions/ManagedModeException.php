<?php

namespace Slyn9\Licensing\Exceptions;

/** Escritura comercial rechazada porque licensing.mode = managed. */
class ManagedModeException extends LicenseException
{
    public static function forOperation(string $operation): self
    {
        return new self(
            "La operación '{$operation}' no está permitida: las licencias de este producto se gestionan de forma centralizada (modo managed)."
        );
    }
}
