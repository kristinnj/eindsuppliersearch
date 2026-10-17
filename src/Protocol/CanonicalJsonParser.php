<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/** @internal Bounded recursive-descent parser; mutable cursor is local to one parse. */
final class CanonicalJsonParser
{
    private int $offset = 0;
    private readonly int $length;

    public function __construct(private readonly string $bytes)
    {
        $this->length = strlen($bytes);
        if ($this->length > CanonicalJson::MAX_BYTES) {
            throw new CanonicalValidationException('JSON_SIZE_EXCEEDED');
        }
    }

    public function parse(): \stdClass
    {
        $value = $this->value(0);
        $this->whitespace();
        if ($this->offset !== $this->length) { $this->invalid(); }
        if (!$value instanceof \stdClass) {
            throw new CanonicalValidationException('ROOT_OBJECT_REQUIRED');
        }
        return $value;
    }

    private function value(int $parentDepth): mixed
    {
        $this->whitespace();
        $c = $this->bytes[$this->offset] ?? '';
        if ($c === '') { $this->invalid(); }
        if ($c === '{' || $c === '[') {
            $depth = $parentDepth + 1;
            if ($depth > CanonicalJson::MAX_DEPTH) {
                throw new CanonicalValidationException('JSON_DEPTH_EXCEEDED');
            }
            ++$this->offset;
            return $c === '{' ? $this->object($depth) : $this->list($depth);
        }
        if ($c === '"') { return $this->string(); }
        foreach (['true' => true, 'false' => false, 'null' => null] as $token => $value) {
            if (substr_compare($this->bytes, $token, $this->offset, strlen($token)) === 0) {
                $this->offset += strlen($token);
                return $value;
            }
        }
        if ($c === '-' || ($c !== '' && $c >= '0' && $c <= '9')) {
            throw new CanonicalValidationException('JSON_NUMBER_FORBIDDEN');
        }
        $this->invalid();
    }

    private function object(int $depth): \stdClass
    {
        $result = new \stdClass();
        $seen = [];
        $this->whitespace();
        if ($this->take('}')) { return $result; }
        do {
            $this->whitespace();
            if (($this->bytes[$this->offset] ?? '') !== '"') { $this->invalid(); }
            $key = $this->string();
            CanonicalJson::validateKey($key);
            // Prefix prevents PHP numeric-key conversion from affecting identity.
            if (isset($seen['k:' . $key])) {
                throw new CanonicalValidationException('JSON_DUPLICATE_KEY');
            }
            $seen['k:' . $key] = true;
            $this->whitespace();
            if (!$this->take(':')) { $this->invalid(); }
            $result->{$key} = $this->value($depth);
            $this->whitespace();
            if ($this->take('}')) { return $result; }
        } while ($this->take(','));
        $this->invalid();
    }

    private function list(int $depth): array
    {
        $result = [];
        $this->whitespace();
        if ($this->take(']')) { return $result; }
        do {
            $result[] = $this->value($depth);
            $this->whitespace();
            if ($this->take(']')) { return $result; }
        } while ($this->take(','));
        $this->invalid();
    }

    private function string(): string
    {
        $start = $this->offset++;
        while ($this->offset < $this->length) {
            $c = $this->bytes[$this->offset++];
            if ($c === '\\') { ++$this->offset; continue; }
            if ($c !== '"') { continue; }
            try {
                $value = json_decode(substr($this->bytes, $start, $this->offset - $start),
                    false, 2, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new CanonicalValidationException('JSON_STRING_INVALID');
            }
            if (!is_string($value)) { $this->invalid(); }
            return $value;
        }
        $this->invalid();
    }

    private function take(string $token): bool
    {
        if (($this->bytes[$this->offset] ?? '') !== $token) { return false; }
        ++$this->offset;
        return true;
    }

    private function whitespace(): void
    {
        while ($this->offset < $this->length && str_contains(" \t\r\n", $this->bytes[$this->offset])) {
            ++$this->offset;
        }
    }

    private function invalid(): never
    {
        throw new CanonicalValidationException('JSON_SYNTAX_INVALID');
    }
}
