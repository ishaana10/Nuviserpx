<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * The accounts that sales documents post to: Accounts Receivable, Sales Revenue and the default cash/bank account.
 *
 * Posting used to look these up by a fragment of the account name and quietly fall back to account id 1 or 2 when
 * nothing matched, which could book a company's sales to whatever happened to be account 1. Now a company chooses
 * them once (Accounting > Default Accounts); until it has, the first sensible match by account class and name is
 * used, and if there is none the document is refused with a clear message instead of being posted somewhere wrong.
 */
class AccountDefaultsService
{
    public const ROLES = [
        'receivable' => ['label' => 'Accounts receivable', 'class' => 'asset', 'help' => 'Debited when you invoice a customer, credited when they pay.'],
        'revenue'    => ['label' => 'Sales revenue',       'class' => 'income', 'help' => 'Credited with the value of what you sell (before VAT).'],
        'cash'       => ['label' => 'Cash / bank',          'class' => 'asset', 'help' => 'Where receipts are banked when no bank account is chosen on the receipt.'],
    ];

    private ?array $cache = null;

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /**
     * Pick the account for a role from a company's accounts, by class and name. Pure.
     *
     * @param array<int,array{name:string,type:string}> $accounts id => account
     */
    public static function detect(array $accounts, string $role): int
    {
        $want = self::ROLES[$role]['class'] ?? null;
        if ($want === null) {
            return 0;
        }
        ksort($accounts);
        foreach ($accounts as $id => $a) {
            $name = strtolower((string)$a['name']);
            $type = strtolower((string)$a['type']);
            if (LedgerService::classify($a['type'])['class'] !== $want) {
                continue;
            }
            $hit = match ($role) {
                'receivable' => str_contains($name, 'receivable') || str_contains($name, 'debtor'),
                'revenue'    => str_contains($name, 'sales') || str_contains($name, 'revenue'),
                'cash'       => $type === 'bank' || str_contains($name, 'cash') || str_contains($name, 'bank'),
                default      => false,
            };
            if ($hit) {
                return (int)$id;
            }
        }
        return 0;
    }

    /** Problems with a set of chosen accounts; empty when fine. Pure. */
    public static function validate(array $chosen, array $accounts): array
    {
        $errors = [];
        foreach (self::ROLES as $role => $meta) {
            $id = (int)($chosen[$role] ?? 0);
            if ($id <= 0 || !isset($accounts[$id])) {
                $errors[] = "Choose the {$meta['label']} account.";
                continue;
            }
            if (LedgerService::classify($accounts[$id]['type'])['class'] !== $meta['class']) {
                $errors[] = "{$meta['label']}: “{$accounts[$id]['name']}” must be " . ($meta['class'] === 'income' ? 'an income' : 'an asset') . ' account.';
            }
        }
        $ids = array_filter(array_map(fn ($r) => (int)($chosen[$r] ?? 0), array_keys(self::ROLES)));
        if (count($ids) !== count(array_unique($ids))) {
            $errors[] = 'Each role needs a different account.';
        }
        return $errors;
    }

    private function tenantId(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    /** id => ['name','type'] for the company's active accounts. */
    public function accounts(): array
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

    /** The accounts the company has chosen: role => account id (0 when not chosen). */
    public function chosen(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $t = $this->tenantId();
        $this->cache = array_fill_keys(array_keys(self::ROLES), 0);
        $stmt = @$this->conn->prepare("SELECT role, account_id FROM account_defaults WHERE tenant_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $t);
            $stmt->execute();
            foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
                if (isset($this->cache[$r['role']])) {
                    $this->cache[$r['role']] = (int)$r['account_id'];
                }
            }
        }
        return $this->cache;
    }

    /** The account to post to for a role: the chosen one if it still exists, else a sensible match, else 0. */
    public function resolve(string $role): int
    {
        $accounts = $this->accounts();
        $chosen = $this->chosen()[$role] ?? 0;
        if ($chosen > 0 && isset($accounts[$chosen])) {
            return $chosen;
        }
        return self::detect($accounts, $role);
    }

    /** The message shown when a role has no account. */
    public static function missing(string $role): string
    {
        $label = strtolower(self::ROLES[$role]['label'] ?? $role);
        return "There is no {$label} account to post to. Choose one under Accounting > Default Accounts, then try again.";
    }

    public function save(array $post): string
    {
        $t = $this->tenantId();
        $accounts = $this->accounts();
        $chosen = [];
        foreach (array_keys(self::ROLES) as $role) {
            $chosen[$role] = (int)($post[$role] ?? 0);
        }
        if ($errors = self::validate($chosen, $accounts)) {
            return json_encode(['status' => 'failed', 'msg' => implode(' ', $errors)]);
        }
        $stmt = $this->conn->prepare("INSERT INTO account_defaults (tenant_id, role, account_id) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE account_id = VALUES(account_id)");
        foreach ($chosen as $role => $acc) {
            $r = $role;
            $a = $acc;
            $stmt->bind_param('isi', $t, $r, $a);
            if (!$stmt->execute()) {
                return json_encode(['status' => 'failed', 'msg' => 'The default accounts could not be saved.']);
            }
        }
        $this->cache = null;
        $this->settings->log_audit('Saved default accounts', 'Accounting', json_encode($chosen));
        $this->settings->set_flashdata('success', 'Default accounts saved.');
        return json_encode(['status' => 'success', 'msg' => 'Default accounts saved.']);
    }
}
