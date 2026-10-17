<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/** Immutable findings, never an admission token. Exact original bytes remain unchanged. */
final readonly class EvidenceProfileValidationResult
{
    public bool $structurallyValid;
    public bool $dependenciesValid;
    public string $historicalAuthorization;
    public string $currentEligibility;
    public bool $commerceEligible;
    public array $unassessedDomains;

    public function __construct(public string $bodyBytes, public bool $cryptographicallyAuthentic)
    {
        $this->structurallyValid = true;
        $this->dependenciesValid = true;
        $this->historicalAuthorization = 'UNASSESSED_INDEPENDENT_APPROVAL_REQUIRED';
        $this->currentEligibility = 'UNASSESSED';
        $this->commerceEligible = false;
        $this->unassessedDomains = ['independent_historical_approval', 'adapter_capture_capability',
            'credential_entitlement', 'native_context_eligibility', 'current_access', 'current_revocation',
            'stock_reservation', 'commerce'];
    }
}
