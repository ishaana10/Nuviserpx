<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Statement of unpaid invoices per customer: what each customer owes, invoice by invoice and by age, and the email
 * that sends it to them. Draft and cancelled invoices are not receivables and never appear. Everything is scoped to
 * the active company. Each email is recorded in the company's email log by the mailer.
 */
class CustomerStatementService
{
    public const MAX_NOTE = 1000;

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /** Pure: the address when it is a usable email, otherwise null. */
    public static function validEmail(?string $email): ?string
    {
        $e = trim((string)$email);
        return ($e !== '' && strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL)) ? $e : null;
    }

    /** Pure: a Y-m-d date, or the fallback when the text is not a real date. */
    public static function cleanDate(?string $d, string $fallback): string
    {
        return ($d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d)) ? $d : $fallback;
    }

    public static function subject(string $company, string $asOf): string
    {
        return 'Statement of unpaid invoices - ' . $company . ' - as at ' . date('j M Y', strtotime($asOf));
    }

    /**
     * Customers with unpaid invoices on or before $asOf, largest balance first. With $partyId only that customer.
     * @return array{customers: array, totals: array}
     */
    public function statements(string $asOf, ?int $partyId = null): array
    {
        $tid = (int)$this->settings->active_tenant_id();
        $pid = (int)($partyId ?? 0);
        $q = $this->conn->prepare(
            "SELECT i.id, i.invoice_no, i.invoice_date, i.due_date, i.total, i.balance,
                    p.id party_id, p.name party_name, p.party_code
               FROM invoice_list i INNER JOIN party_list p ON p.id = i.party_id AND p.tenant_id = i.tenant_id
              WHERE i.tenant_id = ? AND i.status NOT IN ('draft','cancelled') AND i.balance > 0 AND i.invoice_date <= ?
                AND (? = 0 OR i.party_id = ?)
              ORDER BY p.name, i.invoice_date, i.id"
        );
        $rows = [];
        if ($q) {
            $q->bind_param('isii', $tid, $asOf, $pid, $pid);
            $q->execute();
            $res = $q->get_result();
            $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        }
        $ar = LedgerService::agedReceivables($rows, $asOf);

        $emails = [];
        $e = $this->conn->prepare("SELECT id, email FROM party_list WHERE tenant_id = ?");
        if ($e) {
            $e->bind_param('i', $tid);
            $e->execute();
            $er = $e->get_result();
            foreach ($er ? $er->fetch_all(MYSQLI_ASSOC) : [] as $r) {
                $emails[(int)$r['id']] = (string)$r['email'];
            }
        }
        foreach ($ar['customers'] as &$c) {
            $c['email'] = $emails[$c['id']] ?? '';
        }
        unset($c);
        return $ar;
    }

    /** Email one customer's statement. $override replaces the address on file for this send only. JSON for the UI. */
    public function emailOne(int $partyId, string $asOf, string $note = '', ?string $override = null): string
    {
        $asOf = self::cleanDate($asOf, date('Y-m-d'));
        $ar = $this->statements($asOf, $partyId);
        if (!$ar['customers']) {
            return json_encode(['status' => 'failed', 'msg' => 'This customer has no unpaid invoices as at ' . date('j M Y', strtotime($asOf)) . '.']);
        }
        $c = $ar['customers'][0];
        $to = ($override !== null && trim($override) !== '') ? self::validEmail($override) : self::validEmail($c['email']);
        if ($to === null) {
            return json_encode(['status' => 'failed', 'msg' => ($override !== null && trim($override) !== '')
                ? 'That email address is not valid.'
                : 'No valid email address is saved for ' . $c['name'] . '. Enter one to send the statement.']);
        }
        if (!$this->deliver($c, $to, $asOf, $note)) {
            return json_encode(['status' => 'failed', 'msg' => 'The email to ' . $to . ' could not be sent. Check Settings > Company Email, and the email log for the reason.']);
        }
        return json_encode(['status' => 'success', 'msg' => 'Statement sent to ' . $c['name'] . ' (' . $to . ').']);
    }

    /** Email every customer who has unpaid invoices and a valid email address. Customers without one are listed, not guessed. */
    public function emailAll(string $asOf, string $note = ''): string
    {
        $asOf = self::cleanDate($asOf, date('Y-m-d'));
        $sent = 0;
        $noEmail = [];
        $failed = [];
        foreach ($this->statements($asOf)['customers'] as $c) {
            $to = self::validEmail($c['email']);
            if ($to === null) {
                $noEmail[] = $c['name'];
                continue;
            }
            $this->deliver($c, $to, $asOf, $note) ? $sent++ : $failed[] = $c['name'];
        }
        $msg = $sent . ' statement' . ($sent === 1 ? '' : 's') . ' sent.';
        if ($noEmail) {
            $msg .= ' No email address for: ' . implode(', ', array_slice($noEmail, 0, 10)) . (count($noEmail) > 10 ? ' and ' . (count($noEmail) - 10) . ' more' : '') . '.';
        }
        if ($failed) {
            $msg .= ' Could not send to: ' . implode(', ', array_slice($failed, 0, 10)) . '. See the email log.';
        }
        return json_encode(['status' => $sent > 0 ? 'success' : 'failed', 'msg' => $msg]);
    }

    protected function deliver(array $customer, string $to, string $asOf, string $note): bool
    {
        require_once(base_app . 'classes/Emailer.php');
        $b = $this->settings->tenant_branding();
        $sys = [
            'name' => $b['name'] !== '' ? $b['name'] : (string)$this->settings->info('name'),
            'email' => $b['email'] !== '' ? $b['email'] : (string)$this->settings->info('email'),
            'phone' => $b['phone'],
            'currency' => (string)$this->settings->currency_tag(),
        ];
        return (bool)\Emailer::send_customer_statement_email($to, $customer, $asOf, $sys, mb_substr(trim($note), 0, self::MAX_NOTE));
    }
}
