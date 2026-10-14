<?php

declare(strict_types=1);

namespace EindSupplierSearch\Infrastructure\Verification;

/** Read literals only: no module constructor or executable PrestaShop bootstrap. */
final class VerificationSource
{
    public static function read(string $moduleRoot): array
    {
        $php = file_get_contents($moduleRoot . '/eindsuppliersearch.php');
        $xmlText = file_get_contents($moduleRoot . '/config.xml');
        if ($php === false || $xmlText === false || str_contains($xmlText, '<!DOCTYPE')) {
            throw new \RuntimeException('Source unavailable.');
        }
        $php = implode('', array_map(static fn ($token): string => is_array($token)
            ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1]) : $token, token_get_all($php)));
        preg_match_all('/\$this->version\s*=\s*[\x27\x22]([0-9]+\.[0-9]+\.[0-9]+)[\x27\x22]\s*;/', $php, $versions);
        if (count($versions[1]) !== 1) {
            throw new \RuntimeException('Source version unavailable.');
        }
        preg_match_all('/\$this->name\s*=\s*[\x27\x22]([A-Za-z0-9_]+)[\x27\x22]\s*;/', $php, $names);
        $xml = simplexml_load_string($xmlText, \SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false) {
            throw new \RuntimeException('Source XML unavailable.');
        }
        return ['version' => $versions[1][0], 'agrees' => count($names[1]) === 1 && $names[1][0] === UpgradeVerification::MODULE
            && (string) $xml->version === $versions[1][0]
            && (string) $xml->name === UpgradeVerification::MODULE];
    }

    public static function configuration(string $path, string $moduleRoot): array
    {
        $config = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        foreach (['host', 'database', 'prefix', 'user', 'password', 'server_hostname', 'metadata_definer'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
                throw new \RuntimeException('Invalid configuration.');
            }
        }
        if (!preg_match('/\A[A-Za-z0-9_.-]+\z/D', $config['host'])
            || !preg_match('/\A[A-Za-z0-9_]+\z/D', $config['database'])
            || !preg_match('/\A[A-Za-z0-9_]+\z/D', $config['prefix'])
            || strlen($config['prefix']) > 40
            || !is_int($config['port'] ?? null) || $config['port'] < 1 || $config['port'] > 65535) {
            throw new \RuntimeException('Invalid target.');
        }
        // Native generated file contains literal configuration; never require/evaluate it.
        $parameters = file_get_contents(dirname($moduleRoot, 2) . '/app/config/parameters.php');
        if ($parameters === false) {
            throw new \RuntimeException('Installed target unavailable.');
        }
        foreach (['database_host' => 'host', 'database_name' => 'database', 'database_prefix' => 'prefix'] as $key => $target) {
            $pattern = '/[\x27\x22]' . $key . '[\x27\x22]\s*=>\s*\x27([A-Za-z0-9_.-]+)\x27/';
            preg_match_all($pattern, $parameters, $matches);
            if (count($matches[1]) !== 1 || $matches[1][0] !== $config[$target]) {
                throw new \RuntimeException('Target mismatch.');
            }
        }
        preg_match('/[\x27\x22]database_port[\x27\x22]\s*=>\s*(NULL|null|\x27([0-9]*)\x27|([0-9]+))/', $parameters, $port);
        if (!$port || (int) (($port[2] ?? '') ?: (($port[3] ?? '') ?: 3306)) !== $config['port']) {
            throw new \RuntimeException('Target port mismatch.');
        }
        return $config;
    }
}
