<?php

declare(strict_types=1);

namespace EindSupplierSearch\Infrastructure\Database;

use EindSupplierSearch\Contract\LocalProductLookupInterface;
use EindSupplierSearch\Domain\LocalProductBindingEvidence;
use EindSupplierSearch\Domain\LocalProductLookupReason as Reason;
use EindSupplierSearch\Domain\LocalProductLookupResult as Result;
use EindSupplierSearch\Domain\LocalProductTarget;
use EindSupplierSearch\Domain\SupplierOfferIdentity;
use EindSupplierSearch\Exception\LocalProductLookupException;
use EindSupplierSearch\Service\LocalProductCandidateEvaluator;

/** Read-only orchestration, deliberately not registered with runtime commerce. */
final class PersistentLocalProductLookup implements LocalProductLookupInterface
{
    private ?string $diagnostic = null;

    public function __construct(
        private readonly OfferBindingReaderInterface $registry,
        private readonly NativeProductEvidenceReaderInterface $native,
        private readonly LocalProductCandidateEvaluator $evaluator = new LocalProductCandidateEvaluator()
    ) {
    }

    /** Internal sanitized diagnostic; not a new public domain reason. */
    public function getDiagnostic(): ?string
    {
        return $this->diagnostic;
    }

    public function lookup(SupplierOfferIdentity $identity, int $shopId): Result
    {
        $this->diagnostic = null;
        if ($shopId <= 0) {
            throw new \InvalidArgumentException('Lookup shop id must be positive.');
        }
        try {
            $digest = OfferBindingReference::sha256($identity);
        } catch (\LengthException $error) {
            return $this->invalid('REFERENCE_TOO_LONG');
        }
        try {
            $rows = $this->registry->find($shopId, $identity->getSupplierId(), $digest);
            if (count($rows) > 1) {
                return $this->invalid('DUPLICATE_HASH_BUCKET');
            }
            if ($rows === []) {
                return $this->evaluator->evaluate($identity, $this->native->context($identity->getSupplierId(), $shopId), []);
            }
            $row = reset($rows);
            if (!is_array($row)) {
                return $this->invalid('MALFORMED_REGISTRY_ROW');
            }
            try {
                RegistryRow::validate($row);
            } catch (\Throwable $error) {
                return $this->invalid('MALFORMED_REGISTRY_ROW');
            }
            if (RegistryRow::integer($row['id_shop']) !== $shopId
                || RegistryRow::integer($row['id_supplier']) !== $identity->getSupplierId()) {
                return $this->invalid('IDENTITY_SCOPE_MISMATCH');
            }
            if ($row['reference_sha256'] !== $digest) {
                return $this->invalid('DIGEST_MISMATCH');
            }
            if ($row['order_reference'] !== $identity->getSupplierOrderReference()) {
                return $this->invalid('HASH_COLLISION');
            }
            if (hash('sha256', $row['order_reference'], true) !== $row['reference_sha256']) {
                return $this->invalid('DIGEST_MISMATCH');
            }
            if ($row['state'] !== 'ACTIVE') {
                $this->diagnostic = $row['state'];
                return Result::invalid(Reason::OWNERSHIP_UNVERIFIED);
            }
            if ($row['id_product'] === null) {
                return $this->invalid('ACTIVE_WITHOUT_TARGET');
            }
            $target = new LocalProductTarget(RegistryRow::integer($row['id_product']),
                RegistryRow::integer($row['id_product_attribute'], true), $shopId);
            $claims = $this->registry->forTarget($target);
            // The original row must still be the sole claim on its globally reserved target.
            if (count($claims) !== 1 || !is_array(reset($claims)) || reset($claims) !== $row) {
                return $this->invalid('CONFLICTING_TARGET_CLAIMS');
            }
            $binding = new LocalProductBindingEvidence((string) $row['id_binding'], $identity, $target, true);
            $context = $this->native->context($identity->getSupplierId(), $shopId);
            $candidates = $this->native->candidates($binding);
            if ($candidates === []) {
                // A reader must supply an absent-product observation, never erase an ACTIVE claim.
                $this->diagnostic = 'MISSING_NATIVE_EVIDENCE';
                return Result::invalid(Reason::DANGLING_BINDING);
            }
            return $this->evaluator->evaluate($identity, $context, $candidates);
        } catch (\Throwable $error) {
            $this->diagnostic = 'PERSISTENCE_FAILURE';
            throw new LocalProductLookupException('Read-only ownership lookup failed.', 0, $error);
        }
    }

    private function invalid(string $diagnostic): Result
    {
        $this->diagnostic = $diagnostic;
        return Result::invalid(Reason::INCONSISTENT_BINDING);
    }
}
