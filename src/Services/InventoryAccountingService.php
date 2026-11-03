<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * InventoryAccountingService — keeps the ledger in step with stock.
 *
 * Until a company switches this on, stock moves are units only (the long-standing behaviour). Once on:
 *   - a sale issues stock at the product's average cost:   Dr Cost of Sales   Cr Inventory
 *   - a stock-in / count gain:                             Dr Inventory       Cr Stock adjustments
 *   - a stock-out / count loss:                            Dr Stock adjustments  Cr Inventory
 *   - supplier bills for stock products debit Inventory instead of Purchases (see PurchaseService)
 *   - the stock already on hand when switched on is posted once:  Dr Inventory  Cr the opening-balance account
 * The four accounts are chosen explicitly in the company's settings; nothing is guessed from account names.
 *
 * The pure helpers (no database) are static so they can be tested on their own.
 */
class InventoryAccountingService
{
    private ?array $row = null;

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /* ===================================================================
     *  Pure helpers
     * =================================================================== */

    /** Value of a quantity at a unit cost, to the cent. */
    public static function lineValue(float $qty, float $unitCost): float
    {
        return round($qty * $unitCost, 2);
    }

    /** New average cost after adding stock. Cost of nothing-on-hand is simply the incoming cost. */
    public static function weightedAverage(float $oldQty, float $oldCost, float $addQty, float $addCost): float
    {
        $newQty = $oldQty + $addQty;
        if ($addQty <= 0) {
            return round($oldCost, 4);
        }
        if ($newQty <= 0 || $oldQty <= 0) {
            return round($addCost, 4);
        }
        return round((($oldQty * $oldCost) + ($addQty * $addCost)) / $newQty, 4);
    }

    /**
     * Quantity needed per product from document lines. Lines without a product or quantity are ignored and a
     * product that appears twice is added up.
     *
     * @param  array<int,array{product_id:mixed,qty:mixed}> $rows
     * @return array<int,float> product id => quantity
     */
    public static function aggregate(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $pid = (int)($r['product_id'] ?? 0);
            $qty = (float)($r['qty'] ?? 0);
            if ($pid <= 0 || $qty <= 0) {
                continue;
            }
            $out[$pid] = round(($out[$pid] ?? 0.0) + $qty, 4);
        }
        ksort($out);
        return $out;
    }

    /**
     * Products that cannot be supplied in full.
     *
     * @param array<int,float>  $need   product id => quantity wanted
     * @param array<int,float>  $onHand product id => quantity on hand (add back what this document already holds)
     * @param array<int,string> $names  product id => name, for the message
     * @return string[]
     */
    public static function shortages(array $need, array $onHand, array $names = []): array
    {
        $out = [];
        foreach ($need as $pid => $qty) {
            $have = (float)($onHand[$pid] ?? 0.0);
            if ($qty > $have + 0.00001) {
                $label = $names[$pid] ?? ('product #' . $pid);
                $out[] = $label . ': need ' . self::qty($qty) . ', only ' . self::qty(max(0.0, $have)) . ' in stock';
            }
        }
        return $out;
    }

    /** Dr Cost of Sales, Cr Inventory for goods sold. Empty when there is no cost to book. */
    public static function issueLines(float $total, int $inventoryAcc, int $cogsAcc, string $description): array
    {
        $total = round($total, 2);
        if ($total <= 0 || $inventoryAcc <= 0 || $cogsAcc <= 0) {
            return [];
        }
        return [
            ['account_id' => $cogsAcc, 'debit' => $total, 'credit' => 0.0, 'description' => $description],
            ['account_id' => $inventoryAcc, 'debit' => 0.0, 'credit' => $total, 'description' => $description],
        ];
    }

    /**
     * A change in stock value against an offsetting account: a gain debits Inventory, a loss credits it.
     * $signedValue > 0 is stock coming in (or counted up), < 0 stock going out.
     */
    public static function valueLines(float $signedValue, int $inventoryAcc, int $offsetAcc, string $description): array
    {
        $v = round(abs($signedValue), 2);
        if ($v <= 0 || $inventoryAcc <= 0 || $offsetAcc <= 0) {
            return [];
        }
        $in = $signedValue > 0;
        return [
            ['account_id' => $in ? $inventoryAcc : $offsetAcc, 'debit' => $v, 'credit' => 0.0, 'description' => $description],
            ['account_id' => $in ? $offsetAcc : $inventoryAcc, 'debit' => 0.0, 'credit' => $v, 'description' => $description],
        ];
    }

    /** Net value (before tax) of the bill lines that are stock products. */
    public static function stockNet(array $lines): float
    {
        $sum = 0.0;
        foreach ($lines as $l) {
            if ((int)($l['product_id'] ?? 0) > 0) {
                $sum += (float)($l['qty'] ?? 0) * (float)($l['unit_price'] ?? 0);
            }
        }
        return round($sum, 2);
    }

    /**
     * Move the stock part of a purchase's debit from the purchases account to Inventory.
     * $lines are TaxService::postingLines() rows [account, side, amount]; the move is capped at the debit
     * that exists, so a discount or rounding can never leave an unbalanced or negative line.
     */
    public static function splitPurchaseLines(array $lines, int $mainAcc, int $inventoryAcc, float $stockNet): array
    {
        $stockNet = round($stockNet, 2);
        if ($stockNet <= 0 || $inventoryAcc <= 0 || $inventoryAcc === $mainAcc) {
            return $lines;
        }
        $out = [];
        $moved = false;
        foreach ($lines as $ln) {
            if (!$moved && $ln[0] === $mainAcc && $ln[1] === TaxService::DEBIT) {
                $move = round(min($stockNet, (float)$ln[2]), 2);
                $rest = round((float)$ln[2] - $move, 2);
                $out[] = [$inventoryAcc, TaxService::DEBIT, $move];
                if ($rest > 0) {
                    $out[] = [$mainAcc, TaxService::DEBIT, $rest];
                }
                $moved = true;
                continue;
            }
            $out[] = $ln;
        }
        return $out;
    }

    /**
     * Problems with the chosen accounts; empty when fine. $accounts: id => ['name'=>..,'type'=>..].
     *
     * @return string[]
     */
    public static function validateSetup(array $in, array $accounts): array
    {
        $need = [
            'inventory_account_id'  => ['Inventory account', ['asset']],
            'cogs_account_id'       => ['Cost of sales account', ['expense']],
            'adjustment_account_id' => ['Stock adjustments account', ['expense']],
            'offset_account_id'     => ['Opening stock account', ['equity']],
        ];
        $errors = [];
        foreach ($need as $field => [$label, $classes]) {
            $id = (int)($in[$field] ?? 0);
            if ($id <= 0 || !isset($accounts[$id])) {
                $errors[] = "Choose the {$label}.";
                continue;
            }
            $class = LedgerService::classify($accounts[$id]['type'] ?? '')['class'];
            if (!in_array($class, $classes, true)) {
                $errors[] = "{$label}: “{$accounts[$id]['name']}” is a " . $class . ' account; it must be ' . ($classes[0] === 'asset' ? 'an asset' : ($classes[0] === 'equity' ? 'an equity' : 'an expense')) . ' account.';
            }
        }
        $ids = array_filter([(int)($in['inventory_account_id'] ?? 0), (int)($in['cogs_account_id'] ?? 0), (int)($in['adjustment_account_id'] ?? 0), (int)($in['offset_account_id'] ?? 0)]);
        $inv = (int)($in['inventory_account_id'] ?? 0);
        if ($inv > 0 && count(array_keys($ids, $inv, true)) > 1) {
            $errors[] = 'The inventory account cannot also be used for cost of sales, adjustments or opening stock.';
        }
        return $errors;
    }

    private static function qty(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.') ?: '0';
    }

    /* ===================================================================
     *  Settings
     * =================================================================== */

    private function tenantId(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    /** The company's inventory accounting settings (defaults when never saved). */
    public function config(): array
    {
        if ($this->row !== null) {
            return $this->row;
        }
        $t = $this->tenantId();
        $this->row = ['enabled' => 0, 'inventory_account_id' => 0, 'cogs_account_id' => 0, 'adjustment_account_id' => 0,
            'offset_account_id' => 0, 'enabled_on' => null, 'opening_journal_id' => 0];
        $stmt = @$this->conn->prepare("SELECT * FROM inventory_settings WHERE tenant_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $t);
            $stmt->execute();
            if ($r = $stmt->get_result()->fetch_assoc()) {
                foreach ($this->row as $k => $_) {
                    $this->row[$k] = $k === 'enabled_on' ? $r[$k] : (int)($r[$k] ?? 0);
                }
            }
        }
        return $this->row;
    }

    public function isEnabled(): bool
    {
        $c = $this->config();
        return $c['enabled'] === 1 && $c['inventory_account_id'] > 0 && $c['cogs_account_id'] > 0 && $c['adjustment_account_id'] > 0;
    }

    /** Candidate accounts for the settings page: id => ['name','type']. */
    public function accountChoices(): array
    {
        $t = $this->tenantId();
        $out = [];
        $stmt = $this->conn->prepare("SELECT id, name, type FROM account_list WHERE tenant_id = ? AND delete_flag = 0 AND status = 1 ORDER BY name");
        $stmt->bind_param('i', $t);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[(int)$r['id']] = ['name' => (string)$r['name'], 'type' => (string)$r['type']];
        }
        return $out;
    }

    /** Stock on hand at cost, the way the valuation report adds it up. */
    public function valuation(): float
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT COALESCE(SUM(stock_quantity * cost_price), 0) v FROM product_list WHERE tenant_id = ? AND delete_flag = 0");
        $stmt->bind_param('i', $t);
        $stmt->execute();
        return round((float)($stmt->get_result()->fetch_assoc()['v'] ?? 0), 2);
    }

    /** Debit balance of the inventory account from posted journals. */
    public function ledgerBalance(?string $asOf = null): float
    {
        $c = $this->config();
        $acc = $c['inventory_account_id'];
        if ($acc <= 0) {
            return 0.0;
        }
        $t = $this->tenantId();
        $side = LedgerService::SIDE_SQL;
        $posted = LedgerService::POSTED_SQL;
        $asOf = $asOf ?: date('Y-m-d');
        $stmt = $this->conn->prepare("SELECT COALESCE(SUM(CASE WHEN {$side} = 1 THEN ji.amount ELSE -ji.amount END), 0) b
            FROM journal_items ji
            JOIN journal_entries j ON j.id = ji.journal_id
            LEFT JOIN group_list g ON g.id = ji.group_id
            WHERE j.tenant_id = ? AND ji.account_id = ? AND j.journal_date <= ? AND {$posted}");
        $stmt->bind_param('iis', $t, $acc, $asOf);
        $stmt->execute();
        return round((float)($stmt->get_result()->fetch_assoc()['b'] ?? 0), 2);
    }

    /**
     * Save the account choices and switch inventory accounting on. The first time, the stock already on hand is
     * posted once as the opening inventory so the ledger starts level with the valuation report.
     */
    public function saveSettings(array $post): string
    {
        $t = $this->tenantId();
        $accounts = $this->accountChoices();
        $in = [];
        foreach (['inventory_account_id', 'cogs_account_id', 'adjustment_account_id', 'offset_account_id'] as $f) {
            $in[$f] = (int)($post[$f] ?? 0);
        }
        if ($errors = self::validateSetup($in, $accounts)) {
            return json_encode(['status' => 'failed', 'msg' => implode(' ', $errors)]);
        }
        $enable = !empty($post['enabled']) ? 1 : 0;
        $cur = $this->config();
        $date = trim((string)($post['enabled_on'] ?? ''));
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date) ? $date : date('Y-m-d');

        // Switching off again is allowed; the accounts and past postings stay as they are.
        $keepOn = $cur['enabled_on'];
        $openingId = $cur['opening_journal_id'];
        $postOpening = $enable === 1 && $openingId <= 0;
        $value = $postOpening ? $this->valuation() : 0.0;

        $inv = $in['inventory_account_id'];
        $cogs = $in['cogs_account_id'];
        $adj = $in['adjustment_account_id'];
        $off = $in['offset_account_id'];
        $stmt = $this->conn->prepare("INSERT INTO inventory_settings (tenant_id, enabled, inventory_account_id, cogs_account_id, adjustment_account_id, offset_account_id)
            VALUES (?, 0, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE inventory_account_id = VALUES(inventory_account_id), cogs_account_id = VALUES(cogs_account_id),
            adjustment_account_id = VALUES(adjustment_account_id), offset_account_id = VALUES(offset_account_id)");
        $stmt->bind_param('iiiii', $t, $inv, $cogs, $adj, $off);
        if (!$stmt->execute()) {
            return json_encode(['status' => 'failed', 'msg' => 'The settings could not be saved.']);
        }

        if ($postOpening && $value > 0) {
            $lines = self::valueLines($value, $inv, $off, 'Opening inventory when inventory accounting was switched on');
            $res = json_decode((new JournalService($this->conn, $this->settings))->postSystemJournal($date, 'Opening inventory at cost', $lines), true);
            if (($res['status'] ?? '') !== 'success') {
                return json_encode(['status' => 'failed', 'msg' => 'Opening inventory could not be posted: ' . ($res['msg'] ?? 'unknown error') . ' Nothing was switched on.']);
            }
            $openingId = (int)$res['id'];
        } elseif ($postOpening) {
            $openingId = 0;
        }

        $when = $enable === 1 ? ($keepOn ?: $date) : $keepOn;
        $upd = $this->conn->prepare("UPDATE inventory_settings SET enabled = ?, enabled_on = ?, opening_journal_id = NULLIF(?, 0) WHERE tenant_id = ?");
        $upd->bind_param('isii', $enable, $when, $openingId, $t);
        if (!$upd->execute()) {
            if ($postOpening && $openingId > 0) {
                (new JournalService($this->conn, $this->settings))->reverseJournal($openingId, null, true);
            }
            return json_encode(['status' => 'failed', 'msg' => 'The settings could not be saved. Nothing was switched on.']);
        }
        $this->row = null;
        $this->settings->log_audit('Inventory accounting ' . ($enable ? 'switched on' : 'switched off'), 'Inventory', 'Opening inventory ' . number_format($value, 2));
        $msg = $enable ? 'Inventory accounting is on.' . ($postOpening && $value > 0 ? ' Opening inventory of ' . number_format($value, 2) . ' was posted.' : '') : 'Inventory accounting is off. Stock moves are units only again.';
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg]);
    }

    /* ===================================================================
     *  Stock movements with their ledger postings
     * =================================================================== */

    /**
     * Products by id: name, on-hand total and average cost.
     *
     * @param  int[] $ids
     * @return array<int,array{name:string,qty:float,cost:float}>
     */
    public function products(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $t = $this->tenantId();
        $in = implode(',', $ids);
        $res = $this->conn->query("SELECT id, name, stock_quantity, cost_price FROM product_list WHERE tenant_id = {$t} AND id IN ({$in})");
        $out = [];
        while ($res && ($r = $res->fetch_assoc())) {
            $out[(int)$r['id']] = ['name' => (string)$r['name'], 'qty' => (float)$r['stock_quantity'], 'cost' => (float)$r['cost_price']];
        }
        return $out;
    }

    /**
     * Check a sale can be supplied. $returning is what the same document already holds (an edit), added back
     * before comparing. Returns an error message or null.
     *
     * @param array<int,float> $need
     * @param array<int,float> $returning
     */
    public function checkAvailable(array $need, array $returning = []): ?string
    {
        if (!$this->isEnabled() || !$need) {
            return null;
        }
        $prods = $this->products(array_keys($need));
        $onHand = $names = [];
        foreach ($need as $pid => $_) {
            $onHand[$pid] = ($prods[$pid]['qty'] ?? 0.0) + (float)($returning[$pid] ?? 0.0);
            $names[$pid] = $prods[$pid]['name'] ?? ('product #' . $pid);
        }
        $short = self::shortages($need, $onHand, $names);
        return $short ? 'Not enough stock: ' . implode('; ', $short) . '.' : null;
    }

    /**
     * Issue stock for a sale and post its cost. The journal goes first, so a closed period or a missing account
     * stops the sale before any stock moves; each deduction is guarded so stock can never go below zero, and a
     * failure part-way puts back what was taken and reverses the journal.
     *
     * @param  array<int,float> $need product id => quantity
     * @return array{ok:bool,msg:string,journal_id:int,costs:array<int,float>}
     */
    public function issue(array $need, string $date, string $reference, int $warehouseId = 0): array
    {
        $none = ['ok' => true, 'msg' => '', 'journal_id' => 0, 'costs' => []];
        if (!$this->isEnabled() || !$need) {
            return $none;
        }
        $c = $this->config();
        $prods = $this->products(array_keys($need));
        $total = 0.0;
        $costs = [];
        foreach ($need as $pid => $qty) {
            if (!isset($prods[$pid])) {
                return ['ok' => false, 'msg' => "Product #{$pid} was not found.", 'journal_id' => 0, 'costs' => []];
            }
            $costs[$pid] = $prods[$pid]['cost'];
            $total += self::lineValue($qty, $prods[$pid]['cost']);
        }
        if ($msg = $this->checkAvailable($need)) {
            return ['ok' => false, 'msg' => $msg, 'journal_id' => 0, 'costs' => []];
        }

        $journalId = 0;
        $lines = self::issueLines($total, $c['inventory_account_id'], $c['cogs_account_id'], "Cost of sales {$reference}");
        if ($lines) {
            $res = json_decode((new JournalService($this->conn, $this->settings))->postSystemJournal($date, "Cost of sales for {$reference}", $lines), true);
            if (($res['status'] ?? '') !== 'success') {
                return ['ok' => false, 'msg' => (string)($res['msg'] ?? 'The cost of sales could not be posted.'), 'journal_id' => 0, 'costs' => []];
            }
            $journalId = (int)$res['id'];
        }

        $t = $this->tenantId();
        $taken = [];
        foreach ($need as $pid => $qty) {
            $q = $qty;
            $p = $pid;
            $stmt = $this->conn->prepare("UPDATE product_list SET stock_quantity = stock_quantity - ? WHERE id = ? AND tenant_id = ? AND stock_quantity >= ?");
            $stmt->bind_param('diid', $q, $p, $t, $q);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                foreach ($taken as $tp => $tq) {
                    $this->conn->query("UPDATE product_list SET stock_quantity = stock_quantity + {$tq} WHERE id = {$tp} AND tenant_id = {$t}");
                }
                if ($journalId > 0) {
                    (new JournalService($this->conn, $this->settings))->reverseJournal($journalId, $this->reversalDate($journalId, $date), true);
                }
                return ['ok' => false, 'msg' => 'Stock changed while the sale was being saved. Please try again.', 'journal_id' => 0, 'costs' => []];
            }
            $taken[$pid] = $qty;
        }
        foreach ($need as $pid => $qty) {
            $this->locate($pid, $warehouseId, -$qty);
            $this->log($pid, $warehouseId, 'out', $qty, $costs[$pid], $reference, 'Sale', $journalId);
        }
        return ['ok' => true, 'msg' => '', 'journal_id' => $journalId, 'costs' => $costs];
    }

    /**
     * Take a sale back: reverse its cost journal and return the stock at the cost it left at. Fails, changing
     * nothing, if the journal cannot be reversed (for example the period is closed).
     *
     * @param array<int,array{qty:float,cost:float}> $items product id => qty and issue cost
     */
    public function restore(array $items, int $journalId, string $date, string $reference, int $warehouseId = 0): array
    {
        // Works even if the company has since switched inventory accounting off: it only undoes what was issued.
        if ($journalId > 0) {
            $res = json_decode((new JournalService($this->conn, $this->settings))->reverseJournal($journalId, $this->reversalDate($journalId, $date), true), true);
            if (($res['status'] ?? '') !== 'success') {
                return ['ok' => false, 'msg' => (string)($res['msg'] ?? 'The cost of sales could not be reversed.')];
            }
        }
        $t = $this->tenantId();
        $prods = $this->products(array_keys($items));
        foreach ($items as $pid => $it) {
            $qty = (float)$it['qty'];
            $cost = (float)$it['cost'];
            if ($qty <= 0 || !isset($prods[$pid])) {
                continue;
            }
            $avg = self::weightedAverage(max(0.0, $prods[$pid]['qty']), $prods[$pid]['cost'], $qty, $cost);
            $q = $qty;
            $p = $pid;
            $a = $avg;
            $stmt = $this->conn->prepare("UPDATE product_list SET stock_quantity = stock_quantity + ?, cost_price = ? WHERE id = ? AND tenant_id = ?");
            $stmt->bind_param('ddii', $q, $a, $p, $t);
            $stmt->execute();
            $this->locate($pid, $warehouseId, $qty);
            $this->log($pid, $warehouseId, 'in', $qty, $cost, $reference, 'Sale reversed', 0);
        }
        return ['ok' => true, 'msg' => ''];
    }

    /**
     * Post the value of a stock count change or movement that the caller applies itself. For stock coming in at a
     * stated cost the product's average cost is updated too. Returns the journal id (0 when nothing to post) and
     * the cost the quantity was valued at.
     *
     * @return array{ok:bool,msg:string,journal_id:int,unit_cost:float}
     */
    public function valueChange(int $productId, float $deltaQty, ?float $incomingCost, string $date, string $reference): array
    {
        $none = ['ok' => true, 'msg' => '', 'journal_id' => 0, 'unit_cost' => 0.0];
        if (!$this->isEnabled() || $deltaQty == 0.0) {
            return $none;
        }
        $c = $this->config();
        $prods = $this->products([$productId]);
        if (!isset($prods[$productId])) {
            return ['ok' => false, 'msg' => 'Product not found.', 'journal_id' => 0, 'unit_cost' => 0.0];
        }
        $p = $prods[$productId];
        $cost = $p['cost'];
        $newCost = null;
        if ($deltaQty > 0 && $incomingCost !== null && $incomingCost > 0) {
            $cost = $incomingCost;
            $newCost = self::weightedAverage(max(0.0, $p['qty']), $p['cost'], $deltaQty, $incomingCost);
        }
        if ($deltaQty < 0 && -$deltaQty > $p['qty'] + 0.00001) {
            return ['ok' => false, 'msg' => $p['name'] . ': only ' . self::qty(max(0.0, $p['qty'])) . ' in stock.', 'journal_id' => 0, 'unit_cost' => 0.0];
        }
        $value = self::lineValue(abs($deltaQty), $cost) * ($deltaQty > 0 ? 1 : -1);
        $journalId = 0;
        $lines = self::valueLines($value, $c['inventory_account_id'], $c['adjustment_account_id'], "Stock adjustment {$reference}");
        if ($lines) {
            $res = json_decode((new JournalService($this->conn, $this->settings))->postSystemJournal($date, "Stock adjustment {$reference} — {$p['name']}", $lines), true);
            if (($res['status'] ?? '') !== 'success') {
                return ['ok' => false, 'msg' => (string)($res['msg'] ?? 'The stock value could not be posted.'), 'journal_id' => 0, 'unit_cost' => 0.0];
            }
            $journalId = (int)$res['id'];
        }
        if ($newCost !== null) {
            $t = $this->tenantId();
            $nc = $newCost;
            $pid = $productId;
            $stmt = $this->conn->prepare("UPDATE product_list SET cost_price = ? WHERE id = ? AND tenant_id = ?");
            $stmt->bind_param('dii', $nc, $pid, $t);
            $stmt->execute();
        }
        return ['ok' => true, 'msg' => '', 'journal_id' => $journalId, 'unit_cost' => $cost];
    }

    /**
     * Opening stock entered when a product is created: Dr Inventory, Cr the opening stock account.
     */
    public function postOpeningStock(int $productId, float $qty, float $unitCost, string $reference): ?string
    {
        if (!$this->isEnabled() || $qty <= 0 || $unitCost <= 0) {
            return null;
        }
        $c = $this->config();
        $lines = self::valueLines(self::lineValue($qty, $unitCost), $c['inventory_account_id'], $c['offset_account_id'], "Opening stock {$reference}");
        $res = json_decode((new JournalService($this->conn, $this->settings))->postSystemJournal(date('Y-m-d'), "Opening stock {$reference}", $lines), true);
        return ($res['status'] ?? '') === 'success' ? null : (string)($res['msg'] ?? 'The opening stock could not be posted.');
    }

    /* ------------------------------------------------------------------ */

    /** A reversal cannot be dated before the journal it reverses. */
    private function reversalDate(int $journalId, string $wanted): string
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT journal_date FROM journal_entries WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $journalId, $t);
        $stmt->execute();
        $orig = (string)($stmt->get_result()->fetch_assoc()['journal_date'] ?? '');
        return $orig !== '' && $wanted < $orig ? $orig : $wanted;
    }

    private function locate(int $productId, int $warehouseId, float $delta): void
    {
        try {
            (new WarehouseStockService($this->conn, $this->settings))->adjustLocation($productId, $warehouseId, $delta, false);
        } catch (\Throwable $e) {
            // location tracking must not block the sale; the product total is authoritative
        }
    }

    private function log(int $productId, int $warehouseId, string $type, float $qty, float $unitCost, string $reference, string $note, int $journalId): void
    {
        $t = $this->tenantId();
        $uid = (int)$this->settings->userdata('id');
        $wh = max(0, $warehouseId);
        $jid = $journalId > 0 ? $journalId : null;
        $stmt = $this->conn->prepare("INSERT INTO inventory_logs (tenant_id, product_id, warehouse_id, type, qty, unit_cost, reference, notes, journal_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('iiisddssii', $t, $productId, $wh, $type, $qty, $unitCost, $reference, $note, $jid, $uid);
        $stmt->execute();
    }
}
