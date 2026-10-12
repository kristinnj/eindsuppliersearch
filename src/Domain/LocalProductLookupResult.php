<?php

namespace EindSupplierSearch\Domain;

final class LocalProductLookupResult
{
    private function __construct(
        private readonly LocalProductLookupOutcome $outcome,
        private readonly LocalProductLookupReason $reason,
        private readonly ?LocalProductTarget $target
    ) {
    }

    public static function unique(LocalProductTarget $target): self
    {
        return new self(LocalProductLookupOutcome::UNIQUE, LocalProductLookupReason::VERIFIED_OWNED_TARGET, $target);
    }

    public static function missing(): self
    {
        return new self(LocalProductLookupOutcome::MISSING, LocalProductLookupReason::NO_CANDIDATES, null);
    }

    public static function ambiguous(LocalProductLookupReason $reason): self
    {
        if (!in_array($reason, [LocalProductLookupReason::MULTIPLE_OWNED_TARGETS, LocalProductLookupReason::DUPLICATE_BINDINGS], true)) {
            throw new \InvalidArgumentException('Invalid ambiguity reason.');
        }

        return new self(LocalProductLookupOutcome::AMBIGUOUS, $reason, null);
    }

    public static function invalid(LocalProductLookupReason $reason): self
    {
        if (in_array($reason, [LocalProductLookupReason::NO_CANDIDATES, LocalProductLookupReason::VERIFIED_OWNED_TARGET,
            LocalProductLookupReason::MULTIPLE_OWNED_TARGETS, LocalProductLookupReason::DUPLICATE_BINDINGS], true)) {
            throw new \InvalidArgumentException('Invalid rejection reason.');
        }

        return new self(LocalProductLookupOutcome::INVALID, $reason, null);
    }

    public function getOutcome(): LocalProductLookupOutcome
    {
        return $this->outcome;
    }

    public function getReason(): LocalProductLookupReason
    {
        return $this->reason;
    }

    public function getTarget(): ?LocalProductTarget
    {
        return $this->target;
    }
}
