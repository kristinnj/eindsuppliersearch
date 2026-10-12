<?php

namespace EindSupplierSearch\Domain;

enum LocalProductLookupOutcome: string
{
    case MISSING = 'MISSING';
    case UNIQUE = 'UNIQUE';
    case AMBIGUOUS = 'AMBIGUOUS';
    case INVALID = 'INVALID';
}
