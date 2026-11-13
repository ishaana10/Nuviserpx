<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Landed cost: freight, duty, insurance and similar charges for a goods receipt are spread over the items
 * received and added to their cost, so margins reflect what the goods really cost.
 *
 * Only the part that is still on hand is added to stock. Units already sold have left through cost of sales, so
 * their share of the charge stays where it was booked. With inventory accounting on, the added value is posted
 * Dr Inventory / Cr the account the charge was originally booked to.
 */
class LandedCostService
{
    public const MAX_CHARGES = 10;

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /**
     * Pure: split $total across weights, to the cent, with the rounding left-overs going to the largest
     * remainders so the parts always add up to the total exactly.
     *
     * @param  array<int,float> $weights key => weight
     * @return array<int,float> key => amount
     */
    public static function allocate(array $weights, float $total): array
    {
        $cents = (int)round($total * 100);
        $sum = array_sum(array_map(fn($w) => max(0.0, (float)$w), $weights));
        $out = array_fill_keys(array_keys($weights), 0);
        if ($cents <= 0 || $sum <= 0) {
            return array_map(fn() => 0.0, $out);
        }
        $rem = [];
        $given = 0;
        foreach ($weights as $k => $w) {
            $exact = $cents * max(0.0, (float)$w) / $sum;
            $out[$k] = (int)floor($exact);
            $rem[$k] = $exact - $out[$k];
            $given += $out[$k];
        }
        arsort($rem);
        foreach (array_keys($rem) as $k) {
            if ($given >= $cents) {
                break;
            }
            $out[$k]++;
            $given++;
        }
        return array_map(fn($c) => round($c / 100, 2), array_map("floatval", $out));
    }

    /** Pure: the part of an allocation that belongs to units still on hand. */
    public static function capitalisable(float $allocated, float $received, float $onHand): float
    {
        if ($allocated <= 0 || $received <= 0 || $onHand <= 0) {
            return 0.0;
        }
        return round($allocated * min(1.0, $onHand / $received), 2);
    }

    /** Pure: the cost per unit after adding $capitalised to the $onHand units. */
    public static function newCost(float $oldCost, float $onHand, float $capitalised): float
    {
        if ($onHand <= 0 || $capitalised <= 0) {
            return round($oldCost, 4);
        }
        return round($oldCost + $capitalised / $onHand, 4);
    }

    /**
     * Pure: clean the typed charge rows. Returns [charges, error]. A row with an amount needs a description.
     *
     * @return array{0:array<int,array{description:string,amount:float}>,1:?string}
     */
    public static function cleanCharges(array $desc, array $amt): array
    {
        $out = [];
        foreach ($amt as $i => $a) {
            $d = trim((string)($desc[$i] ?? ''));
            $raw = trim(str_replace(',', '', (string)$a));
            if ($raw === '' && $d === '') {
                continue;
            }
            if (!is_numeric($raw) || (float)$raw <= 0) {
                return [[], 'Each charge needs an amount above zero.'];
            }
            if ($d === '') {
                return [[], 'Give each charge a description, such as Freight or Customs duty.'];
            }
            $out[] = ['description' => mb_substr($d, 0, 150), 'amount' => round((float)$raw, 2)];
        }
        if (!$out) {
            return [[], 'Enter at least one charge.'];
        }
        if (count($out) > self::MAX_CHARGES) {
            return [[], 'At most ' . self::MAX_CHARGES . ' charges per entry.'];
        }
        return [$out, null];
    }

    private function tenant(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    /** @return array<string,mixed>|null */
    public function receipt(int $grnId): ?array
    {
        $t = $this->tenant();
        $r = $this->conn->query("SELECT g.*, v.name vendor_name FROM `goods_receipt_list` g LEFT JOIN `party_list` v ON v.id = g.vendor_id
            WHERE g.id = '{$grnId}' AND g.tenant_id = '{$t}'");
        return ($r && $r->num_rows) ? $r->fetch_assoc() : null;
    }

    /**
     * Received stock lines for a receipt, one per product.
     *
     * @return array<int,array{product_id:int,name:string,qty:float,value:float,on_hand:float,cost:float}>
     */
    public function receiptLines(int $grnId): array
    {
        $t = $this->tenant();
        $r = $this->conn->query("SELECT i.product_id, p.name, p.stock_quantity, p.cost_price,
                SUM(i.qty_received) qty, SUM(i.qty_received * i.unit_cost) val
            FROM `goods_receipt_items` i
            JOIN `goods_receipt_list` g ON g.id = i.goods_receipt_id AND g.tenant_id = '{$t}'
            JOIN `product_list` p ON p.id = i.product_id AND p.tenant_id = '{$t}'
            WHERE i.goods_receipt_id = '{$grnId}' AND i.product_id IS NOT NULL
            GROUP BY i.product_id, p.name, p.stock_quantity, p.cost_price ORDER BY p.name");
        $out = [];
        while ($r && ($row = $r->fetch_assoc())) {
            $out[(int)$row['product_id']] = ['product_id' => (int)$row['product_id'], 'name' => (string)$row['name'],
                'qty' => (float)$row['qty'], 'value' => round((float)$row['val'], 2),
                'on_hand' => (float)$row['stock_quantity'], 'cost' => (float)$row['cost_price']];
        }
        return $out;
    }

    /**
     * What an entry would do, without saving anything.
     *
     * @return array{rows:array<int,array<string,mixed>>,total:float,capitalised:float}
     */
    public function plan(array $lines, float $total, string $basis): array
    {
        $weights = [];
        foreach ($lines as $pid => $l) {
            $weights[$pid] = $basis === 'qty' ? $l['qty'] : $l['value'];
        }
        $alloc = self::allocate($weights, $total);
        $rows = [];
        $cap = 0.0;
        foreach ($lines as $pid => $l) {
            $c = self::capitalisable($alloc[$pid], $l['qty'], $l['on_hand']);
            $cap += $c;
            $rows[$pid] = $l + ['allocated' => $alloc[$pid], 'capitalised' => $c, 'new_cost' => self::newCost($l['cost'], $l['on_hand'], $c)];
        }
        return ['rows' => $rows, 'total' => round($total, 2), 'capitalised' => round($cap, 2)];
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $grnId): array
    {
        $t = $this->tenant();
        $r = $this->conn->query("SELECT * FROM `landed_costs` WHERE grn_id = '{$grnId}' AND tenant_id = '{$t}' ORDER BY id DESC");
        $out = [];
        while ($r && ($row = $r->fetch_assoc())) {
            $out[] = $row;
        }
        return $out;
    }

    public function post(array $post): string
    {
        $t = $this->tenant();
        $grnId = (int)($post['grn_id'] ?? 0);
        $grn = $this->receipt($grnId);
        if (!$grn || $grn['status'] === 'cancelled') {
            return json_encode(['status' => 'failed', 'msg' => 'That goods receipt was not found.']);
        }
        [$charges, $err] = self::cleanCharges((array)($post['desc'] ?? []), (array)($post['amount'] ?? []));
        if ($err) {
            return json_encode(['status' => 'failed', 'msg' => $err]);
        }
        $basis = ($post['basis'] ?? 'value') === 'qty' ? 'qty' : 'value';
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($post['cost_date'] ?? '')) ? (string)$post['cost_date'] : date('Y-m-d');
        $creditId = (int)($post['credit_account_id'] ?? 0);
        $notes = mb_substr(trim((string)($post['notes'] ?? '')), 0, 500);

        $lockName = "landed_{$t}_{$grnId}";
        $got = $this->conn->query("SELECT GET_LOCK('" . $this->conn->real_escape_string($lockName) . "', 5) AS l")->fetch_assoc();
        if ((int)($got['l'] ?? 0) !== 1) {
            return json_encode(['status' => 'failed', 'msg' => 'Another landed cost is being saved for this receipt. Try again in a moment.']);
        }
        try {
            $lines = $this->receiptLines($grnId);
            if (!$lines) {
                return json_encode(['status' => 'failed', 'msg' => 'This receipt has no stock items to add cost to.']);
            }
            $total = round(array_sum(array_column($charges, 'amount')), 2);
            $plan = $this->plan($lines, $total, $basis);

            $acct = new InventoryAccountingService($this->conn, $this->settings);
            $journalId = 0;
            if ($acct->isEnabled() && $plan['capitalised'] > 0) {
                $choices = $acct->accountChoices();
                if ($creditId <= 0 || !isset($choices[$creditId])) {
                    return json_encode(['status' => 'failed', 'msg' => 'Choose the account these charges were booked to, so the cost can be moved into inventory.']);
                }
                $inv = (int)$acct->config()['inventory_account_id'];
                if ($creditId === $inv) {
                    return json_encode(['status' => 'failed', 'msg' => 'Choose the account the charges were booked to, not the inventory account.']);
                }
                $jl = InventoryAccountingService::valueLines($plan['capitalised'], $inv, $creditId, "Landed cost on {$grn['grn_no']}");
                $res = json_decode((new JournalService($this->conn, $this->settings))->postSystemJournal($date, "Landed cost on {$grn['grn_no']}", $jl), true);
                if (($res['status'] ?? '') !== 'success') {
                    return json_encode(['status' => 'failed', 'msg' => (string)($res['msg'] ?? 'The journal could not be posted.')]);
                }
                $journalId = (int)$res['id'];
            }

            $no = SequenceService::documentNo($this->conn, $t, 'landed_costs', 'landed_no', 'LC-', 'landed_cost');
            $user = (int)$this->settings->userdata('id');
            $this->conn->begin_transaction();
            try {
                $noE = $this->conn->real_escape_string($no);
                $notesE = $this->conn->real_escape_string($notes);
                if (!$this->conn->query("INSERT INTO `landed_costs` SET tenant_id = '{$t}', landed_no = '{$noE}', grn_id = '{$grnId}',
                    cost_date = '{$date}', basis = '{$basis}', total = '{$total}', capitalised = '{$plan['capitalised']}',
                    credit_account_id = '{$creditId}', journal_id = '{$journalId}', notes = '{$notesE}', created_by = '{$user}'")) {
                    throw new \RuntimeException(\App\Support\Db::oops($this->conn));
                }
                $lid = (int)$this->conn->insert_id;
                foreach ($charges as $c) {
                    $d = $this->conn->real_escape_string($c['description']);
                    if (!$this->conn->query("INSERT INTO `landed_cost_charges` SET landed_id = '{$lid}', description = '{$d}', amount = '{$c['amount']}'")) {
                        throw new \RuntimeException(\App\Support\Db::oops($this->conn));
                    }
                }
                foreach ($plan['rows'] as $pid => $r) {
                    if (!$this->conn->query("INSERT INTO `landed_cost_items` SET landed_id = '{$lid}', product_id = '{$pid}', qty_received = '{$r['qty']}',
                        allocated = '{$r['allocated']}', capitalised = '{$r['capitalised']}', old_cost = '{$r['cost']}', new_cost = '{$r['new_cost']}'")) {
                        throw new \RuntimeException(\App\Support\Db::oops($this->conn));
                    }
                    if ($r['capitalised'] > 0) {
                        if (!$this->conn->query("UPDATE `product_list` SET cost_price = '{$r['new_cost']}' WHERE id = '{$pid}' AND tenant_id = '{$t}'")) {
                            throw new \RuntimeException(\App\Support\Db::oops($this->conn));
                        }
                    }
                }
                $this->conn->commit();
            } catch (\Throwable $e) {
                $this->conn->rollback();
                if ($journalId > 0) {
                    (new JournalService($this->conn, $this->settings))->reverseJournal($journalId, $date, true);
                }
                return json_encode(['status' => 'failed', 'msg' => 'The landed cost could not be saved. Nothing was changed.']);
            }
            $this->settings->log_audit('Landed Cost', 'Purchasing', "{$no} on {$grn['grn_no']} (#{$grnId}): charges {$total}, {$plan['capitalised']} added to stock cost" . ($journalId > 0 ? ", journal #{$journalId}" : '') . '.');
            return json_encode(['status' => 'success', 'msg' => "Landed cost {$no} saved: {$plan['capitalised']} added to stock cost.", 'landed_id' => $lid]);
        } finally {
            $this->conn->query("SELECT RELEASE_LOCK('" . $this->conn->real_escape_string($lockName) . "')");
        }
    }
}
