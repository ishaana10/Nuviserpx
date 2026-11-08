<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;

/**
 * Payroll run references: RUN-YYYYMM-NNN, numbered in sequence for each company and month.
 *
 * A run reference ties payroll rows together: approval, return and salary payment all act on a reference. Two runs
 * that share one would be approved and paid as one, so generated references must never repeat. They used to be
 * random three-digit numbers, which collide. The next number is the larger of the company's saved counter and the
 * highest number already used that month (references typed by hand count too), taken under a short lock.
 */
class PayrollRunRefService
{
    public function __construct(private readonly mysqli $conn)
    {
    }

    public static function prefix(string $ym): string
    {
        return "RUN-{$ym}-";
    }

    public static function format(string $ym, int $n): string
    {
        return self::prefix($ym) . str_pad((string)$n, 3, '0', STR_PAD_LEFT);
    }

    /** Pure: the number at the end of a reference with this prefix, or null if it is not one of ours. */
    public static function suffix(string $ref, string $prefix): ?int
    {
        if (strncmp($ref, $prefix, strlen($prefix)) !== 0) {
            return null;
        }
        $tail = substr($ref, strlen($prefix));
        return ctype_digit($tail) ? (int)$tail : null;
    }

    /** Pure: the highest number used under the prefix (0 if none). */
    public static function highest(array $refs, string $prefix): int
    {
        $max = 0;
        foreach ($refs as $r) {
            $n = self::suffix((string)$r, $prefix);
            if ($n !== null && $n > $max) {
                $max = $n;
            }
        }
        return $max;
    }

    /** Pure: the next number given the saved counter and the references already in use. */
    public static function nextNumber(int $counter, array $refs, string $prefix): int
    {
        return max($counter, self::highest($refs, $prefix)) + 1;
    }

    /** Reserve and return the next reference for the company. Throws if the reservation lock cannot be taken. */
    public function next(int $tenantId, ?string $ym = null): string
    {
        $ym = $ym ?? date('Ym');
        $lock = "payroll_ref_{$tenantId}";
        $g = $this->conn->prepare("SELECT GET_LOCK(?, 5) ok");
        $g->bind_param('s', $lock);
        $g->execute();
        if ((int)($g->get_result()->fetch_assoc()['ok'] ?? 0) !== 1) {
            throw new \RuntimeException('Another payroll run is being numbered. Please try again.');
        }
        try {
            $c = $this->conn->prepare("SELECT last_no FROM payroll_run_counters WHERE tenant_id = ? AND ym = ?");
            $c->bind_param('is', $tenantId, $ym);
            $c->execute();
            $counter = (int)($c->get_result()->fetch_assoc()['last_no'] ?? 0);

            $prefix = self::prefix($ym);
            $like = $prefix . '%';
            $q = $this->conn->prepare("SELECT DISTINCT payroll_run_ref FROM payroll_list WHERE tenant_id = ? AND payroll_run_ref LIKE ?");
            $q->bind_param('is', $tenantId, $like);
            $q->execute();
            $refs = array_column($q->get_result()->fetch_all(MYSQLI_ASSOC), 'payroll_run_ref');

            $n = self::nextNumber($counter, $refs, $prefix);
            $u = $this->conn->prepare("INSERT INTO payroll_run_counters (tenant_id, ym, last_no) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE last_no = VALUES(last_no)");
            $u->bind_param('isi', $tenantId, $ym, $n);
            if (!$u->execute()) {
                throw new \RuntimeException('The payroll run could not be numbered.');
            }
            return self::format($ym, $n);
        } finally {
            $r = $this->conn->prepare("SELECT RELEASE_LOCK(?)");
            $r->bind_param('s', $lock);
            $r->execute();
            $r->get_result();
        }
    }
}
