/*!
 * Nuvis ERPX — customisable dashboards.
 *
 * Dashboards belong to the signed-in user (per tenant). Each is an ordered list
 * of widget placements; data comes from classes/Dashboard.php?f=data.
 * Requires jQuery, jQuery UI (sortable), Bootstrap 4, ApexCharts, SweetAlert2.
 */
(function ($, window, document) {
    'use strict';

    var API = (window._base_url_ || '../') + 'classes/Dashboard.php';
    var store = (window.NuvisUI && window.NuvisUI.store) || { get: function (k, d) { return d; }, set: function () {} };

    var SIZE_CLASS = {
        sm: 'col-12 col-sm-6 col-xl-3',
        md: 'col-12 col-md-6 col-xl-4',
        lg: 'col-12 col-xl-6',
        xl: 'col-12 col-xl-8',
        full: 'col-12'
    };
    var SIZE_LABEL = { sm: 'Small · ¼ width', md: 'Medium · ⅓ width', lg: 'Large · ½ width', xl: 'Wide · ⅔ width', full: 'Full width' };
    var CHART_LABEL = { bar: 'Bar chart', line: 'Line chart', area: 'Area chart', donut: 'Donut chart' };
    var CHART_ICON = { bar: 'fas fa-chart-bar', line: 'fas fa-chart-line', area: 'fas fa-chart-area', donut: 'fas fa-chart-pie' };
    var TYPE_LABEL = { kpi: 'Metric', chart: 'Chart', list: 'List', actions: 'Shortcuts', note: 'Note' };
    var TONE = { blue: '#2563eb', green: '#16a34a', amber: '#d97706', red: '#dc2626', indigo: '#4f46e5', cyan: '#0891b2' };
    var SERIES_COLORS = ['#2563eb', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4', '#94a3b8'];
    var STATUS_COLORS = ['#10b981', '#f59e0b', '#3b82f6', '#ef4444'];
    var STATUS_PILL = { paid: 'green', posted: 'green', approved: 'green', debit: 'blue', credit: 'green', unpaid: 'red', cancelled: '', draft: '', partially_paid: 'amber', pending: 'amber' };

    var S = {
        dashboards: [],
        catalog: {},
        ranges: {},
        defaultLayout: [],
        active: null,
        editing: false,
        currency: '$',
        data: {},
        charts: {},
        galleryCat: 'All'
    };

    /* ------------------------------------------------------------------ *
     * Utilities
     * ------------------------------------------------------------------ */
    function esc(v) {
        return String(v === null || v === undefined ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function uid() { return 'w' + Math.random().toString(36).slice(2, 10); }

    function num(v, digits) {
        var n = Number(v) || 0;
        return n.toLocaleString(undefined, { minimumFractionDigits: digits || 0, maximumFractionDigits: digits || 0 });
    }

    function money(v, opts) {
        opts = opts || {};
        var n = Number(v) || 0;
        var neg = n < 0;
        n = Math.abs(n);
        var s;
        if (opts.compact && n >= 1000) {
            var units = [[1e9, 'B'], [1e6, 'M'], [1e3, 'k']];
            for (var i = 0; i < units.length; i++) {
                if (n >= units[i][0]) { s = (n / units[i][0]).toFixed(n / units[i][0] >= 100 ? 0 : 1).replace(/\.0$/, '') + units[i][1]; break; }
            }
        } else {
            s = n.toLocaleString(undefined, { minimumFractionDigits: n >= 10000 ? 0 : 2, maximumFractionDigits: n >= 10000 ? 0 : 2 });
        }
        return (neg ? '−' : '') + S.currency + s;
    }

    function fmt(v, format) {
        if (format === 'money') { return money(v); }
        if (format === 'number') { return num(v); }
        if (format === 'date') {
            if (!v) { return '—'; }
            var d = new Date(String(v).replace(' ', 'T'));
            return isNaN(d) ? esc(v) : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
        }
        return v;
    }

    function niceDate(ymd) {
        var d = new Date(ymd + 'T00:00:00');
        return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function toast(msg, icon) {
        if (window.alert_toast) { window.alert_toast(msg, icon || 'success'); }
    }

    function api(action, data, method) {
        return $.ajax({
            url: API + '?f=' + encodeURIComponent(action),
            method: method || 'POST',
            data: data || {},
            dataType: 'json'
        });
    }

    function apiError(xhr) {
        var msg = (xhr && xhr.responseJSON && xhr.responseJSON.msg) || 'Something went wrong. Please try again.';
        toast(msg, 'error');
    }

    function def(item) { return S.catalog[item.widget]; }
    function titleOf(item) { return (item.config && item.config.title) || (def(item) ? def(item).title : 'Widget'); }
    function layout() { return S.active ? S.active.layout : []; }
    function findItem(id) {
        var l = layout();
        for (var i = 0; i < l.length; i++) { if (l[i].uid === id) { return { item: l[i], index: i }; } }
        return null;
    }

    /* ------------------------------------------------------------------ *
     * Persistence
     * ------------------------------------------------------------------ */
    var saveTimer = null;
    function setSaveState(state) {
        var $s = $('#nvSaveState').removeClass('saving error');
        if (state === 'saving') { $s.addClass('saving').html('<i class="fas fa-circle-notch fa-spin mr-1"></i>Saving…'); }
        else if (state === 'saved') { $s.html('<i class="fas fa-check mr-1"></i>All changes saved'); }
        else if (state === 'error') { $s.addClass('error').html('<i class="fas fa-exclamation-triangle mr-1"></i>Not saved'); }
        else { $s.empty(); }
    }

    function saveLayout(immediate) {
        if (!S.active) { return; }
        clearTimeout(saveTimer);
        setSaveState('saving');
        var dash = S.active;
        var run = function () {
            api('save', { id: dash.id, layout: JSON.stringify(dash.layout) })
                .done(function () { setSaveState('saved'); })
                .fail(function (xhr) { setSaveState('error'); apiError(xhr); });
        };
        if (immediate) { run(); } else { saveTimer = setTimeout(run, 500); }
    }

    /* ------------------------------------------------------------------ *
     * Loading
     * ------------------------------------------------------------------ */
    function load() {
        api('list', null, 'GET').done(function (resp) {
            S.dashboards = resp.dashboards || [];
            S.ranges = resp.ranges || {};
            S.defaultLayout = resp.defaultLayout || [];
            S.catalog = {};
            $.each(resp.catalog || [], function (_, d) { S.catalog[d.id] = d; });

            var $range = $('#nvRange').empty();
            $.each(S.ranges, function (k, label) { $range.append($('<option>').val(k).text(label)); });

            var wanted = store.get('dash-active', null);
            var pick = S.dashboards[0] || null;
            $.each(S.dashboards, function (_, d) { if (d.id === wanted) { pick = d; } });
            activate(pick);
        }).fail(function (xhr) {
            $('#nvGrid').html('<div class="col-12"><div class="nv-dash-empty"><div class="ic"><i class="fas fa-plug"></i></div><h5>Dashboard unavailable</h5><p class="mb-0">' +
                esc((xhr.responseJSON && xhr.responseJSON.msg) || 'The dashboard service could not be reached.') + '</p></div></div>');
            $('#nvRangeText').text('');
        });
    }

    function activate(dash) {
        S.active = dash;
        if (dash) { store.set('dash-active', dash.id); }
        renderTabs();
        $('#nvRange').val(dash ? dash.range : 'this_month');
        renderBoard();
    }

    function fetchData(onlyIds) {
        if (!S.active) { return; }
        var ids = [];
        $.each(layout(), function (_, it) {
            var d = def(it);
            if (!d || d.type === 'note') { return; }
            if (onlyIds && onlyIds.indexOf(it.widget) === -1) { return; }
            if (ids.indexOf(it.widget) === -1) { ids.push(it.widget); }
        });
        if (!ids.length) { updateRangeText(null); return; }

        var dashId = S.active.id;
        $('#nvRefresh i').addClass('fa-spin');
        api('data', { w: ids.join(','), range: S.active.range }, 'GET').done(function (resp) {
            if (!S.active || S.active.id !== dashId) { return; }
            S.currency = resp.currency || S.currency;
            updateRangeText(resp.range);
            $.each(resp.widgets || {}, function (id, data) { S.data[id] = data; });
            $.each(layout(), function (_, it) {
                if (ids.indexOf(it.widget) !== -1) { renderBody(it); }
            });
        }).fail(function (xhr) {
            $.each(layout(), function (_, it) {
                if (ids.indexOf(it.widget) !== -1) { bodyEl(it).html(errorState()); }
            });
            if (xhr.status === 401) { apiError(xhr); }
        }).always(function () {
            $('#nvRefresh i').removeClass('fa-spin');
        });
    }

    function updateRangeText(range) {
        if (!range) {
            $('#nvRangeText').text(S.ranges[S.active ? S.active.range : 'this_month'] || '');
            return;
        }
        $('#nvRangeText').html('<i class="far fa-calendar mr-1"></i>' + esc(range.label) + ': ' +
            esc(niceDate(range.from)) + ' – ' + esc(niceDate(range.to)));
    }

    /* ------------------------------------------------------------------ *
     * Tabs
     * ------------------------------------------------------------------ */
    function renderTabs() {
        var $tabs = $('#nvTabs').empty();
        $.each(S.dashboards, function (_, d) {
            var active = S.active && S.active.id === d.id;
            var $t = $('<button type="button" class="nv-dash-tab" role="tab"></button>')
                .toggleClass('active', !!active)
                .attr({ 'aria-selected': active ? 'true' : 'false', 'data-id': d.id })
                .append($('<span class="nv-tab-name"></span>').text(d.name));
            if (active) {
                $t.append('<span class="nv-tab-tools">' +
                    '<span class="nv-tab-tool" data-act="rename" title="Rename dashboard" role="button"><i class="fas fa-pen"></i></span>' +
                    (S.dashboards.length > 1 ? '<span class="nv-tab-tool danger" data-act="delete" title="Delete dashboard" role="button"><i class="fas fa-trash-alt"></i></span>' : '') +
                    '</span>');
            }
            $tabs.append($t);
        });
        $tabs.append('<button type="button" class="nv-dash-tab nv-add-tab" data-act="new" title="Create a new dashboard"><i class="fas fa-plus"></i> New dashboard</button>');
    }

    function newDashboard() {
        Swal.fire({
            title: 'New dashboard',
            input: 'text',
            inputLabel: 'Name',
            inputPlaceholder: 'e.g. Payroll, Sales, Month-end',
            showCancelButton: true,
            confirmButtonText: 'Create',
            inputValidator: function (v) { return !$.trim(v) ? 'Give your dashboard a name' : undefined; }
        }).then(function (res) {
            if (!(res.isConfirmed || res.value)) { return; }
            api('create', { name: res.value }).done(function (resp) {
                S.dashboards.push(resp.dashboard);
                activate(resp.dashboard);
                setEditing(true);
                openGallery();
            }).fail(apiError);
        });
    }

    function renameDashboard() {
        var dash = S.active;
        Swal.fire({
            title: 'Rename dashboard',
            input: 'text',
            inputValue: dash.name,
            showCancelButton: true,
            confirmButtonText: 'Save',
            inputValidator: function (v) { return !$.trim(v) ? 'Name cannot be empty' : undefined; }
        }).then(function (res) {
            if (!(res.isConfirmed || res.value)) { return; }
            api('save', { id: dash.id, name: res.value }).done(function (resp) {
                dash.name = resp.dashboard.name;
                renderTabs();
                setSaveState('saved');
            }).fail(apiError);
        });
    }

    function deleteDashboard() {
        var dash = S.active;
        Swal.fire({
            title: 'Delete “' + dash.name + '”?',
            text: 'The dashboard and its widget layout will be removed. Your data is not affected.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Delete',
            confirmButtonColor: '#dc2626',
            reverseButtons: true,
            focusCancel: true
        }).then(function (res) {
            if (!(res.isConfirmed || res.value)) { return; }
            api('delete', { id: dash.id }).done(function () {
                S.dashboards = $.grep(S.dashboards, function (d) { return d.id !== dash.id; });
                activate(S.dashboards[0] || null);
                toast('Dashboard deleted');
            }).fail(apiError);
        });
    }

    /* ------------------------------------------------------------------ *
     * Board & widget chrome
     * ------------------------------------------------------------------ */
    function destroyCharts(filterUid) {
        $.each(S.charts, function (key, chart) {
            if (filterUid && key.indexOf(filterUid + ':') !== 0) { return; }
            try { chart.destroy(); } catch (e) { /* already gone */ }
            delete S.charts[key];
        });
    }

    function renderBoard() {
        destroyCharts();
        var $grid = $('#nvGrid');
        if ($grid.hasClass('ui-sortable')) { $grid.sortable('destroy'); }
        $grid.empty();

        if (!S.active) { return; }
        if (!layout().length) {
            $grid.append(
                '<div class="col-12"><div class="nv-dash-empty"><div class="ic"><i class="fas fa-th-large"></i></div>' +
                '<h5>This dashboard is empty</h5><p>Add metrics, charts and lists to build the view you need.</p>' +
                '<button type="button" class="btn btn-primary" data-act="gallery"><i class="fas fa-plus mr-1"></i> Add widgets</button></div></div>');
            updateRangeText(null);
            return;
        }

        $.each(layout(), function (_, it) { $grid.append(buildWidget(it)); });
        if (S.editing) { enableSorting(); }
        fetchData();
        $.each(layout(), function (_, it) {
            var d = def(it);
            if (d && (d.type === 'note')) { renderBody(it); }
        });
    }

    function colEl(item) { return $('#nvGrid > .nv-w-col[data-uid="' + item.uid + '"]'); }
    function bodyEl(item) { return colEl(item).find('.nv-w-body'); }

    function buildWidget(item) {
        var d = def(item);
        if (!d) { return $(); }
        var color = (item.config && item.config.color) || d.tone;
        var $col = $('<div class="nv-w-col"></div>').addClass(SIZE_CLASS[item.size] || SIZE_CLASS[d.size]).attr('data-uid', item.uid);
        var $w = $('<section class="nv-w"></section>').attr({ 'data-tone': color, 'aria-label': titleOf(item) });

        var head =
            '<header class="nv-w-head">' +
            '<button type="button" class="nv-w-btn nv-w-handle nv-edit-only" title="Drag to move" aria-label="Drag to move"><i class="fas fa-grip-vertical"></i></button>' +
            '<span class="nv-w-icon"><i class="' + esc(d.icon) + '"></i></span>' +
            '<h3 class="nv-w-title" title="' + esc(titleOf(item)) + '">' + esc(titleOf(item)) + '</h3>' +
            '<span class="nv-w-meta nv-view-only"></span>' +
            '<div class="nv-w-tools">' + menuHtml(item, d) +
            '<button type="button" class="nv-w-btn danger nv-edit-only" data-act="remove" title="Remove widget" aria-label="Remove widget"><i class="fas fa-times"></i></button>' +
            '</div></header>';

        $w.append(head).append('<div class="nv-w-body">' + skeleton(d) + '</div>');
        return $col.append($w);
    }

    function menuHtml(item, d) {
        var h = '<div class="dropdown">' +
            '<button type="button" class="nv-w-btn" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Widget options" aria-label="Widget options"><i class="fas fa-ellipsis-h"></i></button>' +
            '<div class="dropdown-menu dropdown-menu-right">';
        if (d.type !== 'note' && d.type !== 'actions') {
            h += '<a class="dropdown-item" href="#" data-act="refresh"><i class="fas fa-sync-alt"></i> Refresh</a>';
        }
        if (d.charts && d.charts.length > 1) {
            var cur = (item.config && item.config.chart) || d.charts[0];
            h += '<div class="dropdown-divider"></div><h6 class="dropdown-header">Chart type</h6>';
            $.each(d.charts, function (_, c) {
                h += '<a class="dropdown-item" href="#" data-act="chart" data-val="' + c + '"><i class="' + CHART_ICON[c] + '"></i> ' + CHART_LABEL[c] + (c === cur ? '<i class="fas fa-check"></i>' : '') + '</a>';
            });
        }
        if (d.sizes.length > 1) {
            h += '<div class="dropdown-divider"></div><h6 class="dropdown-header">Size</h6>';
            $.each(d.sizes, function (_, s) {
                h += '<a class="dropdown-item" href="#" data-act="size" data-val="' + s + '"><i class="fas fa-expand-alt"></i> ' + SIZE_LABEL[s] + (s === item.size ? '<i class="fas fa-check"></i>' : '') + '</a>';
            });
        }
        h += '<div class="dropdown-divider"></div><h6 class="dropdown-header">Colour</h6><div class="d-flex px-2 pb-1" style="gap:6px">';
        var curColor = (item.config && item.config.color) || d.tone;
        $.each(TONE, function (name, hex) {
            h += '<a href="#" data-act="color" data-val="' + name + '" title="' + name + '" aria-label="' + name + '" style="width:20px;height:20px;border-radius:50%;background:' + hex + ';display:inline-block;' +
                (name === curColor ? 'box-shadow:0 0 0 2px #fff,0 0 0 4px ' + hex + ';' : '') + '"></a>';
        });
        h += '</div>';
        h += '<div class="dropdown-divider"></div>' +
            '<a class="dropdown-item" href="#" data-act="rename"><i class="fas fa-pen"></i> Rename</a>' +
            '<a class="dropdown-item" href="#" data-act="duplicate"><i class="far fa-clone"></i> Duplicate</a>' +
            '<a class="dropdown-item" href="#" data-act="move" data-val="-1"><i class="fas fa-arrow-left"></i> Move earlier</a>' +
            '<a class="dropdown-item" href="#" data-act="move" data-val="1"><i class="fas fa-arrow-right"></i> Move later</a>' +
            '<div class="dropdown-divider"></div>' +
            '<a class="dropdown-item text-danger" href="#" data-act="remove"><i class="fas fa-trash-alt text-danger"></i> Remove</a>' +
            '</div></div>';
        return h;
    }

    function skeleton(d) {
        if (d.type === 'kpi') {
            return '<div class="nv-skel" style="height:30px;width:65%"></div><div class="nv-skel mt-3" style="height:12px;width:45%"></div>';
        }
        if (d.type === 'list') {
            var r = '';
            for (var i = 0; i < 5; i++) { r += '<div class="nv-skel mb-2" style="height:14px;width:' + (90 - i * 8) + '%"></div>'; }
            return r;
        }
        if (d.type === 'chart') { return '<div class="nv-skel" style="height:240px"></div>'; }
        return '';
    }

    function emptyState(msg, icon) {
        return '<div class="nv-w-empty"><i class="' + (icon || 'far fa-chart-bar') + '"></i><span>' + esc(msg) + '</span></div>';
    }
    function errorState() {
        return '<div class="nv-w-empty nv-w-error"><i class="fas fa-exclamation-triangle"></i><span>Couldn’t load this widget.</span>' +
            '<a href="#" data-act="refresh" class="small">Try again</a></div>';
    }

    function setFooter(item, html) {
        var $w = colEl(item).find('.nv-w');
        $w.find('.nv-w-foot').remove();
        if (html) { $w.append('<footer class="nv-w-foot">' + html + '</footer>'); }
    }

    /* ------------------------------------------------------------------ *
     * Widget bodies
     * ------------------------------------------------------------------ */
    function renderBody(item) {
        var d = def(item);
        if (!d) { return; }
        var data = S.data[item.widget] || {};
        var $body = bodyEl(item);
        destroyCharts(item.uid);

        if (data.error) {
            $body.html(data.error === 'unavailable' ? emptyState('You no longer have access to this widget.', 'fas fa-lock') : errorState());
            setFooter(item, '');
            return;
        }

        switch (d.type) {
            case 'kpi': renderKpi(item, d, data, $body); break;
            case 'chart': renderChart(item, d, data, $body); break;
            case 'list': renderList(item, d, data, $body); break;
            case 'actions': renderActions(item, data, $body); break;
            case 'note': renderNote(item, $body); break;
        }
    }

    function linkFooter(data, text) {
        return data.link ? '<span></span><a href="' + esc(data.link) + '">' + esc(text || 'View details') + ' <i class="fas fa-arrow-right ml-1"></i></a>' : '';
    }

    function renderKpi(item, d, data, $body) {
        var value = data.format === 'money' ? money(data.value) : num(data.value);
        var h = '<div class="nv-kpi-value" title="' + esc(data.format === 'money' ? money(data.value) : num(data.value)) + '">' + esc(value) + '</div><div class="nv-kpi-row">';

        if (data.delta !== null && data.delta !== undefined) {
            var good = data.invert ? data.delta <= 0 : data.delta >= 0;
            var tone = data.delta === 0 ? 'neutral' : (good ? 'up' : 'down');
            var arrow = data.delta === 0 ? 'fa-minus' : (data.delta > 0 ? 'fa-arrow-up' : 'fa-arrow-down');
            var prev = data.format === 'money' ? money(data.previous) : num(data.previous);
            h += '<span class="nv-delta ' + tone + '" title="Previous period: ' + esc(prev) + '"><i class="fas ' + arrow + '"></i>' + Math.abs(data.delta).toFixed(1) + '%</span><span>vs previous period</span>';
        } else if (data.previous !== null && data.previous !== undefined && Number(data.previous) === 0 && Number(data.value) > 0) {
            h += '<span class="nv-delta up"><i class="fas fa-arrow-up"></i>New</span><span>vs previous period</span>';
        }
        if (data.sub) {
            h += '<span class="nv-sub ' + esc(data.subTone || '') + '">' + esc(data.sub) + '</span>';
        }
        h += '</div>';

        if (data.progress !== undefined) {
            h += '<div class="nv-progress" role="progressbar" aria-valuenow="' + Number(data.progress) + '" aria-valuemin="0" aria-valuemax="100"><span style="width:' + Math.min(100, Number(data.progress) || 0) + '%"></span></div>' +
                '<div class="nv-progress-label">' + Number(data.progress) + '% ' + esc(data.progressLabel || '') + '</div>';
        }

        var spark = data.spark || [];
        var hasSpark = spark.length > 1 && spark.some(function (v) { return Number(v) !== 0; });
        if (hasSpark) {
            h += '<div class="nv-kpi-spark"></div>';
        }
        $body.html(h);
        setFooter(item, linkFooter(data));

        if (hasSpark && window.ApexCharts) {
            var color = TONE[(item.config && item.config.color) || d.tone] || TONE.blue;
            var chart = new ApexCharts($body.find('.nv-kpi-spark')[0], {
                chart: { type: 'area', height: 46, sparkline: { enabled: true }, animations: { enabled: false } },
                series: [{ name: titleOf(item), data: spark }],
                stroke: { curve: 'smooth', width: 2 },
                fill: { type: 'gradient', gradient: { opacityFrom: 0.28, opacityTo: 0.02 } },
                colors: [color],
                tooltip: { enabled: false }
            });
            chart.render();
            S.charts[item.uid + ':spark'] = chart;
        }
    }

    function chartHeight(item) {
        return item.size === 'md' ? 270 : (item.size === 'full' ? 330 : 300);
    }

    function renderChart(item, d, data, $body) {
        var type = (item.config && item.config.chart) || d.charts[0];
        var isPie = data.labels !== undefined;
        var empty = isPie
            ? !(data.values || []).some(function (v) { return Number(v) > 0; })
            : !(data.series || []).some(function (s) { return (s.data || []).some(function (v) { return Number(v) !== 0; }); });

        setFooter(item, linkFooter(data));
        if (empty) { $body.html(emptyState('No data for this period yet.')); return; }
        if (!window.ApexCharts) { $body.html(emptyState('Charts are unavailable offline.')); return; }

        $body.html('<div class="nv-chart"></div>');
        var isMoney = data.format === 'money';
        var axisFmt = function (v) { return isMoney ? money(v, { compact: true }) : num(v); };
        var tipFmt = function (v) { return isMoney ? money(v) : num(v); };
        var base = {
            chart: { type: type, height: chartHeight(item), toolbar: { show: false }, fontFamily: 'Inter, sans-serif', animations: { speed: 350 }, parentHeightOffset: 0 },
            dataLabels: { enabled: false },
            tooltip: { y: { formatter: tipFmt } },
            legend: { fontSize: '12px', markers: { radius: 12 } }
        };
        var opts;

        if (isPie) {
            var colors = data.palette === 'status' ? STATUS_COLORS : SERIES_COLORS;
            if (type === 'donut') {
                var total = (data.values || []).reduce(function (a, b) { return a + Number(b); }, 0);
                opts = $.extend(true, base, {
                    series: data.values,
                    labels: data.labels,
                    colors: colors,
                    legend: { position: 'bottom' },
                    stroke: { width: 2, colors: ['#fff'] },
                    plotOptions: { pie: { donut: { size: '68%', labels: { show: true, value: { formatter: function (v) { return tipFmt(Number(v)); } }, total: { show: true, label: data.totalLabel || 'Total', formatter: function () { return isMoney ? money(total, { compact: total >= 100000 }) : num(total); } } } } } }
                });
            } else {
                opts = $.extend(true, base, {
                    chart: { type: 'bar' },
                    series: [{ name: titleOf(item), data: data.values }],
                    xaxis: { categories: data.labels, labels: { formatter: axisFmt } },
                    colors: colors,
                    legend: { show: false },
                    plotOptions: { bar: { horizontal: true, distributed: true, borderRadius: 4, barHeight: '60%' } },
                    grid: { borderColor: '#eef2f7', strokeDashArray: 3 }
                });
            }
        } else {
            var color0 = TONE[(item.config && item.config.color) || d.tone];
            var colors2 = SERIES_COLORS.slice();
            if (color0 && colors2.indexOf(color0) === -1) { colors2.unshift(color0); }
            opts = $.extend(true, base, {
                series: data.series,
                xaxis: { categories: data.categories, axisBorder: { show: false }, axisTicks: { show: false } },
                yaxis: { labels: { formatter: axisFmt } },
                colors: colors2,
                stroke: { curve: 'smooth', width: type === 'bar' ? 0 : 2.5 },
                fill: type === 'area' ? { type: 'gradient', gradient: { opacityFrom: 0.3, opacityTo: 0.03 } } : { opacity: 1 },
                plotOptions: { bar: { columnWidth: '55%', borderRadius: 4 } },
                grid: { borderColor: '#eef2f7', strokeDashArray: 3, padding: { left: 6, right: 6 } },
                legend: { position: 'top', horizontalAlign: 'right' },
                markers: { size: type === 'line' ? 3 : 0, strokeWidth: 0 }
            });
        }

        var chart = new ApexCharts($body.find('.nv-chart')[0], opts);
        chart.render();
        S.charts[item.uid + ':main'] = chart;
    }

    function renderList(item, d, data, $body) {
        var rows = data.rows || [];
        var meta = data.meta ? esc(data.meta) : '';
        colEl(item).find('.nv-w-meta').html(meta || (rows.length ? rows.length + (rows.length === 1 ? ' item' : ' items') : ''));
        setFooter(item, linkFooter(data, 'View all'));
        if (!rows.length) { $body.html(emptyState(data.empty || 'Nothing to show.', 'far fa-check-circle')); return; }

        var cols = data.columns || [];
        var h = '<div class="nv-w-list-wrap"><table class="nv-w-list"><thead><tr>';
        $.each(cols, function (_, c) { h += '<th class="' + (c.align === 'right' ? 'num' : '') + '">' + esc(c.label) + '</th>'; });
        h += '</tr></thead><tbody>';
        $.each(rows, function (_, r) {
            h += '<tr' + (r.href ? ' data-href="' + esc(r.href) + '" tabindex="0"' : '') + '>';
            $.each(cols, function (ci, c) {
                var v = r[c.key];
                var cell;
                if (c.format === 'money') { cell = esc(money(v)); }
                else if (c.format === 'number') { cell = esc(num(v)); }
                else if (c.format === 'date') { cell = esc(fmt(v, 'date')); }
                else if (c.format === 'days') { cell = '<span class="nv-days">' + esc(num(v)) + ' day' + (Number(v) === 1 ? '' : 's') + '</span>'; }
                else if (c.format === 'status') {
                    var key = String(v || '').toLowerCase();
                    cell = '<span class="nv-pill ' + (STATUS_PILL[key] || '') + '">' + esc(String(v || '').replace(/_/g, ' ').replace(/^./, function (m) { return m.toUpperCase(); })) + '</span>';
                } else {
                    var s = String(v === null || v === undefined ? '' : v);
                    if (c.truncate && s.length > c.truncate) { s = s.slice(0, c.truncate - 1) + '…'; }
                    cell = esc(s);
                }
                if (c.bar && r[c.bar] !== undefined) {
                    cell = '<div class="nv-cell-bar">' + cell + '<div class="bar ' + (c.barTone || '') + '"><span style="width:' + Number(r[c.bar]) + '%"></span></div></div>';
                }
                var cls = (c.align === 'right' ? 'num' : '') + (ci === 0 ? ' strong' : '');
                h += '<td class="' + cls + '">' + cell + '</td>';
            });
            h += '</tr>';
        });
        h += '</tbody></table></div>';
        $body.html(h);
    }

    function renderActions(item, data, $body) {
        var acts = data.actions || [];
        setFooter(item, '');
        if (!acts.length) { $body.html(emptyState('No shortcuts available for your role.', 'fas fa-bolt')); return; }
        var h = '<div class="nv-actions">';
        $.each(acts, function (_, a) {
            h += '<a class="nv-action" data-tone="' + esc(a.tone) + '" href="' + esc(a.href) + '"><i class="' + esc(a.icon) + '"></i>' + esc(a.label) + '</a>';
        });
        $body.html(h + '</div>');
    }

    function renderNote(item, $body) {
        setFooter(item, '<span class="muted">Only you can see this note</span><span class="muted nv-note-state"></span>');
        var $ta = $('<textarea class="nv-note" maxlength="2000" aria-label="Note" placeholder="Jot down reminders, targets or links for this dashboard…"></textarea>')
            .val((item.config && item.config.text) || '');
        $body.empty().append($ta);
    }

    /* ------------------------------------------------------------------ *
     * Editing
     * ------------------------------------------------------------------ */
    function setEditing(on) {
        S.editing = !!on;
        $('#nvDash').toggleClass('nv-dash-editing', S.editing);
        $('#nvEditBtn').html(S.editing ? '<i class="fas fa-check"></i> Done' : '<i class="fas fa-th-large"></i> Customize');
        renderTabs();
        if (S.editing) { enableSorting(); } else if ($('#nvGrid').hasClass('ui-sortable')) { $('#nvGrid').sortable('destroy'); }
    }

    function enableSorting() {
        var $grid = $('#nvGrid');
        if (!$.fn.sortable || $grid.hasClass('ui-sortable') || !layout().length) { return; }
        $grid.sortable({
            items: '> .nv-w-col',
            handle: '.nv-w-handle',
            placeholder: 'nv-w-placeholder',
            tolerance: 'pointer',
            cursor: 'grabbing',
            opacity: 0.95,
            revert: 120,
            start: function (e, ui) {
                ui.placeholder.addClass(ui.item.attr('class').replace(/nv-w-col|ui-sortable-helper/g, ''));
                ui.placeholder.height(ui.item.outerHeight());
            },
            stop: function () {
                var order = $grid.children('.nv-w-col').map(function () { return $(this).attr('data-uid'); }).get();
                var byUid = {};
                $.each(layout(), function (_, it) { byUid[it.uid] = it; });
                S.active.layout = $.map(order, function (u) { return byUid[u] || null; });
                saveLayout();
                resizeCharts();
            }
        });
    }

    function resizeCharts() {
        setTimeout(function () { window.dispatchEvent(new Event('resize')); }, 60);
    }

    function addWidget(widgetId) {
        var d = S.catalog[widgetId];
        if (!d || !S.active) { return; }
        var item = { uid: uid(), widget: widgetId, size: d.size, config: {} };
        S.active.layout.push(item);
        if (S.active.layout.length === 1) { renderBoard(); } else {
            var $grid = $('#nvGrid');
            $grid.append(buildWidget(item));
            if ($grid.hasClass('ui-sortable')) { $grid.sortable('refresh'); } else if (S.editing) { enableSorting(); }
            if (d.type === 'note') { renderBody(item); } else if (S.data[widgetId]) { renderBody(item); } else { fetchData([widgetId]); }
        }
        saveLayout(true);
        return item;
    }

    function rebuildWidget(item) {
        destroyCharts(item.uid);
        colEl(item).replaceWith(buildWidget(item));
        if (def(item).type === 'note' || S.data[item.widget]) { renderBody(item); } else { fetchData([item.widget]); }
        if ($('#nvGrid').hasClass('ui-sortable')) { $('#nvGrid').sortable('refresh'); }
    }

    function widgetAction(uidVal, act, val) {
        var found = findItem(uidVal);
        if (!found) { return; }
        var item = found.item;
        item.config = $.isPlainObject(item.config) ? item.config : {};

        switch (act) {
            case 'refresh':
                bodyEl(item).html(skeleton(def(item)));
                fetchData([item.widget]);
                return;
            case 'size':
                item.size = val;
                colEl(item).attr('class', 'nv-w-col ' + SIZE_CLASS[val]);
                rebuildWidget(item);
                resizeCharts();
                break;
            case 'chart':
                item.config.chart = val;
                rebuildWidget(item);
                break;
            case 'color':
                item.config.color = val;
                rebuildWidget(item);
                break;
            case 'rename':
                Swal.fire({
                    title: 'Rename widget',
                    input: 'text',
                    inputValue: titleOf(item),
                    inputAttributes: { maxlength: 60 },
                    showCancelButton: true,
                    showDenyButton: !!item.config.title,
                    denyButtonText: 'Use default name',
                    confirmButtonText: 'Save'
                }).then(function (res) {
                    if (res.isDenied) { delete item.config.title; }
                    else if (res.isConfirmed || res.value) { item.config.title = $.trim(res.value); }
                    else { return; }
                    rebuildWidget(item);
                    saveLayout();
                });
                return;
            case 'duplicate':
                var copy = { uid: uid(), widget: item.widget, size: item.size, config: $.extend({}, item.config) };
                S.active.layout.splice(found.index + 1, 0, copy);
                colEl(item).after(buildWidget(copy));
                renderBody(copy);
                if ($('#nvGrid').hasClass('ui-sortable')) { $('#nvGrid').sortable('refresh'); }
                break;
            case 'move':
                var to = found.index + Number(val);
                if (to < 0 || to >= S.active.layout.length) { return; }
                S.active.layout.splice(found.index, 1);
                S.active.layout.splice(to, 0, item);
                var $c = colEl(item);
                if (Number(val) < 0) { $c.prev('.nv-w-col').before($c); } else { $c.next('.nv-w-col').after($c); }
                resizeCharts();
                break;
            case 'remove':
                S.active.layout.splice(found.index, 1);
                destroyCharts(item.uid);
                colEl(item).fadeOut(150, function () {
                    $(this).remove();
                    if (!layout().length) { renderBoard(); }
                });
                saveLayout(true);
                var removed = item;
                var at = found.index;
                if (window.Swal) {
                    Swal.fire({
                        toast: true, position: 'bottom', timer: 5000, timerProgressBar: true,
                        showConfirmButton: true, confirmButtonText: 'Undo', icon: 'info',
                        title: '“' + titleOf(removed) + '” removed'
                    }).then(function (res) {
                        if (res.isConfirmed) {
                            S.active.layout.splice(Math.min(at, S.active.layout.length), 0, removed);
                            renderBoard();
                            saveLayout(true);
                        }
                    });
                }
                return;
        }
        saveLayout();
    }

    function resetLayout() {
        Swal.fire({
            title: 'Reset this dashboard?',
            text: 'Its widgets will be replaced with the standard set.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Reset'
        }).then(function (res) {
            if (!(res.isConfirmed || res.value)) { return; }
            S.active.layout = $.map(S.defaultLayout, function (it) { return { uid: uid(), widget: it.widget, size: it.size, config: {} }; });
            renderBoard();
            saveLayout(true);
        });
    }

    /* ------------------------------------------------------------------ *
     * Widget gallery
     * ------------------------------------------------------------------ */
    function openGallery() {
        renderGallery();
        $('#nvWidgetGallery').modal('show');
    }

    function renderGallery() {
        var q = $.trim($('#nvGallerySearch').val() || '').toLowerCase();
        var cats = ['All'];
        $.each(S.catalog, function (_, d) { if (cats.indexOf(d.category) === -1) { cats.push(d.category); } });

        $('#nvGalleryCats').html($.map(cats, function (c) {
            return '<button type="button" data-cat="' + esc(c) + '" class="' + (c === S.galleryCat ? 'active' : '') + '">' + esc(c) + '</button>';
        }).join(''));

        var onBoard = {};
        $.each(layout(), function (_, it) { onBoard[it.widget] = (onBoard[it.widget] || 0) + 1; });

        var html = '';
        $.each(S.catalog, function (id, d) {
            if (S.galleryCat !== 'All' && d.category !== S.galleryCat) { return; }
            if (q && (d.title + ' ' + d.description + ' ' + d.category).toLowerCase().indexOf(q) === -1) { return; }
            html += '<div class="nv-gallery-item" data-tone="' + esc(d.tone) + '">' +
                '<div class="top"><span class="ic"><i class="' + esc(d.icon) + '"></i></span><div><div class="name">' + esc(d.title) + '</div>' +
                '<div class="kind">' + esc(TYPE_LABEL[d.type] || d.type) + ' · ' + esc(d.category) +
                (d.ranged ? ' <i class="far fa-calendar-alt ml-1" title="Follows the dashboard date range"></i>' : '') + '</div></div></div>' +
                '<div class="desc">' + esc(d.description) + '</div>' +
                '<div class="foot"><span class="on">' + (onBoard[id] ? '<i class="fas fa-check-circle mr-1"></i>On dashboard' : '') + '</span>' +
                '<button type="button" class="btn btn-sm ' + (onBoard[id] ? 'btn-default' : 'btn-primary') + '" data-add="' + esc(id) + '"><i class="fas fa-plus"></i> Add</button></div></div>';
        });
        $('#nvGalleryList').html(html || '<div class="nv-w-empty" style="grid-column:1/-1"><i class="fas fa-search"></i><span>No widgets match “' + esc(q) + '”.</span></div>');
    }

    /* ------------------------------------------------------------------ *
     * Events
     * ------------------------------------------------------------------ */
    function bind() {
        var $dash = $('#nvDash');

        $('#nvTabs').on('click', '.nv-dash-tab', function (e) {
            var act = $(e.target).closest('[data-act]').attr('data-act');
            if (act === 'new') { newDashboard(); return; }
            if (act === 'rename') { e.stopPropagation(); renameDashboard(); return; }
            if (act === 'delete') { e.stopPropagation(); deleteDashboard(); return; }
            var id = Number($(this).attr('data-id'));
            if (S.active && S.active.id === id) { return; }
            $.each(S.dashboards, function (_, d) { if (d.id === id) { activate(d); } });
        });

        $('#nvRange').on('change', function () {
            if (!S.active) { return; }
            S.active.range = $(this).val();
            S.data = {};
            $.each(layout(), function (_, it) {
                if (def(it) && def(it).ranged) { bodyEl(it).html(skeleton(def(it))); }
            });
            fetchData();
            api('save', { id: S.active.id, range: S.active.range }).done(function () { setSaveState('saved'); });
        });

        $('#nvRefresh').on('click', function () { S.data = {}; fetchData(); });
        $('#nvEditBtn, #nvDoneBtn').on('click', function () { setEditing(!S.editing); });
        $('#nvAddBtn').on('click', openGallery);
        $('#nvResetBtn').on('click', resetLayout);

        $dash.on('click', '[data-act="gallery"]', function () { setEditing(true); openGallery(); });

        $('#nvGrid').on('click', '.nv-w [data-act]', function (e) {
            e.preventDefault();
            var $a = $(this);
            var u = $a.closest('.nv-w-col').attr('data-uid');
            widgetAction(u, $a.attr('data-act'), $a.attr('data-val'));
        });

        $('#nvGrid').on('click keydown', '.nv-w-list tr[data-href]', function (e) {
            if (e.type === 'keydown' && e.key !== 'Enter') { return; }
            if (S.editing) { return; }
            window.location.href = $(this).attr('data-href');
        });

        // Notes save as you type.
        var noteTimers = {};
        $('#nvGrid').on('input', '.nv-note', function () {
            var $ta = $(this);
            var u = $ta.closest('.nv-w-col').attr('data-uid');
            var found = findItem(u);
            if (!found) { return; }
            found.item.config = $.isPlainObject(found.item.config) ? found.item.config : {};
            found.item.config.text = $ta.val();
            $ta.closest('.nv-w').find('.nv-note-state').text('Saving…');
            clearTimeout(noteTimers[u]);
            noteTimers[u] = setTimeout(function () {
                saveLayout(true);
                $ta.closest('.nv-w').find('.nv-note-state').text('Saved');
            }, 700);
        });

        // Gallery
        $('#nvGallerySearch').on('input', renderGallery);
        $('#nvGalleryCats').on('click', 'button', function () { S.galleryCat = $(this).attr('data-cat'); renderGallery(); });
        $('#nvGalleryList').on('click', '[data-add]', function () {
            var $b = $(this);
            var item = addWidget($b.attr('data-add'));
            if (!item) { return; }
            $b.html('<i class="fas fa-check"></i> Added').removeClass('btn-primary').addClass('btn-default');
            setTimeout(renderGallery, 900);
        });
        $('#nvWidgetGallery').on('shown.bs.modal', function () { $('#nvGallerySearch').trigger('focus'); });
        $('#nvWidgetGallery').on('hidden.bs.modal', function () {
            var $last = $('#nvGrid > .nv-w-col').last();
            if ($last.length && $last[0].scrollIntoView) { $last[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
        });

        // Keep figures fresh on long-open screens.
        setInterval(function () {
            if (!document.hidden && !S.editing) { fetchData(); }
        }, 5 * 60 * 1000);

        // Escape leaves edit mode.
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && S.editing && !$('.modal.show').length && !$('.swal2-container').length) { setEditing(false); }
        });
    }

    $(function () {
        if (!$('#nvDash').length) { return; }
        bind();
        load();
    });

})(window.jQuery, window, document);
