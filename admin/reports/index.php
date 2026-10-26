<?php
/**
 * Reports: a catalogue of the reports that actually exist, grouped by area,
 * with search and per-user pinning (stored in the browser).
 */
$can = fn (string $perm): bool => (bool)$_settings->can_access($perm);

$sections = [
    ['Financial statements', 'fas fa-landmark', 'reports_view', [
        ['reports/income_statement', 'Profit and Loss', 'fas fa-chart-line', 'Income, cost of sales and expenses for a period, with gross and net margin. Compare with the previous period or last year.'],
        ['reports/balance_sheet', 'Balance Sheet', 'fas fa-landmark', 'Assets, liabilities and equity at a date, including retained and current-year earnings.'],
        ['reports/cash_summary', 'Cash Summary', 'fas fa-money-bill-wave', 'Bank balances at the start and end of a period, and where the money came from and went.'],
    ]],
    ['Accounting', 'fas fa-book', 'reports_view', [
        ['reports/trial_balance', 'Trial Balance', 'fas fa-balance-scale', 'Every account’s balance at a date in debit and credit columns, with a balance check.'],
        ['reports/working_trial_balance', 'Working Trial Balance', 'fas fa-th-list', 'Opening balance, period debits and credits, and closing balance for every account.'],
        ['reports/general_ledger', 'General Ledger', 'fas fa-book-open', 'Movement per account for a period. Pick an account to see each transaction with a running balance.'],
        ['journals', 'Manual Journals', 'fas fa-stream', 'All journals with totals, status and source document.', 'List'],
    ]],
    ['Sales and receivables', 'fas fa-file-invoice-dollar', 'invoices_view', [
        ['reports/sales_by_customer', 'Sales by Customer', 'fas fa-users', 'Invoiced sales, tax, payments and amounts owing per customer for a period.'],
        ['reports/ar_aging', 'Aged Receivables', 'fas fa-hourglass-half', 'What each customer owes, split into current, 30, 60, 90 and older.'],
        ['invoices', 'Invoices', 'fas fa-file-invoice', 'Every invoice with status, totals and balance due.', 'List'],
        ['payments', 'Payments', 'fas fa-hand-holding-usd', 'Receipts and payments recorded against invoices.', 'List'],
    ]],
    ['Tax', 'fas fa-receipt', 'reports_view', [
        ['tax/report', 'VAT Return', 'fas fa-receipt', 'Output VAT on sales and input VAT on purchases for a period, and the net payable to FRCS.'],
    ]],
    ['Payroll', 'fas fa-users-cog', 'payroll_view', [
        ['reports/payroll_accounting', 'Payroll Accounting', 'fas fa-file-invoice-dollar', 'Gross pay, PAYE, SRT, FNPF and net pay for a month, with employer costs.'],
        ['payroll/fnpf_schedule', 'FNPF Schedule (CS3)', 'fas fa-file-contract', 'Monthly FNPF contribution schedule per employee.'],
        ['payroll/tax_rebate_report', '150% Tax Rebate', 'fas fa-percentage', 'FRCS employment tax rebate summary.'],
        ['payroll/fiji_ems_export', 'FRCS EMS Export', 'fas fa-file-export', 'Employer monthly schedule export for FRCS.'],
    ]],
    ['Activity', 'fas fa-history', null, [
        ['settings/audit_logs', 'Audit Log', 'fas fa-history', 'Who changed what, and when.'],
        ['settings/login_activity', 'Login Activity', 'fas fa-user-shield', 'Sign-ins, failed attempts and sources.'],
    ]],
];
$is_admin = $_settings->userdata('type') == 1;
?>
<style>
.rpt { max-width: 1140px; margin: 0 auto; padding: 4px 2px 32px; }
.rpt-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px; margin-bottom: 18px; }
.rpt-head h1 { font-size: 1.55rem; font-weight: 700; color: #0f172a; margin: 0; }
.rpt-head p { color: #64748b; margin: 2px 0 0; font-size: .9rem; }
.rpt-search { position: relative; width: 320px; max-width: 100%; }
.rpt-search i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: .85rem; }
.rpt-search input { padding-left: 34px; height: 40px; border-radius: 9px !important; }
.rpt-sec { margin-bottom: 26px; }
.rpt-sec h2 { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #64748b; margin: 0 0 10px; display: flex; align-items: center; gap: 8px; }
.rpt-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 10px; }
.rpt-card { position: relative; display: flex; gap: 12px; align-items: flex-start; padding: 14px 44px 14px 14px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; color: inherit; transition: border-color .15s, box-shadow .15s, transform .15s; }
.rpt-card:hover { text-decoration: none; color: inherit; border-color: #bfdbfe; box-shadow: 0 6px 18px -10px rgba(37,99,235,.45); transform: translateY(-1px); }
.rpt-card .ic { width: 36px; height: 36px; flex: 0 0 36px; border-radius: 9px; background: #eff6ff; color: #2563eb; display: inline-flex; align-items: center; justify-content: center; font-size: .9rem; }
.rpt-card .t { font-weight: 600; color: #0f172a; font-size: .95rem; display: flex; align-items: center; gap: 6px; }
.rpt-card .d { font-size: .82rem; color: #64748b; margin-top: 2px; line-height: 1.45; }
.rpt-card .tag { font-size: .66rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #64748b; background: #f1f5f9; border-radius: 999px; padding: 1px 7px; }
.rpt-pin { position: absolute; top: 10px; right: 10px; width: 28px; height: 28px; border: 0; border-radius: 7px; background: transparent; color: #cbd5e1; }
.rpt-pin:hover { background: #f1f5f9; color: #f59e0b; }
.rpt-pin.on { color: #f59e0b; }
.rpt-none { display: none; text-align: center; color: #64748b; padding: 40px 0; }
</style>

<div class="rpt">
    <div class="rpt-head">
        <div>
            <h1>Reports</h1>
            <p>Financial statements and summaries from your posted transactions.</p>
        </div>
        <div class="rpt-search">
            <i class="fas fa-search"></i>
            <input type="search" class="form-control" id="rpt-q" placeholder="Search reports…" autocomplete="off" aria-label="Search reports">
        </div>
    </div>

    <div class="rpt-sec" id="rpt-pinned" style="display:none">
        <h2><i class="fas fa-star text-warning"></i> Pinned</h2>
        <div class="rpt-grid"></div>
    </div>

    <?php foreach ($sections as [$title, $icon, $perm, $items]): ?>
        <?php if ($perm !== null && !$can($perm)) { continue; } ?>
        <?php if ($title === 'Activity' && !$is_admin) { continue; } ?>
        <div class="rpt-sec" data-sec>
            <h2><i class="<?= $icon ?>"></i> <?= htmlspecialchars($title) ?></h2>
            <div class="rpt-grid">
            <?php foreach ($items as $it): [$page, $name, $ic, $desc] = $it; $tag = $it[4] ?? null; ?>
                <a class="rpt-card" href="./?page=<?= htmlspecialchars($page) ?>" data-key="<?= htmlspecialchars($page) ?>" data-text="<?= htmlspecialchars(strtolower($title . ' ' . $name . ' ' . $desc)) ?>">
                    <span class="ic"><i class="<?= $ic ?>"></i></span>
                    <span>
                        <span class="t"><?= htmlspecialchars($name) ?><?php if ($tag): ?> <span class="tag"><?= $tag ?></span><?php endif; ?></span>
                        <span class="d d-block"><?= htmlspecialchars($desc) ?></span>
                    </span>
                    <button type="button" class="rpt-pin" title="Pin to top" aria-label="Pin <?= htmlspecialchars($name) ?>"><i class="fas fa-star"></i></button>
                </a>
            <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    <div class="rpt-none" id="rpt-none"><i class="fas fa-search d-block mb-2" style="font-size:1.4rem;color:#cbd5e1"></i>No reports match your search.</div>
</div>

<script>
$(function () {
    var store = (window.NuvisUI && NuvisUI.store) || { get: function (k, d) { return d; }, set: function () {} };
    var pins = store.get('report-pins', []);

    function renderPins() {
        var $box = $('#rpt-pinned .rpt-grid').empty();
        $('.rpt-card .rpt-pin').removeClass('on').attr('title', 'Pin to top');
        $.each(pins, function (_, key) {
            var $card = $('[data-sec] .rpt-card[data-key="' + key + '"]');
            if (!$card.length) { return; }
            $card.find('.rpt-pin').addClass('on').attr('title', 'Unpin');
            $box.append($card.clone());
        });
        $('#rpt-pinned').toggle($box.children().length > 0 && !$('#rpt-q').val());
    }

    $(document).on('click', '.rpt-pin', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var key = $(this).closest('.rpt-card').data('key');
        pins = pins.indexOf(key) === -1 ? pins.concat([key]) : pins.filter(function (k) { return k !== key; });
        store.set('report-pins', pins);
        renderPins();
    });

    $('#rpt-q').on('input', function () {
        var q = $.trim($(this).val()).toLowerCase();
        var any = false;
        $('[data-sec]').each(function () {
            var shown = 0;
            $(this).find('.rpt-card').each(function () {
                var hit = !q || $(this).data('text').indexOf(q) !== -1;
                $(this).toggle(hit);
                if (hit) { shown++; }
            });
            $(this).toggle(shown > 0);
            if (shown) { any = true; }
        });
        $('#rpt-none').toggle(!any);
        renderPins();
    }).trigger('focus');

    renderPins();
});
</script>
