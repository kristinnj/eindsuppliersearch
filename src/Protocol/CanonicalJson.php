<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/**
 * EIND-OQ-1 primitive encoding, not record/profile validation.
 * Objects are exactly stdClass; arrays are lists (including []); root is an object.
 * Native numbers and arbitrary objects are never serialized. Null/bool acceptance
 * is structural only: future closed profiles must enforce their field domains.
 */
final class CanonicalJson
{
    public const MAX_BYTES = 1048576;
    public const MAX_DEPTH = 16;

    public static function encode(mixed $value): string
    {
        if (!$value instanceof \stdClass || get_class($value) !== \stdClass::class) {
            throw new CanonicalValidationException('ROOT_OBJECT_REQUIRED');
        }
        $output = '';
        self::write($value, 0, $output);
        return $output;
    }

    /** Exact canonical-byte validation; no normalization of the supplied bytes. */
    public static function decode(string $bytes): \stdClass
    {
        $value = self::parseCanonicalizable($bytes);
        if (self::encode($value) !== $bytes) {
            throw new CanonicalValidationException('NONCANONICAL_JSON');
        }
        return $value;
    }

    /**
     * Parses the protocol's canonicalizable JSON subset, not arbitrary JSON:
     * whitespace/alternate valid escapes/order are allowed, numeric tokens are not.
     * Duplicate decoded keys, malformed syntax and resource violations always fail.
     */
    public static function parseCanonicalizable(string $bytes): \stdClass
    {
        return (new CanonicalJsonParser($bytes))->parse();
    }

    /** Explicit conversion only; callers authenticating bytes must use decode(). */
    public static function canonicalize(string $bytes): string
    {
        return self::encode(self::parseCanonicalizable($bytes));
    }

    /** ASCII key representation only; profile allowlists belong to closed schemas. */
    public static function validateKey(mixed $key): void
    {
        if (!is_string($key) || preg_match('/\A[\x20-\x7e]+\z/D', $key) !== 1) {
            throw new CanonicalValidationException('OBJECT_KEY_INVALID');
        }
    }

    private static function append(string &$output, string $part): void
    {
        if (strlen($part) > self::MAX_BYTES - strlen($output)) {
            throw new CanonicalValidationException('JSON_SIZE_EXCEEDED');
        }
        $output .= $part;
    }

    private static function quoted(string $value): string
    {
        if (strlen($value) > self::MAX_BYTES) {
            throw new CanonicalValidationException('JSON_SIZE_EXCEEDED');
        }
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
        } catch (\JsonException) {
            throw new CanonicalValidationException('JSON_STRING_INVALID');
        }
    }

    private static function write(mixed $value, int $parentDepth, string &$output): void
    {
        if ($value instanceof \stdClass || is_array($value)) {
            $depth = $parentDepth + 1;
            if ($depth > self::MAX_DEPTH) {
                throw new CanonicalValidationException('JSON_DEPTH_EXCEEDED');
            }
            if (is_array($value)) {
                if (!array_is_list($value)) {
                    throw new CanonicalValidationException('LIST_REQUIRED');
                }
                self::append($output, '[');
                foreach ($value as $i => $item) {
                    if ($i !== 0) { self::append($output, ','); }
                    self::write($item, $depth, $output);
                }
                self::append($output, ']');
                return;
            }
            if (get_class($value) !== \stdClass::class) {
                throw new CanonicalValidationException('JSON_TYPE_INVALID');
            }
            $fields = get_object_vars($value);
            // SORT_STRING uses byte comparison, including numeric language keys.
            ksort($fields, SORT_STRING);
            self::append($output, '{');
            $first = true;
            foreach ($fields as $key => $item) {
                // PHP casts numeric property names to integer array keys here.
                $key = (string) $key;
                self::validateKey($key);
                if (!$first) { self::append($output, ','); }
                $first = false;
                self::append($output, self::quoted($key) . ':');
                self::write($item, $depth, $output);
            }
            self::append($output, '}');
        } elseif (is_string($value)) {
            self::append($output, self::quoted($value));
        } elseif ($value === null || is_bool($value)) {
            self::append($output, $value === null ? 'null' : ($value ? 'true' : 'false'));
        } else {
            throw new CanonicalValidationException('JSON_TYPE_INVALID');
        }
    }
}
