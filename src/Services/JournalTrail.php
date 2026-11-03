<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * A permanent before/after record for journals that belong to invoices, receipts, bills and payroll.
 *
 * Those documents can still be edited, and an edit rewrites the journal's lines in place. Rather than let the old
 * figures vanish, the lines and date as they were and as they became are written to journal_revisions whenever an
 * edit actually changes them, with who and when. An edit that leaves the journal as it was records nothing.
 */
class JournalTrail
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /** Lines as [account_id, side(1 debit|2 credit), amount at 2dp], in a stable order. */
    public static function normalize(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $out[] = [(int)$l[0], (int)$l[1], round((float)$l[2], 2)];
        }
        usort($out, static fn($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        return $out;
    }

    /** True when the date or any line differs. Both arguments are ['date' => 'Y-m-d', 'lines' => [...]]. */
    public static function changed(array $before, array $after): bool
    {
        return ($before['date'] ?? '') !== ($after['date'] ?? '')
            || self::normalize($before['lines'] ?? []) !== self::normalize($after['lines'] ?? []);
    }

    /** The journal as it stands now. */
    public function snapshot(int $journalId): array
    {
        $side = LedgerService::SIDE_SQL;
        $stmt = $this->conn->prepare("SELECT j.journal_date, ji.account_id, {$side} side, ji.amount
            FROM journal_entries j LEFT JOIN journal_items ji ON ji.journal_id = j.id LEFT JOIN group_list g ON g.id = ji.group_id
            WHERE j.id = ?");
        if (!$stmt) {
            return ['date' => '', 'lines' => []];
        }
        $stmt->bind_param('i', $journalId);
        $stmt->execute();
        $date = '';
        $lines = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
            $date = (string)$r['journal_date'];
            if ($r['account_id'] !== null) {
                $lines[] = [(int)$r['account_id'], (int)$r['side'], (float)$r['amount']];
            }
        }
        return ['date' => $date, 'lines' => $lines];
    }

    /** After an edit: keep the old and new figures if they differ. Returns whether a revision was written. */
    public function record(int $journalId, string $sourceTable, int $documentId, string $label, array $before): bool
    {
        $after = $this->snapshot($journalId);
        if (!self::changed($before, $after)) {
            return false;
        }
        $t = (int)$this->settings->active_tenant_id();
        $by = (int)$this->settings->userdata('id');
        $b = json_encode(['date' => $before['date'], 'lines' => self::normalize($before['lines'])]);
        $a = json_encode(['date' => $after['date'], 'lines' => self::normalize($after['lines'])]);
        $stmt = @$this->conn->prepare("INSERT INTO journal_revisions (tenant_id, journal_id, source_table, document_id, label, changed_by, before_json, after_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('iisisiss', $t, $journalId, $sourceTable, $documentId, $label, $by, $b, $a);
        $ok = $stmt->execute();
        if ($ok) {
            $this->settings->log_audit('Journal rewritten by document edit', 'Journals', "{$label}: journal #{$journalId} changed; the previous figures are kept in the revision history.");
        }
        return $ok;
    }

    /** Revisions for one journal, newest first. */
    public function history(int $journalId): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $stmt = $this->conn->prepare("SELECT r.id, r.label, r.source_table, r.document_id, r.changed_at, r.before_json, r.after_json,
                CONCAT(u.firstname, ' ', u.lastname) author
            FROM journal_revisions r LEFT JOIN users u ON u.id = r.changed_by
            WHERE r.tenant_id = ? AND r.journal_id = ? ORDER BY r.id DESC");
        $stmt->bind_param('ii', $t, $journalId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as &$r) {
            $r['before'] = json_decode((string)$r['before_json'], true);
            $r['after'] = json_decode((string)$r['after_json'], true);
        }
        return $rows;
    }
}
