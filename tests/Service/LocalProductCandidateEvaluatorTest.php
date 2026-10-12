<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Service;

use EindSupplierSearch\Domain\LocalProductBindingEvidence;
use EindSupplierSearch\Domain\LocalProductCandidate;
use EindSupplierSearch\Domain\LocalProductLookupContext;
use EindSupplierSearch\Domain\LocalProductLookupOutcome as Outcome;
use EindSupplierSearch\Domain\LocalProductLookupReason as Reason;
use EindSupplierSearch\Domain\LocalProductNativeEvidence;
use EindSupplierSearch\Domain\LocalProductTarget;
use EindSupplierSearch\Domain\SupplierOfferIdentity;
use EindSupplierSearch\Service\LocalProductCandidateEvaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalProductCandidateEvaluatorTest extends TestCase
{
    private function identity(int $supplier = 3, string $reference = '001-A B'): SupplierOfferIdentity
    {
        return new SupplierOfferIdentity($supplier, $reference);
    }

    private function context(): LocalProductLookupContext
    {
        return new LocalProductLookupContext(3, 1, true, true, true, true);
    }

    private function candidate(
        int $product = 20,
        string $token = 'record-a',
        ?SupplierOfferIdentity $identity = null,
        int $attribute = 0,
        int $shop = 1
    ): LocalProductCandidate {
        $identity ??= $this->identity();
        $target = new LocalProductTarget($product, $attribute, $shop);

        return new LocalProductCandidate(
            new LocalProductBindingEvidence($token, $identity, $target, true),
            new LocalProductNativeEvidence($target, $identity, true, true, $identity->getSupplierId())
        );
    }

    private function assertEvaluation(array $candidates, Outcome $outcome, Reason $reason): void
    {
        // Both outcome and diagnostic must be invariant under reordered rows.
        foreach ([$candidates, array_reverse($candidates)] as $rows) {
            $result = (new LocalProductCandidateEvaluator())->evaluate($this->identity(), $this->context(), $rows);
            self::assertSame($outcome, $result->getOutcome());
            self::assertSame($reason, $result->getReason());
            if ($outcome !== Outcome::UNIQUE) {
                self::assertNull($result->getTarget());
            } else {
                self::assertTrue($this->candidate()->binding->target->equals($result->getTarget()));
            }
        }
    }

    public function testNoCandidates(): void
    {
        $this->assertEvaluation([], Outcome::MISSING, Reason::NO_CANDIDATES);
    }

    public function testVerifiedOwnedCandidate(): void
    {
        $this->assertEvaluation([$this->candidate()], Outcome::UNIQUE, Reason::VERIFIED_OWNED_TARGET);
    }

    public function testNativeOnlyAndUnknownLegacyProvenanceAreNotReusable(): void
    {
        $this->assertEvaluation([new LocalProductCandidate(null, $this->candidate()->native)], Outcome::INVALID, Reason::OWNERSHIP_UNVERIFIED);
    }

    public function testUnverifiedMappingIsNotOwnershipProof(): void
    {
        $row = $this->candidate();
        $this->assertEvaluation([new LocalProductCandidate(
            new LocalProductBindingEvidence('record-a', $this->identity(), $row->binding->target, false), $row->native
        )], Outcome::INVALID, Reason::OWNERSHIP_UNVERIFIED);
    }

    public function testUnidentifiedLegacyNativeEvidenceIsNotMissing(): void
    {
        $this->assertEvaluation([new LocalProductCandidate(null,
            new LocalProductNativeEvidence(new LocalProductTarget(20, 0, 1), null, true, true, 3)
        )], Outcome::INVALID, Reason::OWNERSHIP_UNVERIFIED);
    }

    public function testOtherShopIsNotSilentlyReused(): void
    {
        $this->assertEvaluation([$this->candidate(shop: 2)], Outcome::INVALID, Reason::RELATIONSHIP_CONFLICT);
    }

    public function testExplicitShopContextCanSelectAnotherShop(): void
    {
        $result = (new LocalProductCandidateEvaluator())->evaluate($this->identity(),
            new LocalProductLookupContext(3, 2, true, true, true, true), [$this->candidate(shop: 2)]);
        self::assertSame(Outcome::UNIQUE, $result->getOutcome());
        self::assertSame(2, $result->getTarget()->getShopId());
    }

    public function testDifferentSupplierWithSameReferenceDoesNotMatch(): void
    {
        $this->assertEvaluation([$this->candidate(identity: $this->identity(4))], Outcome::MISSING, Reason::NO_CANDIDATES);
    }

    public function testTwoSuppliersWithSameCodeKeepRequestedOwnedTarget(): void
    {
        $this->assertEvaluation([$this->candidate(), $this->candidate(21, 'record-b', $this->identity(4))], Outcome::UNIQUE, Reason::VERIFIED_OWNED_TARGET);
    }

    #[DataProvider('conflictingBindingTokens')]
    public function testRelevantBindingTokenConflictsAreRejected(int $supplier, string $reference, int $product): void
    {
        $this->assertEvaluation([
            $this->candidate(),
            $this->candidate($product, 'record-a', $this->identity($supplier, $reference)),
        ], Outcome::INVALID, Reason::INCONSISTENT_BINDING);
    }

    public static function conflictingBindingTokens(): array
    {
        return [
            'different supplier and target' => [4, '001-A B', 21],
            'different supplier same target' => [4, '001-A B', 20],
            'same identity different target' => [3, '001-A B', 21],
            'different reference and target' => [3, 'other', 21],
        ];
    }

    public function testConflictsBetweenUnrelatedTokensDoNotAffectValidLookup(): void
    {
        $this->assertEvaluation([
            $this->candidate(),
            $this->candidate(21, 'unrelated', $this->identity(4)),
            $this->candidate(22, 'unrelated', $this->identity(5)),
        ], Outcome::UNIQUE, Reason::VERIFIED_OWNED_TARGET);
    }

    #[DataProvider('nonMatchingReferences')]
    public function testReferencesAreExact(string $reference): void
    {
        $this->assertEvaluation([$this->candidate(identity: $this->identity(3, $reference))], Outcome::MISSING, Reason::NO_CANDIDATES);
    }

    public static function nonMatchingReferences(): array
    {
        return [['001-a B'], ['1-A B'], ['001-A  B'], [' 001-A B'], ["001-A\tB"], ['001-A B '], ['001/A B']];
    }

    public function testIdenticalJoinedRowsAreDeduplicated(): void
    {
        $this->assertEvaluation([$this->candidate(), $this->candidate()], Outcome::UNIQUE, Reason::VERIFIED_OWNED_TARGET);
    }

    public function testCompetingOwnedTargetsAreAmbiguous(): void
    {
        $this->assertEvaluation([$this->candidate(), $this->candidate(21, 'record-b')], Outcome::AMBIGUOUS, Reason::MULTIPLE_OWNED_TARGETS);
    }

    public function testDifferentAuthoritativeRecordsForSameTargetAreAmbiguous(): void
    {
        $this->assertEvaluation([$this->candidate(), $this->candidate(token: 'record-b')], Outcome::AMBIGUOUS, Reason::DUPLICATE_BINDINGS);
    }

    public function testSameBindingRecordCannotPointToDifferentProducts(): void
    {
        $this->assertEvaluation([$this->candidate(), $this->candidate(21)], Outcome::INVALID, Reason::INCONSISTENT_BINDING);
    }

    public function testConflictingSupplierBindingCannotHideBehindSameTarget(): void
    {
        $this->assertEvaluation([$this->candidate(), $this->candidate(identity: $this->identity(4))], Outcome::INVALID, Reason::INCONSISTENT_BINDING);
    }

    public function testConflictingReferenceBindingCannotHideBehindSameTarget(): void
    {
        $this->assertEvaluation([$this->candidate(), $this->candidate(identity: $this->identity(3, 'other'))], Outcome::INVALID, Reason::INCONSISTENT_BINDING);
    }

    #[DataProvider('conflictingNativeEvidence')]
    public function testNativeRelationshipsMustAgree(int $product, int $attribute, int $shop, int $supplier, bool $inShop, string $reference): void
    {
        $native = new LocalProductNativeEvidence(new LocalProductTarget($product, $attribute, $shop),
            $this->identity(3, $reference), true, $inShop, $supplier);
        $this->assertEvaluation([new LocalProductCandidate($this->candidate()->binding, $native)], Outcome::INVALID, Reason::RELATIONSHIP_CONFLICT);
    }

    public static function conflictingNativeEvidence(): array
    {
        return [[21,0,1,3,true,'001-A B'], [20,1,1,3,true,'001-A B'], [20,0,2,3,true,'001-A B'],
            [20,0,1,4,true,'001-A B'], [20,0,1,3,false,'001-A B'], [20,0,1,3,true,'001-a B']];
    }

    public function testMissingNativeSupplierAssociationIsInvalid(): void
    {
        $this->assertEvaluation([new LocalProductCandidate($this->candidate()->binding,
            new LocalProductNativeEvidence($this->candidate()->binding->target, null, true, true, 3)
        )], Outcome::INVALID, Reason::RELATIONSHIP_CONFLICT);
    }

    public function testContradictoryJoinedRowsCannotBeDeduplicatedAway(): void
    {
        $row = $this->candidate();
        $conflict = new LocalProductCandidate($row->binding,
            new LocalProductNativeEvidence($row->native->target, $this->identity(), true, false, 3));
        $this->assertEvaluation([$row, $conflict], Outcome::INVALID, Reason::RELATIONSHIP_CONFLICT);
    }

    public function testUnidentifiedNativeRowForMappedTargetCannotHideConflict(): void
    {
        $row = $this->candidate();
        $this->assertEvaluation([$row, new LocalProductCandidate(null,
            new LocalProductNativeEvidence($row->native->target, null, true, true, 3))],
            Outcome::INVALID, Reason::RELATIONSHIP_CONFLICT);
    }

    public function testRepeatedEvaluationDoesNotMutateEvidence(): void
    {
        $rows = [$this->candidate(), $this->candidate()];
        $before = serialize($rows);
        $evaluator = new LocalProductCandidateEvaluator();
        $first = $evaluator->evaluate($this->identity(), $this->context(), $rows);
        $second = $evaluator->evaluate($this->identity(), $this->context(), $rows);
        self::assertSame($before, serialize($rows));
        self::assertSame($first->getOutcome(), $second->getOutcome());
        self::assertTrue($first->getTarget()->equals($second->getTarget()));
    }

    public function testConflictingOwnershipEvidenceCannotBeDeduplicatedAway(): void
    {
        $row = $this->candidate();
        $conflict = new LocalProductCandidate(new LocalProductBindingEvidence('record-a', $this->identity(), $row->binding->target, false), $row->native);
        $this->assertEvaluation([$row, $conflict], Outcome::INVALID, Reason::OWNERSHIP_UNVERIFIED);
    }

    public function testDanglingBinding(): void
    {
        $this->assertEvaluation([new LocalProductCandidate($this->candidate()->binding, null)], Outcome::INVALID, Reason::DANGLING_BINDING);
    }

    public function testDeletedProductIsNotReusable(): void
    {
        $row = $this->candidate();
        $this->assertEvaluation([new LocalProductCandidate($row->binding,
            new LocalProductNativeEvidence($row->native->target, $this->identity(), false, true, 3))], Outcome::INVALID, Reason::DANGLING_BINDING);
    }

    public function testCombinationsRemainExplicitAndUnsupported(): void
    {
        $this->assertEvaluation([$this->candidate(attribute: 7)], Outcome::INVALID, Reason::UNSUPPORTED_COMBINATION);
    }

    public function testUnownedOrdinaryProductDoesNotDisplaceVerifiedMapping(): void
    {
        $this->assertEvaluation([$this->candidate(), new LocalProductCandidate(null, $this->candidate(21)->native)], Outcome::UNIQUE, Reason::VERIFIED_OWNED_TARGET);
    }

    #[DataProvider('invalidContexts')]
    public function testInvalidSupplierAndShopContext(LocalProductLookupContext $context, Reason $reason): void
    {
        $result = (new LocalProductCandidateEvaluator())->evaluate($this->identity(), $context, []);
        self::assertSame(Outcome::INVALID, $result->getOutcome());
        self::assertSame($reason, $result->getReason());
        self::assertNull($result->getTarget());
    }

    public static function invalidContexts(): array
    {
        return [
            [new LocalProductLookupContext(4,1,true,true,true,true),Reason::INVALID_CONTEXT],
            [new LocalProductLookupContext(3,0,true,true,true,true),Reason::INVALID_CONTEXT],
            [new LocalProductLookupContext(3,1,false,true,true,true),Reason::SUPPLIER_NOT_FOUND],
            [new LocalProductLookupContext(3,1,true,false,true,true),Reason::SUPPLIER_NOT_ELIGIBLE],
            [new LocalProductLookupContext(3,1,true,true,false,true),Reason::SHOP_NOT_FOUND],
            [new LocalProductLookupContext(3,1,true,true,true,false),Reason::SUPPLIER_NOT_IN_SHOP],
        ];
    }

    public function testInvalidEvidenceOutranksAmbiguityInEitherOrder(): void
    {
        $this->assertEvaluation([$this->candidate(), $this->candidate(21, 'record-b'),
            new LocalProductCandidate($this->candidate(22, 'record-c')->binding, null)], Outcome::INVALID, Reason::DANGLING_BINDING);
    }

    public function testMalformedCandidateListIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new LocalProductCandidateEvaluator())->evaluate($this->identity(), $this->context(), ['not evidence']);
    }
}
