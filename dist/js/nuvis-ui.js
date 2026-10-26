/*!
 * Nuvis ERPX — UI layer for tables, edit forms and modals.
 *
 * Loaded once in admin/inc/footer.php after DataTables, Buttons and Select2.
 * Everything here is progressive: pages keep calling $('#list').dataTable({...})
 * and uni_modal(...) exactly as before and pick up the improvements automatically.
 *
 *  Tables  – toolbar (search, column picker, export, density), remembered
 *            page length / sort / visible columns per page, kebab row menus,
 *            double-click a row to edit, friendlier empty states.
 *  Forms   – required markers, inline validation before AJAX submit,
 *            saving state on the Save button, Ctrl/⌘+S to save,
 *            unsaved-changes guard on modal close, Select2 for long lists.
 */
(function ($, window, document) {
    'use strict';

    if (!$) { return; }

    var NV = window.NuvisUI = window.NuvisUI || {};
    var isMac = /Mac|iPhone|iPad/.test(navigator.platform || '');

    /* ------------------------------------------------------------------ *
     * Small helpers
     * ------------------------------------------------------------------ */
    var store = {
        get: function (key, fallback) {
            try {
                var raw = window.localStorage.getItem('nv:' + key);
                return raw === null ? fallback : JSON.parse(raw);
            } catch (e) { return fallback; }
        },
        set: function (key, value) {
            try { window.localStorage.setItem('nv:' + key, JSON.stringify(value)); } catch (e) { /* storage blocked */ }
        }
    };
    NV.store = store;

    function currentPage() {
        var m = /[?&]page=([^&#]*)/.exec(window.location.search);
        return m ? decodeURIComponent(m[1]) : 'home';
    }

    function isUtilityHeader(th) {
        var $th = $(th);
        if ($th.hasClass('nv-noexport') || $th.find('input[type=checkbox]').length) { return true; }
        var t = $.trim($th.text()).toLowerCase();
        return t === 'action' || t === 'actions' || t === '';
    }

    function isIndexHeader(th) {
        return $.trim($(th).text()) === '#';
    }

    /* ------------------------------------------------------------------ *
     * DataTables defaults
     * ------------------------------------------------------------------ */
    function density() { return store.get('table-density', 'comfortable'); }

    function applyDensity() {
        var compact = density() === 'compact';
        $('.dataTables_wrapper').toggleClass('nv-density-compact', compact);
        $('.nv-density-btn').toggleClass('active', compact)
            .attr('title', compact ? 'Switch to comfortable rows' : 'Switch to compact rows');
    }

    function setupDataTables() {
        if (!$.fn.dataTable) { return; }

        var DT = $.fn.dataTable;
        var hasButtons = !!DT.Buttons;

        if (hasButtons) {
            // Bootstrap 4 integration defaults every button to btn-secondary.
            DT.Buttons.defaults.dom.button.className = 'btn btn-default btn-sm';
        }

        var exportColumns = function (idx, data, node) {
            // Hidden columns are detached from the DOM; skip them and utility columns.
            return node && node.isConnected !== false && !isUtilityHeader(node);
        };

        var buttons = [];
        if (hasButtons) {
            if (DT.ext.buttons.colvis) {
                buttons.push({
                    extend: 'colvis',
                    text: '<i class="fas fa-columns"></i><span class="nv-btn-label">Columns</span>',
                    titleAttr: 'Show or hide columns',
                    columns: function (idx, data, node) { return !isUtilityHeader(node) && !isIndexHeader(node); }
                });
            }

            var exportButtons = [];
            if (DT.ext.buttons.csvHtml5) {
                exportButtons.push({ extend: 'csvHtml5', text: '<i class="fas fa-file-csv text-success mr-2"></i>CSV', exportOptions: { columns: exportColumns } });
            }
            if (DT.ext.buttons.excelHtml5 && window.JSZip) {
                exportButtons.push({ extend: 'excelHtml5', text: '<i class="fas fa-file-excel text-success mr-2"></i>Excel', exportOptions: { columns: exportColumns } });
            }
            if (DT.ext.buttons.print) {
                exportButtons.push({ extend: 'print', text: '<i class="fas fa-print text-primary mr-2"></i>Print', exportOptions: { columns: exportColumns } });
            }
            if (exportButtons.length) {
                buttons.push({
                    extend: 'collection',
                    text: '<i class="fas fa-download"></i><span class="nv-btn-label">Export</span>',
                    titleAttr: 'Export the rows that match the current search',
                    autoClose: true,
                    buttons: exportButtons
                });
            }

            buttons.push({
                text: '<i class="fas fa-grip-lines"></i>',
                className: 'nv-density-btn',
                titleAttr: 'Toggle row density',
                action: function () {
                    store.set('table-density', density() === 'compact' ? 'comfortable' : 'compact');
                    applyDensity();
                }
            });
        }

        var dom =
            "<'nv-dt-toolbar'<'nv-dt-left'f><'nv-dt-right'" + (hasButtons ? 'B' : '') + "l>>" +
            "<'nv-table-scroll'tr>" +
            "<'nv-dt-footer'ip>";

        $.extend(true, DT.defaults, {
            dom: dom,
            autoWidth: false,
            pageLength: 25,
            lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
            buttons: buttons,
            // Remember page length, sort order and visible columns per page + table,
            // but never a stale search term.
            stateSave: true,
            stateDuration: 60 * 60 * 24 * 30,
            stateSaveCallback: function (settings, data) {
                store.set(stateKey(settings), data);
            },
            stateLoadCallback: function (settings) {
                return store.get(stateKey(settings), null);
            },
            stateSaveParams: function (settings, data) {
                if (data.search) { data.search.search = ''; }
                $.each(data.columns || [], function (_, c) { if (c.search) { c.search.search = ''; } });
                data.start = 0;
            },
            language: {
                search: '',
                searchPlaceholder: 'Search…',
                lengthMenu: '_MENU_ per page',
                info: 'Showing <b>_START_–_END_</b> of <b>_TOTAL_</b>',
                infoEmpty: 'No records',
                infoFiltered: '(filtered from _MAX_)',
                zeroRecords:
                    '<div class="nv-empty"><div class="nv-empty-icon"><i class="fas fa-search"></i></div>' +
                    '<div class="nv-empty-title">No matching records</div>' +
                    '<div class="nv-empty-sub">Try a different search term or clear the filter.</div></div>',
                emptyTable:
                    '<div class="nv-empty"><div class="nv-empty-icon"><i class="far fa-folder-open"></i></div>' +
                    '<div class="nv-empty-title">Nothing here yet</div>' +
                    '<div class="nv-empty-sub">Records you create will appear in this list.</div></div>',
                paginate: {
                    previous: '<i class="fas fa-chevron-left" aria-label="Previous"></i>',
                    next: '<i class="fas fa-chevron-right" aria-label="Next"></i>'
                },
                buttons: {
                    colvis: 'Columns'
                }
            }
        });

        function stateKey(settings) {
            return 'dt:' + currentPage() + ':' + (settings.sTableId || $(settings.nTable).index('table'));
        }

        $(document).on('init.dt', function (e, settings) {
            if (e.namespace !== 'dt') { return; }
            enhanceTable(new DT.Api(settings));
        });
        $(document).on('draw.dt', function (e, settings) {
            if (e.namespace !== 'dt') { return; }
            enhanceRows(new DT.Api(settings));
        });
    }

    function enhanceTable(api) {
        var $table = $(api.table().node());
        if ($table.data('nvEnhanced')) { enhanceRows(api); return; }
        $table.data('nvEnhanced', true).addClass('nv-table');

        // Utility columns: not sortable via colvis/export and right-aligned menus.
        $(api.table().header()).find('th').each(function () {
            if (isUtilityHeader(this)) { $(this).addClass('nv-noexport'); }
        });

        enhanceRows(api);
        applyDensity();

        // Double-click a row to open its edit form (if it has one).
        $table.on('dblclick', 'tbody tr', function (ev) {
            if ($(ev.target).closest('a, button, input, select, textarea, label, .dropdown-menu').length) { return; }
            var $edit = $(this).find('.edit_data').first();
            if ($edit.length) { $edit.trigger('click'); return; }
            var $link = $(this).find('a.dropdown-item[href*="manage_"], a.dropdown-item[href*="view"]').first();
            if ($link.length && $link.attr('href') && $link.attr('href').indexOf('javascript') !== 0) {
                window.location.href = $link.attr('href');
            }
        });
    }

    function enhanceRows(api) {
        var nodes = api.rows().nodes();
        $(nodes).find('.dropdown-toggle').each(function () {
            var $btn = $(this);
            if ($btn.data('nvMenu')) { return; }
            $btn.data('nvMenu', true);
            if (/^\s*actions?\s*$/i.test($btn.clone().children('.sr-only').remove().end().text())) {
                $btn.addClass('nv-row-menu')
                    .removeClass('btn-flat dropdown-icon p-1')
                    .attr({ 'aria-label': 'Row actions', title: 'Actions' })
                    .html('<i class="fas fa-ellipsis-h"></i>');
            }
            $btn.siblings('.dropdown-menu').addClass('dropdown-menu-right');
        });
        $(nodes).each(function () {
            if ($(this).find('.edit_data, a.dropdown-item[href*="manage_"]').length) {
                $(this).addClass('nv-row-editable').attr('title', 'Double-click to edit');
            }
        });
    }

    NV.applyDensity = applyDensity;

    /* ------------------------------------------------------------------ *
     * Forms
     * ------------------------------------------------------------------ */
    function labelFor($form, el) {
        var $l = el.id ? $form.find('label[for="' + el.id.replace(/"/g, '') + '"]') : $();
        if (!$l.length) { $l = $(el).closest('.form-group').find('label').first(); }
        return $l;
    }

    NV.enhanceForm = function (root, opts) {
        opts = opts || {};
        $(root).find('form').addBack('form').each(function () {
            var form = this;
            var $form = $(form);
            if ($form.data('nvForm')) { return; }
            $form.data('nvForm', true).addClass('nv-form');

            $form.find('[required]').each(function () {
                labelFor($form, this).addClass('nv-required');
            });

            if (opts.select2 && $.fn.select2) {
                var $parent = $form.closest('.modal');
                $form.find('select').each(function () {
                    var $s = $(this);
                    if ($s.hasClass('select2-hidden-accessible') || $s.is('[data-no-select2]') ||
                        $s.closest('table').length || this.options.length <= 10) { return; }
                    $s.select2({
                        theme: 'bootstrap4',
                        width: '100%',
                        dropdownParent: $parent.length ? $parent : $(document.body)
                    });
                });
            }

            $form.on('input change', '.is-invalid', function () {
                $(this).removeClass('is-invalid');
                $(this).closest('.form-group, [class*="col-"]').find('.nv-invalid-msg').first().remove();
            });

            snapshot($form);
        });
    };

    function snapshot($form) {
        $form.data('nvSnapshot', $form.serialize());
    }

    function isDirty($form) {
        var snap = $form.data('nvSnapshot');
        return typeof snap === 'string' && snap !== $form.serialize();
    }

    function elementIsShown(el) {
        var $el = $(el);
        if ($el.hasClass('select2-hidden-accessible')) { return $el.next('.select2').is(':visible'); }
        if (el.type === 'hidden') { return false; }
        return $el.is(':visible');
    }

    /**
     * Run HTML5 constraint validation on the visible fields of a form and render
     * inline messages. jQuery's .submit() skips native validation, so modal Save
     * buttons call this first.
     */
    NV.validateForm = function (form) {
        var $form = $(form);
        var firstBad = null;
        $form.find('.nv-invalid-msg').remove();
        $form.find('.is-invalid').removeClass('is-invalid');

        $.each(form.elements || [], function (_, el) {
            if (!el.willValidate || el.disabled || !elementIsShown(el)) { return; }
            if (el.checkValidity()) { return; }
            var $el = $(el).addClass('is-invalid');
            var $anchor = $el.hasClass('select2-hidden-accessible') ? $el.next('.select2')
                : ($el.parent('.input-group').length ? $el.parent() : $el);
            $('<span class="nv-invalid-msg"></span>').text(el.validationMessage || 'Please check this field.').insertAfter($anchor);
            if (!firstBad) { firstBad = el; }
        });

        if (firstBad) {
            var $modal = $form.closest('.modal');
            var $content = $modal.find('.modal-content');
            $content.removeClass('nv-shake');
            void ($content[0] && $content[0].offsetWidth);
            $content.addClass('nv-shake');
            if (firstBad.scrollIntoView) { firstBad.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
            setTimeout(function () {
                if ($(firstBad).hasClass('select2-hidden-accessible')) { $(firstBad).select2('open'); } else { firstBad.focus(); }
            }, 150);
            return false;
        }
        return true;
    };

    NV.setSaving = function ($btn, saving) {
        $btn = $($btn);
        if (!$btn.length) { return; }
        if (saving) {
            if (!$btn.data('nvLabel')) { $btn.data('nvLabel', $btn.html()); }
            $btn.prop('disabled', true).html('<span class="nv-spinner"></span>Saving…');
            clearTimeout($btn.data('nvTimer'));
            $btn.data('nvTimer', setTimeout(function () { NV.setSaving($btn, false); }, 15000));
        } else if ($btn.data('nvLabel')) {
            clearTimeout($btn.data('nvTimer'));
            $btn.prop('disabled', false).html($btn.data('nvLabel'));
        }
    };

    /** Replacement for the modal Save button's old `$('#uni_modal form').submit()`. */
    NV.submitModal = function (modalSel) {
        var $modal = $(modalSel || '#uni_modal');
        var $forms = $modal.find('.modal-body form');
        if (!$forms.length) { return; }
        var ok = true;
        $forms.each(function () { ok = NV.validateForm(this) && ok; });
        if (!ok) { return; }
        $forms.data('nvSubmitting', true);
        NV.setSaving($modal.find('#submit'), true);
        $forms.submit();
    };

    function setupForms() {
        // Saving state ends as soon as the form's request finishes without reloading the page.
        $(document).ajaxComplete(function () {
            setTimeout(function () {
                var $btn = $('#uni_modal #submit');
                if ($btn.prop('disabled') && !$('#preloader').length) {
                    NV.setSaving($btn, false);
                    $('#uni_modal .modal-body form').data('nvSubmitting', false);
                }
            }, 400);
        });

        // Keyboard hint in the modal footer.
        var $footer = $('#uni_modal .modal-footer');
        if ($footer.find('#submit').length && !$footer.find('.nv-kbd-hint').length) {
            $footer.prepend('<span class="nv-kbd-hint d-none d-md-inline"><kbd>' + (isMac ? '⌘' : 'Ctrl') + '</kbd> + <kbd>S</kbd> to save</span>');
        }

        $(document).on('shown.bs.modal', '#uni_modal', function () {
            var $modal = $(this);
            $modal.find('.modal-body form').data('nvSubmitting', false);
            NV.setSaving($modal.find('#submit'), false);
            NV.enhanceForm($modal.find('.modal-body'), { select2: true });
            // Fields are often filled in by the form's own scripts right after load.
            setTimeout(function () {
                $modal.find('.modal-body form').each(function () { snapshot($(this)); });
                var $first = $modal.find('.modal-body form').find('input:visible, select:visible, textarea:visible')
                    .not('[readonly], [disabled], [type=hidden], [type=checkbox], [type=radio]').first();
                if ($first.length && !$first.val()) { $first.trigger('focus'); }
            }, 120);
        });

        // Guard against losing edits when the modal is closed.
        $(document).on('hide.bs.modal', '#uni_modal', function (e) {
            var $modal = $(this);
            if ($modal.data('nvForceClose')) { $modal.data('nvForceClose', false); return; }
            var $forms = $modal.find('.modal-body form');
            var dirty = false;
            $forms.each(function () { if (!$(this).data('nvSubmitting') && isDirty($(this))) { dirty = true; } });
            if (!dirty || !window.Swal) { return; }
            e.preventDefault();
            window.Swal.fire({
                title: 'Discard changes?',
                text: 'You have unsaved changes in this form.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Discard',
                cancelButtonText: 'Keep editing',
                confirmButtonColor: '#dc2626',
                reverseButtons: true,
                focusCancel: true
            }).then(function (result) {
                if (result.isConfirmed || result.value) {
                    $modal.data('nvForceClose', true).modal('hide');
                }
            });
        });

        // Ctrl/⌘ + S saves the open modal, or the page form that has focus.
        $(document).on('keydown', function (e) {
            if (!(e.ctrlKey || e.metaKey) || (e.key || '').toLowerCase() !== 's') { return; }
            if ($('#uni_modal').hasClass('show') && $('#uni_modal .modal-body form').length) {
                e.preventDefault();
                NV.submitModal('#uni_modal');
                return;
            }
            var form = document.activeElement && $(document.activeElement).closest('form')[0];
            if (form && $(form).find('[type=submit]').length) {
                e.preventDefault();
                if (form.requestSubmit) { form.requestSubmit(); } else { $(form).find('[type=submit]').first().trigger('click'); }
            }
        });

        // Full-page edit forms (invoices, journals, settings…): labels + required markers.
        NV.enhanceForm($('.content-wrapper'));
    }

    $(function () {
        setupForms();
        applyDensity();
    });

    // DataTables defaults must be in place before page scripts run their
    // $(document).ready initialisers, so this runs immediately.
    setupDataTables();

})(window.jQuery, window, document);
