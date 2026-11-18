<?php
// Company Profile: the active company's identity, tax numbers, contact details, payment details and logo. They appear
// on this company's invoices, receipts, statements, purchase orders, payslips and customer emails.
$nv_b = $_settings->tenant_branding();
$nv_val = fn(string $k) => htmlspecialchars((string)($nv_b[$k] ?? ''));
$nv_logo = !empty($nv_b['logo']) ? validate_image($nv_b['logo']) : '';
?>
<style>
.cp-section{border:1px solid #e2e8f0;border-radius:10px;padding:18px 20px;margin-bottom:16px;background:#fff}
.cp-section h6{font-weight:700;color:#0f172a;margin:0 0 2px}.cp-section .sub{color:#64748b;font-size:.85rem;margin-bottom:14px}
.cp-preview{border:1px solid #e2e8f0;border-radius:10px;background:#fff;padding:22px;position:sticky;top:70px}
.cp-preview .lh{display:flex;gap:14px;align-items:flex-start;border-bottom:2px solid #1e3a8a;padding-bottom:12px;margin-bottom:12px}
.cp-preview .lh img{max-height:60px;max-width:110px;object-fit:contain}.cp-preview .nm{font-weight:800;font-size:1.1rem;color:#0f172a}
.cp-preview .ln{font-size:.8rem;color:#5a6473;line-height:1.45;white-space:pre-line}.cp-preview .pay{font-size:.8rem;color:#475569;border-top:1px dashed #cbd5e1;margin-top:14px;padding-top:10px;white-space:pre-line}
.cp-drop{border:2px dashed #cbd5e1;border-radius:10px;padding:16px;text-align:center;background:#f8fafc;cursor:pointer;transition:border-color .2s,background .2s}
.cp-drop:hover,.cp-drop.over{border-color:#2563eb;background:#eff6ff}.cp-drop img{max-height:80px;max-width:100%;object-fit:contain}
.cp-done{height:6px;border-radius:99px;background:#e2e8f0;overflow:hidden}.cp-done i{display:block;height:100%;background:#16a34a;transition:width .3s}
</style>
<div class="card card-outline rounded-0 shadow-sm">
    <div class="card-header d-flex align-items-center justify-content-between flex-wrap">
        <h3 class="card-title"><i class="fas fa-id-card mr-2"></i>Company Profile</h3>
        <div style="min-width:200px"><small class="text-muted d-block text-right"><span id="cp_pct">0</span>% complete</small><div class="cp-done"><i id="cp_bar" style="width:0"></i></div></div>
    </div>
    <div class="card-body">
        <form id="company-profile-form" enctype="multipart/form-data">
            <div class="row">
                <div class="col-lg-7">
                    <div class="cp-section">
                        <h6>Business</h6><div class="sub">The name and address printed at the top of every document.</div>
                        <div class="form-row">
                            <div class="form-group col-md-12">
                                <label for="cp_name">Company name <span class="text-danger">*</span></label>
                                <input type="text" name="name" id="cp_name" class="form-control form-control-sm rounded-0" value="<?= $nv_val('name') ?>" required>
                            </div>
                            <div class="form-group col-md-12">
                                <label for="cp_address">Address</label>
                                <textarea name="address" id="cp_address" rows="3" class="form-control form-control-sm rounded-0" placeholder="Street&#10;Town, Fiji"><?= $nv_val('address') ?></textarea>
                                <small class="text-muted">Use a new line for each line of the address.</small>
                            </div>
                        </div>
                    </div>

                    <div class="cp-section">
                        <h6>Contact</h6><div class="sub">How customers and suppliers reach you.</div>
                        <div class="form-row">
                            <div class="form-group col-md-6"><label for="cp_email">Email</label>
                                <input type="email" name="email" id="cp_email" class="form-control form-control-sm rounded-0" value="<?= $nv_val('email') ?>"></div>
                            <div class="form-group col-md-6"><label for="cp_phone">Phone</label>
                                <input type="text" name="phone" id="cp_phone" class="form-control form-control-sm rounded-0" value="<?= $nv_val('phone') ?>"></div>
                            <div class="form-group col-md-12 mb-0"><label for="cp_website">Website</label>
                                <input type="text" name="website" id="cp_website" class="form-control form-control-sm rounded-0" placeholder="www.example.com.fj" value="<?= $nv_val('website') ?>"></div>
                        </div>
                    </div>

                    <div class="cp-section">
                        <h6>Tax and registration</h6><div class="sub">Shown under the company name on invoices, so customers can claim VAT.</div>
                        <div class="form-row">
                            <div class="form-group col-md-6 mb-0"><label for="cp_tin">TIN (Taxpayer Identification Number)</label>
                                <input type="text" name="tin" id="cp_tin" class="form-control form-control-sm rounded-0" value="<?= $nv_val('tin') ?>"></div>
                            <div class="form-group col-md-6 mb-0"><label for="cp_vat">VAT registration number</label>
                                <input type="text" name="vat_no" id="cp_vat" class="form-control form-control-sm rounded-0" value="<?= $nv_val('vat_no') ?>"></div>
                        </div>
                    </div>

                    <div class="cp-section">
                        <h6>How customers pay you</h6><div class="sub">Printed on invoices under "How to pay". Bank name, account name and number, or other instructions.</div>
                        <textarea name="bank_details" id="cp_bank" rows="3" maxlength="600" class="form-control form-control-sm rounded-0" placeholder="Bank: ...&#10;Account name: ...&#10;Account no.: ..."><?= $nv_val('bank_details') ?></textarea>
                        <small class="text-muted"><span id="cp_bank_n">0</span>/600</small>
                    </div>

                    <div class="cp-section">
                        <h6>Logo</h6><div class="sub">Appears on invoices, receipts, statements, purchase orders and payslips. PNG, JPG or WebP, up to 2 MB. A square or wide logo on a transparent background works best.</div>
                        <input type="file" name="logo" id="logo" class="d-none" accept=".png,.jpg,.jpeg,.webp">
                        <label for="logo" class="cp-drop d-block mb-2" id="cp_drop">
                            <img id="cp_logo_img" src="<?= $nv_logo ?>" alt="Company logo" <?= $nv_logo ? '' : 'style="display:none"' ?>>
                            <span id="cp_logo_empty" class="text-muted" <?= $nv_logo ? 'style="display:none"' : '' ?>><i class="far fa-image mr-1"></i>No logo yet. Documents print the company name only.</span>
                            <span class="d-block text-primary font-weight-bold mt-2" id="cp_logo_hint"><?= $nv_logo ? 'Click or drop an image to replace it' : 'Click or drop an image here' ?></span>
                        </label>
                        <?php if($nv_logo): ?>
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" class="custom-control-input" id="remove_logo" name="remove_logo" value="1">
                            <label class="custom-control-label" for="remove_logo">Remove the current logo</label>
                        </div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary btn-sm rounded-0"><i class="fas fa-save mr-1"></i>Save Company Profile</button>
                </div>

                <div class="col-lg-5 mt-3 mt-lg-0">
                    <div class="cp-preview">
                        <small class="text-muted d-block mb-2 font-weight-bold">How it looks on your documents</small>
                        <div class="lh">
                            <img id="pv_logo" src="<?= $nv_logo ?>" alt="" <?= $nv_logo ? '' : 'style="display:none"' ?>>
                            <div>
                                <div class="nm" id="pv_name"></div>
                                <div class="ln" id="pv_addr"></div>
                                <div class="ln" id="pv_contact"></div>
                                <div class="ln" id="pv_ids"></div>
                            </div>
                        </div>
                        <div class="text-muted small">Tax invoice, receipt, statement, purchase order...</div>
                        <div class="pay" id="pv_pay" style="display:none"><b>How to pay</b><br><span id="pv_pay_txt"></span></div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
<script>
    $(function(){
        var $f = $('#company-profile-form');
        function v(id){ return $.trim($('#'+id).val() || ''); }
        function render(){
            $('#pv_name').text(v('cp_name') || 'Your company name');
            $('#pv_addr').text($('#cp_address').val() || '');
            var web = v('cp_website').replace(/^https?:\/\//i, '');
            $('#pv_contact').text([v('cp_email'), v('cp_phone'), web].filter(Boolean).join('   '));
            $('#pv_ids').text([v('cp_tin') ? 'TIN ' + v('cp_tin') : '', v('cp_vat') ? 'VAT reg. ' + v('cp_vat') : ''].filter(Boolean).join('   '));
            var bank = $('#cp_bank').val() || '';
            $('#pv_pay').toggle($.trim(bank) !== ''); $('#pv_pay_txt').text(bank);
            $('#cp_bank_n').text(bank.length);
            var fields = ['cp_name','cp_address','cp_email','cp_phone','cp_website','cp_tin','cp_vat','cp_bank'], done = 0;
            fields.forEach(function(id){ if(v(id) !== '') done++; });
            if($('#cp_logo_img').is(':visible')) done++;
            var pct = Math.round(done / (fields.length + 1) * 100);
            $('#cp_pct').text(pct); $('#cp_bar').css('width', pct + '%');
        }
        $f.on('input change', 'input,textarea', render);
        function showFile(file){
            if(!file) return;
            if(!/^image\/(png|jpeg|webp)$/.test(file.type)){ alert_toast('Choose a PNG, JPG or WebP image.', 'error'); $('#logo').val(''); return; }
            if(file.size > 2 * 1024 * 1024){ alert_toast('The logo must be 2 MB or smaller.', 'error'); $('#logo').val(''); return; }
            var r = new FileReader();
            r.onload = function(e){
                $('#cp_logo_img,#pv_logo').attr('src', e.target.result).show();
                $('#cp_logo_empty').hide(); $('#cp_logo_hint').text(file.name + ' (click to change)');
                $('#remove_logo').prop('checked', false); render();
            };
            r.readAsDataURL(file);
        }
        $('#logo').on('change', function(){ showFile(this.files[0]); });
        $('#cp_drop').on('dragover dragenter', function(e){ e.preventDefault(); $(this).addClass('over'); })
            .on('dragleave drop', function(e){ e.preventDefault(); $(this).removeClass('over'); })
            .on('drop', function(e){ var fl = e.originalEvent.dataTransfer.files; if(fl && fl[0]){ $('#logo')[0].files = fl; showFile(fl[0]); } });
        $('#remove_logo').on('change', function(){
            if(this.checked){ $('#cp_logo_img,#pv_logo').hide(); $('#cp_logo_empty').show(); } else { $('#cp_logo_img,#pv_logo').toggle(!!$('#cp_logo_img').attr('src')); $('#cp_logo_empty').toggle(!$('#cp_logo_img').attr('src')); }
            render();
        });
        render();
        $f.submit(function(e){
            e.preventDefault();
            start_loader();
            $.ajax({
                url: _base_url_ + 'classes/Master.php?f=save_company_profile',
                method: 'POST', data: new FormData(this), cache: false, contentType: false, processData: false, dataType: 'json',
                error: function(){ alert_toast('An error occurred.', 'error'); end_loader(); },
                success: function(resp){
                    alert_toast(resp.msg || 'Done', resp.status === 'success' ? 'success' : 'error');
                    end_loader();
                    if(resp.status === 'success'){ setTimeout(function(){ location.reload(); }, 1200); }
                }
            });
        });
    });
</script>
