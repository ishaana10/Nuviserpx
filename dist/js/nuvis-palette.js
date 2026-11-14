/* Nuvis ERPX quick jump: a search overlay opened from the top-bar icon or Ctrl/Cmd+K.
   It reads the sidebar menu and the "Create new" menu already on the page, which the server has filtered to
   what this user may open, so it can never list a page the user can't reach. No network calls. */
(function ($) {
    'use strict';
    var items = null, active = 0, shown = [];

    function titleOf($a) {
        var $c = $a.clone();
        $c.find('i, .badge, .right, small, kbd').remove();
        return $.trim($c.text().replace(/\s+/g, ' '));
    }

    function collect() {
        var seen = {}, out = [];
        $('.main-sidebar nav a.nav-link[href]').each(function () {
            var href = $(this).attr('href') || '';
            if (!href || href === '#' || href.indexOf('javascript') === 0 || seen[href]) { return; }
            var title = titleOf($(this));
            if (!title) { return; }
            var $grp = $(this).closest('li.has-treeview').children('a').first();
            var group = $grp.length && $grp[0] !== this ? titleOf($grp) : 'Menu';
            seen[href] = true;
            out.push({ title: title, group: group, href: href });
        });
        $('#createNewDropdown').siblings('.dropdown-menu').find('a.dropdown-item[href]').each(function () {
            var href = $(this).attr('href') || '';
            var title = titleOf($(this).find('span').first());
            if (!href || href === '#' || !title) { return; }
            out.push({ title: 'New ' + title.toLowerCase(), group: 'Create new', href: href });
        });
        return out;
    }

    function build() {
        if ($('#nvPalette').length) { return; }
        $('body').append(
            '<div id="nvPalette" class="nv-palette" hidden>' +
            '<div class="nv-palette-box" role="dialog" aria-modal="true" aria-label="Quick jump">' +
            '<div class="nv-palette-field"><i class="fas fa-search" aria-hidden="true"></i>' +
            '<input type="search" id="nvPaletteInput" placeholder="Jump to a page or create something…" autocomplete="off" aria-label="Quick jump"></div>' +
            '<ul class="nv-palette-list" id="nvPaletteList" role="listbox"></ul>' +
            '<div class="nv-palette-hint"><span>&uarr;&darr; move</span><span>Enter open</span><span>Esc close</span></div>' +
            '</div></div>');
    }

    function render(term) {
        term = $.trim(term).toLowerCase();
        var words = term ? term.split(/\s+/) : [];
        shown = $.grep(items, function (it) {
            var hay = (it.title + ' ' + it.group).toLowerCase();
            return words.every(function (w) { return hay.indexOf(w) >= 0; });
        }).slice(0, 30);
        active = 0;
        var $ul = $('#nvPaletteList').empty();
        if (!shown.length) { $ul.append($('<li class="nv-palette-empty">').text('Nothing matches.')); return; }
        $.each(shown, function (i, it) {
            $ul.append(
                $('<li class="nv-palette-item" role="option">').attr('data-i', i).toggleClass('is-active', i === 0)
                    .append($('<span class="nv-palette-title">').text(it.title))
                    .append($('<span class="nv-palette-group">').text(it.group)));
        });
    }

    function move(d) {
        if (!shown.length) { return; }
        active = (active + d + shown.length) % shown.length;
        var $li = $('#nvPaletteList .nv-palette-item').removeClass('is-active').eq(active).addClass('is-active');
        if ($li[0] && $li[0].scrollIntoView) { $li[0].scrollIntoView({ block: 'nearest' }); }
    }

    function go(i) {
        var it = shown[i];
        if (it) { window.location.href = it.href; }
    }

    function open() {
        build();
        items = collect();
        $('#nvPalette').prop('hidden', false);
        $('#nvPaletteInput').val('').focus();
        render('');
    }
    function close() { $('#nvPalette').prop('hidden', true); }

    $(document).on('click', '#nvPaletteBtn', function (e) { e.preventDefault(); open(); });
    $(document).on('input', '#nvPaletteInput', function () { render($(this).val()); });
    $(document).on('click', '.nv-palette-item', function () { go(+$(this).attr('data-i')); });
    $(document).on('click', '#nvPalette', function (e) { if (e.target === this) { close(); } });
    $(document).on('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key || '').toLowerCase() === 'k') { e.preventDefault(); open(); return; }
        if ($('#nvPalette').prop('hidden') !== false) { return; }
        if (e.key === 'Escape') { close(); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
        else if (e.key === 'Enter') { e.preventDefault(); go(active); }
    });
})(jQuery);
