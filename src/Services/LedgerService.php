<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;

/**
 * The general ledger: one place that knows how journal lines turn into
 * account balances and financial statements.
 *
 * Conventions used across the app (invoices, payments, payroll, API, manual journals):
 *  - journal_items.group_id = 1 means DEBIT, 2 means CREDIT. Older AJMS data stored a
 *    group_list id instead; for those rows the group's `type` (1 = debit, 2 = credit) applies.
 *  - journal_entries.status: only posted journals hit the ledger. Drafts and cancelled
 *    journals are excluded everywhere.
 *  - account_list.type is free text ("Bank", "Current Asset", "Revenue", "Direct Costs"…);
 *    classify() maps it to one of five classes and a statement section.
 */
class LedgerService
{
    public const SIDE_SQL = "(CASE WHEN ji.group_id IN (1,2) THEN ji.group_id ELSE COALESCE(g.type, 1) END)";
    public const POSTED_SQL = "(j.status IS NULL OR LOWER(j.status) NOT IN ('draft','cancelled','canceled','void','2','3'))";

    /** Statement sections in display order, with the class they belong to. */
    public const SECTIONS = [
        'bank'                  => ['class' => 'asset',     'label' => 'Bank'],
        'current_asset'         => ['class' => 'asset',     'label' => 'Current assets'],
        'fixed_asset'           => ['class' => 'asset',     'label' => 'Fixed assets'],
        'non_current_asset'     => ['class' => 'asset',     'label' => 'Non-current assets'],
        'current_liability'     => ['class' => 'liability', 'label' => 'Current liabilities'],
        'non_current_liability' => ['class' => 'liability', 'label' => 'Non-current liabilities'],
        'equity'                => ['class' => 'equity',    'label' => 'Equity'],
        'revenue'               => ['class' => 'income',    'label' => 'Trading income'],
        'other_income'          => ['class' => 'income',    'label' => 'Other income'],
        'direct_costs'          => ['class' => 'expense',   'label' => 'Cost of sales'],
        'expense'               => ['class' => 'expense',   'label' => 'Operating expenses'],
    ];

    public const CLASS_LABELS = [
        'asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity',
        'income' => 'Income', 'expense' => 'Expenses',
    ];

    public function __construct(
        private readonly ?mysqli $conn,
        private readonly int $tenantId
    ) {}

    /* ==================================================================
     * Classification
     * ================================================================== */

    /**
     * Map an account's free-text type to [class, section, normal side].
     * Unknown types fall back to an operating expense, matching the column default.
     */
    public static function classify(?string $type): array
    {
        $t = strtolower(trim((string)$type));
        $section = 'expense';

        if ($t === 'bank' || str_contains($t, 'cash')) {
            $section = 'bank';
        } elseif (str_contains($t, 'fixed asset') || str_contains($t, 'property') || str_contains($t, 'equipment')) {
            $section = 'fixed_asset';
        } elseif (str_contains($t, 'non-current asset') || str_contains($t, 'non current asset') || str_contains($t, 'noncurrent asset')) {
            $section = 'non_current_asset';
        } elseif (str_contains($t, 'asset') || str_contains($t, 'inventory') || str_contains($t, 'prepayment') || str_contains($t, 'receivable')) {
            $section = 'current_asset';
        } elseif (str_contains($t, 'liabilit') || str_contains($t, 'payable')) {
            $section = (str_contains($t, 'non-current') || str_contains($t, 'non current') || str_contains($t, 'long term') || str_contains($t, 'term liabilit'))
                ? 'non_current_liability' : 'current_liability';
        } elseif (str_contains($t, 'equity') || str_contains($t, 'capital') || str_contains($t, 'retained') || str_contains($t, 'drawing')) {
            $section = 'equity';
        } elseif (str_contains($t, 'other income')) {
            $section = 'other_income';
        } elseif (str_contains($t, 'revenue') || str_contains($t, 'sales') || str_contains($t, 'income')) {
            $section = 'revenue';
        } elseif (str_contains($t, 'direct cost') || str_contains($t, 'cost of') || str_contains($t, 'cogs') || str_contains($t, 'purchases')) {
            $section = 'direct_costs';
        }

        $class = self::SECTIONS[$section]['class'];
        return [
            'class' => $class,
            'section' => $section,
            'normal' => in_array($class, ['asset', 'expense'], true) ? 'debit' : 'credit',
        ];
    }

    /** Balance in the account's natural sign (assets/expenses Dr−Cr, others Cr−Dr). */
    public static function natural(string $class, float $debit, float $credit): float
    {
        return in_array($class, ['asset', 'expense'], true) ? $debit - $credit : $credit - $debit;
    }

    /* ==================================================================
     * Financial year
     * ================================================================== */

    /** First day of the financial year containing $date, given the year-end month (1–12). */
    public static function fyStart(string $date, int $yearEndMonth = 12): string
    {
        $yearEndMonth = max(1, min(12, $yearEndMonth));
        $d = new \DateTimeImmutable($date);
        $startMonth = $yearEndMonth % 12 + 1;
        $year = (int)$d->format('Y');
        if ((int)$d->format('n') < $startMonth) {
            $year--;
        }
        return sprintf('%04d-%02d-01', $year, $startMonth);
    }

    /* ==================================================================
     * Raw balances
     * ================================================================== */

    /**
     * Per-account movement. With $from: opening (before $from) and period ($from..$to)
     * are split; without it everything up to $to is "period" and opening is zero.
     *
     * @return array<int, array{id:int,code:string,name:string,type:string,class:string,section:string,
     *               open_dr:float,open_cr:float,dr:float,cr:float}>
     */
    public function balances(?string $from, string $to, bool $includeEmpty = false): array
    {
        $rows = [];
        if ($this->conn) {
            $side = self::SIDE_SQL;
            $posted = self::POSTED_SQL;
            $fromSql = $from ?? '0001-01-01';
            $sql = "SELECT a.id, a.code, a.name, a.type,
                        COALESCE(SUM(CASE WHEN j.journal_date <  ? AND {$side} = 1 THEN ji.amount END), 0) open_dr,
                        COALESCE(SUM(CASE WHEN j.journal_date <  ? AND {$side} = 2 THEN ji.amount END), 0) open_cr,
                        COALESCE(SUM(CASE WHEN j.journal_date >= ? AND {$side} = 1 THEN ji.amount END), 0) dr,
                        COALESCE(SUM(CASE WHEN j.journal_date >= ? AND {$side} = 2 THEN ji.amount END), 0) cr
                    FROM account_list a
                    LEFT JOIN journal_items ji ON ji.account_id = a.id
                    LEFT JOIN journal_entries j ON j.id = ji.journal_id AND j.tenant_id = a.tenant_id
                         AND j.journal_date <= ? AND {$posted}
                    LEFT JOIN group_list g ON g.id = ji.group_id
                    WHERE a.tenant_id = ? AND a.delete_flag = 0
                    GROUP BY a.id, a.code, a.name, a.type
                    ORDER BY a.code IS NULL, a.code = '', CAST(a.code AS UNSIGNED), a.code, a.name";
            $rows = $this->query($sql, 'sssssi', $fromSql, $fromSql, $fromSql, $fromSql, $to, $this->tenantId);
        }
        return self::shapeBalances($rows, $includeEmpty);
    }

    /** Normalise raw balance rows (also used by tests). */
    public static function shapeBalances(array $rows, bool $includeEmpty = false): array
    {
        $out = [];
        foreach ($rows as $r) {
            $c = self::classify($r['type'] ?? null);
            $row = [
                'id' => (int)$r['id'],
                'code' => (string)($r['code'] ?? ''),
                'name' => (string)$r['name'],
                'type' => (string)($r['type'] ?? ''),
                'class' => $c['class'],
                'section' => $c['section'],
                'open_dr' => round((float)($r['open_dr'] ?? 0), 2),
                'open_cr' => round((float)($r['open_cr'] ?? 0), 2),
                'dr' => round((float)($r['dr'] ?? 0), 2),
                'cr' => round((float)($r['cr'] ?? 0), 2),
            ];
            if (!$includeEmpty && $row['open_dr'] == 0.0 && $row['open_cr'] == 0.0 && $row['dr'] == 0.0 && $row['cr'] == 0.0) {
                continue;
            }
            $out[$row['id']] = $row;
        }
        return $out;
    }

    /* ==================================================================
     * Statements (pure functions over balances)
     * ================================================================== */

    /**
     * Trial balance as at the end of the balances window.
     * Each account's closing net goes in the debit or credit column.
     *
     * With $closePriorYears (and a window starting at the financial-year start),
     * income and expense accounts show only this year's movement and earlier
     * years' profit is shown as one retained-earnings line — the way an
     * accountant reads a trial balance after year end.
     */
    public static function trialBalance(array $balances, bool $closePriorYears = false): array
    {
        $lines = [];
        $tdr = $tcr = 0.0;
        $retained = 0.0;
        foreach ($balances as $b) {
            $isPl = in_array($b['class'], ['income', 'expense'], true);
            if ($closePriorYears && $isPl) {
                $retained += $b['open_cr'] - $b['open_dr'];
                $net = round($b['dr'] - $b['cr'], 2);
            } else {
                $net = round(($b['open_dr'] + $b['dr']) - ($b['open_cr'] + $b['cr']), 2);
            }
            if ($net == 0.0) {
                continue;
            }
            $line = $b + ['debit' => $net > 0 ? $net : 0.0, 'credit' => $net < 0 ? -$net : 0.0];
            $tdr += $line['debit'];
            $tcr += $line['credit'];
            $lines[] = $line;
        }
        $retained = round($retained, 2);
        if ($retained != 0.0) {
            $line = [
                'id' => null, 'code' => '', 'name' => 'Retained earnings', 'type' => 'Equity', 'class' => 'equity', 'section' => 'equity',
                'computed' => true, 'debit' => $retained < 0 ? -$retained : 0.0, 'credit' => $retained > 0 ? $retained : 0.0,
            ];
            $tdr += $line['debit'];
            $tcr += $line['credit'];
            $lines[] = $line;
        }
        usort($lines, fn ($a, $b) => [self::classOrder($a['class']), !empty($a['computed'])] <=> [self::classOrder($b['class']), !empty($b['computed'])]);
        return [
            'lines' => $lines,
            'debit' => round($tdr, 2),
            'credit' => round($tcr, 2),
            'difference' => round($tdr - $tcr, 2),
            'balanced' => abs($tdr - $tcr) < 0.005,
        ];
    }

    /**
     * Worksheet: opening, period debits/credits and closing per account.
     */
    public static function worksheet(array $balances): array
    {
        $lines = [];
        $tot = ['open_dr' => 0.0, 'open_cr' => 0.0, 'dr' => 0.0, 'cr' => 0.0, 'close_dr' => 0.0, 'close_cr' => 0.0];
        foreach ($balances as $b) {
            $open = $b['open_dr'] - $b['open_cr'];
            $close = $open + $b['dr'] - $b['cr'];
            $line = $b + [
                'opening' => round($open, 2),
                'closing' => round($close, 2),
                'close_dr' => $close > 0 ? round($close, 2) : 0.0,
                'close_cr' => $close < 0 ? round(-$close, 2) : 0.0,
            ];
            $tot['open_dr'] += $open > 0 ? $open : 0;
            $tot['open_cr'] += $open < 0 ? -$open : 0;
            $tot['dr'] += $b['dr'];
            $tot['cr'] += $b['cr'];
            $tot['close_dr'] += $line['close_dr'];
            $tot['close_cr'] += $line['close_cr'];
            $lines[] = $line;
        }
        usort($lines, fn ($a, $b) => self::classOrder($a['class']) <=> self::classOrder($b['class']));
        return ['lines' => $lines, 'totals' => array_map(fn ($v) => round($v, 2), $tot)];
    }

    /**
     * Profit and loss for the period part of the balances (opening is ignored).
     */
    public static function incomeStatement(array $balances): array
    {
        $sections = [];
        foreach (['revenue', 'direct_costs', 'other_income', 'expense'] as $s) {
            $sections[$s] = ['label' => self::SECTIONS[$s]['label'], 'lines' => [], 'total' => 0.0];
        }
        foreach ($balances as $b) {
            if (!in_array($b['class'], ['income', 'expense'], true)) {
                continue;
            }
            $amt = round(self::natural($b['class'], $b['dr'], $b['cr']), 2);
            if ($amt == 0.0) {
                continue;
            }
            $sections[$b['section']]['lines'][] = ['id' => $b['id'], 'code' => $b['code'], 'name' => $b['name'], 'amount' => $amt];
            $sections[$b['section']]['total'] += $amt;
        }
        foreach ($sections as &$s) {
            $s['total'] = round($s['total'], 2);
        }
        unset($s);

        $revenue = $sections['revenue']['total'];
        $gross = round($revenue - $sections['direct_costs']['total'], 2);
        $net = round($gross + $sections['other_income']['total'] - $sections['expense']['total'], 2);
        $income = $revenue + $sections['other_income']['total'];

        return [
            'sections' => $sections,
            'revenue' => $revenue,
            'gross_profit' => $gross,
            'gross_margin' => $revenue != 0.0 ? round($gross / $revenue * 100, 1) : null,
            'total_income' => round($income, 2),
            'total_expenses' => round($sections['direct_costs']['total'] + $sections['expense']['total'], 2),
            'net_profit' => $net,
            'net_margin' => $income != 0.0 ? round($net / $income * 100, 1) : null,
        ];
    }

    /**
     * Balance sheet at the end of the window, where the window starts at the
     * financial-year start: income/expense movement before it is retained
     * earnings, movement inside it is current-year earnings.
     */
    public static function balanceSheet(array $balances): array
    {
        $sections = [];
        foreach (self::SECTIONS as $key => $meta) {
            if (in_array($meta['class'], ['asset', 'liability', 'equity'], true)) {
                $sections[$key] = ['label' => $meta['label'], 'class' => $meta['class'], 'lines' => [], 'total' => 0.0];
            }
        }
        $retained = 0.0;
        $current = 0.0;
        foreach ($balances as $b) {
            if (in_array($b['class'], ['income', 'expense'], true)) {
                // Profit = income (Cr−Dr) − expenses (Dr−Cr) = Cr − Dr for both.
                $retained += $b['open_cr'] - $b['open_dr'];
                $current += $b['cr'] - $b['dr'];
                continue;
            }
            $amt = round(self::natural($b['class'], $b['open_dr'] + $b['dr'], $b['open_cr'] + $b['cr']), 2);
            if ($amt == 0.0) {
                continue;
            }
            $sections[$b['section']]['lines'][] = ['id' => $b['id'], 'code' => $b['code'], 'name' => $b['name'], 'amount' => $amt];
            $sections[$b['section']]['total'] += $amt;
        }
        $retained = round($retained, 2);
        $current = round($current, 2);
        if ($retained != 0.0) {
            $sections['equity']['lines'][] = ['id' => null, 'code' => '', 'name' => 'Retained earnings', 'amount' => $retained, 'computed' => true];
            $sections['equity']['total'] += $retained;
        }
        if ($current != 0.0) {
            $sections['equity']['lines'][] = ['id' => null, 'code' => '', 'name' => 'Current year earnings', 'amount' => $current, 'computed' => true];
            $sections['equity']['total'] += $current;
        }

        $tot = ['asset' => 0.0, 'liability' => 0.0, 'equity' => 0.0];
        foreach ($sections as &$s) {
            $s['total'] = round($s['total'], 2);
            $tot[$s['class']] += $s['total'];
        }
        unset($s);
        $tot = array_map(fn ($v) => round($v, 2), $tot);
        $netAssets = round($tot['asset'] - $tot['liability'], 2);

        return [
            'sections' => $sections,
            'total_assets' => $tot['asset'],
            'total_liabilities' => $tot['liability'],
            'total_equity' => $tot['equity'],
            'net_assets' => $netAssets,
            'current_year_earnings' => $current,
            'retained_earnings' => $retained,
            'difference' => round($netAssets - $tot['equity'], 2),
            'balanced' => abs($netAssets - $tot['equity']) < 0.005,
        ];
    }

    private static function classOrder(string $class): int
    {
        return array_search($class, ['asset', 'liability', 'equity', 'income', 'expense'], true) ?: 0;
    }

    /* ==================================================================
     * Account transactions (general ledger)
     * ================================================================== */

    /**
     * Ledger lines for one account in a period, with opening and running balance
     * in the account's natural sign.
     */
    public function accountTransactions(int $accountId, string $from, string $to): ?array
    {
        $acc = $this->query("SELECT id, code, name, type, description FROM account_list WHERE id = ? AND tenant_id = ? AND delete_flag = 0", 'ii', $accountId, $this->tenantId);
        if (!$acc) {
            return null;
        }
        $acc = $acc[0];
        $c = self::classify($acc['type']);
        $side = self::SIDE_SQL;
        $posted = self::POSTED_SQL;

        $open = $this->query(
            "SELECT COALESCE(SUM(CASE WHEN {$side} = 1 THEN ji.amount END),0) dr, COALESCE(SUM(CASE WHEN {$side} = 2 THEN ji.amount END),0) cr
             FROM journal_items ji INNER JOIN journal_entries j ON j.id = ji.journal_id
             LEFT JOIN group_list g ON g.id = ji.group_id
             WHERE ji.account_id = ? AND j.tenant_id = ? AND j.journal_date < ? AND {$posted}",
            'iis', $accountId, $this->tenantId, $from
        );
        $lines = $this->query(
            "SELECT j.id journal_id, j.code, j.journal_date, j.description, ji.description line_description, ji.amount, {$side} side
             FROM journal_items ji INNER JOIN journal_entries j ON j.id = ji.journal_id
             LEFT JOIN group_list g ON g.id = ji.group_id
             WHERE ji.account_id = ? AND j.tenant_id = ? AND j.journal_date BETWEEN ? AND ? AND {$posted}
             ORDER BY j.journal_date, j.id, ji.id",
            'iiss', $accountId, $this->tenantId, $from, $to
        );

        return self::runningLedger($acc + $c, (float)($open[0]['dr'] ?? 0), (float)($open[0]['cr'] ?? 0), $lines);
    }

    /** Build the running-balance ledger (pure; used by tests). */
    public static function runningLedger(array $account, float $openDr, float $openCr, array $lines): array
    {
        $class = $account['class'] ?? self::classify($account['type'] ?? null)['class'];
        $balance = round(self::natural($class, $openDr, $openCr), 2);
        $opening = $balance;
        $tdr = $tcr = 0.0;
        $out = [];
        foreach ($lines as $l) {
            $amt = round((float)$l['amount'], 2);
            $isDr = (int)$l['side'] === 1;
            $dr = $isDr ? $amt : 0.0;
            $cr = $isDr ? 0.0 : $amt;
            $tdr += $dr;
            $tcr += $cr;
            $balance = round($balance + self::natural($class, $dr, $cr), 2);
            $out[] = [
                'journal_id' => (int)$l['journal_id'],
                'code' => (string)$l['code'],
                'date' => (string)$l['journal_date'],
                'description' => trim((string)($l['line_description'] ?? '')) !== '' ? (string)$l['line_description'] : (string)$l['description'],
                'debit' => $dr,
                'credit' => $cr,
                'balance' => $balance,
            ];
        }
        return [
            'account' => $account + ['class' => $class],
            'opening' => $opening,
            'lines' => $out,
            'debit' => round($tdr, 2),
            'credit' => round($tcr, 2),
            'closing' => $balance,
            'net' => round($balance - $opening, 2),
        ];
    }

    /** Monthly closing balance (natural sign) for an account over the last N months — for sparklines. */
    public function monthlyMovement(int $accountId, string $to, int $months = 12): array
    {
        $start = (new \DateTimeImmutable($to))->modify('first day of this month')->modify('-' . ($months - 1) . ' months')->format('Y-m-d');
        $side = self::SIDE_SQL;
        $posted = self::POSTED_SQL;
        $rows = $this->query(
            "SELECT DATE_FORMAT(j.journal_date, '%Y-%m') ym,
                    COALESCE(SUM(CASE WHEN {$side} = 1 THEN ji.amount END),0) dr,
                    COALESCE(SUM(CASE WHEN {$side} = 2 THEN ji.amount END),0) cr
             FROM journal_items ji INNER JOIN journal_entries j ON j.id = ji.journal_id
             LEFT JOIN group_list g ON g.id = ji.group_id
             WHERE ji.account_id = ? AND j.tenant_id = ? AND j.journal_date BETWEEN ? AND ? AND {$posted}
             GROUP BY ym",
            'iiss', $accountId, $this->tenantId, $start, $to
        );
        $map = [];
        foreach ($rows as $r) {
            $map[$r['ym']] = [(float)$r['dr'], (float)$r['cr']];
        }
        $out = [];
        for ($m = new \DateTimeImmutable($start); $m->format('Y-m') <= substr($to, 0, 7); $m = $m->modify('+1 month')) {
            $out[$m->format('M y')] = $map[$m->format('Y-m')] ?? [0.0, 0.0];
        }
        return $out;
    }

    /* ==================================================================
     * Cash summary
     * ================================================================== */

    /**
     * Where cash came from and went to. For every posted journal in the period
     * that touches a bank account, the other lines are the cash movement:
     * a credit to Sales is cash in, a debit to Rent is cash out. Journals that
     * only move money between bank accounts are transfers and are left out.
     *
     * $lines: [journal_id, account_id, side (1 dr / 2 cr), amount]
     * $accounts: id => [code, name, type] for every account in $lines.
     */
    public static function cashMovements(array $lines, array $bankIds, array $accounts): array
    {
        $byJournal = [];
        foreach ($lines as $l) {
            $byJournal[(int)$l['journal_id']][] = $l;
        }
        $in = $out = [];
        $transfers = 0.0;
        foreach ($byJournal as $jl) {
            $touchesBank = false;
            $other = [];
            $bankNet = 0.0;
            foreach ($jl as $l) {
                $amt = (float)$l['amount'];
                $signed = (int)$l['side'] === 1 ? $amt : -$amt;   // + debit, − credit
                if (in_array((int)$l['account_id'], $bankIds, true)) {
                    $touchesBank = true;
                    $bankNet += $signed;
                } else {
                    $other[] = [(int)$l['account_id'], -$signed]; // cash effect: credit elsewhere = cash in
                }
            }
            if (!$touchesBank) {
                continue;
            }
            if (!$other) {
                foreach ($jl as $l) {
                    if ((int)$l['side'] === 1) {
                        $transfers += (float)$l['amount'];
                    }
                }
                continue;
            }
            foreach ($other as [$acc, $cash]) {
                if (abs($cash) < 0.005) {
                    continue;
                }
                $bucket = $cash > 0 ? 'in' : 'out';
                ${$bucket}[$acc] = (${$bucket}[$acc] ?? 0.0) + abs($cash);
            }
        }
        $shape = function (array $map) use ($accounts): array {
            $rows = [];
            foreach ($map as $id => $amt) {
                $a = $accounts[$id] ?? ['code' => '', 'name' => 'Unknown account', 'type' => ''];
                $rows[] = ['id' => $id, 'code' => (string)$a['code'], 'name' => (string)$a['name'],
                    'class' => self::classify($a['type'] ?? '')['class'], 'amount' => round($amt, 2)];
            }
            usort($rows, fn ($x, $y) => $y['amount'] <=> $x['amount']);
            return $rows;
        };
        $inRows = $shape($in);
        $outRows = $shape($out);
        $tin = round(array_sum(array_column($inRows, 'amount')), 2);
        $tout = round(array_sum(array_column($outRows, 'amount')), 2);
        return ['in' => $inRows, 'out' => $outRows, 'total_in' => $tin, 'total_out' => $tout,
            'net' => round($tin - $tout, 2), 'transfers' => round($transfers, 2)];
    }

    /** Raw lines of posted journals in a period that touch any of the given accounts. */
    public function linesOfJournalsTouching(array $accountIds, string $from, string $to): array
    {
        $accountIds = array_values(array_filter(array_map('intval', $accountIds)));
        if (!$accountIds) {
            return [];
        }
        $side = self::SIDE_SQL;
        $posted = self::POSTED_SQL;
        $in = implode(',', $accountIds); // ints only
        return $this->query(
            "SELECT ji.journal_id, ji.account_id, ji.amount, {$side} side
             FROM journal_items ji INNER JOIN journal_entries j ON j.id = ji.journal_id
             LEFT JOIN group_list g ON g.id = ji.group_id
             WHERE j.tenant_id = ? AND j.journal_date BETWEEN ? AND ? AND {$posted}
               AND ji.journal_id IN (SELECT ji2.journal_id FROM journal_items ji2 WHERE ji2.account_id IN ({$in}))",
            'iss', $this->tenantId, $from, $to
        );
    }

    /* ==================================================================
     * Aged receivables
     * ================================================================== */

    public const AGING_BUCKETS = ['current' => 'Current', 'd30' => '1–30 days', 'd60' => '31–60 days', 'd90' => '61–90 days', 'older' => 'Over 90 days'];

    /** Bucket for an amount due on $due (or issued on $issued when there is no due date), seen from $asOf. */
    public static function agingBucket(?string $due, string $issued, string $asOf): array
    {
        $ref = $due ?: $issued;
        $days = (int)floor((strtotime($asOf) - strtotime($ref)) / 86400);
        $bucket = $days <= 0 ? 'current' : ($days <= 30 ? 'd30' : ($days <= 60 ? 'd60' : ($days <= 90 ? 'd90' : 'older')));
        return [$bucket, max(0, $days)];
    }

    /**
     * Group open invoices by customer and age bucket.
     * $rows: [party_id, party_name, party_code, id, invoice_no, invoice_date, due_date, total, balance]
     */
    public static function agedReceivables(array $rows, string $asOf): array
    {
        $empty = array_fill_keys(array_keys(self::AGING_BUCKETS), 0.0);
        $customers = [];
        $totals = $empty + ['total' => 0.0];
        foreach ($rows as $r) {
            $bal = round((float)$r['balance'], 2);
            if ($bal == 0.0) {
                continue;
            }
            [$bucket, $days] = self::agingBucket($r['due_date'] ?? null, (string)$r['invoice_date'], $asOf);
            $pid = (int)$r['party_id'];
            $customers[$pid] ??= ['id' => $pid, 'name' => (string)$r['party_name'], 'code' => (string)($r['party_code'] ?? ''), 'invoices' => []] + $empty + ['total' => 0.0, 'oldest' => 0];
            $customers[$pid][$bucket] += $bal;
            $customers[$pid]['total'] += $bal;
            $customers[$pid]['oldest'] = max($customers[$pid]['oldest'], $days);
            $customers[$pid]['invoices'][] = ['id' => (int)$r['id'], 'invoice_no' => (string)$r['invoice_no'], 'invoice_date' => (string)$r['invoice_date'],
                'due_date' => $r['due_date'] ?? null, 'total' => (float)$r['total'], 'balance' => $bal, 'bucket' => $bucket, 'days' => $days];
            $totals[$bucket] += $bal;
            $totals['total'] += $bal;
        }
        $list = array_values($customers);
        usort($list, fn ($a, $b) => $b['total'] <=> $a['total']);
        return ['customers' => $list, 'totals' => array_map(fn ($v) => round($v, 2), $totals)];
    }

    /* ==================================================================
     * VAT
     * ================================================================== */

    /** Rate in % from a tax label such as "VAT on Income (15%)"; 0 when none. */
    public static function taxRateFromLabel(?string $label): float
    {
        return preg_match('/(\d+(?:\.\d+)?)\s*%/', (string)$label, $m) ? (float)$m[1] : 0.0;
    }

    /**
     * VAT on manual journal lines tagged with a VAT rate. "VAT on Income" lines are
     * output tax, "VAT on Expenses/Purchases" lines are input tax. Line amounts are
     * net unless the journal says amounts are tax inclusive.
     */
    public static function vatFromJournalLines(array $lines): array
    {
        $out = ['output' => [], 'input' => [], 'output_tax' => 0.0, 'input_tax' => 0.0, 'output_net' => 0.0, 'input_net' => 0.0];
        foreach ($lines as $l) {
            $label = (string)($l['tax_rate'] ?? '');
            $rate = self::taxRateFromLabel($label);
            $lower = strtolower($label);
            if ($rate <= 0 || !str_contains($lower, 'vat')) {
                continue;
            }
            $kind = (str_contains($lower, 'expense') || str_contains($lower, 'purchase')) ? 'input'
                : ((str_contains($lower, 'income') || str_contains($lower, 'sales')) ? 'output' : null);
            if ($kind === null) {
                continue;
            }
            $amt = (float)$l['amount'];
            $inclusive = strtolower((string)($l['tax_mode'] ?? '')) === 'tax inclusive';
            $tax = $inclusive ? $amt * $rate / (100 + $rate) : $amt * $rate / 100;
            $net = $inclusive ? $amt - $tax : $amt;
            // A credit on an expense line (or a debit on an income line) is a refund: it reverses VAT.
            $sign = ($kind === 'input') === ((int)($l['side'] ?? 1) === 1) ? 1 : -1;
            $row = [
                'journal_id' => (int)$l['journal_id'], 'code' => (string)$l['code'], 'date' => (string)$l['journal_date'],
                'description' => trim((string)($l['line_description'] ?? '')) !== '' ? (string)$l['line_description'] : (string)($l['description'] ?? ''),
                'label' => $label, 'net' => round($sign * $net, 2), 'tax' => round($sign * $tax, 2), 'inclusive' => $inclusive,
            ];
            $out[$kind][] = $row;
            $out[$kind . '_tax'] += $row['tax'];
            $out[$kind . '_net'] += $row['net'];
        }
        foreach (['output_tax', 'input_tax', 'output_net', 'input_net'] as $k) {
            $out[$k] = round($out[$k], 2);
        }
        return $out;
    }

    /** Posted manual-journal lines carrying a VAT tax rate (invoice journals excluded — invoices are counted directly). */
    public function vatJournalLines(string $from, string $to): array
    {
        $side = self::SIDE_SQL;
        $posted = self::POSTED_SQL;
        return $this->query(
            "SELECT j.id journal_id, j.code, j.journal_date, j.description, j.tax_mode, ji.description line_description, ji.amount, ji.tax_rate, {$side} side
             FROM journal_items ji INNER JOIN journal_entries j ON j.id = ji.journal_id
             LEFT JOIN group_list g ON g.id = ji.group_id
             WHERE j.tenant_id = ? AND j.journal_date BETWEEN ? AND ? AND {$posted}
               AND ji.tax_rate LIKE '%VAT%'
               AND NOT EXISTS (SELECT 1 FROM invoice_list i WHERE i.journal_id = j.id)
             ORDER BY j.journal_date, j.id, ji.id",
            'iss', $this->tenantId, $from, $to
        );
    }

    /* ==================================================================
     * Journal validation
     * ================================================================== */

    /**
     * Validate journal lines before saving. Lines are [account_id, debit, credit].
     * Returns a list of human-readable problems (empty = OK). Posted journals must
     * balance across at least two lines; drafts ($requireBalanced = false) need not.
     */
    public static function validateLines(array $lines, ?array $validAccountIds = null, bool $requireBalanced = true): array
    {
        $errors = [];
        $dr = $cr = 0.0;
        $count = 0;
        foreach ($lines as $i => $l) {
            $n = $i + 1;
            $d = round((float)($l['debit'] ?? 0), 2);
            $c = round((float)($l['credit'] ?? 0), 2);
            $acc = (int)($l['account_id'] ?? 0);
            if ($acc === 0 && $d == 0.0 && $c == 0.0) {
                continue; // blank row
            }
            if ($acc === 0) {
                $errors[] = "Line {$n}: choose an account.";
                continue;
            }
            if ($validAccountIds !== null && !in_array($acc, $validAccountIds, true)) {
                $errors[] = "Line {$n}: the account is not available for this company.";
            }
            if ($d < 0 || $c < 0) {
                $errors[] = "Line {$n}: amounts cannot be negative.";
            }
            if ($d > 0 && $c > 0) {
                $errors[] = "Line {$n}: enter either a debit or a credit, not both.";
            }
            if ($d == 0.0 && $c == 0.0) {
                $errors[] = "Line {$n}: enter an amount.";
            }
            $dr += $d;
            $cr += $c;
            $count++;
        }
        if (!$requireBalanced) {
            // Drafts never reach the ledger, so they may be incomplete.
            return $count === 0 ? ['Add at least one line before saving.'] : $errors;
        }
        if ($count < 2) {
            $errors[] = 'A journal needs at least two lines.';
        }
        if (abs($dr - $cr) >= 0.005) {
            $errors[] = sprintf('Debits (%s) and credits (%s) must be equal — out by %s.', number_format($dr, 2), number_format($cr, 2), number_format(abs($dr - $cr), 2));
        }
        return $errors;
    }

    /* ==================================================================
     * Helpers
     * ================================================================== */

    public function accountIds(): array
    {
        return array_map(fn ($r) => (int)$r['id'], $this->query("SELECT id FROM account_list WHERE tenant_id = ? AND delete_flag = 0", 'i', $this->tenantId));
    }

    private function query(string $sql, string $types = '', ...$params): array
    {
        if (!$this->conn) {
            return [];
        }
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        if (!$stmt->execute()) {
            return [];
        }
        $res = $stmt->get_result();
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
}
