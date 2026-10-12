<?php

namespace EindSupplierSearch\Service;

use EindSupplierSearch\Domain\LocalProductCandidate;
use EindSupplierSearch\Domain\LocalProductLookupContext;
use EindSupplierSearch\Domain\LocalProductLookupReason as Reason;
use EindSupplierSearch\Domain\LocalProductLookupResult as Result;
use EindSupplierSearch\Domain\LocalProductTarget;
use EindSupplierSearch\Domain\SupplierOfferIdentity;

/** Pure evaluation of supplied facts; no discovery, adoption, or mutation. */
final class LocalProductCandidateEvaluator
{
    /** @param LocalProductCandidate[] $candidates */
    public function evaluate(SupplierOfferIdentity $identity, LocalProductLookupContext $context, array $candidates): Result
    {
        if ($context->supplierId !== $identity->getSupplierId() || $context->shopId <= 0) {
            return Result::invalid(Reason::INVALID_CONTEXT);
        }
        if (!$context->supplierExists) {
            return Result::invalid(Reason::SUPPLIER_NOT_FOUND);
        }
        if (!$context->supplierEligible) {
            return Result::invalid(Reason::SUPPLIER_NOT_ELIGIBLE);
        }
        if (!$context->shopExists) {
            return Result::invalid(Reason::SHOP_NOT_FOUND);
        }
        if (!$context->supplierInShop) {
            return Result::invalid(Reason::SUPPLIER_NOT_IN_SHOP);
        }

        // Discover relevant mapped targets before processing rows, so conflicting
        // evidence for the same target cannot disappear depending on row order.
        $relevantTargets = [];
        $relevantBindings = [];
        foreach ($candidates as $candidate) {
            if (!$candidate instanceof LocalProductCandidate) {
                throw new \InvalidArgumentException('Expected LocalProductCandidate evidence.');
            }
            if ($candidate->binding !== null && $candidate->binding->identity->equals($identity)) {
                $relevantTargets[$this->key($candidate->binding->target)] = true;
                $relevantBindings[$candidate->binding->bindingToken] = $candidate->binding;
            }
        }

        // Check every observation of a requested binding token before filtering
        // unrelated identities/targets. No conflicting row is preferred.
        foreach ($candidates as $candidate) {
            $binding = $candidate->binding;
            if ($binding === null || !isset($relevantBindings[$binding->bindingToken])) {
                continue;
            }
            $expected = $relevantBindings[$binding->bindingToken];
            if (!$binding->identity->equals($expected->identity) || !$binding->target->equals($expected->target)) {
                return Result::invalid(Reason::INCONSISTENT_BINDING);
            }
        }

        $errors = [];
        $targets = [];
        $tokens = [];
        $unverified = false;
        foreach ($candidates as $candidate) {
            $binding = $candidate->binding;
            $native = $candidate->native;
            if ($binding === null && $native !== null && $native->supplierIdentity === null
                && !isset($relevantTargets[$this->key($native->target)])) {
                // An unidentified legacy observation is not evidence of absence.
                $unverified = true;
                continue;
            }
            $matchesBinding = $binding !== null && $binding->identity->equals($identity);
            $matchesNative = $native !== null && $native->supplierIdentity !== null && $native->supplierIdentity->equals($identity);
            $sharesTarget = ($binding !== null && isset($relevantTargets[$this->key($binding->target)]))
                || ($native !== null && isset($relevantTargets[$this->key($native->target)]));
            if (!$matchesBinding && !$matchesNative && !$sharesTarget) {
                continue;
            }

            if ($binding !== null && !$matchesBinding) {
                $errors[Reason::INCONSISTENT_BINDING->value] = Reason::INCONSISTENT_BINDING;
            }
            if ($native === null || !$native->productExists) {
                $errors[Reason::DANGLING_BINDING->value] = Reason::DANGLING_BINDING;
                continue;
            }
            if (!$matchesNative || !$native->productInShop || $native->target->getShopId() !== $context->shopId
                || $native->defaultSupplierId !== $identity->getSupplierId()
                || ($binding !== null && !$binding->target->equals($native->target))) {
                $errors[Reason::RELATIONSHIP_CONFLICT->value] = Reason::RELATIONSHIP_CONFLICT;
            }
            if ($native->target->getProductAttributeId() !== 0
                || ($binding !== null && $binding->target->getProductAttributeId() !== 0)) {
                $errors[Reason::UNSUPPORTED_COMBINATION->value] = Reason::UNSUPPORTED_COMBINATION;
            }
            if ($binding === null || !$binding->moduleOwnershipVerified) {
                if ($binding !== null) {
                    $errors[Reason::OWNERSHIP_UNVERIFIED->value] = Reason::OWNERSHIP_UNVERIFIED;
                }
                $unverified = true;
                continue;
            }

            $key = $this->key($binding->target);
            if (isset($tokens[$binding->bindingToken]) && $tokens[$binding->bindingToken] !== $key) {
                $errors[Reason::INCONSISTENT_BINDING->value] = Reason::INCONSISTENT_BINDING;
            }
            $tokens[$binding->bindingToken] = $key;
            $targets[$key] = $binding->target;
        }

        // Fixed diagnostic precedence: contradictory evidence overrides ambiguity.
        foreach ([Reason::INCONSISTENT_BINDING, Reason::DANGLING_BINDING,
            Reason::RELATIONSHIP_CONFLICT, Reason::UNSUPPORTED_COMBINATION, Reason::OWNERSHIP_UNVERIFIED] as $reason) {
            if (isset($errors[$reason->value])) {
                return Result::invalid($reason);
            }
        }
        if (count($targets) > 1) {
            return Result::ambiguous(Reason::MULTIPLE_OWNED_TARGETS);
        }
        if (count($tokens) > 1) {
            return Result::ambiguous(Reason::DUPLICATE_BINDINGS);
        }
        if (count($targets) === 1) {
            return Result::unique(reset($targets));
        }
        if ($unverified) {
            return Result::invalid(Reason::OWNERSHIP_UNVERIFIED);
        }

        return Result::missing();
    }

    private function key(LocalProductTarget $target): string
    {
        // Validated integer tuples make this coordinate key unambiguous.
        return $target->getProductId() . ':' . $target->getProductAttributeId() . ':' . $target->getShopId();
    }
}
