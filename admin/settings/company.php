<?php
// Company Profile: the active company's name, contact details and logo, used
// on its invoices, receipts, payslips and emails.
$nv_b = $_settings->tenant_branding();
?>
<div class="card card-outline rounded-0 shadow-sm">
    <div class="card-header"><h3 class="card-title"><i class="fas fa-id-card mr-2"></i>Company Profile</h3></div>
    <div class="card-body">
        <div class="alert alert-info rounded-0"><i class="fas fa-info-circle mr-1"></i>These details and the logo appear on this company's invoices, receipts, payslips and customer emails.</div>
        <form id="company-profile-form" enctype="multipart/form-data">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label>Company Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars($nv_b['name']) ?>" required>
                </div>
                <div class="form-group col-md-6">
                    <label>Email</label>
                    <input type="email" name="email" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars($nv_b['email']) ?>">
                </div>
                <div class="form-group col-md-6">
                    <label>Phone</label>
                    <input type="text" name="phone" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars($nv_b['phone']) ?>">
                </div>
                <div class="form-group col-md-6">
                    <label>Address</label>
                    <input type="text" name="address" class="form-control form-control-sm rounded-0" value="<?php echo htmlspecialchars($nv_b['address']) ?>">
                </div>
                <div class="form-group col-md-6">
                    <label>Logo</label>
                    <div class="custom-file">
                        <input type="file" name="logo" id="logo" class="custom-file-input" accept=".png,.jpg,.jpeg,.webp">
                        <label class="custom-file-label" for="logo">PNG, JPG or WebP, up to 2 MB</label>
                    </div>
                    <?php if(!empty($nv_b['logo'])): ?>
                    <div class="custom-control custom-checkbox mt-2">
                        <input type="checkbox" class="custom-control-input" id="remove_logo" name="remove_logo" value="1">
                        <label class="custom-control-label" for="remove_logo">Remove the current logo</label>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="form-group col-md-6 text-center">
                    <label class="d-block">Current Logo</label>
                    <?php if(!empty($nv_b['logo'])): ?>
                        <img src="<?php echo validate_image($nv_b['logo']) ?>" alt="Company logo" class="border rounded p-2 bg-white" style="max-height:90px;max-width:100%;object-fit:contain;">
                    <?php else: ?>
                        <span class="text-muted"><i class="far fa-image mr-1"></i>No logo yet - documents print the company name only.</span>
                    <?php endif; ?>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm rounded-0"><i class="fas fa-save mr-1"></i>Save Company Profile</button>
        </form>
    </div>
</div>
<script>
    $(function(){
        $('#logo').on('change', function(){ $(this).next('.custom-file-label').text(this.files[0] ? this.files[0].name : 'PNG, JPG or WebP, up to 2 MB'); });
        $('#company-profile-form').submit(function(e){
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
