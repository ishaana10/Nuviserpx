<?php
/**
 * Shared helpers for the financial reports (trial balance, P&L, balance sheet,
 * general ledger, worksheet). Included by each report page.
 */
use App\Services\LedgerService;

if (!function_exists('nvr_money')) {

    /** 1,234.56 — negatives in brackets, zero as an en dash. */
    function nvr_money($v, bool $dashZero = true): string
    {
        $v = round((float)$v, 2);
        if ($v == 0.0) {
            return $dashZero ? '–' : '0.00';
        }
        $s = number_format(abs($v), 2);
        return $v < 0 ? '(' . $s . ')' : $s;
    }

    function nvr_pct(?float $v): string
    {
        return $v === null ? '–' : number_format($v, 1) . '%';
    }

    /** Change between two figures as a %, or null when there is no base. */
    function nvr_change(float $cur, float $prev): ?float
    {
        return $prev == 0.0 ? null : round(($cur - $prev) / abs($prev) * 100, 1);
    }

    function nvr_date(?string $v, string $fallback): string
    {
        return ($v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v)) ? $v : $fallback;
    }

    function nvr_nice_date(string $ymd): string
    {
        return date('j M Y', strtotime($ymd));
    }

    function nvr_tenant_name(): string
    {
        global $conn, $_settings;
        $name = (string)$_settings->info('name');
        $tid = (int)$_settings->active_tenant_id();
        $stmt = $conn->prepare("SELECT name FROM tenants WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $tid);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            if (!empty($row['name'])) {
                $name = $row['name'];
            }
        }
        return $name;
    }

    function nvr_year_end_month(): int
    {
        global $_settings;
        $m = (int)($_settings->info('fiscal_year_end_month') ?: 12);
        return $m >= 1 && $m <= 12 ? $m : 12;
    }

    function nvr_ledger(): LedgerService
    {
        global $conn, $_settings;
        return new LedgerService($conn, (int)$_settings->active_tenant_id());
    }

    /** Named periods relative to today and the financial year. key => [label, from, to] */
    function nvr_periods(): array
    {
        $today = new DateTimeImmutable('today');
        $fy = new DateTimeImmutable(LedgerService::fyStart($today->format('Y-m-d'), nvr_year_end_month()));
        $q = intdiv((int)$today->format('n') - 1, 3);
        $qStart = $today->setDate((int)$today->format('Y'), $q * 3 + 1, 1);
        $f = fn (DateTimeImmutable $d) => $d->format('Y-m-d');
        return [
            'this_month'   => ['This month', $f($today->modify('first day of this month')), $f($today->modify('last day of this month'))],
            'last_month'   => ['Last month', $f($today->modify('first day of last month')), $f($today->modify('last day of last month'))],
            'this_quarter' => ['This quarter', $f($qStart), $f($qStart->modify('+2 months')->modify('last day of this month'))],
            'last_quarter' => ['Last quarter', $f($qStart->modify('-3 months')), $f($qStart->modify('-1 day'))],
            'this_fy'      => ['This financial year', $f($fy), $f($fy->modify('+1 year')->modify('-1 day'))],
            'fy_to_date'   => ['Financial year to date', $f($fy), $f($today)],
            'last_fy'      => ['Last financial year', $f($fy->modify('-1 year')), $f($fy->modify('-1 day'))],
        ];
    }

    /** The preset that matches a from/to pair, or 'custom'. */
    function nvr_match_period(string $from, string $to): string
    {
        foreach (nvr_periods() as $k => $p) {
            if ($p[1] === $from && $p[2] === $to) {
                return $k;
            }
        }
        return 'custom';
    }

    /**
     * Report header: title, company, period, and the filter/action bar.
     *
     * $opt = [
     *   'page' => 'reports/trial_balance', 'title' => 'Trial balance', 'icon' => 'fas fa-balance-scale',
     *   'mode' => 'range' | 'asat', 'from' => …, 'to' => …, 'asat' => …,
     *   'compare' => ['none' => 'No comparison', …] (optional), 'compareValue' => 'none',
     *   'extra' => '<html for extra filter fields>' (optional), 'subtitle' => 'As at 31 Oct 2026',
     * ]
     */
    function nvr_header(array $opt): void
    {
        $mode = $opt['mode'] ?? 'range';
        $periods = nvr_periods();
        $current = $mode === 'range' ? nvr_match_period($opt['from'], $opt['to']) : 'custom';
        ?>
        <link rel="stylesheet" href="<?= base_url ?>dist/css/nuvis-reports.css?v=<?= urlencode((string)(class_exists('AppVersion') ? AppVersion::get('commit') : '1')) ?>">
        <div class="nvr" data-report="<?= htmlspecialchars($opt['page']) ?>">
            <div class="nvr-top d-print-none">
                <div>
                    <a href="./?page=reports" class="nvr-back"><i class="fas fa-arrow-left"></i> Reports</a>
                    <h1 class="nvr-title"><i class="<?= htmlspecialchars($opt['icon'] ?? 'fas fa-file-alt') ?>"></i> <?= htmlspecialchars($opt['title']) ?></h1>
                </div>
                <div class="nvr-actions">
                    <button type="button" class="btn btn-default" data-nvr="csv"><i class="fas fa-file-csv"></i> Export CSV</button>
                    <button type="button" class="btn btn-default" data-nvr="print"><i class="fas fa-print"></i> Print / PDF</button>
                </div>
            </div>

            <form class="nvr-filters d-print-none" method="get" action="./">
                <input type="hidden" name="page" value="<?= htmlspecialchars($opt['page']) ?>">
                <?php if ($mode === 'range'): ?>
                    <div class="nvr-field">
                        <label for="nvr-period">Period</label>
                        <select id="nvr-period" class="custom-select" data-nvr="period">
                            <?php foreach ($periods as $k => $p): ?>
                                <option value="<?= $k ?>" data-from="<?= $p[1] ?>" data-to="<?= $p[2] ?>" <?= $current === $k ? 'selected' : '' ?>><?= htmlspecialchars($p[0]) ?></option>
                            <?php endforeach; ?>
                            <option value="custom" <?= $current === 'custom' ? 'selected' : '' ?>>Custom dates</option>
                        </select>
                    </div>
                    <div class="nvr-field">
                        <label for="nvr-from">From</label>
                        <input type="date" id="nvr-from" name="from" class="form-control" value="<?= htmlspecialchars($opt['from']) ?>" required>
                    </div>
                    <div class="nvr-field">
                        <label for="nvr-to">To</label>
                        <input type="date" id="nvr-to" name="to" class="form-control" value="<?= htmlspecialchars($opt['to']) ?>" required>
                    </div>
                <?php else: ?>
                    <div class="nvr-field">
                        <label for="nvr-asat">As at</label>
                        <input type="date" id="nvr-asat" name="as_of" class="form-control" value="<?= htmlspecialchars($opt['asat']) ?>" required>
                    </div>
                    <div class="nvr-field nvr-quick">
                        <label>&nbsp;</label>
                        <div class="btn-group">
                            <?php foreach (['today' => date('Y-m-d'), 'end of last month' => date('Y-m-d', strtotime('last day of last month')), 'end of last FY' => $periods['last_fy'][2]] as $lbl => $d): ?>
                                <button type="button" class="btn btn-default btn-sm" data-nvr="asat" data-date="<?= $d ?>"><?= ucfirst($lbl) ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if (!empty($opt['compare'])): ?>
                    <div class="nvr-field">
                        <label for="nvr-compare">Compare with</label>
                        <select id="nvr-compare" name="compare" class="custom-select">
                            <?php foreach ($opt['compare'] as $k => $lbl): ?>
                                <option value="<?= $k ?>" <?= ($opt['compareValue'] ?? 'none') === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <?= $opt['extra'] ?? '' ?>
                <div class="nvr-field nvr-submit">
                    <label>&nbsp;</label>
                    <button class="btn btn-primary"><i class="fas fa-sync-alt"></i> Update</button>
                </div>
            </form>

            <div class="nvr-sheet" id="outprint">
                <div class="nvr-sheet-head">
                    <div class="nvr-company"><?= htmlspecialchars(nvr_tenant_name()) ?></div>
                    <div class="nvr-sheet-title"><?= htmlspecialchars($opt['title']) ?></div>
                    <div class="nvr-sheet-sub"><?= htmlspecialchars($opt['subtitle'] ?? '') ?></div>
                </div>
        <?php
    }

    function nvr_footer(array $notes = [], bool $ledgerNote = true): void
    {
        ?>
                <div class="nvr-sheet-foot">
                    <?php foreach ($notes as $n): ?><div><?= $n ?></div><?php endforeach; ?>
                    <div><?= $ledgerNote ? 'Only posted journals are included; drafts and cancelled journals are excluded. ' : '' ?>Generated <?= date('j M Y, g:i a') ?>.</div>
                </div>
            </div>
        </div>
        <script src="<?= base_url ?>dist/js/nuvis-reports.js?v=<?= urlencode((string)(class_exists('AppVersion') ? AppVersion::get('commit') : '1')) ?>"></script>
        <?php
    }

    /** Link to the general ledger for one account and period. */
    function nvr_gl_link(?int $accountId, string $from, string $to, string $label): string
    {
        if (!$accountId) {
            return htmlspecialchars($label);
        }
        $href = './?page=reports/general_ledger&account_id=' . $accountId . '&from=' . urlencode($from) . '&to=' . urlencode($to);
        return '<a class="nvr-drill" href="' . htmlspecialchars($href) . '" title="View transactions">' . htmlspecialchars($label) . '</a>';
    }

    /** Balanced / out-of-balance badge. */
    function nvr_check(bool $ok, float $diff, string $okText, string $badText): string
    {
        return $ok
            ? '<span class="nvr-check ok"><i class="fas fa-check-circle"></i> ' . htmlspecialchars($okText) . '</span>'
            : '<span class="nvr-check bad"><i class="fas fa-exclamation-triangle"></i> ' . htmlspecialchars($badText) . ' — out by ' . number_format(abs($diff), 2) . '</span>';
    }
}
