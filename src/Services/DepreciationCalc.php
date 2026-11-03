<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Depreciation maths for the fixed-asset register. Pure functions: no database.
 *
 * An asset is described by the register's own fields: purchase_price, cost_limit, residual_value,
 * dep_start_date, dep_method (Straight Line | Diminishing Value | Full Depreciation | No Depreciation),
 * averaging_method (Full Month | Actual Days) and either dep_rate (% a year) or effective_life_years.
 * Charges are monthly; each month is worked out from what has already been charged, so the schedule can be
 * posted in order, month by month, and never takes the carrying value below the residual value.
 */
final class DepreciationCalc
{
    /** The cost depreciation is based on: the purchase price, capped by the cost limit when one is set. */
    public static function cost(array $a): float
    {
        $cost = (float)($a['purchase_price'] ?? 0);
        $limit = (float)($a['cost_limit'] ?? 0);
        return round($limit > 0 ? min($cost, $limit) : $cost, 2);
    }

    /** What may be depreciated in total: cost less residual value, never negative. */
    public static function depreciableAmount(array $a): float
    {
        return round(max(0.0, self::cost($a) - (float)($a['residual_value'] ?? 0)), 2);
    }

    /** Annual rate as a fraction (0.20 = 20%). Straight line from a life is 1/life; diminishing value is twice that. */
    public static function annualRate(array $a): float
    {
        $method = (string)($a['dep_method'] ?? '');
        $byLife = ($a['dep_calc_by'] ?? 'rate') === 'effective_life';
        $life = (float)($a['effective_life_years'] ?? 0);
        if ($byLife) {
            if ($life <= 0) {
                return 0.0;
            }
            return $method === 'Diminishing Value' ? min(1.0, 2 / $life) : 1 / $life;
        }
        return max(0.0, min(100.0, (float)($a['dep_rate'] ?? 0))) / 100;
    }

    /** First day of the month a date falls in. */
    public static function monthStart(string $date): string
    {
        return substr($date, 0, 7) . '-01';
    }

    /** Last day of the month a date falls in. */
    public static function monthEnd(string $date): string
    {
        return (new \DateTimeImmutable(self::monthStart($date)))->modify('last day of this month')->format('Y-m-d');
    }

    /**
     * How much of a month the asset is in use: 0 before it starts, 1 for a full month, and in its first month
     * 1 (Full Month averaging) or the days left in the month over the days in it (Actual Days).
     */
    public static function monthFraction(string $startDate, string $periodEnd, string $averaging): float
    {
        $startMonth = substr($startDate, 0, 7);
        $periodMonth = substr($periodEnd, 0, 7);
        if ($periodMonth < $startMonth) {
            return 0.0;
        }
        if ($periodMonth > $startMonth || $averaging !== 'Actual Days') {
            return 1.0;
        }
        $days = (int)date('t', strtotime($startDate));
        $left = $days - (int)date('j', strtotime($startDate)) + 1;
        return $left / $days;
    }

    /**
     * The charge for the month ending $periodEnd, given what has been charged so far ($accumulated).
     * Rounded to cents and limited so accumulated depreciation never exceeds the depreciable amount.
     */
    public static function monthlyCharge(array $a, string $periodEnd, float $accumulated): float
    {
        $method = (string)($a['dep_method'] ?? '');
        $start = (string)($a['dep_start_date'] ?? '');
        if ($start === '' || $method === '' || $method === 'No Depreciation') {
            return 0.0;
        }
        $room = round(self::depreciableAmount($a) - $accumulated, 2);
        if ($room <= 0) {
            return 0.0;
        }
        $fraction = self::monthFraction($start, $periodEnd, (string)($a['averaging_method'] ?? 'Full Month'));
        if ($fraction <= 0) {
            return 0.0;
        }
        if ($method === 'Full Depreciation') {
            return $room;
        }
        $rate = self::annualRate($a);
        if ($rate <= 0) {
            return 0.0;
        }
        if ($method === 'Diminishing Value') {
            $carrying = self::cost($a) - $accumulated;
            $charge = $carrying * $rate / 12 * $fraction;
        } else { // Straight Line
            $charge = self::depreciableAmount($a) * $rate / 12 * $fraction;
        }
        return round(min($room, max(0.0, $charge)), 2);
    }

    /** Month-end dates from the month after the last charged one (or the start month) up to and including $through. */
    public static function monthsToPost(string $startDate, ?string $lastChargedEnd, string $through): array
    {
        $first = $lastChargedEnd === null
            ? self::monthStart($startDate)
            : (new \DateTimeImmutable(self::monthStart($lastChargedEnd)))->modify('+1 month')->format('Y-m-d');
        $out = [];
        $d = new \DateTimeImmutable($first);
        while (count($out) < 600) {
            $end = self::monthEnd($d->format('Y-m-d'));
            if ($end > self::monthEnd($through)) {
                break;
            }
            $out[] = $end;
            $d = $d->modify('first day of next month');
        }
        return $out;
    }

    /**
     * Journal lines for disposing of an asset. Cost leaves, accumulated depreciation is cleared, proceeds come in
     * and the difference is a gain (credit) or loss (debit). Always balances.
     * Returns lines as [account_id, debit, credit, description] plus 'gain' (positive = gain).
     */
    public static function disposalLines(float $cost, float $accumulated, float $proceeds, array $acc): array
    {
        $cost = round($cost, 2);
        $accumulated = round($accumulated, 2);
        $proceeds = round($proceeds, 2);
        $gain = round($proceeds + $accumulated - $cost, 2);

        $lines = [];
        if ($proceeds > 0) {
            $lines[] = ['account_id' => $acc['proceeds'], 'debit' => $proceeds, 'credit' => 0.0, 'description' => 'Disposal proceeds'];
        }
        if ($accumulated > 0) {
            $lines[] = ['account_id' => $acc['accumulated'], 'debit' => $accumulated, 'credit' => 0.0, 'description' => 'Clear accumulated depreciation'];
        }
        if ($gain < 0) {
            $lines[] = ['account_id' => $acc['gain_loss'], 'debit' => abs($gain), 'credit' => 0.0, 'description' => 'Loss on disposal'];
        }
        $lines[] = ['account_id' => $acc['cost'], 'debit' => 0.0, 'credit' => $cost, 'description' => 'Remove asset at cost'];
        if ($gain > 0) {
            $lines[] = ['account_id' => $acc['gain_loss'], 'debit' => 0.0, 'credit' => $gain, 'description' => 'Gain on disposal'];
        }
        return ['lines' => $lines, 'gain' => $gain];
    }
}
