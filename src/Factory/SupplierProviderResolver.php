<?php

namespace EindSupplierSearch\Factory;

use Configuration;
use EindCallSupplierApi;
use EindSupplierSearch\Contract\SupplierProviderInterface;
use EindSupplierSearch\Provider\JsonFixtureSupplierProvider;
use EindSupplierSearch\Provider\LiveSupplierProvider;
use PrestaShopLogger;

/**
 * Chooses between LiveSupplierProvider and JsonFixtureSupplierProvider
 * based on configuration only. Nothing outside this class (in particular,
 * the controller) branches on the mode.
 */
final class SupplierProviderResolver
{
    public const MODE_LIVE = 'live';
    public const MODE_FIXTURE = 'fixture';

    public const CONFIG_KEY = 'EIND_SUPPLIERSEARCH_API_MODE';

    private string $mode;
    private string $fixturesPath;

    /** @var callable(string, int): void */
    private $logger;

    /**
     * @param callable(string, int): void|null $logger Receives (message, severity).
     */
    public function __construct(string $mode, string $fixturesPath, ?callable $logger = null)
    {
        $this->mode = strtolower(trim($mode)) === self::MODE_FIXTURE ? self::MODE_FIXTURE : self::MODE_LIVE;
        $this->fixturesPath = $fixturesPath;
        $this->logger = $logger ?? static function (string $message, int $severity): void {
        };
    }

    /**
     * Builds a resolver wired to the module's real PrestaShop configuration
     * value and logger. Not used by unit tests (those use the constructor
     * directly), which is what keeps this class testable without a
     * PrestaShop bootstrap.
     */
    public static function forModule(): self
    {
        $configuredMode = (string) Configuration::get(self::CONFIG_KEY);
        $fixturesPath = dirname(__DIR__, 2) . '/tests/fixtures/suppliers';
        $logger = static function (string $message, int $severity): void {
            PrestaShopLogger::addLog('[EindSupplierSearch] ' . $message, $severity);
        };

        return new self($configuredMode !== '' ? $configuredMode : self::MODE_LIVE, $fixturesPath, $logger);
    }

    public function resolve(): SupplierProviderInterface
    {
        ($this->logger)(sprintf('resolved supplier provider mode=%s', $this->mode), 1);

        if ($this->mode === self::MODE_FIXTURE) {
            return new JsonFixtureSupplierProvider($this->fixturesPath, $this->logger);
        }

        return new LiveSupplierProvider(new EindCallSupplierApi());
    }

    public function getMode(): string
    {
        return $this->mode;
    }
}
