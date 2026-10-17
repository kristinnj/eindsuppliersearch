<?php

declare(strict_types=1);

namespace EindSupplierSearch\Tests\Protocol;

use EindSupplierSearch\Protocol\CanonicalJson;

/** Test-only local fixture loading. Protocol classes have no filesystem dependencies. */
final class ProfileFixtures
{
    public const CURRENCIES = ['1' => ['1' => 'EUR']];
    public const DIGESTS = [
        'transport' => 'de8788ff26db56602ad806e9d737a8597b83153df464d939ae58134f963f6ad4',
        'integration' => '0021934aa1aeb580f092f793aa747098305d2556cb8a046344f5bd50ded39c92',
        'observation_policy' => '05b05186bc5c5fb349b79ea9f9e3fed302b6277a690be0beabbed8fede74d64f',
        'response_projection' => '39e7c4c7c8d77e65021a4f487291bf23060c2bb10e2559303825f01dcae204e2',
        'markup' => '6526888072ea00a040d79301c5c6c7a610658b568bea9af2d89ee4894e138c47',
        'rounding' => 'c1ad389a4fa78ae43535a6c02df0a360025935926b9cb2bcaaa1822f187d8f43',
        'tax' => '77e572dbda8625427d7925cd928a8f55075b5fdc0fdd37ee0d09cb94cbf62bdb',
        'quote_bundle' => '662afd35ca690f68f66e2cb8b2f8892390f6e775fa0522552b404810c4f55a5a',
        'supplier_validity' => '878e483cff0840ebb6587e75f4c9756a51e909efeeea6de2aa43d4f64216d098',
        'approval_manifest' => '8408540e8053874f1059e7873503764350ad3c9582b34e7f3ee09722aa43b3d0',
        'observation' => '6054f682855f9488e60695608f684a1b8092255d24bdf1ec24c4ecbd08fc839a',
        'quote' => 'c004e87e2909ef0dfb3a96c27b7c0ca74b49818df8883b01a2ba560be8956bea',
    ];

    public static function bytes(string $kind): string
    {
        return file_get_contents(__DIR__ . '/fixtures/oq1/' . $kind . '.json');
    }

    public static function observationArtifacts(): array
    {
        return array_combine(['integration', 'transport', 'observation_policy', 'supplier_validity', 'response_projection'],
            array_map(self::bytes(...), ['integration', 'transport', 'observation_policy', 'supplier_validity', 'response_projection']));
    }

    public static function artifacts(): array
    {
        return self::observationArtifacts() + array_combine(['markup', 'rounding', 'tax', 'quote_bundle', 'approval_manifest'],
            array_map(self::bytes(...), ['markup', 'rounding', 'tax', 'quote_bundle', 'approval_manifest']));
    }

    /** Rehash changed synthetic graph edges without repairing any semantic fields. */
    public static function rehash(string $o, string $q, array $a): array
    {
        $body = CanonicalJson::decode($o);
        $body->normalized_payload_sha256 = hash('sha256', CanonicalJson::encode($body->normalized_payload));
        $o = CanonicalJson::encode($body);
        foreach (['transport', 'integration', 'supplier_validity', 'observation_policy', 'response_projection',
            'markup', 'rounding', 'tax', 'quote_bundle', 'approval_manifest'] as $kind) {
            if (!isset($a[$kind])) { continue; }
            $old = self::DIGESTS[$kind];
            $new = hash('sha256', $a[$kind]);
            $o = str_replace($old, $new, $o);
            $q = str_replace($old, $new, $q);
            $a = array_map(static fn ($bytes) => str_replace($old, $new, $bytes), $a);
        }
        $q = str_replace(self::DIGESTS['observation'], hash('sha256', $o), $q);
        return [$o, $q, $a];
    }

    public static function mutate(string $bytes, string $path, mixed $value, bool $remove = false): string
    {
        $body = CanonicalJson::decode($bytes);
        $fields = explode('.', $path);
        $last = array_pop($fields);
        $object = $body;
        foreach ($fields as $field) { $object = $object->$field; }
        if ($remove) { unset($object->$last); } else { $object->$last = $value; }
        return CanonicalJson::encode($body);
    }
}
