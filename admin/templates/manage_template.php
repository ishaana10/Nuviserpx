<?php
$tenant_id = $_settings->active_tenant_id();

// Fetch current template settings for tenant
$tmpl = [
    'template_name' => 'standard',
    'bg_color'      => '#ffffff',
    'label_color'   => '#0f172a',
    'font_color'    => '#334155',
    'font_family'   => 'sans-serif',
    'logo_path'     => $_settings->info('logo') ?: ''
];

$qry = $conn->query("SELECT * FROM `invoice_templates` WHERE tenant_id = '{$tenant_id}' LIMIT 1");
if ($qry && $qry->num_rows > 0) {
    $row = $qry->fetch_assoc();
    $tmpl['template_name'] = $row['template_name'] ?: 'standard';
    $tmpl['bg_color']      = $row['bg_color'] ?: '#ffffff';
    $tmpl['label_color']   = $row['label_color'] ?: '#0f172a';
    $tmpl['font_color']    = $row['font_color'] ?: '#334155';
    $tmpl['font_family']   = $row['font_family'] ?: 'sans-serif';
    if (!empty($row['logo_path'])) {
        $tmpl['logo_path'] = $row['logo_path'];
    }
}
?>

<style>
    .template-card-option {
        border: 2px solid #e2e8f0;
        border-radius: 12px;
        padding: 1rem;
        cursor: pointer;
        transition: all 0.2s ease;
        text-align: center;
        background: #ffffff;
        position: relative;
    }
    .template-card-option:hover {
        border-color: #3b82f6;
        transform: translateY(-2px);
    }
    .template-card-option.active {
        border-color: #2563eb;
        background: #f0f6ff;
        box-shadow: 0 4px 12px rgba(37, 99, 235, 0.15);
    }
    .template-card-option .check-badge {
        position: absolute;
        top: 8px;
        right: 8px;
        background: #2563eb;
        color: #fff;
        border-radius: 50%;
        width: 20px;
        height: 20px;
        display: none;
        align-items: center;
        justify-content: center;
        font-size: 11px;
    }
    .template-card-option.active .check-badge {
        display: flex;
    }
    .template-mini-svg {
        width: 60px;
        height: 75px;
        margin: 0 auto 0.5rem auto;
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        display: flex;
        flex-direction: column;
        padding: 6px;
        justify-content: space-between;
    }
    .preview-card {
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        padding: 2.5rem;
        min-height: 520px;
        transition: all 0.3s ease;
    }
</style>

<div class="card card-outline card-primary shadow-sm">
    <div class="card-header">
        <h3 class="card-title font-weight-bold">
            <i class="fas fa-file-invoice text-primary mr-2"></i> Invoice Template Customization & Branding
        </h3>
    </div>
    <div class="card-body">
        <p class="text-muted mb-4">Pick an invoice template layout and customize your organization branding, logo, and colors.</p>

        <form id="template-form" enctype="multipart/form-data">
            <input type="hidden" name="template_name" id="input_template_name" value="<?= htmlspecialchars($tmpl['template_name']) ?>">

            <div class="row">
                <!-- Left Control Panel -->
                <div class="col-lg-5 col-md-6 border-right pr-lg-4">
                    <h5 class="font-weight-bold mb-3 text-navy">1. Invoice Template</h5>

                    <div class="row g-2 mb-4">
                        <div class="col-6 mb-2">
                            <div class="template-card-option <?= $tmpl['template_name'] == 'standard' ? 'active' : '' ?>" data-template="standard">
                                <div class="check-badge"><i class="fas fa-check"></i></div>
                                <div class="template-mini-svg">
                                    <div style="height:12px; background:#e2e8f0; width:40%; border-radius:2px;"></div>
                                    <div style="height:16px; background:#cbd5e1; border-radius:2px;"></div>
                                    <div style="height:20px; background:#f1f5f9; border-radius:2px;"></div>
                                </div>
                                <span class="font-weight-bold text-xs text-uppercase">Standard</span>
                            </div>
                        </div>
                        <div class="col-6 mb-2">
                            <div class="template-card-option <?= $tmpl['template_name'] == 'spreadsheet' ? 'active' : '' ?>" data-template="spreadsheet">
                                <div class="check-badge"><i class="fas fa-check"></i></div>
                                <div class="template-mini-svg">
                                    <div style="height:10px; background:#cbd5e1; border-radius:2px;"></div>
                                    <div style="height:24px; border:1px solid #cbd5e1; border-radius:2px;"></div>
                                    <div style="height:10px; background:#e2e8f0; border-radius:2px;"></div>
                                </div>
                                <span class="font-weight-bold text-xs text-uppercase">Spreadsheet</span>
                            </div>
                        </div>
                        <div class="col-6 mb-2">
                            <div class="template-card-option <?= $tmpl['template_name'] == 'continental' ? 'active' : '' ?>" data-template="continental">
                                <div class="check-badge"><i class="fas fa-check"></i></div>
                                <div class="template-mini-svg" style="background:#0f172a;">
                                    <div style="height:12px; background:#38bdf8; width:50%; border-radius:2px;"></div>
                                    <div style="height:20px; background:#ffffff; border-radius:2px;"></div>
                                </div>
                                <span class="font-weight-bold text-xs text-uppercase">Continental</span>
                            </div>
                        </div>
                        <div class="col-6 mb-2">
                            <div class="template-card-option <?= $tmpl['template_name'] == 'compact' ? 'active' : '' ?>" data-template="compact">
                                <div class="check-badge"><i class="fas fa-check"></i></div>
                                <div class="template-mini-svg">
                                    <div style="height:8px; background:#94a3b8; border-radius:2px;"></div>
                                    <div style="height:28px; background:#f1f5f9; border-radius:2px;"></div>
                                </div>
                                <span class="font-weight-bold text-xs text-uppercase">Compact</span>
                            </div>
                        </div>
                    </div>

                    <h5 class="font-weight-bold mb-3 text-navy">2. Organization Logo</h5>
                    <div class="form-group mb-4">
                        <input type="file" name="logo" id="logo_file" class="form-control-file" accept="image/png, image/jpeg">
                        <small class="form-text text-muted">Upload PNG or JPG logo for invoice header.</small>
                    </div>

                    <h5 class="font-weight-bold mb-3 text-navy">3. Invoice Colors & Typography</h5>
                    <div class="form-group row align-items-center">
                        <label class="col-sm-5 col-form-label font-weight-bold">Background</label>
                        <div class="col-sm-7">
                            <input type="color" name="bg_color" id="input_bg_color" class="form-control form-control-color w-100" value="<?= htmlspecialchars($tmpl['bg_color']) ?>">
                        </div>
                    </div>
                    <div class="form-group row align-items-center">
                        <label class="col-sm-5 col-form-label font-weight-bold">Label & Header</label>
                        <div class="col-sm-7">
                            <input type="color" name="label_color" id="input_label_color" class="form-control form-control-color w-100" value="<?= htmlspecialchars($tmpl['label_color']) ?>">
                        </div>
                    </div>
                    <div class="form-group row align-items-center">
                        <label class="col-sm-5 col-form-label font-weight-bold">Font / Body Text</label>
                        <div class="col-sm-7">
                            <input type="color" name="font_color" id="input_font_color" class="form-control form-control-color w-100" value="<?= htmlspecialchars($tmpl['font_color']) ?>">
                        </div>
                    </div>
                    <div class="form-group row align-items-center mb-4">
                        <label class="col-sm-5 col-form-label font-weight-bold">Font Family</label>
                        <div class="col-sm-7">
                            <select name="font_family" id="input_font_family" class="form-control form-control-sm">
                                <option value="sans-serif" <?= $tmpl['font_family'] == 'sans-serif' ? 'selected' : '' ?>>Sans-Serif (Modern)</option>
                                <option value="serif" <?= $tmpl['font_family'] == 'serif' ? 'selected' : '' ?>>Serif (Classic)</option>
                                <option value="monospace" <?= $tmpl['font_family'] == 'monospace' ? 'selected' : '' ?>>Monospace (Spreadsheet)</option>
                            </select>
                        </div>
                    </div>

                    <div class="alert alert-info border-0 text-sm">
                        <i class="fas fa-info-circle mr-1"></i> You can view and print your live invoices anytime under <b>Invoices &gt; View</b> using these template settings.
                    </div>

                    <button type="submit" class="btn btn-primary btn-block font-weight-bold py-2 mt-4">
                        <i class="fas fa-save mr-1"></i> Save & Apply Template
                    </button>
                </div>

                <!-- Right Live Preview Panel -->
                <div class="col-lg-7 col-md-6 pl-lg-4 mt-4 mt-md-0">
                    <h5 class="font-weight-bold mb-3 text-navy d-flex align-items-center justify-content-between">
                        <span><i class="fas fa-eye mr-2"></i> Interactive Live Preview</span>
                        <span class="badge badge-light border font-weight-normal text-xs text-uppercase" id="preview_tmpl_badge">TEMPLATE: Standard</span>
                    </h5>

                    <div class="preview-card" id="invoice_live_preview">
                        <!-- Preview Header -->
                        <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-4" id="prev_header_row">
                            <div class="d-flex align-items-center">
                                <img src="<?= !empty($tmpl['logo_path']) ? validate_image($tmpl['logo_path']) : validate_image($_settings->info('logo')) ?>" id="prev_logo_img" alt="Logo" style="max-height: 55px; width: auto; margin-right: 15px; object-fit: contain;">
                                <div>
                                    <h5 class="font-weight-bold mb-0" id="prev_company_name"><?= htmlspecialchars($_settings->info('name') ?: 'Nuvis Technologies') ?></h5>
                                    <small class="text-muted">Fiji Islands</small>
                                </div>
                            </div>
                            <div class="text-right">
                                <h4 class="font-weight-bold mb-0" id="prev_inv_label">INVOICE</h4>
                                <span class="text-muted small">#INV0291</span>
                            </div>
                        </div>

                        <!-- Preview Dates -->
                        <div class="row mb-4" id="prev_dates_row">
                            <div class="col-6">
                                <small class="text-muted d-block">Invoice Date:</small>
                                <strong id="prev_date_val">14.09.2026</strong>
                            </div>
                            <div class="col-6 text-right">
                                <small class="text-muted d-block">Due Date:</small>
                                <strong id="prev_due_val">29.09.2026</strong>
                            </div>
                        </div>

                        <!-- Preview Items Table -->
                        <div class="table-responsive mb-4">
                            <table class="table table-sm border-0" id="prev_table">
                                <thead id="prev_thead">
                                    <tr>
                                        <th>Item & Description</th>
                                        <th class="text-center">Qty</th>
                                        <th class="text-right">Rate</th>
                                        <th class="text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>
                                            <strong>MacBook Pro</strong><br>
                                            <small class="text-muted">(14.2-inch) Liquid Retina</small>
                                        </td>
                                        <td class="text-center">1</td>
                                        <td class="text-right">1,54,000.00</td>
                                        <td class="text-right font-weight-bold">1,54,000.00</td>
                                    </tr>
                                    <tr>
                                        <td>
                                            <strong>USB-C Multiport Adapter</strong><br>
                                            <small class="text-muted">HDMI & Charging Hub</small>
                                        </td>
                                        <td class="text-center">2</td>
                                        <td class="text-right">2,500.00</td>
                                        <td class="text-right font-weight-bold">5,000.00</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Preview Total Footer -->
                        <div class="row pt-3 border-top" id="prev_footer_row">
                            <div class="col-6">
                                <small class="text-muted">Notes:</small>
                                <p class="small mb-0">Thank you for your business!</p>
                            </div>
                            <div class="col-6 text-right">
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">Grand Total:</span>
                                    <strong class="h5 font-weight-bold" id="prev_total_val">$1,59,000.00</strong>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
$(document).ready(function(){
    function updatePreview() {
        var tmpl = $('#input_template_name').val();
        var bgColor = $('#input_bg_color').val();
        var labelColor = $('#input_label_color').val();
        var fontColor = $('#input_font_color').val();
        var fontFamily = $('#input_font_family').val();

        $('#preview_tmpl_badge').text('TEMPLATE: ' + tmpl.toUpperCase());

        var card = $('#invoice_live_preview');
        card.css('background-color', bgColor);
        card.css('color', fontColor);
        card.css('font-family', fontFamily);

        $('#prev_company_name, #prev_inv_label, #prev_total_val').css('color', labelColor);

        var thead = $('#prev_thead');
        if (tmpl === 'continental') {
            thead.css({'background-color': labelColor, 'color': '#ffffff'});
        } else if (tmpl === 'spreadsheet') {
            thead.css({'background-color': '#f1f5f9', 'color': fontColor, 'border': '1px solid #cbd5e1'});
        } else {
            thead.css({'background-color': 'transparent', 'color': fontColor, 'border-bottom': '2px solid ' + labelColor});
        }
    }

    $('.template-card-option').click(function(){
        $('.template-card-option').removeClass('active');
        $(this).addClass('active');
        $('#input_template_name').val($(this).data('template'));
        updatePreview();
    });

    $('#input_bg_color, #input_label_color, #input_font_color, #input_font_family').on('input change', function(){
        updatePreview();
    });

    $('#logo_file').change(function(){
        var file = this.files[0];
        if (file) {
            var reader = new FileReader();
            reader.onload = function(e){
                $('#prev_logo_img').attr('src', e.target.result);
            }
            reader.readAsDataURL(file);
        }
    });

    $('#template-form').submit(function(e){
        e.preventDefault();
        start_loader();
        var formData = new FormData(this);
        $.ajax({
            url: _base_url_ + 'classes/Master.php?f=save_invoice_template',
            method: 'POST',
            data: formData,
            cache: false,
            contentType: false,
            processData: false,
            dataType: 'json',
            error: function(err){
                console.log(err);
                alert_toast("An error occurred.", 'error');
                end_loader();
            },
            success: function(resp){
                end_loader();
                if(resp.status == 'success'){
                    alert_toast(resp.msg, 'success');
                } else {
                    alert_toast(resp.msg || "Failed to save template.", 'error');
                }
            }
        });
    });

    updatePreview();
});
</script>