<?php

namespace Slyn9\Licensing\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Utilidades para los resolvedores configurables: invocar la función/método configurado
 * y convertir su resultado (Model, id o null) en un modelo.
 */
final class ResolvesContext
{
    /**
     * @param  string|array{0:string,1:string}  $callable  función global o [Clase, 'método']
     */
    public static function invoke(string|array $callable): mixed
    {
        if (is_string($callable)) {
            if (! function_exists($callable)) {
                throw new LogicException("El resolvedor '{$callable}' configurado en licensing.resolvers no existe como función.");
            }

            return $callable();
        }

        [$class, $method] = $callable + [null, null];

        if (! is_string($class) || ! is_string($method) || ! class_exists($class) || ! method_exists($class, $method)) {
            throw new LogicException('El resolvedor configurado en licensing.resolvers no es un [Clase, método] válido.');
        }

        $reflection = new \ReflectionMethod($class, $method);

        return $reflection->isStatic() ? $class::$method() : app($class)->{$method}();
    }

    public static function toModel(Builder $query, mixed $value): ?Model
    {
        if ($value instanceof Model) {
            return $value;
        }

        if ($value === null || $value === '' || $value === 0 || $value === false) {
            return null;
        }

        if (is_int($value) || is_string($value)) {
            return $query->find($value);
        }

        throw new LogicException('El resolvedor debe devolver un Model, un id o null.');
    }
}
