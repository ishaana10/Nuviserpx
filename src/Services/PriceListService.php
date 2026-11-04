<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Customer price lists: a named set of prices per product, with optional quantity breaks, assigned to customers.
 * A customer on a list gets the list's price filled in on their invoices (the user can still type another price);
 * a product not on the list falls back to its normal selling price.
 */
class PriceListService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /**
     * The price for a quantity from a product's tiers: the tier with the highest minimum quantity that the
     * quantity reaches. Null when no tier applies (quantity below every minimum). Pure.
     *
     * @param array<int,array{min_qty:float,price:float}> $tiers
     */
    public static function tierPrice(array $tiers, float $qty): ?float
    {
        $best = null;
        foreach ($tiers as $t) {
            if ($qty + 0.00005 >= (float)$t['min_qty'] && ($best === null || (float)$t['min_qty'] > (float)$best['min_qty'])) {
                $best = $t;
            }
        }
        return $best === null ? null : round((float)$best['price'], 4);
    }

    /** Check a price entry; returns ['min_qty','price'] or ['error']. Pure. */
    public static function cleanEntry(mixed $minQty, mixed $price): array
    {
        $min = trim((string)$minQty) === '' ? 1.0 : BudgetService::cleanAmount($minQty);
        $p = BudgetService::cleanAmount($price);
        if ($min === null || $min <= 0) {
            return ['error' => 'The minimum quantity must be above zero.'];
        }
        if ($p === null || $p < 0) {
            return ['error' => 'Enter the price as a number (zero or more).'];
        }
        return ['min_qty' => round($min, 4), 'price' => round($p, 4)];
    }

    private function tenantId(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    private function ok(string $msg, array $extra = []): string
    {
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg] + $extra);
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }

    /** All the company's lists with how many prices and customers each has. */
    public function lists(): array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT l.*,
                (SELECT COUNT(*) FROM price_list_items i WHERE i.price_list_id = l.id AND i.tenant_id = l.tenant_id) prices,
                (SELECT COUNT(*) FROM party_list p WHERE p.price_list_id = l.id AND p.tenant_id = l.tenant_id AND p.delete_flag = 0) customers
            FROM price_lists l WHERE l.tenant_id = ? ORDER BY l.name");
        $stmt->bind_param('i', $t);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function find(int $id): ?array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT * FROM price_lists WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $id, $t);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function items(int $listId): array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT i.*, p.name product_name, p.product_code, p.selling_price
            FROM price_list_items i LEFT JOIN product_list p ON p.id = i.product_id
            WHERE i.price_list_id = ? AND i.tenant_id = ? ORDER BY p.name, i.min_qty");
        $stmt->bind_param('ii', $listId, $t);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** The customers on a list: party id => name. */
    public function customers(int $listId): array
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("SELECT id, name FROM party_list WHERE price_list_id = ? AND tenant_id = ? AND delete_flag = 0 ORDER BY name");
        $stmt->bind_param('ii', $listId, $t);
        $stmt->execute();
        $out = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $out[(int)$r['id']] = (string)$r['name'];
        }
        return $out;
    }

    public function saveList(array $post): string
    {
        $t = $this->tenantId();
        $id = (int)($post['id'] ?? 0);
        $name = mb_substr(trim((string)($post['name'] ?? '')), 0, 100);
        $status = !empty($post['status']) ? 1 : 0;
        if ($name === '') {
            return $this->fail('Give the price list a name.');
        }
        $dup = $this->conn->prepare("SELECT 1 FROM price_lists WHERE tenant_id = ? AND name = ? AND id <> ? LIMIT 1");
        $dup->bind_param('isi', $t, $name, $id);
        $dup->execute();
        if ($dup->get_result()->num_rows > 0) {
            return $this->fail('There is already a price list with that name.');
        }
        if ($id > 0) {
            if (!$this->find($id)) {
                return $this->fail('Price list not found.');
            }
            $stmt = $this->conn->prepare("UPDATE price_lists SET name = ?, status = ? WHERE id = ? AND tenant_id = ?");
            $stmt->bind_param('siii', $name, $status, $id, $t);
        } else {
            $stmt = $this->conn->prepare("INSERT INTO price_lists (tenant_id, name, status) VALUES (?, ?, ?)");
            $stmt->bind_param('isi', $t, $name, $status);
        }
        if (!$stmt->execute()) {
            return $this->fail('The price list could not be saved.');
        }
        $id = $id > 0 ? $id : (int)$this->conn->insert_id;
        $this->settings->log_audit('Saved price list', 'Price lists', $name);
        return $this->ok('Price list saved.', ['id' => $id]);
    }

    /** Delete a list; the customers on it go back to normal prices. */
    public function deleteList(int $id): string
    {
        $t = $this->tenantId();
        if (!($l = $this->find($id))) {
            return $this->fail('Price list not found.');
        }
        $this->conn->begin_transaction();
        try {
            foreach (["UPDATE party_list SET price_list_id = NULL WHERE price_list_id = ? AND tenant_id = ?",
                      "DELETE FROM price_list_items WHERE price_list_id = ? AND tenant_id = ?",
                      "DELETE FROM price_lists WHERE id = ? AND tenant_id = ?"] as $sql) {
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param('ii', $id, $t);
                if (!$stmt->execute()) {
                    throw new \RuntimeException('failed');
                }
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail('The price list could not be deleted.');
        }
        $this->settings->log_audit('Deleted price list', 'Price lists', (string)$l['name']);
        return $this->ok('Price list deleted.');
    }

    /** Add or change a price (one per product and minimum quantity). */
    public function saveItem(array $post): string
    {
        $t = $this->tenantId();
        $listId = (int)($post['price_list_id'] ?? 0);
        $pid = (int)($post['product_id'] ?? 0);
        if (!$this->find($listId)) {
            return $this->fail('Price list not found.');
        }
        $chk = $this->conn->prepare("SELECT 1 FROM product_list WHERE id = ? AND tenant_id = ?");
        $chk->bind_param('ii', $pid, $t);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            return $this->fail('Choose a product.');
        }
        $e = self::cleanEntry($post['min_qty'] ?? '', $post['price'] ?? '');
        if (isset($e['error'])) {
            return $this->fail($e['error']);
        }
        $min = $e['min_qty'];
        $price = $e['price'];
        $stmt = $this->conn->prepare("INSERT INTO price_list_items (tenant_id, price_list_id, product_id, min_qty, price) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE price = VALUES(price)");
        $stmt->bind_param('iiidd', $t, $listId, $pid, $min, $price);
        if (!$stmt->execute()) {
            return $this->fail('The price could not be saved.');
        }
        return $this->ok('Price saved.');
    }

    public function deleteItem(int $id): string
    {
        $t = $this->tenantId();
        $stmt = $this->conn->prepare("DELETE FROM price_list_items WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $id, $t);
        $stmt->execute();
        return $this->ok('Price removed.');
    }

    /** Put exactly these customers on the list (others who were on it go back to normal prices). */
    public function assign(int $listId, array $partyIds): string
    {
        $t = $this->tenantId();
        if (!$this->find($listId)) {
            return $this->fail('Price list not found.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $partyIds))));
        $this->conn->begin_transaction();
        try {
            $clr = $this->conn->prepare("UPDATE party_list SET price_list_id = NULL WHERE price_list_id = ? AND tenant_id = ?");
            $clr->bind_param('ii', $listId, $t);
            $clr->execute();
            if ($ids) {
                $set = $this->conn->prepare("UPDATE party_list SET price_list_id = ? WHERE id = ? AND tenant_id = ?");
                foreach ($ids as $pid) {
                    $p = $pid;
                    $set->bind_param('iii', $listId, $p, $t);
                    $set->execute();
                }
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return $this->fail('The customers could not be assigned.');
        }
        $this->settings->log_audit('Assigned price list', 'Price lists', "list #{$listId}: " . count($ids) . ' customers');
        return $this->ok('Customers updated.');
    }

    /**
     * Prices for a customer: for each requested [product_id, qty], the price their list gives, or null when they
     * have no list or the product is not on it. Result keyed by the same position as the request.
     *
     * @param array<int,array{product_id:int,qty:float}> $lines
     * @return array<int,?float>
     */
    public function pricesFor(int $partyId, array $lines): array
    {
        $t = $this->tenantId();
        $out = array_fill_keys(array_keys($lines), null);
        $stmt = $this->conn->prepare("SELECT l.id FROM party_list p INNER JOIN price_lists l ON l.id = p.price_list_id AND l.tenant_id = p.tenant_id AND l.status = 1
            WHERE p.id = ? AND p.tenant_id = ?");
        $stmt->bind_param('ii', $partyId, $t);
        $stmt->execute();
        $listId = (int)($stmt->get_result()->fetch_assoc()['id'] ?? 0);
        if ($listId <= 0 || !$lines) {
            return $out;
        }
        $tiers = [];
        foreach ($this->items($listId) as $r) {
            $tiers[(int)$r['product_id']][] = ['min_qty' => (float)$r['min_qty'], 'price' => (float)$r['price']];
        }
        foreach ($lines as $k => $ln) {
            $out[$k] = self::tierPrice($tiers[(int)$ln['product_id']] ?? [], (float)$ln['qty']);
        }
        return $out;
    }

    /** Ajax: POST party_id and lines[] = {product_id, qty}. */
    public function lookup(array $post): string
    {
        $lines = [];
        foreach ((array)($post['lines'] ?? []) as $k => $l) {
            $lines[$k] = ['product_id' => (int)($l['product_id'] ?? 0), 'qty' => (float)($l['qty'] ?? 1)];
        }
        return json_encode(['status' => 'success', 'prices' => $this->pricesFor((int)($post['party_id'] ?? 0), $lines)]);
    }
}
