<?php
require_once('../../config.php');

$tenant_id = $_settings->active_tenant_id();

if(isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0){
    $role_id_clean = intval($_GET['id']);
    $qry = $conn->query("SELECT * from `roles` where id = '{$role_id_clean}' and tenant_id = '{$tenant_id}' ");
    if($qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
    }
}

$permissions_list = isset($permissions) ? explode(',', $permissions) : [];
?>

<div class="container-fluid">
	<form action="" id="role-form">
		<input type="hidden" name="id" value="<?php echo isset($id) ? $id : '' ?>">

		<div class="form-group">
			<label for="name" class="control-label">Role Title / Name</label>
			<input type="text" name="name" id="name" class="form-control form-control-sm rounded-0" value="<?php echo isset($name) ? htmlspecialchars($name) : ''; ?>" placeholder="e.g. Accountant, Sales Manager, HR Officer, Warehouse Clerk" required/>
		</div>

		<div class="form-group">
			<label for="description" class="control-label">Description</label>
			<textarea name="description" id="description" rows="2" class="form-control form-control-sm rounded-0" placeholder="Role duties or access scope..."><?php echo isset($description) ? htmlspecialchars($description) : ''; ?></textarea>
		</div>

		<div class="form-group">
			<label class="control-label font-weight-bold text-navy"><i class="fas fa-user-shield"></i> Custom Permission Matrix</label>

			<div class="card card-outline card-navy p-3">
				<!-- Invoicing & Sales -->
				<h6 class="font-weight-bold text-primary border-bottom pb-1"><i class="fas fa-file-invoice"></i> Invoicing & Sales</h6>
				<div class="row mb-3">
					<div class="col-md-6">
						<div class="custom-control custom-checkbox">
							<input type="checkbox" class="custom-control-input" id="p_invoices_view" name="permissions[]" value="invoices_view" <?php echo in_array('invoices_view', $permissions_list) ? 'checked' : '' ?>>
							<label class="custom-control-label" for="p_invoices_view">View Sales Invoices & Customers</label>
						</div>
					</div>
					<div class="col-md-6">
						<div class="custom-control custom-checkbox">
							<input type="checkbox" class="custom-control-input" id="p_invoices_manage" name="permissions[]" value="invoices_manage" <?php echo in_array('invoices_manage', $permissions_list) ? 'checked' : '' ?>>
							<label class="custom-control-label" for="p_invoices_manage">Create / Edit Invoices & Parties</label>
						</div>
					</div>
				</div>

				<!-- Payments & Receipts -->
				<h6 class="font-weight-bold text-success border-bottom pb-1"><i class="fas fa-receipt"></i> Payment Receipts</h6>
				<div class="row mb-3">
					<div class="col-md-6">
						<div class="custom-control custom-checkbox">
							<input type="checkbox" class="custom-control-input" id="p_payments_view" name="permissions[]" value="payments_view" <?php echo in_array('payments_view', $permissions_list) ? 'checked' : '' ?>>
							<label class="custom-control-label" for="p_payments_view">View Payment Receipts</label>
						</div>
					</div>
					<div class="col-md-6">
						<div class="custom-control custom-checkbox">
							<input type="checkbox" class="custom-control-input" id="p_payments_manage" name="permissions[]" value="payments_manage" <?php echo in_array('payments_manage', $permissions_list) ? 'checked' : '' ?>>
							<label class="custom-control-label" for="p_payments_manage">Record & Allocate Receipts</label>
						</div>
					</div>
				</div>

				<!-- Stock & Mobile Orders -->
				<h6 class="font-weight-bold text-warning border-bottom pb-1"><i class="fas fa-boxes"></i> Commercial Stock & Orders</h6>
				<div class="row mb-3">
					<div class="col-md-6">
						<div class="custom-control custom-checkbox mb-1">
							<input type="checkbox" class="custom-control-input" id="p_products_view" name="permissions[]" value="products_view" <?php echo in_array('products_view', $permissions_list) ? 'checked' : '' ?>>
							<label class="custom-control-label" for="p_products_view">View Product Catalog & Stock</label>
						</div>
						<div class="custom-control custom-checkbox">
							<input type="checkbox" class="custom-control-input" id="p_sales_orders_view" name="permissions[]" value="sales_orders_view" <?php echo in_array('sales_orders_view', $permissions_list) ? 'checked' : '' ?>>
							<label class="custom-control-label" for="p_sales_orders_view">View On-The-Go Sales Orders</label>
						</div>
					</div>
					<div class="col-md-6">
						<div class="custom-control custom-checkbox mb-1">
							<input type="checkbox" class="custom-control-input" id="p_products_manage" name="permissions[]" value="products_manage" <?php echo in_array('products_manage', $permissions_list) ? 'checked' : '' ?>>
							<label class="custom-control-label" for="p_products_manage">Manage Products & Stock Adjustments</label>
						</div>
						<div class="custom-control custom-checkbox">
							<input type="checkbox" class="custom-control-input" id="p_sales_orders_manage" name="permissions[]" value="sales_orders_manage" <?php echo in_array('sales_orders_manage', $permissions_list) ? 'checked' : '' ?>>
							<label class="custom-control-label" for="p_sales_orders_manage">Take Orders & Confirm Sales</label>
						</div>
					</div>
				</div>

				<!-- HR & Payroll -->
				<h6 class="font-weight-bold text-info border-bottom pb-1"><i class="fas fa-user-tie"></i> Payroll & HR</h6>
				<div class="row mb-3">
					<div class="col-md-6">
						<div class="custom-control custom-checkbox mb-1">
							<input type="checkbox" class="custom-control-input" id="p_employees_view" name="permissions[]" value="employees_view" <?php echo in_array('employees_view', $permissions_list) ? 'checked' : '' ?>>
							<label class="custom-control-label" for="p_employees_view">View Employee Profiles</label>
						</div>
						<div class="custom-control custom-checkbox">
							<input type="checkbox" class="custom-control-input" id="p_payroll_view" name="permissions[]" value="payroll_view" <?php echo in_array('payroll_view', $permissions_list) ? 'checked' : '' ?>>
							<label class="custom-control-label" for="p_payroll_view">View Payroll Runs & Payslips</label>
						</div>
					</div>
					<div class="col-md-6">
						<div class="custom-control custom-checkbox mb-1">
							<input type="checkbox" class="custom-control-input" id="p_employees_manage" name="permissions[]" value="employees_manage" <?php echo in_array('employees_manage', $permissions_list) ? 'checked' : '' ?>>
							<label class="custom-control-label" for="p_employees_manage">Manage Employees, Attendance & Leaves</label>
						</div>
						<div class="custom-control custom-checkbox">
							<input type="checkbox" class="custom-control-input" id="p_payroll_manage" name="permissions[]" value="payroll_manage" <?php echo in_array('payroll_manage', $permissions_list) ? 'checked' : '' ?>>
							<label class="custom-control-label" for="p_payroll_manage">Process Payroll & Compliance Exports</label>
						</div>
					</div>
				</div>

				<!-- Accounting & Financial Reports -->
				<h6 class="font-weight-bold text-navy border-bottom pb-1"><i class="fas fa-book"></i> Financial Accounting & Reports</h6>
				<div class="row">
					<div class="col-md-6">
						<div class="custom-control custom-checkbox mb-1">
							<input type="checkbox" class="custom-control-input" id="p_journals_view" name="permissions[]" value="journals_view" <?php echo in_array('journals_view', $permissions_list) ? 'checked' : '' ?>>
							<label class="custom-control-label" for="p_journals_view">View Journal Entries</label>
						</div>
						<div class="custom-control custom-checkbox">
							<input type="checkbox" class="custom-control-input" id="p_reports_view" name="permissions[]" value="reports_view" <?php echo in_array('reports_view', $permissions_list) ? 'checked' : '' ?>>
							<label class="custom-control-label" for="p_reports_view">Access Financial Statements (GL, P&L, Balance Sheet)</label>
						</div>
					</div>
					<div class="col-md-6">
						<div class="custom-control custom-checkbox mb-1">
							<input type="checkbox" class="custom-control-input" id="p_journals_manage" name="permissions[]" value="journals_manage" <?php echo in_array('journals_manage', $permissions_list) ? 'checked' : '' ?>>
							<label class="custom-control-label" for="p_journals_manage">Post / Manage Journal Entries</label>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div class="form-group">
			<label for="status" class="control-label">Status</label>
			<select name="status" id="status" class="form-control form-control-sm rounded-0" required>
				<option value="1" <?php echo !isset($status) || $status == 1 ? 'selected' : '' ?>>Active</option>
				<option value="0" <?php echo isset($status) && $status == 0 ? 'selected' : '' ?>>Inactive</option>
			</select>
		</div>
	</form>
</div>

<script>
	$(document).ready(function(){
		$('#role-form').submit(function(e){
			e.preventDefault();
            var _this = $(this)
			$('.err-msg').remove();
			start_loader();
			$.ajax({
				url:_base_url_+"classes/Master.php?f=save_role",
				data: new FormData($(this)[0]),
                cache: false,
                contentType: false,
                processData: false,
                method: 'POST',
                type: 'POST',
                dataType: 'json',
				error:err=>{
					console.log(err)
					alert_toast("An error occurred",'error');
					end_loader();
				},
				success:function(resp){
					if(typeof resp =='object' && resp.status == 'success'){
						location.reload()
					}else if(resp.status == 'failed' && !!resp.msg){
                        var el = $('<div>')
                            el.addClass("alert alert-danger err-msg").text(resp.msg)
                            _this.prepend(el)
                            el.show('slow')
                            end_loader()
                    }else{
						alert_toast("An error occurred",'error');
						end_loader();
					}
				}
			})
		});
	});
</script>