/*!
 * Nuvis ERPX — report page behaviour: period presets, quick dates, CSV export, print.
 */
(function ($) {
    'use strict';

    function csvCell(text) {
        var t = String(text).replace(/\s+/g, ' ').trim();
        // Accounting brackets → minus sign so spreadsheets read the number.
        var m = /^\(([\d,]+\.\d+)\)$/.exec(t);
        if (m) { t = '-' + m[1]; }
        if (/^-?[\d,]+\.\d+$/.test(t)) { t = t.replace(/,/g, ''); }
        if (t === '–') { t = ''; }
        return /[",\n]/.test(t) ? '"' + t.replace(/"/g, '""') + '"' : t;
    }

    function exportCsv() {
        var $sheet = $('#outprint');
        var rows = [];
        $sheet.find('.nvr-company, .nvr-sheet-title, .nvr-sheet-sub').each(function () { rows.push(csvCell($(this).text())); });
        rows.push('');
        $sheet.find('table.nvr-table').each(function () {
            $(this).find('tr').each(function () {
                var cells = $(this).children('th, td').map(function () {
                    var span = parseInt($(this).attr('colspan') || '1', 10);
                    var out = [csvCell($(this).text())];
                    for (var i = 1; i < span; i++) { out.push(''); }
                    return out.join(',');
                }).get();
                rows.push(cells.join(','));
            });
            rows.push('');
        });
        var title = ($sheet.find('.nvr-sheet-title').text() || 'report').trim().toLowerCase().replace(/[^a-z0-9]+/g, '-');
        var sub = ($sheet.find('.nvr-sheet-sub').text() || '').trim().toLowerCase().replace(/[^a-z0-9]+/g, '-');
        var blob = new Blob(['﻿' + rows.join('\r\n')], { type: 'text/csv;charset=utf-8' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = title + (sub ? '_' + sub : '') + '.csv';
        document.body.appendChild(a);
        a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
    }

    $(function () {
        var $form = $('.nvr-filters');

        $('[data-nvr="period"]').on('change', function () {
            var $o = $(this).find(':selected');
            if ($o.val() === 'custom') { $('#nvr-from').trigger('focus'); return; }
            $('#nvr-from').val($o.data('from'));
            $('#nvr-to').val($o.data('to'));
            $form.trigger('submit');
        });
        $('#nvr-from, #nvr-to').on('change', function () { $('[data-nvr="period"]').val('custom'); });

        $('[data-nvr="asat"]').on('click', function () {
            $('#nvr-asat').val($(this).data('date'));
            $form.trigger('submit');
        });

        $form.on('change', 'select[name="compare"], select[name="account_id"], input[type="checkbox"]', function () { $form.trigger('submit'); });

        $('[data-nvr="csv"]').on('click', exportCsv);
        $('[data-nvr="print"]').on('click', function () { window.print(); });

        if ($.fn.select2) {
            $form.find('select.nvr-select2').select2({ theme: 'bootstrap4', width: '100%' });
        }
    });
})(window.jQuery);
