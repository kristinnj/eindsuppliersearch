<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Contract;

use EindSupplierSearch\Contract\LocalProductLookupInterface;
use EindSupplierSearch\Domain\LocalProductLookupOutcome as Outcome;
use EindSupplierSearch\Domain\LocalProductLookupReason as Reason;
use EindSupplierSearch\Domain\LocalProductLookupResult as Result;
use EindSupplierSearch\Domain\LocalProductTarget;
use EindSupplierSearch\Domain\SupplierOfferIdentity;
use EindSupplierSearch\Exception\LocalProductLookupException;
use EindSupplierSearch\Tests\Doubles\FakeLocalProductLookup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Contract examples only; these tests do not exercise a database adapter. */
final class LocalProductLookupInterfaceTest extends TestCase
{
    public function testExactIdentityAndShopScopeArePreserved(): void
    {
        $identity = new SupplierOfferIdentity(4, " 001-Ab/C\t ");
        $target = new LocalProductTarget(20, 0, 2);
        $response = Result::unique($target);
        $fake = new FakeLocalProductLookup($response);
        $result = $this->lookup($fake, $identity, 2);

        self::assertSame($identity, $fake->receivedIdentity);
        self::assertSame(4, $fake->receivedIdentity->getSupplierId());
        self::assertSame(" 001-Ab/C\t ", $fake->receivedIdentity->getSupplierOrderReference());
        self::assertSame(2, $fake->receivedShopId);
        self::assertSame($response, $result);
        self::assertSame(Outcome::UNIQUE, $result->getOutcome());
        self::assertSame($target, $result->getTarget());
    }

    public function testMissingAndInvalidOwnershipRemainDistinct(): void
    {
        $identity = new SupplierOfferIdentity(3, 'ABC');
        $missing = $this->lookup(new FakeLocalProductLookup(Result::missing()), $identity, 1);
        $invalid = $this->lookup(new FakeLocalProductLookup(Result::invalid(Reason::OWNERSHIP_UNVERIFIED)), $identity, 1);

        self::assertSame(Outcome::MISSING, $missing->getOutcome());
        self::assertSame(Reason::NO_CANDIDATES, $missing->getReason());
        self::assertSame(Outcome::INVALID, $invalid->getOutcome());
        self::assertSame(Reason::OWNERSHIP_UNVERIFIED, $invalid->getReason());
        self::assertNull($missing->getTarget());
        self::assertNull($invalid->getTarget());
    }

    public function testInfrastructureFailurePropagatesUnchanged(): void
    {
        $cause = new \RuntimeException('Database unavailable');
        $failure = new LocalProductLookupException('Lookup unavailable', 0, $cause);
        try {
            $this->lookup(new FakeLocalProductLookup($failure), new SupplierOfferIdentity(3, 'ABC'), 1);
            self::fail('Infrastructure failure must not return a business result.');
        } catch (LocalProductLookupException $actual) {
            self::assertSame($failure, $actual);
            self::assertSame($cause, $actual->getPrevious());
        }
    }

    #[DataProvider('invalidShopIds')]
    public function testNonpositiveShopIsRejectedBeforeLookup(int $shopId): void
    {
        $fake = new FakeLocalProductLookup(new LocalProductLookupException('Must not reach lookup'));
        try {
            $this->lookup($fake, new SupplierOfferIdentity(3, 'ABC'), $shopId);
            self::fail('Invalid shop must be rejected.');
        } catch (\InvalidArgumentException $failure) {
            self::assertNull($fake->receivedIdentity);
            self::assertNull($fake->receivedShopId);
        }
    }

    public static function invalidShopIds(): array
    {
        return [[0], [-1]];
    }

    private function lookup(LocalProductLookupInterface $lookup, SupplierOfferIdentity $identity, int $shopId): Result
    {
        return $lookup->lookup($identity, $shopId);
    }
}
