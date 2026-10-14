<?php
require_once(__DIR__ . '/../../config.php');

$tenant_id = $_settings->active_tenant_id();

if(isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0){
    $inv_id_clean = intval($_GET['id']);
    $qry = $conn->query("SELECT * from `invoice_list` where id = '{$inv_id_clean}' and tenant_id = '{$tenant_id}' ");
    if($qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
    }
}

// Fetch products for line item dropdowns
$products_qry = $conn->query("SELECT * FROM `product_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 AND status = 1 ORDER BY name ASC");
$products = [];
while($pr = $products_qry->fetch_assoc()){
    $products[$pr['id']] = $pr;
}

// Fetch GL accounts for line item accounting
$accounts_qry = $conn->query("SELECT * FROM `account_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 AND status = 1 ORDER BY name ASC");
$accounts = [];
while($ac = $accounts_qry->fetch_assoc()){
    $accounts[] = $ac;
}
?>

<style>
.invoice-builder-card {
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 12px rgba(0,0,0,0.03);
    background: #ffffff;
}

.invoice-header-bar {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 16px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-radius: 12px 12px 0 0;
}

.invoice-header-title {
    font-size: 1.25rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
}

.form-label-custom {
    font-size: 0.82rem;
    font-weight: 600;
    color: #475569;
    margin-bottom: 5px;
}

.form-control-modern {
    border-radius: 6px;
    border: 1px solid #cbd5e1;
    font-size: 0.88rem;
    padding: 6px 10px;
    height: auto;
}

.form-control-modern:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
}

.items-table th {
    background-color: #f1f5f9;
    color: #334155;
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 10px;
    border-bottom: 1px solid #cbd5e1;
}

.items-table td {
    padding: 8px 6px;
    vertical-align: middle;
}

.payment-badge-box {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 8px;
    padding: 8px 12px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-size: 0.82rem;
    color: #1d4ed8;
    font-weight: 600;
}

.totals-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 18px 24px;
}
</style>

<div class="invoice-builder-card mb-4">
    <!-- Header Banner Bar -->
    <div class="invoice-header-bar">
        <h3 class="invoice-header-title">
            <i class="fas fa-file-invoice text-primary mr-2"></i>
            <?php echo isset($id) ? 'Edit Sales Invoice #'.$invoice_no : 'Set Up New Sales Invoice' ?>
        </h3>
        <div class="d-flex align-items-center gap-2">
            <a href="./?page=invoices" class="btn btn-sm btn-outline-secondary font-weight-bold mr-2">
                <i class="fas fa-times mr-1"></i> Cancel / Skip
            </a>
            <button type="button" class="btn btn-sm btn-outline-primary font-weight-bold" onclick="window.print()">
                <i class="fas fa-print mr-1"></i> Print Preview
            </button>
        </div>
    </div>

    <div class="p-4">
        <form action="" id="invoice-form" enctype="multipart/form-data">
            <input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">

            <!-- First Form Row: Contact, Issue Date, Due Date, Invoice Number, Reference -->
            <div class="row mb-3">
                <!-- Customer / Contact -->
                <div class="col-lg-3 col-md-6 form-group">
                    <div class="d-flex justify-content-between align-items-center">
                        <label for="party_id" class="form-label-custom">Customer / Contact <span class="text-danger">*</span></label>
                        <button type="button" class="btn btn-xs btn-link p-0 text-primary font-weight-bold" id="btn_open_customer_modal">
                            <i class="fas fa-plus-circle mr-1"></i> Add Customer
                        </button>
                    </div>
                    <select name="party_id" id="party_id" class="form-control form-control-modern select2" required>
                        <option value="" disabled <?php echo !isset($party_id) ? 'selected' : '' ?>></option>
                        <?php
                        $parties = $conn->query("SELECT * FROM `party_list` WHERE tenant_id = '{$tenant_id}' AND delete_flag = 0 ORDER BY name ASC");
                        while($p = $parties->fetch_assoc()):
                        ?>
                        <option value="<?php echo $p['id'] ?>" <?php echo isset($party_id) && $party_id == $p['id'] ? 'selected' : '' ?>>
                            <?php echo htmlspecialchars($p['name']) . ($p['party_code'] ? ' ('.$p['party_code'].')' : '') ?>
                        </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <!-- Issue Date -->
                <div class="col-lg-2 col-md-3 form-group">
                    <label for="invoice_date" class="form-label-custom">Issue Date <span class="text-danger">*</span></label>
                    <input type="date" name="invoice_date" id="invoice_date" class="form-control form-control-modern" value="<?php echo isset($invoice_date) ? $invoice_date : date('Y-m-d'); ?>" required/>
                </div>

                <!-- Due Date -->
                <div class="col-lg-2 col-md-3 form-group">
                    <label for="due_date" class="form-label-custom">Due Date <span class="text-danger">*</span></label>
                    <input type="date" name="due_date" id="due_date" class="form-control form-control-modern" value="<?php echo isset($due_date) ? $due_date : date('Y-m-d', strtotime('+30 days')); ?>" required/>
                </div>

                <!-- Invoice Number -->
                <div class="col-lg-2 col-md-6 form-group">
                    <label for="invoice_no" class="form-label-custom">Invoice Number <span class="text-danger">*</span></label>
                    <input type="text" name="invoice_no" id="invoice_no" class="form-control form-control-modern font-weight-bold" value="<?php echo isset($invoice_no) ? $invoice_no : 'INV-'.date('Ym').'-'.rand(1000,9999); ?>" required/>
                </div>

                <!-- Reference -->
                <div class="col-lg-3 col-md-6 form-group">
                    <label for="reference" class="form-label-custom">Reference / PO #</label>
                    <input type="text" name="reference" id="reference" class="form-control form-control-modern" placeholder="e.g. PO-98421" value="<?php echo isset($reference) ? htmlspecialchars($reference) : ''; ?>"/>
                </div>
            </div>

            <!-- Second Form Row: Currency, Amounts Are, Online Payments Banner -->
            <div class="row align-items-center mb-4 pb-3 border-bottom">
                <div class="col-md-3 form-group mb-md-0">
                    <label for="currency" class="form-label-custom">Currency</label>
                    <select name="currency" id="currency" class="form-control form-control-modern">
                        <option value="FJD" selected>Fijian Dollar (FJD)</option>
                        <option value="USD">US Dollar (USD $)</option>
                        <option value="AUD">Australian Dollar (AUD $)</option>
                        <option value="NZD">NZ Dollar (NZD $)</option>
                        <option value="EUR">Euro (EUR €)</option>
                    </select>
                </div>

                <div class="col-md-3 form-group mb-md-0">
                    <label for="tax_type" class="form-label-custom">Amounts Are</label>
                    <select name="tax_type" id="tax_type" class="form-control form-control-modern">
                        <option value="exclusive" selected>Tax exclusive (15% VAT added)</option>
                        <option value="inclusive">Tax inclusive (VAT included)</option>
                        <option value="none">No tax (0% Exempt)</option>
                    </select>
                </div>

                <div class="col-md-6 text-md-right mt-3 mt-md-0">
                    <div class="payment-badge-box">
                        <i class="fas fa-credit-card"></i>
                        <span>Online Payments Active: Visa, Mastercard, Direct Bank Transfer & M-PAiSA</span>
                    </div>
                </div>
            </div>

            <!-- Line Items Table Section -->
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h6 class="font-weight-bold text-navy m-0"><i class="fas fa-list mr-1"></i> Line Items</h6>
                <button type="button" class="btn btn-xs btn-outline-success font-weight-bold" id="btn_open_product_modal">
                    <i class="fas fa-plus-circle mr-1"></i> Quick Add New Product / Item
                </button>
            </div>

            <div class="table-responsive mb-3">
                <table class="table table-bordered items-table" id="items-table">
                    <thead>
                        <tr>
                            <th width="20%">Item / Product</th>
                            <th width="22%">Description</th>
                            <th width="8%" class="text-center">Qty</th>
                            <th width="11%" class="text-right">Price</th>
                            <th width="8%" class="text-center">Disc (%)</th>
                            <th width="14%">Account</th>
                            <th width="8%" class="text-center">Tax Rate</th>
                            <th width="10%" class="text-right">Amount (FJD)</th>
                            <th width="4%" class="text-center"><i class="fas fa-cog"></i></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if(isset($id)):
                            $items = $conn->query("SELECT * FROM `invoice_items` WHERE invoice_id = '{$id}' ORDER BY id ASC");
                            while($item = $items->fetch_assoc()):
                        ?>
                        <tr class="item-row">
                            <td>
                                <select name="product_id[]" class="form-control form-control-modern product-select mb-1">
                                    <option value="">-- Custom Item --</option>
                                    <?php foreach($products as $pid => $pr): ?>
                                        <option value="<?php echo $pid ?>" <?php echo (isset($item['product_id']) && $item['product_id'] == $pid) ? 'selected' : '' ?> data-price="<?php echo $pr['selling_price'] ?>" data-desc="<?php echo htmlspecialchars($pr['description']) ?>">
                                            <?php echo htmlspecialchars($pr['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="item_name[]" class="form-control form-control-modern item-name" placeholder="Item Name" value="<?php echo htmlspecialchars($item['item_name']) ?>" required>
                            </td>
                            <td><input type="text" name="item_desc[]" class="form-control form-control-modern" placeholder="Description" value="<?php echo htmlspecialchars($item['description']) ?>"></td>
                            <td><input type="number" step="any" name="item_qty[]" class="form-control form-control-modern text-center calc-item qty" value="<?php echo $item['qty'] ?>" required></td>
                            <td><input type="number" step="any" name="item_price[]" class="form-control form-control-modern text-right calc-item price" value="<?php echo $item['unit_price'] ?>" required></td>
                            <td><input type="number" step="any" name="item_discount_pct[]" class="form-control form-control-modern text-center calc-item disc_pct" value="0.00"></td>
                            <td>
                                <select name="item_account_id[]" class="form-control form-control-modern">
                                    <?php foreach($accounts as $ac): ?>
                                        <option value="<?php echo $ac['id'] ?>"><?php echo htmlspecialchars($ac['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="number" step="any" name="item_tax_rate[]" class="form-control form-control-modern text-center calc-item tax_rate" value="<?php echo $item['tax_rate'] ?>"></td>
                            <td><input type="number" step="any" name="item_amount[]" class="form-control form-control-modern text-right amount bg-light font-weight-bold" value="<?php echo $item['amount'] ?>" readonly></td>
                            <td class="text-center align-middle"><button type="button" class="btn btn-xs btn-outline-danger remove_row"><i class="fas fa-trash"></i></button></td>
                        </tr>
                        <?php
                            endwhile;
                        else:
                        ?>
                        <tr class="item-row">
                            <td>
                                <select name="product_id[]" class="form-control form-control-modern product-select mb-1">
                                    <option value="">-- Custom Item --</option>
                                    <?php foreach($products as $pid => $pr): ?>
                                        <option value="<?php echo $pid ?>" data-price="<?php echo $pr['selling_price'] ?>" data-desc="<?php echo htmlspecialchars($pr['description']) ?>">
                                            <?php echo htmlspecialchars($pr['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="item_name[]" class="form-control form-control-modern item-name" placeholder="Item / Service Name" required>
                            </td>
                            <td><input type="text" name="item_desc[]" class="form-control form-control-modern" placeholder="Optional description"></td>
                            <td><input type="number" step="any" name="item_qty[]" class="form-control form-control-modern text-center calc-item qty" value="1" required></td>
                            <td><input type="number" step="any" name="item_price[]" class="form-control form-control-modern text-right calc-item price" value="0.00" required></td>
                            <td><input type="number" step="any" name="item_discount_pct[]" class="form-control form-control-modern text-center calc-item disc_pct" value="0.00"></td>
                            <td>
                                <select name="item_account_id[]" class="form-control form-control-modern">
                                    <?php foreach($accounts as $ac): ?>
                                        <option value="<?php echo $ac['id'] ?>"><?php echo htmlspecialchars($ac['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="number" step="any" name="item_tax_rate[]" class="form-control form-control-modern text-center calc-item tax_rate" value="15.00"></td>
                            <td><input type="number" step="any" name="item_amount[]" class="form-control form-control-modern text-right amount bg-light font-weight-bold" value="0.00" readonly></td>
                            <td class="text-center align-middle"><button type="button" class="btn btn-xs btn-outline-danger remove_row"><i class="fas fa-trash"></i></button></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Table Actions & File Upload -->
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
                <div class="d-flex align-items-center gap-2 mb-2 mb-sm-0">
                    <button type="button" class="btn btn-sm btn-outline-primary font-weight-bold mr-2" id="add_item_row">
                        <i class="fas fa-plus mr-1"></i> Add Row
                    </button>
                    <label class="btn btn-sm btn-outline-secondary font-weight-bold mb-0 cursor-pointer">
                        <i class="fas fa-paperclip mr-1"></i> Attach Files
                        <input type="file" name="invoice_file" class="d-none" id="invoice_file_input" onchange="$('#file_name_display').text(this.files[0] ? this.files[0].name : '')">
                    </label>
                    <span class="text-muted text-xs ml-2" id="file_name_display"></span>
                </div>
            </div>

            <!-- Lower Section: Notes & Totals Breakdown -->
            <div class="row pt-3 border-top">
                <div class="col-md-6 form-group">
                    <label for="notes" class="form-label-custom">Payment Terms & Notes</label>
                    <textarea name="notes" id="notes" rows="4" class="form-control form-control-modern" placeholder="Payment terms, bank details, or notes..."><?php echo isset($notes) ? htmlspecialchars($notes) : "Payment Due Within 30 Days.\nBank: Bank South Pacific (BSP)\nAccount: 1234567890"; ?></textarea>
                </div>

                <div class="col-md-6">
                    <div class="totals-card">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="font-weight-bold text-muted">Subtotal:</span>
                            <input type="number" step="any" name="subtotal" id="subtotal" class="form-control form-control-sm text-right bg-transparent border-0 font-weight-bold" style="width: 150px;" value="<?php echo isset($subtotal) ? $subtotal : '0.00'; ?>" readonly>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span class="font-weight-bold text-muted">Total Tax (VAT 15%):</span>
                            <input type="number" step="any" name="tax_amount" id="tax_amount" class="form-control form-control-sm text-right bg-transparent border-0 font-weight-bold" style="width: 150px;" value="<?php echo isset($tax_amount) ? $tax_amount : '0.00'; ?>" readonly>
                        </div>
                        <div class="d-flex justify-content-between mb-2 align-items-center">
                            <span class="font-weight-bold text-muted">Overall Discount:</span>
                            <input type="number" step="any" name="discount" id="discount" class="form-control form-control-modern text-right calc-totals" style="width: 150px;" value="<?php echo isset($discount) ? $discount : '0.00'; ?>">
                        </div>
                        <hr class="my-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="font-weight-bold text-navy m-0">Total (FJD):</h5>
                            <input type="number" step="any" name="total" id="total" class="form-control form-control-lg text-right bg-transparent border-0 font-weight-bold text-success" style="width: 200px; font-size: 1.6rem;" value="<?php echo isset($total) ? $total : '0.00'; ?>" readonly>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer Save Actions Bar -->
            <div class="d-flex justify-content-between align-items-center mt-4 pt-3 border-top">
                <a href="./?page=invoices" class="btn btn-sm btn-outline-secondary font-weight-bold px-4">
                    <i class="fas fa-arrow-left mr-1"></i> Back to Invoices List
                </a>
                <button type="submit" class="btn btn-primary bg-gradient-primary font-weight-bold px-4 shadow-sm">
                    <i class="fas fa-save mr-1"></i> Save Invoice
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 1: QUICK ADD CUSTOMER -->
<div class="modal fade" id="modal_quick_customer" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-navy text-white py-2">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-user-plus mr-1"></i> Quick Add New Customer</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="form_quick_customer">
                <div class="modal-body">
                    <div class="form-group mb-2">
                        <label class="form-label-custom">Customer Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-modern" placeholder="Company or Individual Name" required>
                    </div>
                    <div class="form-group mb-2">
                        <label class="form-label-custom">Customer Code</label>
                        <input type="text" name="party_code" class="form-control form-control-modern" value="CUST-<?php echo rand(100,999) ?>">
                    </div>
                    <div class="row">
                        <div class="col-6 form-group mb-2">
                            <label class="form-label-custom">Email</label>
                            <input type="email" name="email" class="form-control form-control-modern" placeholder="email@domain.com">
                        </div>
                        <div class="col-6 form-group mb-2">
                            <label class="form-label-custom">Phone</label>
                            <input type="text" name="phone" class="form-control form-control-modern" placeholder="+679 000 0000">
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label-custom">Address / City</label>
                        <input type="text" name="address" class="form-control form-control-modern" placeholder="Billing address">
                    </div>
                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-primary font-weight-bold">Save & Select Customer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL 2: QUICK ADD PRODUCT -->
<div class="modal fade" id="modal_quick_product" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-navy text-white py-2">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-box-open mr-1"></i> Quick Add New Product / Service</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form id="form_quick_product">
                <div class="modal-body">
                    <div class="form-group mb-2">
                        <label class="form-label-custom">Product / Service Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control form-control-modern" placeholder="e.g. Consulting Fee" required>
                    </div>
                    <div class="form-group mb-2">
                        <label class="form-label-custom">Product Code</label>
                        <input type="text" name="product_code" class="form-control form-control-modern" value="PROD-<?php echo rand(100,999) ?>">
                    </div>
                    <div class="row">
                        <div class="col-6 form-group mb-2">
                            <label class="form-label-custom">Selling Price (FJD)</label>
                            <input type="number" step="any" name="selling_price" class="form-control form-control-modern" value="0.00" required>
                        </div>
                        <div class="col-6 form-group mb-2">
                            <label class="form-label-custom">Category</label>
                            <input type="text" name="category" class="form-control form-control-modern" placeholder="e.g. Services">
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label class="form-label-custom">Description</label>
                        <textarea name="description" rows="2" class="form-control form-control-modern" placeholder="Optional product details"></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light py-2">
                    <button type="button" class="btn btn-sm btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-success font-weight-bold">Save & Add to Row</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function calculate_invoice_totals(){
        var subtotal = 0;
        var total_tax = 0;
        var tax_mode = $('#tax_type').val() || 'exclusive';

        $('#items-table tbody tr.item-row').each(function(){
            var qty = parseFloat($(this).find('.qty').val()) || 0;
            var price = parseFloat($(this).find('.price').val()) || 0;
            var disc_pct = parseFloat($(this).find('.disc_pct').val()) || 0;
            var tax_rate = (tax_mode === 'none') ? 0 : (parseFloat($(this).find('.tax_rate').val()) || 0);

            var line_raw = qty * price;
            var line_disc = line_raw * (disc_pct / 100);
            var line_sub = line_raw - line_disc;

            var line_tax = 0;
            if(tax_mode === 'exclusive'){
                line_tax = line_sub * (tax_rate / 100);
            } else if(tax_mode === 'inclusive'){
                line_tax = line_sub - (line_sub / (1 + (tax_rate / 100)));
            }

            var line_total = (tax_mode === 'exclusive') ? (line_sub + line_tax) : line_sub;

            $(this).find('.amount').val(line_total.toFixed(2));

            subtotal += line_sub;
            total_tax += line_tax;
        });

        var discount = parseFloat($('#discount').val()) || 0;
        var grand_total = (subtotal + (tax_mode === 'exclusive' ? total_tax : 0)) - discount;

        $('#subtotal').val(subtotal.toFixed(2));
        $('#tax_amount').val(total_tax.toFixed(2));
        $('#total').val(grand_total.toFixed(2));
    }

    $(document).ready(function(){
        $('.select2').select2({
            placeholder: "Please select customer...",
            width: '100%'
        });

        // Toggle Product Selection
        $(document).on('change', '.product-select', function(){
            var opt = $(this).find('option:selected');
            var tr = $(this).closest('tr');
            if(opt.val() !== ""){
                tr.find('.item-name').val(opt.text().trim());
                if(opt.data('price')) tr.find('.price').val(opt.data('price'));
                if(opt.data('desc')) tr.find('input[name="item_desc[]"]').val(opt.data('desc'));
                calculate_invoice_totals();
            }
        });

        // Add Row Button
        $('#add_item_row').click(function(){
            var row = `<tr class="item-row">
                <td>
                    <select name="product_id[]" class="form-control form-control-modern product-select mb-1">
                        <option value="">-- Custom Item --</option>
                        <?php foreach($products as $pid => $pr): ?>
                            <option value="<?php echo $pid ?>" data-price="<?php echo $pr['selling_price'] ?>" data-desc="<?php echo htmlspecialchars($pr['description']) ?>">
                                <?php echo htmlspecialchars($pr['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="item_name[]" class="form-control form-control-modern item-name" placeholder="Item / Service Name" required>
                </td>
                <td><input type="text" name="item_desc[]" class="form-control form-control-modern" placeholder="Optional description"></td>
                <td><input type="number" step="any" name="item_qty[]" class="form-control form-control-modern text-center calc-item qty" value="1" required></td>
                <td><input type="number" step="any" name="item_price[]" class="form-control form-control-modern text-right calc-item price" value="0.00" required></td>
                <td><input type="number" step="any" name="item_discount_pct[]" class="form-control form-control-modern text-center calc-item disc_pct" value="0.00"></td>
                <td>
                    <select name="item_account_id[]" class="form-control form-control-modern">
                        <?php foreach($accounts as $ac): ?>
                            <option value="<?php echo $ac['id'] ?>"><?php echo htmlspecialchars($ac['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><input type="number" step="any" name="item_tax_rate[]" class="form-control form-control-modern text-center calc-item tax_rate" value="15.00"></td>
                <td><input type="number" step="any" name="item_amount[]" class="form-control form-control-modern text-right amount bg-light font-weight-bold" value="0.00" readonly></td>
                <td class="text-center align-middle"><button type="button" class="btn btn-xs btn-outline-danger remove_row"><i class="fas fa-trash"></i></button></td>
            </tr>`;
            $('#items-table tbody').append(row);
        });

        // Remove Row
        $(document).on('click', '.remove_row', function(){
            if($('#items-table tbody tr.item-row').length > 1){
                $(this).closest('tr').remove();
                calculate_invoice_totals();
            } else {
                alert_toast("Invoice must contain at least one line item.", 'warning');
            }
        });

        // Calculate Totals on Change
        $(document).on('input change', '.calc-item, .calc-totals, #tax_type', function(){
            calculate_invoice_totals();
        });

        calculate_invoice_totals();

        // Modal Triggers
        $('#btn_open_customer_modal').click(function(){
            $('#modal_quick_customer').modal('show');
        });
        $('#btn_open_product_modal').click(function(){
            $('#modal_quick_product').modal('show');
        });

        // Quick Customer AJAX
        $('#form_quick_customer').submit(function(e){
            e.preventDefault();
            start_loader();
            $.ajax({
                url: _base_url_ + "classes/Master.php?f=save_party",
                method: "POST",
                data: $(this).serialize(),
                dataType: "json",
                error: err => {
                    console.log(err);
                    alert_toast("An error occurred.", 'error');
                    end_loader();
                },
                success: function(resp){
                    end_loader();
                    if(resp.status == 'success'){
                        alert_toast("Customer added successfully.", 'success');
                        $('#modal_quick_customer').modal('hide');
                        setTimeout(function(){ location.reload(); }, 1000);
                    } else {
                        alert_toast(resp.msg || "Failed to save customer.", 'error');
                    }
                }
            });
        });

        // Quick Product AJAX
        $('#form_quick_product').submit(function(e){
            e.preventDefault();
            start_loader();
            $.ajax({
                url: _base_url_ + "classes/Master.php?f=save_product",
                method: "POST",
                data: $(this).serialize(),
                dataType: "json",
                error: err => {
                    console.log(err);
                    alert_toast("An error occurred.", 'error');
                    end_loader();
                },
                success: function(resp){
                    end_loader();
                    if(resp.status == 'success'){
                        alert_toast("Product added successfully.", 'success');
                        $('#modal_quick_product').modal('hide');
                        setTimeout(function(){ location.reload(); }, 1000);
                    } else {
                        alert_toast(resp.msg || "Failed to save product.", 'error');
                    }
                }
            });
        });

        // Submit Invoice Form
        $('#invoice-form').submit(function(e){
            e.preventDefault();
            start_loader();
            $.ajax({
                url: _base_url_ + "classes/Master.php?f=save_invoice",
                data: new FormData($(this)[0]),
                cache: false,
                contentType: false,
                processData: false,
                method: 'POST',
                type: 'POST',
                dataType: 'json',
                error: err => {
                    console.log(err);
                    alert_toast("An error occurred while saving invoice.", 'error');
                    end_loader();
                },
                success: function(resp){
                    if(typeof resp == 'object' && resp.status == 'success'){
                        alert_toast("Invoice saved successfully.", 'success');
                        setTimeout(function(){
                            location.href = './?page=invoices';
                        }, 1000);
                    } else {
                        alert_toast(resp.msg || "Failed to save invoice.", 'error');
                        end_loader();
                    }
                }
            });
        });
    });
</script>
