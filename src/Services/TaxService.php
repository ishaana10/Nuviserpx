<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Tax codes and VAT posting.
 *
 * A tax code is a named rate (Standard 15%, Zero rated, Exempt). Invoices and bills carry the rate on each
 * line; when a document is posted the tax portion goes to a control account instead of being buried in
 * revenue or expense:
 *   sale     Dr Receivables (gross)   Cr Revenue (net)         Cr VAT Output (tax)
 *   purchase Dr Expense (net)         Dr VAT Input (tax)       Cr Payables (gross)
 * so the ledger itself holds what is owed to, or reclaimable from, the tax authority.
 */
class TaxService
{
    public const OUTPUT_ACCOUNT = 'VAT Output';
    public const INPUT_ACCOUNT = 'VAT Input';
    public const DEBIT = 1;
    public const CREDIT = 2;

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /* ------------------------------ pure helpers ------------------------------ */

    /** Tax on a net amount at a percentage rate, rounded to cents. */
    public static function taxOn(float $net, float $rate): float
    {
        return round($net * $rate / 100, 2);
    }

    /** Net and tax out of a tax-inclusive gross amount. */
    public static function fromGross(float $gross, float $rate): array
    {
        $net = $rate > 0 ? round($gross / (1 + $rate / 100), 2) : round($gross, 2);
        return ['net' => $net, 'tax' => round($gross - $net, 2)];
    }

    /** A valid percentage: 0 to 100, up to four decimals. Null when it isn't one. */
    public static function cleanRate(mixed $v): ?float
    {
        $s = trim((string)$v);
        if ($s === '' || !is_numeric($s)) {
            return null;
        }
        $r = (float)$s;
        return ($r < 0 || $r > 100) ? null : round($r, 4);
    }

    /**
     * Journal lines for a document. Returns [[account_id, side(1 debit|2 credit), amount], ...] and always
     * balances. If the tax figure is missing, negative or larger than the total the document posts without a
     * tax split rather than producing a negative net.
     *
     * @param string $kind 'sale' or 'purchase'
     */
    public static function postingLines(string $kind, float $total, float $tax, int $mainAcc, int $taxAcc, int $controlAcc): array
    {
        $total = round($total, 2);
        $tax = round($tax, 2);
        if ($tax <= 0 || $tax >= $total || $taxAcc <= 0) {
            $tax = 0.0;
        }
        $net = round($total - $tax, 2);

        if ($kind === 'sale') {
            $lines = [[$controlAcc, self::DEBIT, $total], [$mainAcc, self::CREDIT, $net]];
            if ($tax > 0) {
                $lines[] = [$taxAcc, self::CREDIT, $tax];
            }
        } else {
            $lines = [[$mainAcc, self::DEBIT, $net]];
            if ($tax > 0) {
                $lines[] = [$taxAcc, self::DEBIT, $tax];
            }
            $lines[] = [$controlAcc, self::CREDIT, $total];
        }
        return $lines;
    }

    /** VAT return totals from ledger balances of the two control accounts (credit-positive output, debit-positive input). */
    public static function ledgerPosition(float $outputCr, float $outputDr, float $inputDr, float $inputCr): array
    {
        $out = round($outputCr - $outputDr, 2);
        $in = round($inputDr - $inputCr, 2);
        return ['output' => $out, 'input' => $in, 'payable' => round($out - $in, 2)];
    }

    /* --------------------------------- database -------------------------------- */

    public function tenantId(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    /** Seed the standard codes for a company that has none. */
    public static function seedDefaults(mysqli $conn, int $tenantId): void
    {
        $r = @$conn->query("SELECT COUNT(*) n FROM tax_codes WHERE tenant_id = " . (int)$tenantId);
        if (!$r || (int)$r->fetch_assoc()['n'] > 0) {
            return;
        }
        $stmt = @$conn->prepare("INSERT INTO tax_codes (tenant_id, name, rate, applies_to, is_default) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            return;
        }
        foreach ([['Standard rate VAT', '15.0000', 'both', 1], ['Zero rated', '0.0000', 'both', 0], ['Exempt', '0.0000', 'both', 0]] as [$n, $rate, $ap, $def]) {
            $stmt->bind_param('isssi', $tenantId, $n, $rate, $ap, $def);
            $stmt->execute();
        }
    }

    /** All active codes for the company; kind 'sale' or 'purchase' limits to codes usable there. */
    public function codes(?string $kind = null): array
    {
        $t = $this->tenantId();
        self::seedDefaults($this->conn, $t);
        $stmt = $this->conn->prepare("SELECT * FROM tax_codes WHERE tenant_id = ? AND delete_flag = 0 ORDER BY is_default DESC, name");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $t);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if ($kind) {
            $rows = array_values(array_filter($rows, fn ($c) => $c['applies_to'] === 'both' || $c['applies_to'] === $kind));
        }
        return $rows;
    }

    /** The rate new invoice and bill lines start with. */
    public function defaultRate(string $kind = 'sale'): float
    {
        foreach ($this->codes($kind) as $c) {
            if ((int)$c['is_default'] === 1) {
                return (float)$c['rate'];
            }
        }
        return 0.0;
    }

    public function saveCode(array $post): string
    {
        $t = $this->tenantId();
        $id = (int)($post['id'] ?? 0);
        $name = trim((string)($post['name'] ?? ''));
        $rate = self::cleanRate($post['rate'] ?? '');
        $applies = in_array($post['applies_to'] ?? 'both', ['sale', 'purchase', 'both'], true) ? $post['applies_to'] : 'both';
        $default = !empty($post['is_default']) ? 1 : 0;

        if ($name === '') {
            return $this->fail('Enter a name for the tax rate.');
        }
        if ($rate === null) {
            return $this->fail('The rate must be a percentage between 0 and 100.');
        }
        $stmt = $this->conn->prepare("SELECT id FROM tax_codes WHERE tenant_id = ? AND delete_flag = 0 AND name = ? AND id <> ?");
        $stmt->bind_param('isi', $t, $name, $id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows) {
            return $this->fail("A tax rate called “{$name}” already exists.");
        }

        $this->conn->begin_transaction();
        try {
            if ($default) {
                $clear = $this->conn->prepare("UPDATE tax_codes SET is_default = 0 WHERE tenant_id = ?");
                $clear->bind_param('i', $t);
                $clear->execute();
            }
            if ($id) {
                $up = $this->conn->prepare("UPDATE tax_codes SET name = ?, rate = ?, applies_to = ?, is_default = ? WHERE id = ? AND tenant_id = ? AND delete_flag = 0");
                $up->bind_param('sdsiii', $name, $rate, $applies, $default, $id, $t);
            } else {
                $up = $this->conn->prepare("INSERT INTO tax_codes (tenant_id, name, rate, applies_to, is_default) VALUES (?, ?, ?, ?, ?)");
                $up->bind_param('isdsi', $t, $name, $rate, $applies, $default);
            }
            if (!$up->execute()) {
                throw new \RuntimeException($this->conn->error);
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail('The tax rate could not be saved.');
        }
        $this->settings->log_audit($id ? 'Updated tax rate' : 'Added tax rate', 'Tax', "{$name} {$rate}%");
        $this->settings->set_flashdata('success', 'Tax rate saved.');
        return json_encode(['status' => 'success', 'msg' => 'Tax rate saved.']);
    }

    public function deleteCode(int $id): string
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("UPDATE tax_codes SET delete_flag = 1, is_default = 0 WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $id, $t);
        if (!$stmt->execute() || $stmt->affected_rows < 1) {
            return $this->fail('That tax rate could not be deleted.');
        }
        $this->settings->log_audit('Deleted tax rate', 'Tax', "Tax rate #{$id}");
        $this->settings->set_flashdata('success', 'Tax rate deleted.');
        return json_encode(['status' => 'success', 'msg' => 'Tax rate deleted.']);
    }

    /**
     * The company's VAT control account ('output' or 'input'), created on first use so posting never
     * fails for a company that hasn't set one up. Returns 0 if it cannot be found or created.
     */
    public function controlAccount(string $which): int
    {
        $t = $this->tenantId();
        $name = $which === 'input' ? self::INPUT_ACCOUNT : self::OUTPUT_ACCOUNT;
        $find = $this->conn->prepare("SELECT id FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND name = ? LIMIT 1");
        $find->bind_param('is', $t, $name);
        $find->execute();
        if ($row = $find->get_result()->fetch_assoc()) {
            return (int)$row['id'];
        }
        $type = $which === 'input' ? 'Current Asset' : 'Current Liability';
        $desc = $which === 'input' ? 'VAT paid on purchases, reclaimable from the tax authority' : 'VAT collected on sales, payable to the tax authority';
        $res = json_decode((new AccountService($this->conn, $this->settings))->saveAccount([
            'name' => $name, 'type' => $type, 'description' => $desc, 'status' => 1,
        ]), true);
        if (($res['status'] ?? '') !== 'success') {
            return 0;
        }
        $find->execute();
        return (int)($find->get_result()->fetch_assoc()['id'] ?? 0);
    }

    /** Write document lines from postingLines() with explicit debit/credit sides. */
    public function writeLines(int $journalId, array $lines): bool
    {
        $stmt = $this->conn->prepare("INSERT INTO journal_items (journal_id, account_id, group_id, entry_side, amount) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            return false;
        }
        foreach ($lines as [$acc, $side, $amt]) {
            $g = (int)$side;
            $s = $side === self::DEBIT ? 'debit' : 'credit';
            $a = number_format((float)$amt, 4, '.', '');
            $stmt->bind_param('iiiss', $journalId, $acc, $g, $s, $a);
            if (!$stmt->execute()) {
                return false;
            }
        }
        return true;
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
