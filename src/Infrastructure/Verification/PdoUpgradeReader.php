<?php

declare(strict_types=1);

namespace EindSupplierSearch\Infrastructure\Verification;

use EindSupplierSearch\Infrastructure\Database\OfferBindingSchema;

/** SQL allowlist plus a SELECT-only account. No cached/native-core reads. */
final class PdoUpgradeReader
{
    private array $queries = [];

    public function __construct(private readonly \PDO $pdo, private readonly string $prefix)
    {
        if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql'
            || $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES)
            || !preg_match('/\A[A-Za-z0-9_]+\z/D', $prefix) || strlen($prefix) > 40) {
            throw new \RuntimeException('Invalid read connection.');
        }
    }

    public function select(string $sql, array $parameters = []): array
    {
        if (!preg_match('/\ASELECT\s/i', $sql) || str_contains($sql, ';')
            || preg_match('/\b(INTO|FOR\s+UPDATE|GET_LOCK|RELEASE_LOCK|SLEEP)\b/i', $sql)) {
            throw new \LogicException('Non-read query rejected.');
        }
        $this->queries[] = $sql;
        $statement = $this->pdo->prepare($sql);
        if (!$statement || !$statement->execute($parameters)) {
            throw new \RuntimeException('Read failed.');
        }
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function validateTarget(array $config): array
    {
        $identity = $this->select('SELECT DATABASE() AS db, @@hostname AS hostname, CURRENT_USER() AS account')[0];
        if ($identity['db'] !== $config['database'] || $identity['hostname'] !== $config['server_hostname']) {
            throw new \RuntimeException('Database identity mismatch.');
        }
        // SHOW GRANTS is read-only metadata. Require full DB SELECT to avoid invisible tables.
        $grants = $this->pdo->query('SHOW GRANTS FOR CURRENT_USER')->fetchAll(\PDO::FETCH_COLUMN);
        $select = false;
        foreach ($grants as $grant) {
            if (str_starts_with($grant, 'GRANT USAGE ON *.* TO ') && !str_contains($grant, 'WITH GRANT OPTION')) {
                continue;
            }
            if (preg_match('/\AGRANT SELECT, SHOW VIEW ON `([^`]+)`\.\* TO /', $grant, $match)
                && str_replace('\\_', '_', $match[1]) === $config['database']
                && !str_contains($grant, 'WITH GRANT OPTION')) {
                $select = true;
                continue;
            }
            throw new \RuntimeException('Unexpected database permissions.');
        }
        if (!$select) {
            throw new \RuntimeException('Metadata visibility unproven.');
        }
        $this->validateTriggerMetadata($config);
        $settings = $this->select('SELECT @@check_constraint_checks AS checks, @@unique_checks AS uniques')[0];
        if ((int) $settings['checks'] !== 1 || (int) $settings['uniques'] !== 1) {
            throw new \RuntimeException('Constraint enforcement unavailable.');
        }
        return ['database' => $identity['db'], 'table_prefix' => $this->prefix, 'server_hostname' => $identity['hostname']];
    }

    private function validateTriggerMetadata(array $config): void
    {
        $view = $this->select('SELECT VIEW_DEFINITION,SECURITY_TYPE,DEFINER,IS_UPDATABLE FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$this->prefix . 'eind_upgrade_trigger_metadata']);
        $expected = "SELECT TRIGGER_SCHEMA, EVENT_OBJECT_TABLE, TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '" . $config['database'] . "'";
        if (count($view) !== 1 || $view[0]['SECURITY_TYPE'] !== 'DEFINER'
            || $view[0]['DEFINER'] !== $config['metadata_definer'] || $view[0]['IS_UPDATABLE'] !== 'NO'
            || self::normalizeView($view[0]['VIEW_DEFINITION']) !== self::normalizeView($expected)) {
            throw new \RuntimeException('Trigger metadata visibility unproven.');
        }
        $capability = $this->select('SELECT VIEW_DEFINITION,SECURITY_TYPE,DEFINER FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?',
            [$this->prefix . 'eind_upgrade_metadata_capability']);
        if (count($capability) !== 1 || $capability[0]['SECURITY_TYPE'] !== 'DEFINER'
            || $capability[0]['DEFINER'] !== $config['metadata_definer']
            || self::normalizeView($capability[0]['VIEW_DEFINITION']) !== self::normalizeView('SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES')) {
            throw new \RuntimeException('Metadata capability unavailable.');
        }
        $definer = explode('@', $config['metadata_definer']);
        if (count($definer) !== 2 || !preg_match('/\\A[A-Za-z0-9_.-]+@[A-Za-z0-9_.%-]+\\z/D', $config['metadata_definer'])) {
            throw new \RuntimeException('Invalid metadata definer.');
        }
        $privileges = $this->select('SELECT PRIVILEGE_TYPE FROM `' . $this->prefix . 'eind_upgrade_metadata_capability` WHERE GRANTEE=? AND PRIVILEGE_TYPE=?',
            ["'" . $definer[0] . "'@'" . $definer[1] . "'", 'TRIGGER']);
        if (count($privileges) !== 1) {
            throw new \RuntimeException('Definer trigger visibility unavailable.');
        }
        // Query it now: a missing definer or lost privileges is operational failure.
        $this->select('SELECT TRIGGER_NAME FROM `' . $this->prefix . 'eind_upgrade_trigger_metadata` WHERE EVENT_OBJECT_TABLE=?',
            [$this->prefix . OfferBindingSchema::TABLE]);
    }

    private static function normalizeView(string $sql): string
    {
        $parts = preg_split("/('[^']*')/", $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $index => &$part) {
            if ($index % 2 === 0) {
                $part = preg_replace('/\\s+/', '', strtolower(str_replace(['`', '(', ')'], '', $part)));
                $part = str_replace(['information_schema.triggers.', 'information_schema.user_privileges.'], '', $part);
                foreach (['trigger_schema', 'event_object_table', 'trigger_name', 'grantee', 'privilege_type'] as $column) {
                    $part = str_replace('as' . $column, '', $part);
                }
            }
        }
        return implode('', $parts);
    }

    public function registration(): array
    {
        $modules = $this->select('SELECT id_module,name,version,active FROM `' . $this->prefix . 'module` WHERE name=?', [UpgradeVerification::MODULE]);
        $shops = $this->select('SELECT id_shop,active,deleted FROM `' . $this->prefix . 'shop` ORDER BY id_shop');
        $links = $this->select('SELECT ms.id_module,ms.id_shop,ms.enable_device FROM `' . $this->prefix . 'module_shop` ms JOIN `'
            . $this->prefix . 'module` m ON m.id_module=ms.id_module WHERE m.name=? ORDER BY ms.id_shop', [UpgradeVerification::MODULE]);
        return compact('modules', 'shops', 'links');
    }

    public function registry(): array
    {
        $exists = $this->select('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$this->prefix . OfferBindingSchema::TABLE]) !== [];
        if (!$exists) {
            return ['exists' => false, 'schema_valid' => false];
        }
        $schema = new OfferBindingSchema($this->prefix,
            static function (string $sql): bool { throw new \LogicException('Mutation prohibited.'); },
            fn (string $sql): array => $this->select(str_replace('information_schema.TRIGGERS',
                '`' . $this->prefix . 'eind_upgrade_trigger_metadata`', $sql)));
        try {
            $schema->verify();
        } catch (\PDOException $error) {
            throw $error;
        } catch (\RuntimeException $error) {
            // Only known structural incompatibilities are integrity failures.
            if (!str_starts_with($error->getMessage(), 'Incompatible offer binding')
                && !str_starts_with($error->getMessage(), 'Unexpected offer binding')) {
                throw $error;
            }
            return ['exists' => true, 'schema_valid' => false];
        }
        return ['exists' => true, 'schema_valid' => true];
    }

    public function queries(): array
    {
        return $this->queries;
    }
}
