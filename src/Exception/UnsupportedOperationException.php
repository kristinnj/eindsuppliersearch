<?php

namespace EindSupplierSearch\Exception;

/**
 * Thrown by provider methods the current search flow doesn't exercise yet
 * (getProduct()/getOffer()), so the interface can be implemented fully
 * later without breaking anything that depends on it today.
 */
class UnsupportedOperationException extends SupplierProviderException
{
}
