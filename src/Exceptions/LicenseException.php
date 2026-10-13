<?php

namespace Slyn9\Licensing\Exceptions;

use RuntimeException;

/**
 * Regla de negocio de licencias incumplida. `field` indica qué campo del
 * formulario debe mostrar el mensaje.
 */
class LicenseException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'license')
    {
        parent::__construct($message);
    }

    public static function forField(string $field, string $message): self
    {
        return new self($message, $field);
    }
}
