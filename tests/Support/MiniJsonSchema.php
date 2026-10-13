<?php

namespace Slyn9\Licensing\Tests\Support;

/**
 * Validador de JSON Schema mínimo para los tests: soporta el subconjunto que usa el
 * esquema del snapshot (type, required, properties, items, enum, const, pattern,
 * minLength, maxLength, format uuid|date-time, $ref interno a $defs).
 */
final class MiniJsonSchema
{
    /** @return array<int, string> lista de errores (vacía = válido) */
    public static function validate(mixed $data, array $schema, ?array $root = null, string $path = '$'): array
    {
        $root ??= $schema;
        $errors = [];

        if (isset($schema['$ref'])) {
            $ref = explode('/', ltrim($schema['$ref'], '#/'));
            $target = $root;
            foreach ($ref as $part) {
                $target = $target[$part] ?? [];
            }

            return self::validate($data, $target, $root, $path);
        }

        if (array_key_exists('const', $schema) && $data !== $schema['const']) {
            $errors[] = "$path: debe ser ".json_encode($schema['const']);
        }

        if (isset($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            $errors[] = "$path: valor fuera de enum (".json_encode($data).')';
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            if (! array_filter($types, fn ($t) => self::isType($data, $t))) {
                $errors[] = "$path: tipo inválido, se esperaba ".implode('|', $types).' y llegó '.get_debug_type($data);

                return $errors;
            }
        }

        if (is_string($data)) {
            if (isset($schema['pattern']) && ! preg_match('/'.str_replace('/', '\/', $schema['pattern']).'/', $data)) {
                $errors[] = "$path: no cumple el patrón {$schema['pattern']} ($data)";
            }
            if (isset($schema['minLength']) && mb_strlen($data) < $schema['minLength']) {
                $errors[] = "$path: demasiado corto";
            }
            if (isset($schema['maxLength']) && mb_strlen($data) > $schema['maxLength']) {
                $errors[] = "$path: demasiado largo";
            }
            if (($schema['format'] ?? null) === 'uuid' && ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $data)) {
                $errors[] = "$path: uuid inválido";
            }
            if (($schema['format'] ?? null) === 'date-time' && strtotime($data) === false) {
                $errors[] = "$path: fecha-hora inválida";
            }
        }

        if (is_array($data) && array_is_list($data) === false || (is_array($data) && $data === [] && ($schema['type'] ?? null) === 'object')) {
            foreach ($schema['required'] ?? [] as $key) {
                if (! array_key_exists($key, $data)) {
                    $errors[] = "$path: falta la clave obligatoria '$key'";
                }
            }
            foreach ($schema['properties'] ?? [] as $key => $sub) {
                if (array_key_exists($key, $data)) {
                    $errors = array_merge($errors, self::validate($data[$key], $sub, $root, "$path.$key"));
                }
            }
        }

        if (is_array($data) && array_is_list($data) && isset($schema['items'])) {
            foreach ($data as $i => $item) {
                $errors = array_merge($errors, self::validate($item, $schema['items'], $root, "{$path}[$i]"));
            }
        }

        return $errors;
    }

    private static function isType(mixed $data, string $type): bool
    {
        return match ($type) {
            'string' => is_string($data),
            'integer' => is_int($data),
            'number' => is_int($data) || is_float($data),
            'boolean' => is_bool($data),
            'null' => $data === null,
            'array' => is_array($data) && array_is_list($data),
            'object' => is_array($data) && (! array_is_list($data) || $data === []),
            default => false,
        };
    }
}
