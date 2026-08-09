<?php

namespace EindSupplierSearch\Exception;

/**
 * Base type for all provider-side failures. SearchService catches this
 * (not raw \Throwable from a specific provider) and converts it into a
 * safe, empty SearchResult.
 */
class SupplierProviderException extends \RuntimeException
{
}
