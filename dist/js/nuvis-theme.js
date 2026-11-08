/* Nuvis ERPX personalisation: theme panel, accent colour, side menu search, pinned items, remembered groups.
   Preferences come from window.NUVIS_UI (rendered by the server) and are saved through classes/UiPrefs.php. */
(function ($) {
    'use strict';
    if (!window.NUVIS_UI) { return; }

    var root = document.documentElement;
    var P = $.extend({}, window.NUVIS_UI);
    var DEFAULTS = { mode: 'light', accent: '#2563eb', sidebar: 'dark', density: 'comfortable', text: 'medium', collapsed: false, pins: [] };
    var MAX_PINS = 8;
    var saveTimer = null;
    var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    /* ---------- colour helpers (mirror UiPrefsService) ---------- */
    function rgb(hex) { return [1, 3, 5].map(function (i) { return parseInt(hex.substr(i, 2), 16); }); }
    function shade(hex, pct) {
        var c = rgb(hex).map(function (v) {
            v = pct < 0 ? Math.round(v * (100 + pct) / 100) : Math.round(v + (255 - v) * pct / 100);
            v = Math.max(0, Math.min(255, v));
            return (v < 16 ? '0' : '') + v.toString(16);
        });
        return '#' + c.join('');
    }
    function onAccent(hex) {
        var l = rgb(hex).map(function (v) { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); });
        return (0.2126 * l[0] + 0.7152 * l[1] + 0.0722 * l[2]) > 0.4 ? '#0f172a' : '#ffffff';
    }

    function effective(mode) { return mode === 'dark' || (mode === 'auto' && mq && mq.matches) ? 'dark' : 'light'; }

    /** Put the preferences on the page right now (no reload). */
    function apply() {
        root.setAttribute('data-nv-mode', P.mode);
        root.setAttribute('data-nv-eff', effective(P.mode));
        root.setAttribute('data-nv-sidebar', P.sidebar);
        root.setAttribute('data-nv-density', P.density);
        root.setAttribute('data-nv-text', P.text);
        var a = P.accent, c = rgb(a);
        var css = ':root{--nv-accent:' + a + ';--nv-accent-rgb:' + c.join(',') + ';--nv-accent-hover:' + shade(a, -12) +
            ';--nv-accent-dark:' + shade(a, -55) + ';--nv-accent-soft:' + shade(a, 88) + ';--nv-on-accent:' + onAccent(a) + ';}';
        $('#nv-accent-vars').text(css);
        $(document).trigger('nuvis:theme', [P, root.getAttribute('data-nv-eff')]);
    }
    if (mq && mq.addEventListener) { mq.addEventListener('change', function () { if (P.mode === 'auto') { apply(); } }); }

    /* ---------- saving ---------- */
    function say(t) { $('#nvPersSaved').text(t); }
    function save(changes, immediate) {
        $.extend(P, changes);
        apply();
        say('Saving…');
        clearTimeout(saveTimer);
        saveTimer = setTimeout(function () {
            $.post(_base_url_ + 'classes/UiPrefs.php?f=save', { mode: P.mode, accent: P.accent, sidebar: P.sidebar, density: P.density,
                text: P.text, collapsed: P.collapsed ? 1 : 0, pins: P.pins.length ? P.pins : [''] }, null, 'json')
                .done(function (r) { say(r && r.status === 'success' ? 'Saved to your account' : (r && r.msg) || 'Could not save'); })
                .fail(function () { say('Could not save – check your connection'); });
        }, immediate ? 0 : 350);
    }

    /* ---------- the panel ---------- */
    function syncPanel() {
        $('#nvPersonalise [data-pref]').each(function () {
            var $i = $(this), k = $i.data('pref');
            if ($i.is(':radio')) { $i.prop('checked', String(P[k]) === $i.val()); }
            else if ($i.is(':checkbox')) { $i.prop('checked', !!P[k]); }
        });
        $('#nvSwatches .nv-swatch').each(function () { $(this).toggleClass('is-on', $(this).data('accent') === P.accent); });
        $('#nvAccentCustom').val(P.accent);
        renderPinList();
    }
    function renderPinList() {
        var $ul = $('#nvPersPins').empty();
        P.pins.forEach(function (k) {
            var $a = linkFor(k);
            if (!$a.length) { return; }
            var $li = $('<li>').append($('<span>').text(linkTitle($a)));
            $('<button type="button" class="btn btn-link btn-sm text-danger p-0" aria-label="Unpin">').text('Unpin').on('click', function () { togglePin(k); }).appendTo($li);
            $ul.append($li);
        });
        $('#nvPersPinHint').toggle(!$ul.children().length);
    }

    /* ---------- side menu ---------- */
    var SKIP = { 'nav-link': 1, 'nav-icon': 1, 'nav-item': 1, 'nav-is-tree': 1, 'nav-pin': 1, 'nav-treeview': 1, 'nav-home': 0 };
    function keyOf($a) {
        var cls = ($a.attr('class') || '').split(/\s+/);
        for (var i = 0; i < cls.length; i++) { if (/^nav-[a-z0-9_]+$/i.test(cls[i]) && !SKIP[cls[i]]) { return cls[i].substr(4); } }
        return null;
    }
    function mainMenu() { return $('.main-sidebar .nav-sidebar').not('#nvPinnedList'); }
    function linkFor(key) { return mainMenu().find('a.nav-link.nav-' + key).first(); }
    function linkTitle($a) { return $.trim($a.find('p').first().clone().children().remove().end().text() || $a.text()); }

    function decoratePins() {
        mainMenu().find('a.nav-link.tree-item').each(function () {
            var $a = $(this), k = keyOf($a);
            if (!k || $a.children('.nv-pin-btn').length) { return; }
            $('<span class="nv-pin-btn" role="button" tabindex="0"><i class="fas fa-star"></i></span>').attr({ 'data-key': k, title: 'Pin to top' }).appendTo($a);
        });
        refreshPinState();
    }
    function refreshPinState() {
        mainMenu().find('.nv-pin-btn').each(function () {
            var on = P.pins.indexOf($(this).data('key')) >= 0;
            $(this).toggleClass('is-pinned', on).attr({ title: on ? 'Unpin' : 'Pin to top', 'aria-pressed': on ? 'true' : 'false', 'aria-label': (on ? 'Unpin ' : 'Pin ') + linkTitle($(this).closest('a')) });
        });
    }
    function renderPinned() {
        var $ul = $('#nvPinnedList').empty(), shown = 0;
        P.pins = P.pins.filter(function (k) { return linkFor(k).length > 0; }); // drop items this user can no longer see
        P.pins.forEach(function (k) {
            var $src = linkFor(k);
            var $a = $('<a class="nav-link">').attr('href', $src.attr('href'));
            $a.append($src.find('.nav-icon').first().clone()).append($('<p>').text(linkTitle($src)));
            if ($src.hasClass('active')) { $a.addClass('active'); }
            $('<span class="nv-pin-btn is-pinned" role="button" tabindex="0" title="Unpin"><i class="fas fa-star"></i></span>').attr('data-key', k).appendTo($a);
            $('<li class="nav-item">').append($a).appendTo($ul);
            shown++;
        });
        $('#nvPinned').toggleClass('is-empty', !shown);
        refreshPinState();
    }
    function togglePin(key) {
        var i = P.pins.indexOf(key);
        if (i >= 0) { P.pins.splice(i, 1); }
        else {
            if (P.pins.length >= MAX_PINS) { if (window.alert_toast) { alert_toast('You can pin up to ' + MAX_PINS + ' items. Unpin one first.', 'warning'); } return; }
            P.pins.push(key);
        }
        renderPinned();
        renderPinList();
        save({ pins: P.pins });
    }
    $(document).on('click keydown', '.nv-pin-btn', function (e) {
        if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') { return; }
        e.preventDefault(); e.stopPropagation();
        togglePin(String($(this).data('key')));
    });

    /* search */
    function filterMenu(term) {
        term = $.trim(term).toLowerCase();
        var $aside = $('.main-sidebar');
        $('#nvSideSearch').toggleClass('has-value', term.length > 0);
        var $items = mainMenu().find('li.nav-item').removeClass('nv-hide nv-match');
        if (!term) { $aside.removeClass('nv-side-filtering'); $('#nvSideEmpty').removeClass('show'); return; }
        $aside.addClass('nv-side-filtering');
        var any = false;
        mainMenu().children('li.nav-item').each(function () {
            var $top = $(this), topText = linkTitle($top.children('a')).toLowerCase();
            if ($top.hasClass('has-treeview')) {
                var groupHit = topText.indexOf(term) >= 0, kidHit = false;
                $top.find('.nav-treeview > li.nav-item').each(function () {
                    var hit = groupHit || linkTitle($(this).children('a')).toLowerCase().indexOf(term) >= 0;
                    $(this).toggleClass('nv-hide', !hit);
                    kidHit = kidHit || hit;
                });
                $top.toggleClass('nv-hide', !kidHit).toggleClass('nv-match', kidHit);
                any = any || kidHit;
            } else {
                var hit = topText.indexOf(term) >= 0;
                $top.toggleClass('nv-hide', !hit);
                any = any || hit;
            }
        });
        $('#nvSideEmpty').toggleClass('show', !any);
    }
    $(document).on('input', '#nvSideSearchInput', function () { filterMenu($(this).val()); });
    $(document).on('click', '#nvSideClear', function () { $('#nvSideSearchInput').val('').focus(); filterMenu(''); });
    $(document).on('keydown', '#nvSideSearchInput', function (e) {
        if (e.key === 'Escape') { $(this).val('').blur(); filterMenu(''); }
        if (e.key === 'Enter') {
            var $first = mainMenu().find('li.nav-item:not(.nv-hide) a.nav-link[href]:not([href="#"])').first();
            if ($first.length) { window.location.href = $first.attr('href'); }
        }
    });
    $(document).on('keydown', function (e) {
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) { return; }
        var t = e.target, tag = (t.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || t.isContentEditable) { return; }
        e.preventDefault();
        if ($('body').hasClass('sidebar-collapse') || $(window).width() < 992) { $('[data-widget="pushmenu"]').first().trigger('click'); }
        setTimeout(function () { $('#nvSideSearchInput').focus(); }, 50);
    });

    /* remember which groups were open on this device */
    var OPEN_KEY = 'nv-nav-open-' + (window.NUVIS_UID || 0);
    function groupName($li) { return linkTitle($li.children('a')); }
    function restoreGroups() {
        var saved = [];
        try { saved = JSON.parse(window.localStorage.getItem(OPEN_KEY) || '[]'); } catch (e) { return; }
        var accordion = mainMenu().attr('data-accordion') === 'true';
        if (accordion && mainMenu().find('li.has-treeview.menu-open').length) { return; } // the current page's group wins
        mainMenu().children('li.has-treeview').each(function () {
            if (saved.indexOf(groupName($(this))) >= 0) { $(this).addClass('menu-open'); if (accordion) { return false; } }
        });
    }
    function watchGroups() {
        if (!window.MutationObserver) { return; }
        var t = null;
        var mo = new MutationObserver(function () {
            clearTimeout(t);
            t = setTimeout(function () {
                var open = mainMenu().children('li.has-treeview.menu-open').map(function () { return groupName($(this)); }).get();
                try { window.localStorage.setItem(OPEN_KEY, JSON.stringify(open)); } catch (e) { /* private mode */ }
            }, 250);
        });
        mainMenu().children('li.has-treeview').each(function () { mo.observe(this, { attributes: true, attributeFilter: ['class'] }); });
    }

    /* ---------- init ---------- */
    $(function () {
        // panel controls
        $(document).on('change', '#nvPersonalise [data-pref]', function () {
            var $i = $(this), k = $i.data('pref'), ch = {};
            ch[k] = $i.is(':checkbox') ? $i.prop('checked') : $i.val();
            save(ch);
            if (k === 'collapsed' && $(window).width() >= 992) { $('body').toggleClass('sidebar-collapse', !!ch.collapsed); }
            syncPanel();
        });
        $(document).on('click', '#nvSwatches .nv-swatch', function () { save({ accent: String($(this).data('accent')) }); syncPanel(); });
        $(document).on('input', '#nvAccentCustom', function () { if (/^#[0-9a-f]{6}$/i.test(this.value)) { save({ accent: this.value.toLowerCase() }); $('#nvSwatches .nv-swatch').removeClass('is-on'); } });
        $(document).on('click', '#nvPersReset', function () {
            $.post(_base_url_ + 'classes/UiPrefs.php?f=reset', {}, null, 'json').always(function () {
                P = $.extend({}, DEFAULTS, { pins: [] });
                apply(); renderPinned(); syncPanel(); say('Back to defaults');
                if ($(window).width() >= 992) { $('body').removeClass('sidebar-collapse'); }
            });
        });
        $(document).on('click', '[data-nv-open="personalise"]', function (e) { e.preventDefault(); syncPanel(); $('#nvPersonalise').modal('show'); });

        // side menu
        decoratePins();
        renderPinned();
        restoreGroups();
        watchGroups();
        var $act = mainMenu().find('a.nav-link.active').last();
        if ($act.length && $act[0].scrollIntoView) { try { $act[0].scrollIntoView({ block: 'nearest' }); } catch (e) { /* old browser */ } }
        apply();
    });
})(jQuery);
