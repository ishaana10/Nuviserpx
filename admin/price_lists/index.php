<?php
/** Price lists: customer-specific prices, with quantity breaks. */
require_once(__DIR__ . '/../../config.php');

$svc = new \App\Services\PriceListService($conn, $_settings);
$tenant_id = (int)$_settings->active_tenant_id();
$e = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$tag = $_settings->currency_tag();
$lists = $svc->lists();
$sel = (int)($_GET['id'] ?? 0);
$current = $sel ? $svc->find($sel) : null;

$products = [];
$pq = $conn->query("SELECT id, name, product_code, selling_price FROM product_list WHERE tenant_id = {$tenant_id} AND delete_flag = 0 ORDER BY name");
while ($pq && ($r = $pq->fetch_assoc())) { $products[] = $r; }
$parties = [];
$cq = $conn->query("SELECT id, name, price_list_id FROM party_list WHERE tenant_id = {$tenant_id} AND delete_flag = 0 ORDER BY name");
while ($cq && ($r = $cq->fetch_assoc())) { $parties[] = $r; }
?>
<div class="row">
  <div class="col-md-4">
    <div class="card card-outline card-primary shadow-sm rounded-0">
      <div class="card-header"><h3 class="card-title font-weight-bold"><i class="fas fa-tags text-primary mr-2"></i> Price lists</h3></div>
      <div class="card-body">
        <p class="text-muted text-sm">A customer on a list gets its prices filled in on their invoices. Products not on the list keep their normal selling price. You can still type a different price on the invoice.</p>
        <ul class="list-group mb-3">
          <?php foreach ($lists as $l): ?>
            <a class="list-group-item list-group-item-action d-flex justify-content-between <?= $sel === (int)$l['id'] ? 'active' : '' ?>" href="./?page=price_lists&id=<?= (int)$l['id'] ?>">
              <span><?= $e($l['name']) ?><?= $l['status'] ? '' : ' <small>(inactive)</small>' ?></span>
              <small><?= (int)$l['prices'] ?> prices · <?= (int)$l['customers'] ?> customers</small>
            </a>
          <?php endforeach; if (!$lists): ?><li class="list-group-item text-muted">No price lists yet.</li><?php endif; ?>
        </ul>
        <form id="new-list" class="form-inline">
          <input type="text" name="name" class="form-control form-control-sm mr-2" placeholder="New list, e.g. Wholesale" maxlength="100" required>
          <input type="hidden" name="status" value="1">
          <button class="btn btn-sm btn-primary" type="submit">Add</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-md-8">
  <?php if ($current): $items = $svc->items((int)$current['id']); $onList = $svc->customers((int)$current['id']); ?>
    <div class="card card-outline card-info shadow-sm rounded-0">
      <div class="card-header d-flex justify-content-between">
        <h3 class="card-title font-weight-bold"><?= $e($current['name']) ?></h3>
        <button class="btn btn-xs btn-outline-danger" id="del-list" data-id="<?= (int)$current['id'] ?>">Delete list</button>
      </div>
      <div class="card-body">
        <form id="list-form" class="form-inline mb-3">
          <input type="hidden" name="id" value="<?= (int)$current['id'] ?>">
          <input type="text" name="name" class="form-control form-control-sm mr-2" value="<?= $e($current['name']) ?>" maxlength="100" required>
          <label class="mr-2 text-sm"><input type="checkbox" name="status" value="1" <?= $current['status'] ? 'checked' : '' ?>> Active</label>
          <button class="btn btn-sm btn-outline-primary" type="submit">Save</button>
        </form>

        <h6 class="font-weight-bold">Prices</h6>
        <table class="table table-sm">
          <thead><tr><th>Product</th><th class="text-right">From qty</th><th class="text-right">Price</th><th class="text-right">Normal price</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($items as $i): ?>
            <tr>
              <td><?= $e($i['product_name']) ?> <small class="text-muted"><?= $e($i['product_code']) ?></small></td>
              <td class="text-right"><?= $e(rtrim(rtrim(number_format((float)$i['min_qty'], 4, '.', ''), '0'), '.')) ?></td>
              <td class="text-right font-weight-bold"><?= $tag . number_format((float)$i['price'], 2) ?></td>
              <td class="text-right text-muted"><?= $tag . number_format((float)$i['selling_price'], 2) ?></td>
              <td class="text-right"><button class="btn btn-xs btn-link text-danger del-item" data-id="<?= (int)$i['id'] ?>">&times;</button></td>
            </tr>
          <?php endforeach; if (!$items): ?><tr><td colspan="5" class="text-muted">No prices yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
        <form id="item-form" class="form-inline mb-4">
          <input type="hidden" name="price_list_id" value="<?= (int)$current['id'] ?>">
          <select name="product_id" class="form-control form-control-sm mr-2" required style="max-width:240px">
            <option value="">Product…</option>
            <?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>"><?= $e($p['name']) ?> (<?= $tag . number_format((float)$p['selling_price'], 2) ?>)</option><?php endforeach; ?>
          </select>
          <input type="number" name="min_qty" class="form-control form-control-sm mr-2" step="any" min="0" value="1" style="width:90px" title="Applies from this quantity">
          <input type="number" name="price" class="form-control form-control-sm mr-2" step="any" min="0" placeholder="Price" style="width:110px" required>
          <button class="btn btn-sm btn-primary" type="submit">Add price</button>
        </form>
        <p class="text-muted text-sm">Quantity breaks: add the same product again with a higher "from qty" and a lower price. The highest break the quantity reaches wins.</p>

        <h6 class="font-weight-bold">Customers on this list</h6>
        <form id="assign-form">
          <input type="hidden" name="id" value="<?= (int)$current['id'] ?>">
          <select name="party_ids[]" class="form-control select2" multiple style="width:100%">
            <?php foreach ($parties as $p): ?>
              <option value="<?= (int)$p['id'] ?>" <?= isset($onList[(int)$p['id']]) ? 'selected' : '' ?>><?= $e($p['name']) ?><?= $p['price_list_id'] && !isset($onList[(int)$p['id']]) ? ' (moves from another list)' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-sm btn-primary mt-2" type="submit">Save customers</button>
        </form>
      </div>
    </div>
  <?php else: ?>
    <div class="alert alert-info">Choose a price list, or add one.</div>
  <?php endif; ?>
  </div>
</div>
<script>
$(function () {
    var go = function (f, data, then) {
        start_loader();
        $.post(_base_url_ + 'classes/Master.php?f=' + f, data, function (r) {
            end_loader();
            if (r && r.status === 'success') { then ? then(r) : location.reload(); }
            else { alert_toast((r && r.msg) || 'Failed', 'error'); }
        }, 'json').fail(function () { end_loader(); alert_toast('An error occurred.', 'error'); });
    };
    $('.select2').select2();
    $('#new-list').on('submit', function (e) { e.preventDefault(); go('save_price_list', $(this).serialize(), function (r) { location.href = './?page=price_lists&id=' + r.id; }); });
    $('#list-form').on('submit', function (e) { e.preventDefault(); go('save_price_list', $(this).serialize()); });
    $('#item-form').on('submit', function (e) { e.preventDefault(); go('save_price_list_item', $(this).serialize()); });
    $('#assign-form').on('submit', function (e) { e.preventDefault(); go('assign_price_list', $(this).serialize()); });
    $('.del-item').on('click', function () { go('delete_price_list_item', { id: $(this).data('id') }); });
    $('#del-list').on('click', function () {
        if (window.confirm('Delete this price list? Its customers go back to normal prices.')) { go('delete_price_list', { id: $(this).data('id') }, function () { location.href = './?page=price_lists'; }); }
    });
});
</script>
