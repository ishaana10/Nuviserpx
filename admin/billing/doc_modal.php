<?php
// An invoice or receipt shown inside the modal, with Download and Print. The PDF is the same one Sales produces.
require_once(dirname(__DIR__, 2) . '/config.php');
if (!$_settings->userdata('id') || !$_settings->can_access('settings_manage')) { echo '<div class="alert alert-danger">Access denied.</div>'; return; }
$id = (int)($_GET['id'] ?? 0);
$receipt = ($_GET['type'] ?? '') === 'receipt';
$src = base_url . 'admin/billing/' . ($receipt ? 'receipt_pdf.php?id=' . $id . '&payment=' . (int)($_GET['payment'] ?? 0) : 'invoice_pdf.php?id=' . $id);
?>
<div class="d-flex justify-content-end mb-2" style="gap:6px">
  <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($src) ?>&download=1"><i class="fa fa-download"></i> Download</a>
  <button type="button" class="btn btn-sm btn-primary" id="nv-doc-print"><i class="fa fa-print"></i> Print</button>
</div>
<iframe id="nv-doc-frame" src="<?= htmlspecialchars($src) ?>" style="width:100%;height:70vh;border:1px solid #e2e8f0;border-radius:6px" title="<?= $receipt ? 'Receipt' : 'Invoice' ?>"></iframe>
<script>
$('#uni_modal .modal-footer').html('<button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>');
$('#nv-doc-print').on('click', function(){
  var f = document.getElementById('nv-doc-frame');
  try { f.contentWindow.focus(); f.contentWindow.print(); }
  catch (e) { window.open(f.src, '_blank'); }
});
</script>
