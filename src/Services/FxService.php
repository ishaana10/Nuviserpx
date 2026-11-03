<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Exchange rates and foreign-currency revaluation.
 *
 * Rates are stored as base-currency units per ONE unit of the foreign currency, dated, so history is kept
 * (1 USD = 2.2500 FJD on a given day). A journal line may carry a currency: it is then entered in that
 * currency, converted at the rate, and stored in the base currency with the original amount kept beside
 * it (currency, fx_rate, amount_fc). Every report keeps working in base currency.
 *
 * Revaluation restates the base value of foreign-currency balances in asset and liability accounts at a
 * closing rate. For one account and currency:
 *     adjustment = foreign balance x closing rate - base value currently carried
 * The carried value already includes earlier revaluation lines, so the calculation is cumulative and
 * running it twice for the same date posts nothing the second time. The difference goes to
 * "Unrealised FX Gain/Loss".
 */
class FxService
{
    public const FX_ACCOUNT = 'Unrealised FX Gain/Loss';
    public const REALISED_ACCOUNT = 'Realised FX Gain/Loss';

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /* ------------------------------ pure helpers ------------------------------ */

    public static function toBase(float $foreign, float $rate): float
    {
        return round($foreign * $rate, 2);
    }

    /** A usable rate: positive, up to 8 decimals, not absurd. Null otherwise. */
    public static function cleanRate(mixed $v): ?float
    {
        $s = trim(str_replace(',', '', (string)$v));
        if ($s === '' || !is_numeric($s)) {
            return null;
        }
        $r = (float)$s;
        return ($r <= 0 || $r > 1000000000) ? null : round($r, 8);
    }

    /**
     * Convert foreign-currency journal lines to base. Lines are form-shaped
     * [account_id, debit, credit, currency?, fx_rate?, ...]; for a line with a currency, debit/credit are in
     * that currency. $rateFor(code) supplies the dated rate when the line doesn't carry its own.
     * Returns [lines, errors]; converted lines gain amount_fc and fx_rate.
     */
    public static function convertLines(array $lines, string $base, callable $rateFor): array
    {
        $errors = [];
        foreach ($lines as $i => &$l) {
            $cur = strtoupper(trim((string)($l['currency'] ?? '')));
            if ($cur === '' || $cur === strtoupper($base)) {
                $l['currency'] = null;
                continue;
            }
            $n = $i + 1;
            $rate = isset($l['fx_rate']) && $l['fx_rate'] !== '' ? self::cleanRate($l['fx_rate']) : $rateFor($cur);
            if ($rate === null || $rate <= 0) {
                $errors[] = "Line {$n}: no exchange rate for {$cur} on this date. Add one under Exchange rates.";
                continue;
            }
            $dr = (float)$l['debit'];
            $cr = (float)$l['credit'];
            $l['currency'] = $cur;
            $l['fx_rate'] = $rate;
            $l['amount_fc'] = round($dr > 0 ? $dr : $cr, 2);
            $l['debit'] = $dr > 0 ? self::toBase($dr, $rate) : 0.0;
            $l['credit'] = $cr > 0 ? self::toBase($cr, $rate) : 0.0;
        }
        unset($l);
        return [$lines, $errors];
    }

    /**
     * Base-currency value to take off a foreign-currency invoice for an allocation of $allocFc. It is carried
     * at the invoice's own rate; the allocation that clears the last foreign unit takes the whole remaining base
     * value, so a fully settled invoice never leaves a rounding cent behind.
     */
    public static function allocationBase(float $allocFc, float $remainingFc, float $remainingBase, float $docRate): float
    {
        $remainingBase = round($remainingBase, 2);
        if ($allocFc >= round($remainingFc, 2) - 0.005) {
            return $remainingBase;
        }
        return min($remainingBase, self::toBase($allocFc, $docRate));
    }

    /**
     * Journal for a foreign-currency receipt: cash at the receipt-date rate, receivables relieved at the carried
     * (invoice) value, the difference to realised gain/loss. Lines are [account, side, base amount, fx tag?].
     * The receivable line carries the foreign amount so revaluation sees the balance fall.
     */
    public static function receiptLines(int $cashAcc, int $arAcc, int $fxAcc, float $cashBase, float $arBase, string $currency, float $rate, float $arFc): array
    {
        $cashBase = round($cashBase, 2);
        $arBase = round($arBase, 2);
        $lines = [
            [$cashAcc, TaxService::DEBIT, $cashBase],
            [$arAcc, TaxService::CREDIT, $arBase, ['currency' => $currency, 'fx_rate' => $rate, 'amount_fc' => round($arFc, 2)]],
        ];
        $gain = round($cashBase - $arBase, 2);
        if ($gain > 0) {
            $lines[] = [$fxAcc, TaxService::CREDIT, $gain];
        } elseif ($gain < 0) {
            $lines[] = [$fxAcc, TaxService::DEBIT, abs($gain)];
        }
        return $lines;
    }

    /**
     * Revaluation adjustments. $groups: rows of [account_id, currency, fc_net, base_net] where both nets are
     * debit-positive (debits minus credits). $rates: currency => closing rate. Rows with no rate come back
     * under 'missing'; rows already at the closing value are left out.
     */
    public static function adjustments(array $groups, array $rates): array
    {
        $rows = [];
        $missing = [];
        foreach ($groups as $g) {
            $cur = strtoupper((string)$g['currency']);
            if (!isset($rates[$cur]) || (float)$rates[$cur] <= 0) {
                $missing[$cur] = true;
                continue;
            }
            $fc = round((float)$g['fc_net'], 2);
            $carry = round((float)$g['base_net'], 2);
            $target = self::toBase($fc, (float)$rates[$cur]);
            $delta = round($target - $carry, 2);
            if ($delta == 0.0) {
                continue;
            }
            $rows[] = [
                'account_id' => (int)$g['account_id'], 'currency' => $cur, 'fc_net' => $fc,
                'rate' => (float)$rates[$cur], 'carrying' => $carry, 'revalued' => $target, 'adjustment' => $delta,
            ];
        }
        return ['rows' => $rows, 'missing' => array_keys($missing)];
    }

    /** Journal lines for the adjustments: the account moves, the other side goes to the FX account. Balances by construction. */
    public static function journalLines(array $rows, int $fxAccountId): array
    {
        $lines = [];
        $fxDr = $fxCr = 0.0;
        foreach ($rows as $r) {
            $a = abs($r['adjustment']);
            if ($r['adjustment'] > 0) {
                $lines[] = ['account_id' => $r['account_id'], 'debit' => $a, 'credit' => 0.0, 'currency' => $r['currency'], 'fx_rate' => $r['rate'], 'amount_fc' => 0.0,
                    'description' => "Revalue {$r['currency']} balance at {$r['rate']}"];
                $fxCr += $a;
            } else {
                $lines[] = ['account_id' => $r['account_id'], 'debit' => 0.0, 'credit' => $a, 'currency' => $r['currency'], 'fx_rate' => $r['rate'], 'amount_fc' => 0.0,
                    'description' => "Revalue {$r['currency']} balance at {$r['rate']}"];
                $fxDr += $a;
            }
        }
        $net = round($fxCr - $fxDr, 2); // credit-positive: a gain
        if ($net > 0) {
            $lines[] = ['account_id' => $fxAccountId, 'debit' => 0.0, 'credit' => $net, 'description' => 'Unrealised exchange gain'];
        } elseif ($net < 0) {
            $lines[] = ['account_id' => $fxAccountId, 'debit' => abs($net), 'credit' => 0.0, 'description' => 'Unrealised exchange loss'];
        }
        return $lines;
    }

    /* --------------------------------- database -------------------------------- */

    private function tenantId(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    public function baseCurrency(): string
    {
        $t = $this->tenantId();
        $r = @$this->conn->query("SELECT code FROM currencies WHERE tenant_id = {$t} AND is_default = 1 AND status = 1 LIMIT 1");
        $row = $r ? $r->fetch_assoc() : null;
        return strtoupper((string)($row['code'] ?? 'FJD'));
    }

    /** Foreign currencies the company has set up (everything but the base). */
    public function foreignCurrencies(): array
    {
        $t = $this->tenantId();
        $base = $this->baseCurrency();
        $stmt = $this->conn->prepare("SELECT code, name, symbol FROM currencies WHERE tenant_id = ? AND status = 1 AND code <> ? ORDER BY code");
        $stmt->bind_param('is', $t, $base);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** The latest rate on or before $date for a currency; null if none has been entered. */
    public function rateOn(string $currency, string $date): ?float
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT rate FROM exchange_rates WHERE tenant_id = ? AND currency_code = ? AND rate_date <= ? ORDER BY rate_date DESC LIMIT 1");
        $stmt->bind_param('iss', $t, $currency, $date);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? (float)$row['rate'] : null;
    }

    /** Rate history, newest first. */
    public function history(int $limit = 100): array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT id, currency_code, rate_date, rate FROM exchange_rates WHERE tenant_id = ? ORDER BY rate_date DESC, currency_code LIMIT ?");
        $stmt->bind_param('ii', $t, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function saveRate(array $post): string
    {
        $t = $this->tenantId();
        $code = strtoupper(trim((string)($post['currency_code'] ?? '')));
        $date = trim((string)($post['rate_date'] ?? ''));
        $rate = self::cleanRate($post['rate'] ?? '');
        if (!preg_match('/^[A-Z]{3,10}$/', $code) || $code === $this->baseCurrency()) {
            return $this->fail('Choose a foreign currency. The base currency is always 1.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            return $this->fail('Enter a valid date.');
        }
        if ($rate === null) {
            return $this->fail('Enter the rate as a positive number: how much base currency one unit of ' . $code . ' is worth.');
        }
        $stmt = $this->conn->prepare("INSERT INTO exchange_rates (tenant_id, currency_code, rate_date, rate) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE rate = VALUES(rate)");
        $r = number_format($rate, 8, '.', '');
        $stmt->bind_param('isss', $t, $code, $date, $r);
        if (!$stmt->execute()) {
            return $this->fail('The rate could not be saved.');
        }
        $this->settings->log_audit('Saved exchange rate', 'Currencies', "{$code} on {$date}: {$r}");
        $this->settings->set_flashdata('success', 'Exchange rate saved.');
        return json_encode(['status' => 'success', 'msg' => 'Exchange rate saved.']);
    }

    /** Foreign-currency balances (debit-positive) by account and currency up to $asOf, asset and liability accounts only. */
    public function foreignBalances(string $asOf): array
    {
        $t = $this->tenantId();
        $side = LedgerService::SIDE_SQL;
        $posted = LedgerService::POSTED_SQL;
        $stmt = $this->conn->prepare("SELECT ji.account_id, a.type, ji.currency,
                SUM(CASE WHEN {$side} = 1 THEN ji.amount_fc ELSE -ji.amount_fc END) fc_net,
                SUM(CASE WHEN {$side} = 1 THEN ji.amount ELSE -ji.amount END) base_net
            FROM journal_items ji
            INNER JOIN journal_entries j ON j.id = ji.journal_id AND j.tenant_id = ? AND j.journal_date <= ? AND {$posted}
            INNER JOIN account_list a ON a.id = ji.account_id AND a.tenant_id = j.tenant_id
            LEFT JOIN group_list g ON g.id = ji.group_id
            WHERE ji.currency IS NOT NULL AND ji.currency <> ''
            GROUP BY ji.account_id, a.type, ji.currency");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('is', $t, $asOf);
        $stmt->execute();
        $out = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            if (in_array(LedgerService::classify($r['type'])['class'], ['asset', 'liability'], true)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** What revaluing at $asOf would post, using the latest rate on or before that date for each currency. */
    public function preview(string $asOf): array
    {
        $groups = $this->foreignBalances($asOf);
        $rates = [];
        foreach ($groups as $g) {
            $c = strtoupper((string)$g['currency']);
            if (!isset($rates[$c])) {
                $r = $this->rateOn($c, $asOf);
                if ($r !== null) {
                    $rates[$c] = $r;
                }
            }
        }
        $res = self::adjustments($groups, $rates);
        $names = [];
        $t = $this->tenantId();
        $q = $this->conn->prepare("SELECT id, name FROM account_list WHERE tenant_id = ?");
        $q->bind_param('i', $t);
        $q->execute();
        foreach ($q->get_result()->fetch_all(MYSQLI_ASSOC) as $a) {
            $names[(int)$a['id']] = $a['name'];
        }
        foreach ($res['rows'] as &$r) {
            $r['account'] = $names[$r['account_id']] ?? ('#' . $r['account_id']);
        }
        unset($r);
        return $res;
    }

    /** The rate for a document: the one typed on it if valid, else the latest on or before its date. Null if neither. */
    public function documentRate(string $currency, string $date, mixed $typed): ?float
    {
        $r = ($typed !== null && $typed !== '') ? self::cleanRate($typed) : null;
        return $r ?? $this->rateOn(strtoupper($currency), $date);
    }

    public function isForeign(?string $currency): bool
    {
        $c = strtoupper(trim((string)$currency));
        return $c !== '' && $c !== $this->baseCurrency();
    }

    /** The realised gain/loss account, created on first use. */
    public function realisedAccount(): int
    {
        return $this->ensureAccount(self::REALISED_ACCOUNT, 'Exchange differences made real when a foreign-currency invoice is paid');
    }

    private function ensureAccount(string $name, string $description): int
    {
        $t = $this->tenantId();
        $find = $this->conn->prepare("SELECT id FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND name = ? LIMIT 1");
        $find->bind_param('is', $t, $name);
        $find->execute();
        if ($row = $find->get_result()->fetch_assoc()) {
            return (int)$row['id'];
        }
        $res = json_decode((new AccountService($this->conn, $this->settings))->saveAccount([
            'name' => $name, 'type' => 'Other Income', 'description' => $description, 'status' => 1,
        ]), true);
        if (($res['status'] ?? '') !== 'success') {
            return 0;
        }
        $find->execute();
        return (int)($find->get_result()->fetch_assoc()['id'] ?? 0);
    }

    /** The company's unrealised FX account, created on first use. */
    public function fxAccount(): int
    {
        $t = $this->tenantId();
        $name = self::FX_ACCOUNT;
        $find = $this->conn->prepare("SELECT id FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND name = ? LIMIT 1");
        $find->bind_param('is', $t, $name);
        $find->execute();
        if ($row = $find->get_result()->fetch_assoc()) {
            return (int)$row['id'];
        }
        $res = json_decode((new AccountService($this->conn, $this->settings))->saveAccount([
            'name' => $name, 'type' => 'Other Income', 'description' => 'Gains and losses from restating foreign-currency balances', 'status' => 1,
        ]), true);
        if (($res['status'] ?? '') !== 'success') {
            return 0;
        }
        $find->execute();
        return (int)($find->get_result()->fetch_assoc()['id'] ?? 0);
    }

    /** Post the revaluation journal for $asOf. A second run for the same date finds nothing to post. */
    public function revalue(string $asOf): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) || !strtotime($asOf)) {
            return $this->fail('Enter a valid revaluation date.');
        }
        $p = $this->preview($asOf);
        if ($p['missing']) {
            return $this->fail('No exchange rate on or before ' . $asOf . ' for ' . implode(', ', $p['missing']) . '. Add the rate first.');
        }
        if (!$p['rows']) {
            return $this->fail('Nothing to revalue: foreign-currency balances are already at the ' . $asOf . ' rates.');
        }
        $fx = $this->fxAccount();
        if ($fx === 0) {
            return $this->fail('The ' . self::FX_ACCOUNT . ' account could not be created.');
        }
        $lines = self::journalLines($p['rows'], $fx);
        return (new JournalService($this->conn, $this->settings))
            ->postRevaluation($asOf, 'Foreign currency revaluation at ' . $asOf, $lines);
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
