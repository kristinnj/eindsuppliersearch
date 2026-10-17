<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/**
 * Pure verification facade using the accepted synthetic-only key boundary.
 * No operational attestation/signing, administrative approval or action admission API.
 */
final class EvidenceProfileAuthenticator
{
    public function __construct(private readonly EvidenceAuthenticator $authenticator) {}

    public function observation(string $body, string $installation, string $keyId, string $macHex,
        array $artifacts): EvidenceProfileValidationResult
    {
        $o = (new ObservationProfileValidator())->validate($body);
        $this->context($o, $installation);
        EvidenceProfileSchema::check($this->authenticator->verifyHex('OBSERVATION', $installation, $keyId, $body, $macHex), 'MAC_INVALID');
        (new EvidenceDependencyValidator())->observation($body, $artifacts);
        return new EvidenceProfileValidationResult($body, true);
    }

    public function quote(string $body, string $observationBody, string $installation,
        string $keyId, string $macHex, string $observationKeyId, string $observationMacHex,
        array $artifacts, array $currencyBindings): EvidenceProfileValidationResult
    {
        $q = (new QuoteProfileValidator())->validate($body);
        $o = (new ObservationProfileValidator())->validate($observationBody);
        $this->context($q, $installation);
        $this->context($o, $installation);
        EvidenceProfileSchema::check($this->authenticator->verifyHex('QUOTE', $installation, $keyId, $body, $macHex), 'MAC_INVALID');
        EvidenceProfileSchema::check($this->authenticator->verifyHex('OBSERVATION', $installation,
            $observationKeyId, $observationBody, $observationMacHex), 'OBSERVATION_MAC_INVALID');
        (new EvidenceDependencyValidator())->quote($body, $observationBody, $artifacts, $currencyBindings);
        return new EvidenceProfileValidationResult($body, true);
    }

    private function context(\stdClass $body, string $installation): void
    {
        EvidenceMacFrame::validateInstallation($installation);
        EvidenceProfileSchema::check($body->installation_id === bin2hex($installation), 'INSTALLATION_MISMATCH');
    }
}
