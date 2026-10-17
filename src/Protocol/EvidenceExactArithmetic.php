<?php

declare(strict_types=1);

namespace EindSupplierSearch\Protocol;

/** @internal Unsigned decimal digit arithmetic; no machine-sized monetary intermediates. */
final class EvidenceExactArithmetic
{
    public static function compare(string $a, string $b): int
    {
        $a = self::trim($a);
        $b = self::trim($b);
        return (strlen($a) <=> strlen($b)) ?: (strcmp($a, $b) <=> 0);
    }

    public static function add(string $a, string $b): string
    {
        $out = '';
        $carry = 0;
        for ($i = strlen($a) - 1, $j = strlen($b) - 1; $i >= 0 || $j >= 0 || $carry; --$i, --$j) {
            $sum = ($i >= 0 ? (int) $a[$i] : 0) + ($j >= 0 ? (int) $b[$j] : 0) + $carry;
            $out = (string) ($sum % 10) . $out;
            $carry = intdiv($sum, 10);
        }
        return self::trim($out);
    }

    public static function multiply(string $a, string $b): string
    {
        $out = '0';
        foreach (str_split($b) as $digit) {
            $out .= '0';
            for ($i = 0; $i < (int) $digit; ++$i) { $out = self::add($out, $a); }
        }
        return self::trim($out);
    }

    /** Divide by 10^digits with exact HALF_UP rounding. */
    public static function round(string $a, int $digits): string
    {
        if ($digits === 0) { return self::trim($a); }
        $a = str_pad($a, $digits + 1, '0', STR_PAD_LEFT);
        $whole = substr($a, 0, -$digits);
        return self::trim((int) $a[strlen($a) - $digits] >= 5 ? self::add($whole, '1') : $whole);
    }

    public static function scaled(string $money): string
    {
        CanonicalDecimal::decimal($money);
        return self::trim(str_replace('.', '', $money));
    }

    public static function money(string $scaled, bool $positive = false): string
    {
        $scaled = str_pad(self::trim($scaled), 7, '0', STR_PAD_LEFT);
        return CanonicalDecimal::decimal(substr($scaled, 0, -6) . '.' . substr($scaled, -6), $positive);
    }

    /** Validate all frozen unit/net/tax/gross formulas, including the final net floor. */
    public static function quote(\stdClass $q): void
    {
        $p = $q->commercial_policy;
        $unit = self::round(self::multiply(self::scaled($q->source_price->unit_price_tax_excl),
            self::add('10000', $p->markup_rate_bps)), 4);
        $precision = (int) $p->currency_precision;
        $net = self::round(self::multiply($unit, $q->quantity), 6 - $precision) . str_repeat('0', 6 - $precision);
        $tax = self::round(self::multiply($net, self::scaled($q->tax_basis->rates[0])), 14 - $precision)
            . str_repeat('0', 6 - $precision);
        $gross = self::add($net, $tax);
        EvidenceProfileSchema::check($q->unit_price_tax_excl === self::money($unit, true)
            && $q->accepted_amounts->net_line === self::money($net, true)
            && $q->accepted_amounts->tax_line === self::money($tax)
            && $q->accepted_amounts->gross_line === self::money($gross, true)
            && self::compare($net, self::scaled($p->minimum_net_line)) >= 0, 'COMMERCIAL_TERMS_INVALID');
    }

    private static function trim(string $value): string
    {
        return ltrim($value, '0') ?: '0';
    }
}
