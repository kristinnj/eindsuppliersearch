<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/** Small composable validators. No record fields or schema evolution implied. */
final class ClosedSchema
{
    /**
     * Field maps contain name => callable(mixed): void. Required nulls remain
     * present fields; only nullable() permits null. Optional omissions are explicit.
     * Nested objects/lists use these same validators, with bounded recursion.
     */
    public static function object(mixed $value, array $required, array $optional = []): void
    {
        self::scalarType($value, 'object');
        foreach ([$required, $optional] as $fields) {
            foreach ($fields as $name => $validator) {
                CanonicalJson::validateKey((string) $name);
                if (!is_callable($validator)) { throw new CanonicalValidationException('SCHEMA_DEFINITION_INVALID'); }
            }
        }
        if (array_intersect_key($required, $optional) !== []) {
            throw new CanonicalValidationException('SCHEMA_DEFINITION_INVALID');
        }
        $fields = get_object_vars($value);
        if (array_diff_key($fields, $required + $optional) !== []) {
            throw new CanonicalValidationException('SCHEMA_UNKNOWN_FIELD');
        }
        if (array_diff_key($required, $fields) !== []) {
            throw new CanonicalValidationException('SCHEMA_MISSING_FIELD');
        }
        foreach ($required + $optional as $name => $validator) {
            if (array_key_exists($name, $fields)) { $validator($fields[$name]); }
        }
    }

    public static function list(mixed $value, callable $element, int $minimum = 0, int $maximum = CanonicalJson::MAX_BYTES): void
    {
        self::scalarType($value, 'list');
        if ($minimum < 0 || $maximum < $minimum || $maximum > CanonicalJson::MAX_BYTES) {
            throw new CanonicalValidationException('SCHEMA_DEFINITION_INVALID');
        }
        if (count($value) < $minimum || count($value) > $maximum) {
            throw new CanonicalValidationException('SCHEMA_LIST_SIZE_INVALID');
        }
        foreach ($value as $item) { $element($item); }
    }

    /** Exact runtime types only. Numeric-domain values must use string validators. */
    public static function scalarType(mixed $value, string $type): void
    {
        $valid = match ($type) {
            'string' => is_string($value),
            'bool' => is_bool($value),
            'object' => $value instanceof \stdClass && get_class($value) === \stdClass::class,
            'list' => is_array($value) && array_is_list($value),
            default => throw new CanonicalValidationException('SCHEMA_DEFINITION_INVALID'),
        };
        if (!$valid) { throw new CanonicalValidationException('SCHEMA_TYPE_INVALID'); }
    }

    public static function nullable(mixed $value, callable $validator): void
    {
        if ($value !== null) { $validator($value); }
    }
}
