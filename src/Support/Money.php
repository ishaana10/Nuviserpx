<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Exact money arithmetic for balance checks.
 *
 * Amounts are held as whole cents (integers), so adding ten thousand lines cannot drift the way
 * summing floats does. Use this wherever the answer is "do the books balance?" - display code can
 * keep rounding floats to 2 places.
 */
final class Money
{
    /** Round half away from zero to whole cents, via a string so 1.005 style values do not misround. */
    public static function cents(float|int|string|null $amount): int
    {
        $s = trim((string)($amount ?? 0));
        if ($s === '' || !is_numeric($s)) {
            return 0;
        }
        // Normalise through 6 decimals first to shed binary noise (e.g. 0.1 + 0.2), then round half up.
        $f = round((float)$s, 6);
        $neg = $f < 0;
        $c = (int)floor(abs($f) * 100 + 0.5 + 1e-9);
        return $neg ? -$c : $c;
    }

    /** Sum of amounts, in cents. */
    public static function sumCents(iterable $amounts): int
    {
        $t = 0;
        foreach ($amounts as $a) {
            $t += self::cents($a);
        }
        return $t;
    }

    /** Cents back to a 2-decimal string. */
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);
        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /** Cents back to a float for display and legacy callers. */
    public static function toFloat(int $cents): float
    {
        return $cents / 100;
    }

    /** True when two amounts are the same to the cent. */
    public static function equal(float|int|string|null $a, float|int|string|null $b): bool
    {
        return self::cents($a) === self::cents($b);
    }
}
