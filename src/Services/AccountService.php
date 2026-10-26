<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

class AccountService
{
    /** Account types offered in the chart of accounts, by class. */
    public const ACCOUNT_TYPES = [
        'Assets' => ['Bank', 'Current Asset', 'Inventory', 'Prepayment', 'Fixed Asset', 'Non-current Asset'],
        'Liabilities' => ['Current Liability', 'Non-current Liability'],
        'Equity' => ['Equity'],
        'Income' => ['Revenue', 'Other Income'],
        'Expenses' => ['Direct Costs', 'Expense', 'Depreciation', 'Overhead'],
    ];

    private const ACCOUNT_FIELDS = ['code', 'name', 'type', 'tax_rate', 'description', 'status', 'bank_name', 'account_number', 'currency'];
    private const GROUP_FIELDS = ['name', 'description', 'type', 'status'];

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    public function saveGroup(array $data): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            return $this->fail('Enter a group name.');
        }
        if ($this->exists('group_list', 'name', $name, $tenant_id, $id)) {
            return $this->fail("A group called “{$name}” already exists.");
        }
        $fields = $this->pick($data, self::GROUP_FIELDS);
        $fields['type'] = in_array((int)($fields['type'] ?? 1), [1, 2], true) ? (int)$fields['type'] : 1;
        $ok = $this->write('group_list', $fields, $id, $tenant_id);
        if (!$ok) {
            return $this->fail('The group could not be saved.');
        }
        $msg = $id ? 'Account group updated.' : 'Account group added.';
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg]);
    }

    public function deleteGroup(int $id): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $stmt = $this->conn->prepare("UPDATE `group_list` SET delete_flag = 1 WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $id, $tenant_id);
        if ($stmt->execute()) {
            $this->settings->set_flashdata('success', 'Account group deleted.');
            return json_encode(['status' => 'success']);
        }
        return $this->fail('The group could not be deleted.');
    }

    /**
     * Create or update an account. Only known columns are written; names and
     * codes must be unique within the company.
     */
    public function saveAccount(array $post): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $id = (int)($post['id'] ?? 0);
        $fields = $this->pick($post, self::ACCOUNT_FIELDS);
        $fields['name'] = trim((string)($fields['name'] ?? ''));
        $fields['code'] = trim((string)($fields['code'] ?? ''));

        if ($fields['name'] === '') {
            return $this->fail('Enter an account name.');
        }
        if ($fields['code'] !== '' && !preg_match('/^[A-Za-z0-9.\-]{1,20}$/', $fields['code'])) {
            return $this->fail('Account codes can use letters, numbers, dots and dashes (up to 20 characters).');
        }
        if ($this->exists('account_list', 'name', $fields['name'], $tenant_id, $id)) {
            return $this->fail("An account called “{$fields['name']}” already exists.");
        }
        if ($fields['code'] !== '' && $this->exists('account_list', 'code', $fields['code'], $tenant_id, $id)) {
            return $this->fail("Code {$fields['code']} is already used by another account.");
        }
        if ($fields['code'] === '') {
            $fields['code'] = null;
        }

        // Changing the type of an account with history moves its balance between statements.
        if ($id && isset($fields['type']) && $this->transactionCount($id) > 0) {
            $old = $this->accountRow($id, $tenant_id);
            if ($old && LedgerService::classify($old['type'])['class'] !== LedgerService::classify($fields['type'])['class']) {
                $this->settings->log_audit('Changed account type', 'Chart of Accounts', "{$old['name']}: {$old['type']} → {$fields['type']}");
            }
        }

        if (!$this->write('account_list', $fields, $id, $tenant_id)) {
            return $this->fail('The account could not be saved.');
        }
        $msg = $id ? 'Account updated.' : 'Account added.';
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg]);
    }

    /**
     * Delete an account. Accounts with journal lines are kept — deleting them would
     * drop those lines from every report and unbalance the ledger — so archive instead.
     */
    public function deleteAccount(int $id): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $acc = $this->accountRow($id, $tenant_id);
        if (!$acc) {
            return $this->fail('Account not found.');
        }
        $n = $this->transactionCount($id);
        if ($n > 0) {
            return $this->fail("“{$acc['name']}” has {$n} transaction" . ($n === 1 ? '' : 's') . ' and cannot be deleted. Archive it instead.');
        }
        $stmt = $this->conn->prepare("UPDATE `account_list` SET delete_flag = 1 WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $id, $tenant_id);
        if ($stmt->execute()) {
            $this->settings->set_flashdata('success', 'Account deleted.');
            return json_encode(['status' => 'success']);
        }
        return $this->fail('The account could not be deleted.');
    }

    /** Delete several accounts, skipping (and reporting) those with transactions. */
    public function deleteAccounts(array $ids): string
    {
        $tenant_id = (int)$this->settings->active_tenant_id();
        $deleted = 0;
        $kept = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $acc = $this->accountRow($id, $tenant_id);
            if (!$acc) {
                continue;
            }
            if ($this->transactionCount($id) > 0) {
                $kept[] = $acc['name'];
                continue;
            }
            $stmt = $this->conn->prepare("UPDATE `account_list` SET delete_flag = 1 WHERE id = ? AND tenant_id = ?");
            $stmt->bind_param('ii', $id, $tenant_id);
            $stmt->execute();
            $deleted += $stmt->affected_rows > 0 ? 1 : 0;
        }
        if (!$deleted && $kept) {
            return $this->fail('None deleted: ' . implode(', ', $kept) . ' ' . (count($kept) === 1 ? 'has' : 'have') . ' transactions. Archive instead.');
        }
        $msg = "{$deleted} account" . ($deleted === 1 ? '' : 's') . ' deleted.'
            . ($kept ? ' Kept ' . implode(', ', $kept) . ' because they have transactions — archive them instead.' : '');
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg]);
    }

    public function transactionCount(int $accountId): int
    {
        $stmt = $this->conn->prepare("SELECT COUNT(*) n FROM journal_items WHERE account_id = ?");
        $stmt->bind_param('i', $accountId);
        $stmt->execute();
        return (int)($stmt->get_result()->fetch_assoc()['n'] ?? 0);
    }

    /* ------------------------------------------------------------------ */

    private function pick(array $data, array $allowed): array
    {
        $out = [];
        foreach ($allowed as $k) {
            if (array_key_exists($k, $data)) {
                $out[$k] = is_string($data[$k]) ? trim($data[$k]) : $data[$k];
            }
        }
        return $out;
    }

    private function write(string $table, array $fields, int $id, int $tenant_id): bool
    {
        if (!$fields) {
            return false;
        }
        $cols = array_keys($fields);
        $vals = array_map(fn ($v) => $v === null ? null : (string)$v, array_values($fields));
        if ($id) {
            $sql = "UPDATE `{$table}` SET " . implode(', ', array_map(fn ($c) => "`{$c}` = ?", $cols)) . " WHERE id = ? AND tenant_id = ?";
            $types = str_repeat('s', count($vals)) . 'ii';
            $vals[] = $id;
            $vals[] = $tenant_id;
        } else {
            $sql = "INSERT INTO `{$table}` (`tenant_id`, `" . implode('`, `', $cols) . "`) VALUES (?" . str_repeat(', ?', count($cols)) . ")";
            $types = 'i' . str_repeat('s', count($vals));
            array_unshift($vals, $tenant_id);
        }
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param($types, ...$vals);
        return $stmt->execute();
    }

    private function exists(string $table, string $col, string $value, int $tenant_id, int $exceptId): bool
    {
        $stmt = $this->conn->prepare("SELECT id FROM `{$table}` WHERE `{$col}` = ? AND tenant_id = ? AND delete_flag = 0 AND id != ? LIMIT 1");
        $stmt->bind_param('sii', $value, $tenant_id, $exceptId);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }

    private function accountRow(int $id, int $tenant_id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM account_list WHERE id = ? AND tenant_id = ? AND delete_flag = 0");
        $stmt->bind_param('ii', $id, $tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
