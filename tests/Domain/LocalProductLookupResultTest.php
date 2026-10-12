<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Domain;

use EindSupplierSearch\Domain\LocalProductCandidate;
use EindSupplierSearch\Domain\LocalProductBindingEvidence;
use EindSupplierSearch\Domain\LocalProductLookupOutcome as Outcome;
use EindSupplierSearch\Domain\LocalProductLookupReason as Reason;
use EindSupplierSearch\Domain\LocalProductLookupResult as Result;
use EindSupplierSearch\Domain\LocalProductTarget;
use EindSupplierSearch\Domain\SupplierOfferIdentity;
use EindSupplierSearch\Exception\LocalProductLookupException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalProductLookupResultTest extends TestCase
{
    public function testOnlyUniqueExposesTarget(): void
    {
        $target = new LocalProductTarget(20, 7, 1);
        $unique = Result::unique($target);
        self::assertSame(Outcome::UNIQUE, $unique->getOutcome());
        self::assertSame($target, $unique->getTarget());
        self::assertSame(7, $unique->getTarget()->getProductAttributeId());
        foreach ([Result::missing(), Result::ambiguous(Reason::DUPLICATE_BINDINGS), Result::invalid(Reason::OWNERSHIP_UNVERIFIED)] as $result) {
            self::assertNull($result->getTarget());
        }
    }

    #[DataProvider('invalidCoordinates')]
    public function testInvalidCoordinatesAreRejected(int $product, int $attribute, int $shop): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LocalProductTarget($product, $attribute, $shop);
    }

    public static function invalidCoordinates(): array
    {
        return [[0,0,1],[-1,0,1],[1,-1,1],[1,0,0],[1,0,-1]];
    }

    public function testTargetEqualityIncludesEveryCoordinate(): void
    {
        $target = new LocalProductTarget(20, 0, 1);
        self::assertTrue($target->equals(new LocalProductTarget(20, 0, 1)));
        self::assertFalse($target->equals(new LocalProductTarget(21, 0, 1)));
        self::assertFalse($target->equals(new LocalProductTarget(20, 1, 1)));
        self::assertFalse($target->equals(new LocalProductTarget(20, 0, 2)));
    }

    public function testInvalidOutcomeReasonPairCannotBeConstructed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Result::invalid(Reason::VERIFIED_OWNED_TARGET);
    }

    public function testInvalidAmbiguityReasonIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Result::ambiguous(Reason::OWNERSHIP_UNVERIFIED);
    }

    public function testEmptyCandidateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LocalProductCandidate(null, null);
    }

    public function testEmptyBindingTokenIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LocalProductBindingEvidence('', new SupplierOfferIdentity(3, 'ABC'), new LocalProductTarget(20, 0, 1), true);
    }

    public function testTechnicalFailureIsSeparateFromBusinessResult(): void
    {
        $failure = new LocalProductLookupException('Lookup unavailable', 0, new \RuntimeException('Transport failure'));
        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertNotInstanceOf(Result::class, $failure);
        self::assertNotNull($failure->getPrevious());
        self::assertSame(Outcome::MISSING, Result::missing()->getOutcome());
    }
}
