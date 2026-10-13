<?php

declare(strict_types=1);

namespace EindSupplierSearch\Infrastructure\Database;

use EindSupplierSearch\Domain\LocalProductBindingEvidence;
use EindSupplierSearch\Domain\LocalProductCandidate;
use EindSupplierSearch\Domain\LocalProductLookupContext;
use EindSupplierSearch\Domain\LocalProductNativeEvidence;
use EindSupplierSearch\Domain\LocalProductTarget;
use EindSupplierSearch\Domain\SupplierOfferIdentity;

final class PdoNativeProductEvidenceReader implements NativeProductEvidenceReaderInterface
{
    private readonly \Closure $supplierEligible;

    /** Trusted server-side module eligibility policy; never browser/API supplied. */
    public function __construct(private readonly LookupSelectConnection $connection, callable $supplierEligible)
    {
        $this->supplierEligible = \Closure::fromCallable($supplierEligible);
    }

    public function context(int $supplierId, int $shopId): LocalProductLookupContext
    {
        $supplier = $this->connection->select('SELECT id_supplier, active FROM ' . $this->connection->table('supplier')
            . ' WHERE id_supplier = ?', [$supplierId]);
        $shop = $this->connection->select('SELECT id_shop, active, deleted FROM ' . $this->connection->table('shop')
            . ' WHERE id_shop = ?', [$shopId]);
        $association = $this->connection->select('SELECT id_supplier FROM ' . $this->connection->table('supplier_shop')
            . ' WHERE id_supplier = ? AND id_shop = ?', [$supplierId, $shopId]);
        return new LocalProductLookupContext($supplierId, $shopId, count($supplier) === 1,
            count($supplier) === 1 && (string) $supplier[0]['active'] === '1'
                && ($this->supplierEligible)($supplierId, $shopId) === true,
            count($shop) === 1 && (string) $shop[0]['active'] === '1' && (string) $shop[0]['deleted'] === '0',
            count($association) === 1);
    }

    public function candidates(LocalProductBindingEvidence $binding): array
    {
        $target = $binding->target;
        $products = $this->connection->select('SELECT id_product, id_supplier FROM ' . $this->connection->table('product')
            . ' WHERE id_product = ?', [$target->getProductId()]);
        $shops = $this->connection->select('SELECT id_product FROM ' . $this->connection->table('product_shop')
            . ' WHERE id_product = ? AND id_shop = ?', [$target->getProductId(), $target->getShopId()]);
        // All supplier/attribute rows for this product are evidence, including contradictions.
        // product_supplier is global and has no id_shop column in installed PrestaShop 9.1.
        $suppliers = $this->connection->select('SELECT id_product, id_product_attribute, id_supplier, '
            . 'CAST(product_supplier_reference AS BINARY) AS order_reference FROM '
            . $this->connection->table('product_supplier') . ' WHERE id_product = ?', [$target->getProductId()]);
        if (count($products) > 1 || count($shops) > 1) {
            throw new \UnexpectedValueException('Ambiguous native product evidence.');
        }
        $default = $products === [] || $products[0]['id_supplier'] === null ? 0
            : RegistryRow::integer($products[0]['id_supplier'], true);
        $candidates = [];
        foreach ($suppliers ?: [null] as $row) {
            $nativeTarget = $target;
            $identity = null;
            if ($row !== null) {
                $nativeTarget = new LocalProductTarget(RegistryRow::integer($row['id_product']),
                    RegistryRow::integer($row['id_product_attribute'], true), $target->getShopId());
                $supplierId = RegistryRow::integer($row['id_supplier']);
                $reference = $row['order_reference'];
                if (is_string($reference) && self::referenceCompatible($reference)) {
                    $identity = new SupplierOfferIdentity($supplierId, $reference);
                }
            }
            $candidates[] = new LocalProductCandidate($binding, new LocalProductNativeEvidence($nativeTarget,
                $identity, count($products) === 1, count($shops) === 1, $default));
        }
        return $candidates;
    }

    /** Native VARCHAR(64)/isReference compatibility, without changing any bytes. */
    public static function referenceCompatible(string $reference): bool
    {
        return $reference !== '' && !str_contains($reference, "\0")
            && preg_match('/\A[^<>;={}]{1,64}\z/u', $reference) === 1
            && preg_match('/\A[\s\p{Z}]*\z/u', $reference) !== 1;
    }
}
