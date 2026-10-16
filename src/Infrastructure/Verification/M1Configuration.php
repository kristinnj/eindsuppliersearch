<?php

declare(strict_types=1);

namespace EindSupplierSearch\Infrastructure\Verification;

/** Read literals only: no module constructor or executable PrestaShop bootstrap. */
final class M1Configuration
{
    public static function configuration(string $path, string $moduleRoot): array
    {
        $config = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        foreach (['host', 'database', 'prefix', 'user', 'password', 'server_hostname', 'metadata_definer'] as $key) {
            if (!isset($config[$key]) || !is_string($config[$key]) || ($key !== 'prefix' && $config[$key] === '')) {
                throw new \RuntimeException('Invalid configuration.');
            }
        }
        if (!preg_match('/\A[A-Za-z0-9_.-]+\z/D', $config['host'])
            || !preg_match('/\A[A-Za-z0-9_]+\z/D', $config['database'])
            || !preg_match('/\A[A-Za-z0-9_]*\z/D', $config['prefix'])
            || strlen($config['prefix']) > 32
            || !is_int($config['port'] ?? null) || $config['port'] < 1 || $config['port'] > 65535) {
            throw new \RuntimeException('Invalid target.');
        }
        $parameters = self::installedParameters((string) file_get_contents(
            dirname($moduleRoot, 2) . '/app/config/parameters.php'
        ));
        self::matchTarget($parameters, $config);
        return $config;
    }

    /**
     * Supported grammar: <?php return ['parameters' => [...]]; or array(...).
     * String-keyed literal arrays, single-quoted strings, unsigned integers,
     * true/false/null only. Comments are trivia, never assignments. No execution.
     */
    public static function installedParameters(string $source): array
    {
        if (strlen($source) > 1048576) { throw new \RuntimeException('Unsupported installed configuration.'); }
        $tokens = array_values(array_filter(token_get_all($source), static fn ($t) =>
            !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $i = 0;
        if (($tokens[$i++][0] ?? null) !== T_OPEN_TAG
            || ($tokens[$i++][0] ?? null) !== T_RETURN) {
            throw new \RuntimeException('Unsupported installed configuration.');
        }
        $root = self::value($tokens, $i, 0);
        if (($tokens[$i++] ?? null) !== ';' || $i !== count($tokens)
            || !is_array($root) || array_keys($root) !== ['parameters'] || !is_array($root['parameters'])) {
            throw new \RuntimeException('Unsupported installed configuration.');
        }
        $parameters = $root['parameters'];
        foreach (['database_host', 'database_port', 'database_name', 'database_prefix'] as $key) {
            if (!array_key_exists($key, $parameters)) {
                throw new \RuntimeException('Incomplete installed configuration.');
            }
        }
        return $parameters;
    }

    private static function value(array $tokens, int &$i, int $depth): mixed
    {
        if ($depth > 16) { throw new \RuntimeException('Unsupported installed configuration.'); }
        $token = $tokens[$i++] ?? null;
        if ($token === '[' || (is_array($token) && $token[0] === T_ARRAY)) {
            $end = ']';
            if ($token !== '[') {
                if (($tokens[$i++] ?? null) !== '(') { throw new \RuntimeException('Unsupported installed configuration.'); }
                $end = ')';
            }
            $result = [];
            while (($tokens[$i] ?? null) !== $end) {
                $key = self::value($tokens, $i, $depth + 1);
                if (!is_string($key) || !preg_match('/\\A[A-Za-z_][A-Za-z0-9_]*\\z/D', $key)
                    || array_key_exists($key, $result) || ($tokens[$i++][0] ?? null) !== T_DOUBLE_ARROW) {
                    throw new \RuntimeException('Ambiguous installed configuration.');
                }
                $result[$key] = self::value($tokens, $i, $depth + 1);
                if (($tokens[$i] ?? null) === $end) { break; }
                if (($tokens[$i++] ?? null) !== ',') { throw new \RuntimeException('Unsupported installed configuration.'); }
            }
            ++$i;
            return $result;
        }
        if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && $token[1][0] === "'") {
            // PHP single-quoted strings decode only backslash-backslash and backslash-quote.
            return str_replace(["\\\\", "\\'"], ["\\", "'"], substr($token[1], 1, -1));
        }
        if (is_array($token) && $token[0] === T_LNUMBER && preg_match('/\\A(?:0|[1-9][0-9]*)\\z/D', $token[1])
            && (strlen($token[1]) < strlen((string) PHP_INT_MAX)
                || (strlen($token[1]) === strlen((string) PHP_INT_MAX) && strcmp($token[1], (string) PHP_INT_MAX) <= 0))) {
            return (int) $token[1];
        }
        if (is_array($token) && $token[0] === T_STRING) {
            return match (strtolower($token[1])) {
                'null' => null, 'true' => true, 'false' => false,
                default => throw new \RuntimeException('Unsupported installed configuration.'),
            };
        }
        throw new \RuntimeException('Unsupported installed configuration.');
    }

    public static function matchTarget(array $parameters, array $config): void
    {
        foreach (['database_host' => 'host', 'database_name' => 'database', 'database_prefix' => 'prefix'] as $key => $target) {
            if (!isset($parameters[$key]) || !is_string($parameters[$key]) || $parameters[$key] !== $config[$target]) {
                throw new \RuntimeException('Target mismatch.');
            }
        }
        $port = $parameters['database_port'] ?? null;
        if ($port === null || $port === '') { $port = 3306; }
        if (is_string($port) && preg_match('/\\A[1-9][0-9]{0,4}\\z/D', $port)) { $port = (int) $port; }
        if (!is_int($port) || $port < 1 || $port > 65535 || $port !== $config['port']) {
            throw new \RuntimeException('Target port mismatch.');
        }
    }
}
