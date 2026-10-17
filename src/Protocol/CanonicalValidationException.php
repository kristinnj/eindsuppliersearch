<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/** Stable, body-free error categories; never includes rejected input. */
final class CanonicalValidationException extends \InvalidArgumentException
{
    public function __construct(public readonly string $category)
    {
        parent::__construct($category);
    }
}
