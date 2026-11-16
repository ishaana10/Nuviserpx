<?php
/**
 * Shared by the purchase pages: one search-and-add combo box for vendors and one for items.
 *
 * Typing a name that does not exist offers to add it; the new vendor or item is saved through the normal
 * save_party / save_product actions (always for the signed-in company) and appears in every combo on the page
 * straight away, with no reload. Include this before the page's own script, then call:
 *   NvQuick.vendor($('#vendor_id'))      for a vendor picker
 *   NvQuick.item($('select.nv-item-select'))   for each line's item picker
 *
 * Needs $conn, $_settings and $tenant_id from the including page.
 */
$qa_tenant = (int)$_settings->active_tenant_id();
$qa_can_vendor = $_settings->can_access('parties_save');
$qa_can_item = $_settings->can_access('products_save');
$qa_items = [];
$qa_q = $conn->query("SELECT id, product_code, name, description, cost_price FROM `product_list` WHERE tenant_id = {$qa_tenant} AND delete_flag = 0 AND status = 1 ORDER BY name ASC");
while ($qa_q && ($qa_p = $qa_q->fetch_assoc())) {
    $qa_items[] = [
        'id'    => (string)(int)$qa_p['id'],
        'text'  => $qa_p['product_code'] . ' - ' . $qa_p['name'],
        'price' => number_format((float)$qa_p['cost_price'], 2, '.', ''),
        'name'  => $qa_p['name'],
        'desc'  => (string)($qa_p['description'] ?? ''),
    ];
}
?>
<?php if ($qa_can_vendor): ?>
<div class="modal fade" id="nv_modal_vendor" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header py-2"><h5 class="modal-title font-weight-bold">New vendor</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
      <form id="nv_form_vendor" autocomplete="off">
        <input type="hidden" name="type" value="vendor">
        <input type="hidden" name="status" value="1">
        <div class="modal-body">
          <div class="form-group"><label>Name <span class="text-danger">*</span></label><input type="text" name="name" id="nv_vendor_name" class="form-control form-control-sm" required></div>
          <div class="row">
            <div class="col-6 form-group"><label>Phone</label><input type="text" name="phone" class="form-control form-control-sm"></div>
            <div class="col-6 form-group"><label>Email</label><input type="email" name="email" class="form-control form-control-sm"></div>
          </div>
          <div class="row">
            <div class="col-6 form-group"><label>TIN</label><input type="text" name="tin" class="form-control form-control-sm"></div>
            <div class="col-6 form-group"><label>Code <small class="text-muted">(automatic if blank)</small></label><input type="text" name="party_code" class="form-control form-control-sm" placeholder="VEN-0001"></div>
          </div>
          <div class="form-group mb-0"><label>Address</label><textarea name="address" rows="2" class="form-control form-control-sm"></textarea></div>
        </div>
        <div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-default" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-primary" id="nv_vendor_save"><i class="fa fa-save"></i> Save vendor</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<?php if ($qa_can_item): ?>
<div class="modal fade" id="nv_modal_item" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header py-2"><h5 class="modal-title font-weight-bold">New item</h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
      <form id="nv_form_item" autocomplete="off">
        <input type="hidden" name="status" value="1">
        <div class="modal-body">
          <div class="form-group"><label>Item name <span class="text-danger">*</span></label><input type="text" name="name" id="nv_item_name" class="form-control form-control-sm" required></div>
          <div class="row">
            <div class="col-6 form-group"><label>Code <small class="text-muted">(automatic if blank)</small></label><input type="text" name="product_code" class="form-control form-control-sm" placeholder="ITEM-0001"></div>
            <div class="col-6 form-group"><label>Unit</label><input type="text" name="unit" class="form-control form-control-sm" value="pcs"></div>
          </div>
          <div class="row">
            <div class="col-6 form-group"><label>Unit cost</label><input type="number" step="any" min="0" name="cost_price" id="nv_item_cost" class="form-control form-control-sm" value="0.00"></div>
            <div class="col-6 form-group"><label>Selling price</label><input type="number" step="any" min="0" name="selling_price" class="form-control form-control-sm" value="0.00"></div>
          </div>
          <div class="form-group mb-0"><label>Description</label><textarea name="description" id="nv_item_desc" rows="2" class="form-control form-control-sm"></textarea></div>
        </div>
        <div class="modal-footer py-2"><button type="button" class="btn btn-sm btn-default" data-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm btn-primary" id="nv_item_save"><i class="fa fa-save"></i> Save item</button></div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>
<script>
window.NvQuick = (function () {
    var canVendor = <?= $qa_can_vendor ? 'true' : 'false' ?>;
    var canItem = <?= $qa_can_item ? 'true' : 'false' ?>;
    var items = <?= json_encode($qa_items, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var NEW_V = '__new_vendor__', NEW_I = '__new_item__';
    var vendorCallback = null, itemRow = null;

    function plus(text) {
        return $('<span>').append($('<i class="fas fa-plus-circle mr-1 text-primary">')).append(document.createTextNode(text));
    }
    function hasOption($sel, value) {
        return $sel.find('option').filter(function () { return String(this.value) === String(value); }).length > 0;
    }
    /** Add an option to a combo, keeping the "+ Add/Create new…" entry last so Enter on an empty search never opens it. */
    function addOption($sel, $opt, marker) {
        var m = $sel.find('option').filter(function () { return String(this.value) === marker; });
        if (m.length) { $opt.insertBefore(m.first()); } else { $sel.append($opt); }
    }
    function post(action, data, done) {
        $.ajax({ url: _base_url_ + 'classes/Master.php?f=' + action, method: 'POST', data: data, dataType: 'json',
            error: function () { alert_toast('An error occurred.', 'error'); done(null); },
            success: function (r) { done(r); } });
    }

    /* ---------------- vendor combo ---------------- */
    function vendor($sel) {
        $sel.addClass('nv-vendor-select').data('prev', $sel.val() || '');
        if (canVendor && !hasOption($sel, NEW_V)) {
            $sel.append($('<option>').val(NEW_V).text('+ Add new vendor…'));
        }
        $sel.select2({
            width: '100%',
            placeholder: canVendor ? 'Search, or type a name to add a new vendor…' : 'Select vendor',
            tags: canVendor,
            createTag: function (p) {
                var t = $.trim(p.term);
                return t ? { id: NEW_V + ':' + t, text: t, nv_new: true } : null;
            },
            templateResult: function (d) {
                if (d.nv_new) { return plus('Add "' + d.text + '" as a new vendor'); }
                if (d.id === NEW_V) { return plus('Add new vendor…'); }
                return d.text;
            }
        });
        $sel.on('change', function () {
            var v = String($sel.val() || '');
            if (v.indexOf(NEW_V) !== 0) { $sel.data('prev', v); }
        });
        $sel.on('select2:select', function (e) {
            var id = String(e.params.data.id);
            if (id.indexOf(NEW_V) !== 0) { return; }
            var name = id.indexOf(':') > -1 ? id.slice(id.indexOf(':') + 1) : '';
            $sel.find('option').filter(function () { return String(this.value).indexOf(NEW_V + ':') === 0; }).remove();
            $sel.val($sel.data('prev') || null).trigger('change');
            openVendor(name, function (v) {
                $('.nv-vendor-select').each(function () {
                    var s = $(this);
                    if (!hasOption(s, v.id)) { addOption(s, $('<option>').val(v.id).text(v.label), NEW_V); }
                });
                $sel.val(String(v.id)).trigger('change');
            });
        });
    }
    function openVendor(name, cb) {
        if (!canVendor) { return; }
        vendorCallback = cb;
        $('#nv_form_vendor')[0].reset();
        $('#nv_vendor_name').val(name || '');
        $('#nv_modal_vendor').modal('show');
    }

    /* ---------------- item combo (one box: search, pick, or type) ---------------- */
    function mkOption(it) {
        return $('<option>').val(it.id).text(it.text).attr({ 'data-price': it.price, 'data-name': it.name, 'data-desc': it.desc });
    }
    function item($sels) {
        $sels.each(function () {
            var $sel = $(this);
            if ($sel.hasClass('select2-hidden-accessible')) { return; }
            var tr = $sel.closest('tr'), nameInp = tr.find('.item-name');
            $sel.addClass('nv-item-select');
            if (!$sel.find('option[value=""]').length) { $sel.prepend('<option value=""></option>'); }
            items.forEach(function (it) { if (!hasOption($sel, it.id)) { $sel.append(mkOption(it)); } });
            if (canItem && !hasOption($sel, NEW_I)) {
                $sel.append($('<option>').val(NEW_I).text('+ Create new item…'));
            }
            var nm = $.trim(nameInp.val());
            if (!$sel.val() && nm) { $sel.append(new Option(nm, 'custom:' + nm, true, true)); }
            nameInp.addClass('d-none').prop('required', false);   // kept in the form, filled from the combo
            $sel.data('prev', $sel.val() || '');
            $sel.select2({
                width: '100%',
                tags: true,
                placeholder: 'Search items, or type to use a one-off item…',
                createTag: function (p) {
                    var t = $.trim(p.term);
                    return t ? { id: 'custom:' + t, text: t, nv_custom: true } : null;
                },
                templateResult: function (d) {
                    if (d.nv_custom) { return plus('Use "' + d.text + '" as a one-off item'); }
                    if (d.id === NEW_I) { return plus('Create new item…'); }
                    return d.text;
                }
            });
        });
    }
    $(document).on('change', '.nv-item-select', function () {
        var $s = $(this), v = String($s.val() || ''), row = $s.closest('tr');
        if (v === NEW_I) {
            $s.val($s.data('prev') || null).trigger('change.select2');
            itemRow = row;
            openItem('');
            return;
        }
        $s.data('prev', v);
        var o = $s.find(':selected');
        if (v.indexOf('custom:') === 0) {
            row.find('.item-name').val(o.text().trim());
            row.find('.item-desc').val('');
        } else if (v !== '') {
            row.find('.item-name').val(o.attr('data-name') || o.text());
            row.find('.item-desc').val(o.attr('data-desc') || '');
            if (o.attr('data-price') !== undefined) { row.find('.price').val(parseFloat(o.attr('data-price') || 0).toFixed(2)); }
        } else {
            row.find('.item-name').val('');
        }
        row.find('.price').trigger('input');   // pages recalculate their totals on this
    });
    function openItem(name) {
        if (!canItem) { return; }
        $('#nv_form_item')[0].reset();
        $('#nv_item_name').val(name || '');
        $('#nv_modal_item').modal('show');
    }

    $(function () {
        $('#nv_form_vendor').on('submit', function (e) {
            e.preventDefault();
            var btn = $('#nv_vendor_save').prop('disabled', true);
            post('save_party', $(this).serialize(), function (r) {
                btn.prop('disabled', false);
                if (r && r.status === 'success' && r.id) {
                    $('#nv_modal_vendor').modal('hide');
                    alert_toast('Vendor added.', 'success');
                    if (vendorCallback) { vendorCallback({ id: r.id, label: r.party_code + ' - ' + r.name + ' (Vendor)' }); }
                } else if (r) { alert_toast(r.msg || 'Could not save the vendor.', 'error'); }
            });
        });
        $('#nv_form_item').on('submit', function (e) {
            e.preventDefault();
            var btn = $('#nv_item_save').prop('disabled', true);
            var cost = parseFloat($('#nv_item_cost').val() || 0) || 0, desc = $('#nv_item_desc').val();
            post('save_product', $(this).serialize(), function (r) {
                btn.prop('disabled', false);
                if (r && r.status === 'success' && r.id) {
                    $('#nv_modal_item').modal('hide');
                    alert_toast('Item added.', 'success');
                    var it = { id: String(r.id), text: r.product_code + ' - ' + r.name, price: cost.toFixed(2), name: r.name, desc: desc };
                    items.push(it);
                    $('.nv-item-select').each(function () { if (!hasOption($(this), it.id)) { addOption($(this), mkOption(it), NEW_I); } });
                    if (itemRow) { itemRow.find('.nv-item-select').val(it.id).trigger('change'); }
                } else if (r) { alert_toast(r.msg || 'Could not save the item.', 'error'); }
            });
        });
    });

    return { vendor: vendor, item: item };
})();
</script>
