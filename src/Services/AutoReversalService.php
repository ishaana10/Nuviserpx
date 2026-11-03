<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Posts the reversals that manual journals ask for with “Reverse on” (auto_reversing_date).
 *
 * Accruals and prepayments are booked at month end and undone on the first day of the next month. A
 * journal with a reversal date that has arrived, that is posted, and that has not already been reversed is
 * reversed by the same code as a manual reversal, dated on the requested day, so it gets the same period
 * lock check, audit entry and linked reversing journal. A reversal that cannot be posted (usually because
 * its period is locked) is reported and left for the next run; nothing is forced.
 */
class AutoReversalService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /** A reversal falls due on its date or any day after, never before. */
    public static function isDue(?string $reversingDate, string $today): bool
    {
        if ($reversingDate === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $reversingDate)) {
            return false;
        }
        return $reversingDate <= $today;
    }

    /** Posted journals of the active company whose reversal date has arrived and which are not yet reversed. */
    public function due(string $today): array
    {
        $t = (int)$this->settings->active_tenant_id();
        $posted = LedgerService::POSTED_SQL;
        $stmt = $this->conn->prepare("SELECT j.id, j.code, j.journal_date, j.auto_reversing_date, j.description
            FROM journal_entries j
            WHERE j.tenant_id = ? AND {$posted}
              AND j.auto_reversing_date IS NOT NULL AND j.auto_reversing_date <= ?
              AND j.reverses_id IS NULL
              AND NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.reverses_id = j.id AND r.tenant_id = j.tenant_id)
            ORDER BY j.auto_reversing_date, j.id");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('is', $t, $today);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** Reverse everything due for the active company. Returns ['done' => [codes], 'failed' => [code => reason]]. */
    public function run(string $today): array
    {
        $result = ['done' => [], 'failed' => []];
        $journals = new JournalService($this->conn, $this->settings);
        foreach ($this->due($today) as $j) {
            $res = json_decode($journals->reverseJournal((int)$j['id'], (string)$j['auto_reversing_date']), true);
            if (($res['status'] ?? '') === 'success') {
                $result['done'][$j['code']] = $res['code'] ?? '';
            } else {
                $result['failed'][$j['code']] = (string)($res['msg'] ?? 'Could not be reversed.');
            }
        }
        if ($result['done']) {
            $this->settings->log_audit('Ran auto-reversals', 'Journals', count($result['done']) . ' journal(s) reversed: ' . implode(', ', array_keys($result['done'])) . '.');
        }
        return $result;
    }
}
