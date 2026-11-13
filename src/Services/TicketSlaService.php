<?php

declare(strict_types=1);

namespace App\Services;

use mysqli;
use SystemSettings;

/**
 * Service-level targets for support tickets. Each priority has a time to first reply and a time to resolution
 * (calendar hours). The first reply is the first staff reply on the ticket. The resolution clock stops while the
 * ticket is waiting on the customer and when it is resolved or closed; reopening starts it again.
 */
class TicketSlaService
{
    public const PRIORITIES = ['urgent', 'high', 'medium', 'low'];

    /** Hours to first reply and to resolution, used where a company has not set its own. */
    public const DEFAULTS = [
        'urgent' => [1, 4],
        'high'   => [4, 8],
        'medium' => [8, 24],
        'low'    => [24, 72],
    ];

    /** A clock is "at risk" once this share of its time (or less) is left. */
    public const AT_RISK_SHARE = 0.25;

    public function __construct(
        private readonly mysqli $conn,
        private readonly SystemSettings $settings
    ) {}

    /** Pure: seconds that count against the resolution clock. Time spent waiting on the customer is left out. */
    public static function elapsed(int $created, int $end, int $pausedSeconds, ?int $pausedAt): int
    {
        $e = $end - $created - max(0, $pausedSeconds);
        if ($pausedAt !== null && $pausedAt < $end) {
            $e -= ($end - $pausedAt);
        }
        return max(0, $e);
    }

    /**
     * Pure: where one clock stands. $doneElapsed is the time it took when finished, null while still running.
     * Returns state (met, met_late, on_track, at_risk, breached) and seconds_left (negative once breached).
     *
     * @return array{state:string,seconds_left:?int}
     */
    public static function clock(float $limitHours, int $elapsedNow, ?int $doneElapsed): array
    {
        $limit = (int)round($limitHours * 3600);
        if ($doneElapsed !== null) {
            return ['state' => $doneElapsed <= $limit ? 'met' : 'met_late', 'seconds_left' => null];
        }
        $left = $limit - $elapsedNow;
        if ($left < 0) {
            return ['state' => 'breached', 'seconds_left' => $left];
        }
        return ['state' => $left <= (int)round($limit * self::AT_RISK_SHARE) ? 'at_risk' : 'on_track', 'seconds_left' => $left];
    }

    /**
     * Pure: both clocks for a ticket row (date_created, first_response_at, resolved_at, paused_at, paused_seconds).
     * The first-reply clock runs in plain calendar time; only the resolution clock pauses for the customer.
     *
     * @param array{0:float,1:float} $policy [response hours, resolve hours]
     * @return array{response:array,resolve:array}
     */
    public static function evaluate(array $t, array $policy, int $now): array
    {
        $created = (int)strtotime((string)$t['date_created']);
        $first = !empty($t['first_response_at']) ? (int)strtotime((string)$t['first_response_at']) : null;
        $res = !empty($t['resolved_at']) ? (int)strtotime((string)$t['resolved_at']) : null;
        $pausedAt = !empty($t['paused_at']) ? (int)strtotime((string)$t['paused_at']) : null;
        $paused = (int)($t['paused_seconds'] ?? 0);

        $response = self::clock((float)$policy[0], max(0, $now - $created), $first === null ? null : max(0, $first - $created));
        $response['due'] = $created + (int)round($policy[0] * 3600);

        $resolveDone = $res === null ? null : self::elapsed($created, $res, $paused, null);
        $resolve = self::clock((float)$policy[1], self::elapsed($created, $now, $paused, $pausedAt), $resolveDone);
        $resolve['due'] = $created + (int)round($policy[1] * 3600) + $paused + ($res === null && $pausedAt !== null ? max(0, $now - $pausedAt) : 0);
        $resolve['paused'] = $res === null && $pausedAt !== null;
        return ['response' => $response, 'resolve' => $resolve];
    }

    /**
     * Pure: the ticket columns to change when its status changes. Entering waiting_client pauses the resolution
     * clock, leaving it banks the waiting time, resolved/closed stamps the resolution time and reopening clears it.
     *
     * @return array<string,mixed> column => value (a datetime string, int or null)
     */
    public static function transition(array $t, string $new, int $now): array
    {
        $old = (string)($t['status'] ?? '');
        $out = [];
        $pausedAt = !empty($t['paused_at']) ? (int)strtotime((string)$t['paused_at']) : null;
        $finished = ['resolved', 'closed'];

        if ($new === 'waiting_client' && $pausedAt === null && !in_array($old, $finished, true)) {
            $out['paused_at'] = date('Y-m-d H:i:s', $now);
        }
        if ($new !== 'waiting_client' && $pausedAt !== null) {
            $out['paused_seconds'] = (int)($t['paused_seconds'] ?? 0) + max(0, $now - $pausedAt);
            $out['paused_at'] = null;
        }
        if (in_array($new, $finished, true) && empty($t['resolved_at'])) {
            $out['resolved_at'] = date('Y-m-d H:i:s', $now);
        }
        if (!in_array($new, $finished, true) && !empty($t['resolved_at'])) {
            $out['resolved_at'] = null;
        }
        return $out;
    }

    /** Pure: "2h 15m", "45m" or "3d 4h" for a number of seconds (sign ignored). */
    public static function human(int $seconds): string
    {
        $s = abs($seconds);
        if ($s >= 86400) {
            return intdiv($s, 86400) . 'd ' . intdiv($s % 86400, 3600) . 'h';
        }
        if ($s >= 3600) {
            return intdiv($s, 3600) . 'h ' . intdiv($s % 3600, 60) . 'm';
        }
        return max(1, intdiv($s, 60)) . 'm';
    }

    /**
     * Pure: clean the posted policy table. Each priority needs a first-reply time and a resolution time in hours,
     * the reply no longer than the resolution. Returns [rows priority => [response, resolve], error].
     *
     * @return array{0:array<string,array{0:float,1:float}>,1:?string}
     */
    public static function cleanPolicies(array $response, array $resolve): array
    {
        $rows = [];
        foreach (self::PRIORITIES as $p) {
            $r = trim(str_replace(',', '', (string)($response[$p] ?? '')));
            $x = trim(str_replace(',', '', (string)($resolve[$p] ?? '')));
            if (!is_numeric($r) || !is_numeric($x) || (float)$r < 0.25 || (float)$x < 0.25 || (float)$r > 720 || (float)$x > 720) {
                return [[], ucfirst($p) . ': enter hours between 0.25 and 720 for both targets.'];
            }
            if ((float)$r > (float)$x) {
                return [[], ucfirst($p) . ': the first reply cannot be due after the resolution.'];
            }
            $rows[$p] = [round((float)$r, 2), round((float)$x, 2)];
        }
        return [$rows, null];
    }

    private function tenant(): int
    {
        return (int)$this->settings->active_tenant_id();
    }

    /** @return array<string,array{0:float,1:float}> priority => [response hours, resolve hours] */
    public function policies(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $p => [$a, $b]) {
            $out[$p] = [(float)$a, (float)$b];
        }
        $t = $this->tenant();
        $r = @$this->conn->query("SELECT priority, response_hours, resolve_hours FROM `ticket_sla_policies` WHERE tenant_id = '{$t}'");
        while ($r && ($row = $r->fetch_assoc())) {
            $out[$row['priority']] = [(float)$row['response_hours'], (float)$row['resolve_hours']];
        }
        return $out;
    }

    public function savePolicies(array $post): string
    {
        [$rows, $err] = self::cleanPolicies((array)($post['response'] ?? []), (array)($post['resolve'] ?? []));
        if ($err) {
            return json_encode(['status' => 'failed', 'msg' => $err]);
        }
        $t = $this->tenant();
        $this->conn->begin_transaction();
        try {
            foreach ($rows as $p => [$a, $b]) {
                if (!$this->conn->query("INSERT INTO `ticket_sla_policies` (tenant_id, priority, response_hours, resolve_hours)
                    VALUES ('{$t}', '{$p}', '{$a}', '{$b}')
                    ON DUPLICATE KEY UPDATE response_hours = VALUES(response_hours), resolve_hours = VALUES(resolve_hours)")) {
                    throw new \RuntimeException(\App\Support\Db::oops($this->conn));
                }
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollback();
            return json_encode(['status' => 'failed', 'msg' => 'The targets could not be saved.']);
        }
        $this->settings->log_audit('Updated Ticket SLA Targets', 'Helpdesk', json_encode($rows));
        return json_encode(['status' => 'success', 'msg' => 'Service targets saved.']);
    }

    /** Evaluate a ticket row against this company's targets. */
    public function evaluateRow(array $ticket, ?array $policies = null): array
    {
        $policies ??= $this->policies();
        $p = $policies[$ticket['priority']] ?? $policies['medium'];
        return self::evaluate($ticket, $p, time());
    }

    /** Move the clocks for a status change. Call before the status itself is written. */
    public function applyStatus(int $ticketId, string $newStatus): void
    {
        $t = $this->tenant();
        $r = @$this->conn->query("SELECT status, resolved_at, paused_at, paused_seconds FROM `tickets` WHERE id = '{$ticketId}' AND tenant_id = '{$t}'");
        if (!$r || !($row = $r->fetch_assoc())) {
            return;
        }
        $set = [];
        foreach (self::transition($row, $newStatus, time()) as $col => $val) {
            $set[] = "`{$col}` = " . ($val === null ? 'NULL' : "'" . $this->conn->real_escape_string((string)$val) . "'");
        }
        if ($set) {
            $this->conn->query("UPDATE `tickets` SET " . implode(', ', $set) . " WHERE id = '{$ticketId}' AND tenant_id = '{$t}'");
        }
    }

    /** Stamp the first staff reply. */
    public function markFirstResponse(int $ticketId): void
    {
        $t = $this->tenant();
        @$this->conn->query("UPDATE `tickets` SET first_response_at = NOW() WHERE id = '{$ticketId}' AND tenant_id = '{$t}' AND first_response_at IS NULL");
    }

    /** Open tickets past a target, for the list header. */
    public function breachCount(): int
    {
        $n = 0;
        $t = $this->tenant();
        $policies = $this->policies();
        $r = @$this->conn->query("SELECT priority, date_created, first_response_at, resolved_at, paused_at, paused_seconds FROM `tickets`
            WHERE tenant_id = '{$t}' AND status NOT IN ('resolved','closed')");
        while ($r && ($row = $r->fetch_assoc())) {
            $e = $this->evaluateRow($row, $policies);
            if ($e['response']['state'] === 'breached' || $e['resolve']['state'] === 'breached') {
                $n++;
            }
        }
        return $n;
    }
}
