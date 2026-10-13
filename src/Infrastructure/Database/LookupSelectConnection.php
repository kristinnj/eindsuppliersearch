<?php

declare(strict_types=1);

namespace EindSupplierSearch\Infrastructure\Database;

/** Narrow SELECT-only facade; PDO is supplied explicitly, never discovered from core. */
final class LookupSelectConnection
{
    public function __construct(private readonly \PDO $pdo, private readonly string $prefix)
    {
        if (!preg_match('/\A[A-Za-z0-9_]*\z/D', $prefix) || strlen($prefix . OfferBindingSchema::TABLE) > 64) {
            throw new \InvalidArgumentException('Invalid lookup table prefix.');
        }
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES)) {
            throw new \InvalidArgumentException('Lookup requires MySQL PDO with native prepared statements.');
        }
    }

    public function table(string $name): string
    {
        if (!in_array($name, [OfferBindingSchema::TABLE, 'product', 'product_shop', 'product_supplier',
            'supplier', 'supplier_shop', 'shop'], true)) {
            throw new \InvalidArgumentException('Unknown lookup table.');
        }
        return '`' . $this->prefix . $name . '`';
    }

    /** Binary values are bound as LOBs; no reference is interpolated in SQL. */
    public function select(string $sql, array $parameters): array
    {
        if (!str_starts_with($sql, 'SELECT ')) {
            throw new \InvalidArgumentException('Lookup permits SELECT statements only.');
        }
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new \RuntimeException('Lookup query preparation failed.');
        }
        foreach (array_values($parameters) as $index => $value) {
            $type = is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_LOB;
            if (!$statement->bindValue($index + 1, $value, $type)) {
                throw new \RuntimeException('Lookup parameter binding failed.');
            }
        }
        if (!$statement->execute()) {
            throw new \RuntimeException('Lookup query execution failed.');
        }
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
