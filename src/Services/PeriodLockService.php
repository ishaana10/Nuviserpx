<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Period lock: "the books are closed up to <date>".
 *
 * Nothing may be created, changed or removed with a journal date on or before the lock date.
 * The lock date of a company is its newest row in `period_locks`; every change adds a row, so the
 * table is also the history of who closed or reopened the books, and when. A row with a NULL
 * lock_date means the books were fully reopened.
 *
 * Enforced twice: invoices, payments, payroll, bills, sales-order conversion, the API and manual
 * journals call guard() before they write (clear message, nothing half-saved), and database triggers
 * (see DBConnection::check_ledger_controls_migrations) refuse any write that slips past.
 */
class PeriodLockService
{
    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /** Pure: is $date (Y-m-d…) on or before the lock date? No lock date means nothing is locked. */
    public static function isLocked(?string $lockDate, ?string $date): bool
    {
        $lock = self::ymd($lockDate);
        $d = self::ymd($date);
        return $lock !== null && $d !== null && $d <= $lock;
    }

    /** Pure: the message for the first locked date among $dates, or null when all are open. */
    public static function message(?string $lockDate, ?string ...$dates): ?string
    {
        foreach ($dates as $date) {
            if (self::isLocked($lockDate, $date)) {
                return 'The books are closed up to ' . date('j M Y', strtotime((string)self::ymd($lockDate)))
                    . ', so nothing dated ' . date('j M Y', strtotime((string)self::ymd($date)))
                    . ' can be created, changed or removed. Ask an administrator to reopen the period, or use a date after the lock.';
            }
        }
        return null;
    }

    /** Current lock date of a company (newest row), or null when open. */
    public static function lockDate(mysqli $conn, int $tenantId): ?string
    {
        $stmt = @$conn->prepare("SELECT lock_date FROM period_locks WHERE tenant_id = ? ORDER BY id DESC LIMIT 1");
        if (!$stmt) {
            return null; // table not created yet
        }
        $stmt->bind_param('i', $tenantId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return self::ymd($row['lock_date'] ?? null);
    }

    /**
     * Check before writing: the new date, and — for an existing journal — the date it already carries
     * (moving an entry out of a closed period is as much a change to it as moving one in).
     * Returns an error message, or null when the write is allowed.
     */
    public static function guard(mysqli $conn, int $tenantId, ?string $newDate, ?int $journalId = null): ?string
    {
        $lock = self::lockDate($conn, $tenantId);
        if ($lock === null) {
            return null;
        }
        $dates = [$newDate];
        if ($journalId) {
            $stmt = $conn->prepare("SELECT journal_date FROM journal_entries WHERE id = ? AND tenant_id = ?");
            $stmt->bind_param('ii', $journalId, $tenantId);
            $stmt->execute();
            $dates[] = $stmt->get_result()->fetch_assoc()['journal_date'] ?? null;
        }
        return self::message($lock, ...$dates);
    }

    /**
     * guard() for a document that owns a journal (invoice, payment, payroll record, vendor bill):
     * checks the new date and the date of the journal the document already has, if any.
     * Pass $newDate = null when deleting.
     */
    public static function guardDocument(mysqli $conn, int $tenantId, ?string $newDate, string $table, int $docId): ?string
    {
        if (!in_array($table, ['invoice_list', 'payment_list', 'payroll_list', 'vendor_bill_list'], true)) {
            throw new \InvalidArgumentException("Not a journal-owning table: {$table}");
        }
        $journalId = null;
        if ($docId > 0) {
            $stmt = $conn->prepare("SELECT journal_id FROM `{$table}` WHERE id = ? AND tenant_id = ?");
            $stmt->bind_param('ii', $docId, $tenantId);
            $stmt->execute();
            $journalId = (int)($stmt->get_result()->fetch_assoc()['journal_id'] ?? 0) ?: null;
        }
        return self::guard($conn, $tenantId, $newDate, $journalId);
    }

    /**
     * Close the books up to $date, or reopen them (an earlier date, or empty for fully open).
     * Closing cannot reach into the future: a lock on days that have not happened is meaningless.
     */
    public function setLock(string $date, string $note = ''): string
    {
        $tenant = (int)$this->settings->active_tenant_id();
        $date = trim($date);
        $new = $date === '' ? null : self::ymd($date);
        if ($date !== '' && ($new === null || !strtotime($new))) {
            return $this->fail('Enter a valid date.');
        }
        if ($new !== null && $new > date('Y-m-d')) {
            return $this->fail('The books can only be closed up to today.');
        }

        $current = self::lockDate($this->conn, $tenant);
        if ($new === $current) {
            return $this->fail('The books are already ' . ($current ? 'closed up to ' . date('j M Y', strtotime($current)) : 'open') . '.');
        }

        $note = mb_substr(trim($note), 0, 255);
        $userId = (int)$this->settings->userdata('id');
        $stmt = $this->conn->prepare("INSERT INTO period_locks (tenant_id, lock_date, note, locked_by) VALUES (?, ?, ?, ?)");
        if (!$stmt) {
            return $this->fail('The period lock could not be saved.');
        }
        $stmt->bind_param('issi', $tenant, $new, $note, $userId);
        if (!$stmt->execute()) {
            return $this->fail('The period lock could not be saved.');
        }

        $reopened = $current !== null && ($new === null || $new < $current);
        $this->settings->log_audit(
            $reopened ? 'Reopened books' : 'Closed books',
            'Period lock',
            'From ' . ($current ?? 'open') . ' to ' . ($new ?? 'open') . ($note !== '' ? " — {$note}" : '')
        );
        $msg = $new === null ? 'The books are open.' : 'The books are closed up to ' . date('j M Y', strtotime($new)) . '.';
        $this->settings->set_flashdata('success', $msg);
        return json_encode(['status' => 'success', 'msg' => $msg]);
    }

    private static function ymd(?string $value): ?string
    {
        $value = trim((string)$value);
        return preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) ? $m[1] : null;
    }

    private function fail(string $msg): string
    {
        return json_encode(['status' => 'failed', 'msg' => $msg]);
    }
}
