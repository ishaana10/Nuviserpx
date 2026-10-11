<?php
require_once('../config.php');
Class Master extends DBConnection {
	private $settings;
	public function __construct(){
		global $_settings;
		$this->settings = $_settings;
		parent::__construct();
	}
	public function __destruct(){
		parent::__destruct();
	}
	function capture_err(){
		if(!$this->conn->error)
			return false;
		else{
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
			return json_encode($resp);
			exit;
		}
	}
	function save_group(){
		extract($_POST);
		$data = "";
		foreach($_POST as $k =>$v){
			if(!in_array($k,array('id'))){
				if(!is_numeric($v))
					$v = $this->conn->real_escape_string($v);
				if(!empty($data)) $data .=",";
				$data .= " `{$k}`='{$v}' ";
			}
		}
		$tenant_id = $this->settings->active_tenant_id();
		if(empty($id)){
			$sql = "INSERT INTO `group_list` set `tenant_id`='{$tenant_id}', {$data} ";
		}else{
			$sql = "UPDATE `group_list` set {$data} where id = '{$id}' and tenant_id = '{$tenant_id}' ";
		}
		$check = $this->conn->query("SELECT * FROM `group_list` where `name` = '{$name}' and tenant_id = '{$tenant_id}' and delete_flag = 0 ".($id > 0 ? " and id != '{$id}'" : ""));
		if($check->num_rows > 0){
			$resp['status'] = 'failed';
			$resp['msg'] = " Account's Group Name already exists.";
		}else{
			$save = $this->conn->query($sql);
			if($save){
				$gid = !empty($id) ? $id : $this->conn->insert_id;
				$resp['status'] = 'success';
				if(empty($id))
					$resp['msg'] = " Account's Group has successfully added.";
				else
					$resp['msg'] = " Account's Group details has been updated successfully.";
			}else{
				$resp['status'] = 'failed';
				$resp['msg'] = "An error occured.";
				$resp['err'] = $this->conn->error."[{$sql}]";
			}
		}
		if($resp['status'] =='success')
			$this->settings->set_flashdata('success',$resp['msg']);
		return json_encode($resp);
	}
	function delete_group(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$id = intval($id);
		$del = $this->conn->query("UPDATE `group_list` set delete_flag = 1 where id = '{$id}' and tenant_id = '{$tenant_id}'");
		if($del){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success'," Account's Group has been deleted successfully.");
		}else{
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);
	}

	function save_role(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$id = !empty($id) ? $this->conn->real_escape_string($id) : '';

		$name = $this->conn->real_escape_string($name);
		$description = isset($description) ? $this->conn->real_escape_string($description) : '';
		$permissions = isset($permissions) && is_array($permissions) ? implode(',', $permissions) : '';
		$status = isset($status) ? (int)$status : 1;

		$check = $this->conn->query("SELECT * FROM `roles` WHERE `name` = '{$name}' AND tenant_id = '{$tenant_id}' AND delete_flag = 0 " . (!empty($id) ? " AND id != '{$id}'" : ""))->num_rows;
		if($check > 0){
			return json_encode(['status' => 'failed', 'msg' => 'Role name already exists for this company.']);
		}

		if(empty($id)){
			$sql = "INSERT INTO `roles` SET
				`tenant_id` = '{$tenant_id}',
				`name` = '{$name}',
				`description` = '{$description}',
				`permissions` = '{$permissions}',
				`status` = '{$status}'";
		} else {
			$sql = "UPDATE `roles` SET
				`name` = '{$name}',
				`description` = '{$description}',
				`permissions` = '{$permissions}',
				`status` = '{$status}'
				WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
		}

		$save = $this->conn->query($sql);
		if($save){
			$resp['status'] = 'success';
			$resp['msg'] = empty($id) ? " Custom role created successfully." : " Role permissions updated successfully.";
			$this->settings->set_flashdata('success', $resp['msg']);
		} else {
			$resp['status'] = 'failed';
			$resp['msg'] = "An error occurred while saving role.";
			$resp['err'] = $this->conn->error;
		}

		return json_encode($resp);
	}

	function delete_role(){
		extract($_POST);
		$id = intval($id);
		$tenant_id = $this->settings->active_tenant_id();

		$del = $this->conn->query("UPDATE `roles` SET `delete_flag` = 1 WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
		if($del){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success', " Role deleted successfully.");
		} else {
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);
	}

	function email_invoice(){
		require_once(__DIR__ . '/Emailer.php');
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$id = intval($id);

		$inv_qry = $this->conn->query("
			SELECT i.*, p.name AS party_name, p.email AS party_email
			FROM `invoice_list` i
			INNER JOIN `party_list` p ON i.party_id = p.id
			WHERE i.id = '{$id}' AND i.tenant_id = '{$tenant_id}'
		");

		if(!$inv_qry || $inv_qry->num_rows == 0){
			return json_encode(['status' => 'failed', 'msg' => 'Invoice not found.']);
		}

		$invoice = $inv_qry->fetch_assoc();
		$email_to = !empty($custom_email) ? $this->conn->real_escape_string($custom_email) : $invoice['party_email'];

		if(empty($email_to)){
			return json_encode(['status' => 'failed', 'msg' => 'No email address found for this customer. Please specify an email address.']);
		}

		$items_qry = $this->conn->query("SELECT * FROM `invoice_items` WHERE invoice_id = '{$id}'");
		$items = [];
		while($it = $items_qry->fetch_assoc()){
			$items[] = $it;
		}

		$sys_info = [
			'name' => $this->settings->info('name'),
			'email' => $this->settings->info('email')
		];

		$send = Emailer::send_invoice_email($email_to, $invoice, $items, $sys_info);
		if($send){
			return json_encode(['status' => 'success', 'msg' => "Invoice #{$invoice['invoice_no']} sent successfully to {$email_to}."]);
		} else {
			return json_encode(['status' => 'failed', 'msg' => 'Failed to send invoice email. Please check SMTP settings.']);
		}
	}

	function email_payment_receipt(){
		require_once(__DIR__ . '/Emailer.php');
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$id = intval($id);

		$pay_qry = $this->conn->query("
			SELECT p.*, pt.name AS party_name, pt.email AS party_email
			FROM `payment_list` p
			INNER JOIN `party_list` pt ON p.party_id = pt.id
			WHERE p.id = '{$id}' AND p.tenant_id = '{$tenant_id}'
		");

		if(!$pay_qry || $pay_qry->num_rows == 0){
			return json_encode(['status' => 'failed', 'msg' => 'Payment receipt not found.']);
		}

		$payment = $pay_qry->fetch_assoc();
		$email_to = !empty($custom_email) ? $this->conn->real_escape_string($custom_email) : $payment['party_email'];

		if(empty($email_to)){
			return json_encode(['status' => 'failed', 'msg' => 'No email address found for this customer. Please specify an email address.']);
		}

		$alloc_qry = $this->conn->query("
			SELECT pa.amount AS allocated_amount, i.invoice_no, i.invoice_date, i.total AS invoice_total
			FROM `payment_allocations` pa
			INNER JOIN `invoice_list` i ON pa.invoice_id = i.id
			WHERE pa.payment_id = '{$id}'
		");
		$allocations = [];
		while($al = $alloc_qry->fetch_assoc()){
			$allocations[] = $al;
		}

		$sys_info = [
			'name' => $this->settings->info('name'),
			'email' => $this->settings->info('email')
		];

		$send = Emailer::send_payment_receipt_email($email_to, $payment, $allocations, $sys_info);
		if($send){
			return json_encode(['status' => 'success', 'msg' => "Payment Receipt #{$payment['payment_no']} sent successfully to {$email_to}."]);
		} else {
			return json_encode(['status' => 'failed', 'msg' => 'Failed to send payment receipt email. Please check SMTP settings.']);
		}
	}

	function email_payslip(){
		require_once(__DIR__ . '/Emailer.php');
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$id = intval($id);

		$pay_qry = $this->conn->query("
			SELECT p.*, concat(e.firstname, ' ', e.lastname) as emp_name, e.phone
			FROM `payroll_list` p
			INNER JOIN `employee_list` e ON p.employee_id = e.id
			WHERE p.id = '{$id}' AND p.tenant_id = '{$tenant_id}'
		");

		if(!$pay_qry || $pay_qry->num_rows == 0){
			return json_encode(['status' => 'failed', 'msg' => 'Payroll record not found.']);
		}

		$payroll = $pay_qry->fetch_assoc();
		$email_to = !empty($custom_email) ? $this->conn->real_escape_string($custom_email) : '';

		if(empty($email_to)){
			return json_encode(['status' => 'failed', 'msg' => 'Please enter the employee email address.']);
		}

		$sys_info = [
			'name' => $this->settings->info('name'),
			'email' => $this->settings->info('email')
		];

		$send = Emailer::send_payslip_email($email_to, $payroll, $sys_info);
		if($send){
			return json_encode(['status' => 'success', 'msg' => "Payslip for {$payroll['emp_name']} ({$payroll['salary_month']}) sent successfully to {$email_to}."]);
		} else {
			return json_encode(['status' => 'failed', 'msg' => 'Failed to send payslip email. Please check SMTP settings.']);
		}
	}

	function save_api_key(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$user_id = $this->settings->userdata('id');
		$id = !empty($id) ? $this->conn->real_escape_string($id) : '';

		$name = $this->conn->real_escape_string($name);
		$permissions = isset($permissions) && is_array($permissions) ? implode(',', $permissions) : '*';
		$status = isset($status) ? (int)$status : 1;

		if(empty($id)){
			$api_key = 'erpx_' . bin2hex(random_bytes(16));
			$api_secret = bin2hex(random_bytes(32));

			$sql = "INSERT INTO `api_keys` SET
				`tenant_id` = '{$tenant_id}',
				`name` = '{$name}',
				`api_key` = '{$api_key}',
				`api_secret` = '{$api_secret}',
				`permissions` = '{$permissions}',
				`status` = '{$status}',
				`created_by` = '{$user_id}'";
		} else {
			$sql = "UPDATE `api_keys` SET
				`name` = '{$name}',
				`permissions` = '{$permissions}',
				`status` = '{$status}'
				WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
		}

		$save = $this->conn->query($sql);
		if($save){
			$resp['status'] = 'success';
			$resp['msg'] = empty($id) ? " API Key generated successfully." : " API Key updated successfully.";
			$this->settings->set_flashdata('success', $resp['msg']);
		} else {
			$resp['status'] = 'failed';
			$resp['msg'] = "An error occurred while saving API Key.";
			$resp['err'] = $this->conn->error;
		}

		return json_encode($resp);
	}

	function delete_api_key(){
		extract($_POST);
		$id = $this->conn->real_escape_string($id);
		$tenant_id = $this->settings->active_tenant_id();

		$del = $this->conn->query("DELETE FROM `api_keys` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
		if($del){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success', " API Key revoked and deleted successfully.");
		} else {
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);
	}

	function save_stock_adjustment(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$user_id = $this->settings->userdata('id');

		$product_id = (int)$product_id;
		$type = !empty($type) ? $this->conn->real_escape_string($type) : 'in';
		$qty = (float)$qty;
		$unit_cost = isset($unit_cost) ? (float)$unit_cost : 0;
		$reference = isset($reference) ? $this->conn->real_escape_string($reference) : 'Stock Adj '.date('Ymd');
		$notes = isset($notes) ? $this->conn->real_escape_string($notes) : '';

		if($product_id <= 0 || $qty <= 0){
			return json_encode(['status' => 'failed', 'msg' => 'Please select a product and enter a valid positive quantity.']);
		}

		$prod_qry = $this->conn->query("SELECT * FROM `product_list` WHERE id = '{$product_id}' AND tenant_id = '{$tenant_id}'");
		if(!$prod_qry || $prod_qry->num_rows == 0){
			return json_encode(['status' => 'failed', 'msg' => 'Selected product not found.']);
		}

		// Update stock quantity: type 'in' adds, 'out' subtracts, 'adjustment' sets or adds
		if($type == 'in'){
			$this->conn->query("UPDATE `product_list` SET `stock_quantity` = `stock_quantity` + {$qty} WHERE id = '{$product_id}'");
		} elseif($type == 'out'){
			$this->conn->query("UPDATE `product_list` SET `stock_quantity` = `stock_quantity` - {$qty} WHERE id = '{$product_id}'");
		} elseif($type == 'adjustment'){
			if(isset($_POST['is_absolute']) && $_POST['is_absolute'] == 1){
				$this->conn->query("UPDATE `product_list` SET `stock_quantity` = '{$qty}' WHERE id = '{$product_id}'");
			} else {
				$this->conn->query("UPDATE `product_list` SET `stock_quantity` = `stock_quantity` + {$qty} WHERE id = '{$product_id}'");
			}
		}

		// Insert inventory log
		$save_log = $this->conn->query("INSERT INTO `inventory_logs` SET
			`tenant_id` = '{$tenant_id}',
			`product_id` = '{$product_id}',
			`type` = '{$type}',
			`qty` = '{$qty}',
			`unit_cost` = '{$unit_cost}',
			`reference` = '{$reference}',
			`notes` = '{$notes}',
			`created_by` = '{$user_id}'");

		if($save_log){
			$resp['status'] = 'success';
			$resp['msg'] = " Stock adjustment saved successfully.";
			$this->settings->set_flashdata('success', $resp['msg']);
		} else {
			$resp['status'] = 'failed';
			$resp['msg'] = "An error occurred while saving stock adjustment.";
			$resp['err'] = $this->conn->error;
		}

		return json_encode($resp);
	}

	function save_sales_order(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$user_id = $this->settings->userdata('id');
		$id = !empty($id) ? $this->conn->real_escape_string($id) : '';

		$order_no = !empty($order_no) ? $this->conn->real_escape_string($order_no) : 'SO-'.date('Ym').'-'.rand(1000,9999);
		$party_id = $this->conn->real_escape_string($party_id);
		$order_date = !empty($order_date) ? $this->conn->real_escape_string($order_date) : date('Y-m-d');
		$type = !empty($type) ? $this->conn->real_escape_string($type) : 'proforma';
		$subtotal = isset($subtotal) ? (float)$subtotal : 0;
		$tax_amount = isset($tax_amount) ? (float)$tax_amount : 0;
		$discount = isset($discount) ? (float)$discount : 0;
		$total = isset($total) ? (float)$total : 0;
		$notes = isset($notes) ? $this->conn->real_escape_string($notes) : '';
		$status = !empty($status) ? $this->conn->real_escape_string($status) : 'proforma';

		if(empty($id)){
			$sql = "INSERT INTO `sales_order_list` SET
				`tenant_id` = '{$tenant_id}',
				`order_no` = '{$order_no}',
				`party_id` = '{$party_id}',
				`order_date` = '{$order_date}',
				`type` = '{$type}',
				`subtotal` = '{$subtotal}',
				`tax_amount` = '{$tax_amount}',
				`discount` = '{$discount}',
				`total` = '{$total}',
				`status` = '{$status}',
				`notes` = '{$notes}',
				`created_by` = '{$user_id}'";
			$save = $this->conn->query($sql);
			$sales_order_id = $this->conn->insert_id;
		} else {
			$sql = "UPDATE `sales_order_list` SET
				`order_no` = '{$order_no}',
				`party_id` = '{$party_id}',
				`order_date` = '{$order_date}',
				`type` = '{$type}',
				`subtotal` = '{$subtotal}',
				`tax_amount` = '{$tax_amount}',
				`discount` = '{$discount}',
				`total` = '{$total}',
				`status` = '{$status}',
				`notes` = '{$notes}'
				WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
			$save = $this->conn->query($sql);
			$sales_order_id = $id;
		}

		if($save){
			// Save Order Items
			$this->conn->query("DELETE FROM `sales_order_items` WHERE sales_order_id = '{$sales_order_id}'");
			if(isset($item_name) && is_array($item_name)){
				foreach($item_name as $k => $v){
					$i_name = $this->conn->real_escape_string($v);
					if(empty($i_name)) continue;

					$p_id = isset($product_id[$k]) && is_numeric($product_id[$k]) ? (int)$product_id[$k] : 'NULL';
					$i_desc = isset($item_desc[$k]) ? $this->conn->real_escape_string($item_desc[$k]) : '';
					$i_qty = isset($item_qty[$k]) ? (float)$item_qty[$k] : 1;
					$i_price = isset($item_price[$k]) ? (float)$item_price[$k] : 0;
					$i_tax_rate = isset($item_tax_rate[$k]) ? (float)$item_tax_rate[$k] : 0;
					$i_tax_amt = round(($i_qty * $i_price) * ($i_tax_rate / 100), 2);
					$i_amount = round(($i_qty * $i_price) + $i_tax_amt, 2);

					$item_sql = "INSERT INTO `sales_order_items` SET
						`sales_order_id` = '{$sales_order_id}',
						`product_id` = {$p_id},
						`item_name` = '{$i_name}',
						`description` = '{$i_desc}',
						`qty` = '{$i_qty}',
						`unit_price` = '{$i_price}',
						`tax_rate` = '{$i_tax_rate}',
						`tax_amount` = '{$i_tax_amt}',
						`amount` = '{$i_amount}'";
					$this->conn->query($item_sql);
				}
			}

			// If status is confirmed/converted, auto adjust stock
			if($status == 'confirmed' || $status == 'converted_to_invoice'){
				$this->confirm_sales_order_internal($sales_order_id);
			}

			$resp['status'] = 'success';
			$resp['msg'] = empty($id) ? " Sales Order / Proforma Invoice created successfully." : " Order details updated successfully.";
			$this->settings->set_flashdata('success', $resp['msg']);
		} else {
			$resp['status'] = 'failed';
			$resp['msg'] = "An error occurred while saving sales order.";
			$resp['err'] = $this->conn->error;
		}

		return json_encode($resp);
	}

	function confirm_sales_order_internal($sales_order_id){
		$tenant_id = $this->settings->active_tenant_id();
		$user_id = $this->settings->userdata('id');

		$order_qry = $this->conn->query("SELECT * FROM `sales_order_list` WHERE id = '{$sales_order_id}' AND tenant_id = '{$tenant_id}'");
		if(!$order_qry || $order_qry->num_rows == 0) return false;

		$order = $order_qry->fetch_assoc();

		// Auto stock adjustment if not yet adjusted
		if($order['stock_adjusted'] == 0){
			$items = $this->conn->query("SELECT * FROM `sales_order_items` WHERE sales_order_id = '{$sales_order_id}'");
			$total_cogs = 0;

			while($item = $items->fetch_assoc()){
				$pid = (int)$item['product_id'];
				$qty = (float)$item['qty'];

				if($pid > 0 && $qty > 0){
					$prod = $this->conn->query("SELECT cost_price, stock_quantity FROM `product_list` WHERE id = '{$pid}'")->fetch_assoc();
					$cost = isset($prod['cost_price']) ? (float)$prod['cost_price'] : 0;
					$total_cogs += ($cost * $qty);

					// Deduct stock
					$this->conn->query("UPDATE `product_list` SET `stock_quantity` = `stock_quantity` - {$qty} WHERE id = '{$pid}'");

					// Log inventory movement
					$this->conn->query("INSERT INTO `inventory_logs` SET
						`tenant_id` = '{$tenant_id}',
						`product_id` = '{$pid}',
						`type` = 'out',
						`qty` = '{$qty}',
						`unit_cost` = '{$cost}',
						`reference` = '" . $this->conn->real_escape_string($order['order_no']) . "',
						`notes` = 'Auto stock deduction on sales order confirmation',
						`created_by` = '{$user_id}'");
				}
			}

			// Mark stock adjusted
			$this->conn->query("UPDATE `sales_order_list` SET `stock_adjusted` = 1, `status` = 'confirmed' WHERE id = '{$sales_order_id}'");

			// Convert to Sales Invoice if not created
			if(empty($order['invoice_id'])){
				$inv_no = 'INV-'.str_replace(['SO-', 'PRO-'], '', $order['order_no']);

				// Accounts
				$ar_acc = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%receivable%' OR `name` LIKE '%asset%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
				$ar_acc_id = ($ar_acc && $ar_acc->num_rows > 0) ? $ar_acc->fetch_assoc()['id'] : 1;

				$rev_acc = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%sales%' OR `name` LIKE '%revenue%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
				$rev_acc_id = ($rev_acc && $rev_acc->num_rows > 0) ? $rev_acc->fetch_assoc()['id'] : 2;

				// Create Journal Entry
				$prefix = date("Ym-");
				$code = sprintf("%'.05d",1);
				while(true){
					$check = $this->conn->query("SELECT * FROM `journal_entries` where `code` = '{$prefix}{$code}' and tenant_id = '{$tenant_id}' ")->num_rows;
					if($check > 0){
						$code = sprintf("%'.05d",ceil($code) + 1);
					}else{
						break;
					}
				}
				$journal_code = $prefix.$code;
				$journal_date = $order['order_date'];
				$description = $this->conn->real_escape_string("Sales Invoice {$inv_no} from Order {$order['order_no']}");

				$j_sql = "INSERT INTO `journal_entries` (`tenant_id`, `code`, `journal_date`, `description`, `user_id`) VALUES ('{$tenant_id}', '{$journal_code}', '{$journal_date}', '{$description}', '{$user_id}')";
				$this->conn->query($j_sql);
				$journal_id = $this->conn->insert_id;

				$j_items = "INSERT INTO `journal_items` (`journal_id`, `account_id`, `group_id`, `amount`) VALUES
					('{$journal_id}', '{$ar_acc_id}', '1', '{$order['total']}'),
					('{$journal_id}', '{$rev_acc_id}', '2', '{$order['total']}')";
				$this->conn->query($j_items);

				// Insert Invoice
				$inv_sql = "INSERT INTO `invoice_list` SET
					`tenant_id` = '{$tenant_id}',
					`invoice_no` = '{$inv_no}',
					`party_id` = '{$order['party_id']}',
					`invoice_date` = '{$order['order_date']}',
					`due_date` = '" . date('Y-m-d', strtotime($order['order_date'] . ' +30 days')) . "',
					`subtotal` = '{$order['subtotal']}',
					`tax_amount` = '{$order['tax_amount']}',
					`discount` = '{$order['discount']}',
					`total` = '{$order['total']}',
					`paid_amount` = '0.00',
					`balance` = '{$order['total']}',
					`status` = 'unpaid',
					`notes` = '" . $this->conn->real_escape_string($order['notes']) . "',
					`journal_id` = '{$journal_id}',
					`created_by` = '{$user_id}'";
				$this->conn->query($inv_sql);
				$invoice_id = $this->conn->insert_id;

				// Copy Items to Invoice
				$items2 = $this->conn->query("SELECT * FROM `sales_order_items` WHERE sales_order_id = '{$sales_order_id}'");
				while($it2 = $items2->fetch_assoc()){
					$this->conn->query("INSERT INTO `invoice_items` SET
						`invoice_id` = '{$invoice_id}',
						`item_name` = '" . $this->conn->real_escape_string($it2['item_name']) . "',
						`description` = '" . $this->conn->real_escape_string($it2['description']) . "',
						`qty` = '{$it2['qty']}',
						`unit_price` = '{$it2['unit_price']}',
						`tax_rate` = '{$it2['tax_rate']}',
						`tax_amount` = '{$it2['tax_amount']}',
						`amount` = '{$it2['amount']}'");
				}

				// Update order with invoice link
				$this->conn->query("UPDATE `sales_order_list` SET `invoice_id` = '{$invoice_id}', `status` = 'converted_to_invoice' WHERE id = '{$sales_order_id}'");
			}
		}
		return true;
	}

	function confirm_sales_order(){
		extract($_POST);
		$id = $this->conn->real_escape_string($id);
		if($this->confirm_sales_order_internal($id)){
			$resp['status'] = 'success';
			$resp['msg'] = " Sale confirmed, auto stock adjusted, and converted to Sales Invoice successfully.";
			$this->settings->set_flashdata('success', $resp['msg']);
		} else {
			$resp['status'] = 'failed';
			$resp['msg'] = "An error occurred while confirming order.";
		}
		return json_encode($resp);
	}

	function delete_sales_order(){
		extract($_POST);
		$id = $this->conn->real_escape_string($id);
		$tenant_id = $this->settings->active_tenant_id();

		$order = $this->conn->query("SELECT * FROM `sales_order_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc();
		if($order){
			// If stock was adjusted, restore stock
			if($order['stock_adjusted'] == 1){
				$items = $this->conn->query("SELECT * FROM `sales_order_items` WHERE sales_order_id = '{$id}'");
				while($it = $items->fetch_assoc()){
					$pid = (int)$it['product_id'];
					$qty = (float)$it['qty'];
					if($pid > 0 && $qty > 0){
						$this->conn->query("UPDATE `product_list` SET `stock_quantity` = `stock_quantity` + {$qty} WHERE id = '{$pid}'");
					}
				}
			}
			$this->conn->query("DELETE FROM `sales_order_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success', " Order deleted successfully.");
		} else {
			$resp['status'] = 'failed';
			$resp['msg'] = "Order not found.";
		}
		return json_encode($resp);
	}

	function save_product(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$id = !empty($id) ? $this->conn->real_escape_string($id) : '';

		$product_code = $this->conn->real_escape_string($product_code);
		$name = $this->conn->real_escape_string($name);
		$category = isset($category) ? $this->conn->real_escape_string($category) : '';
		$unit = !empty($unit) ? $this->conn->real_escape_string($unit) : 'pcs';
		$cost_price = isset($cost_price) ? (float)$cost_price : 0;
		$selling_price = isset($selling_price) ? (float)$selling_price : 0;
		$stock_quantity = isset($stock_quantity) ? (float)$stock_quantity : 0;
		$reorder_level = isset($reorder_level) ? (float)$reorder_level : 10;
		$description = isset($description) ? $this->conn->real_escape_string($description) : '';
		$status = isset($status) ? (int)$status : 1;

		$check = $this->conn->query("SELECT * FROM `product_list` WHERE `product_code` = '{$product_code}' AND tenant_id = '{$tenant_id}' AND delete_flag = 0 " . (!empty($id) ? " AND id != '{$id}'" : ""))->num_rows;
		if($check > 0){
			return json_encode(['status' => 'failed', 'msg' => 'Product Code already exists.']);
		}

		if(empty($id)){
			$sql = "INSERT INTO `product_list` SET
				`tenant_id` = '{$tenant_id}',
				`product_code` = '{$product_code}',
				`name` = '{$name}',
				`category` = '{$category}',
				`unit` = '{$unit}',
				`cost_price` = '{$cost_price}',
				`selling_price` = '{$selling_price}',
				`stock_quantity` = '{$stock_quantity}',
				`reorder_level` = '{$reorder_level}',
				`description` = '{$description}',
				`status` = '{$status}'";
			$save = $this->conn->query($sql);
			$product_id = $this->conn->insert_id;

			if($save && $stock_quantity > 0){
				// Log initial stock entry
				$this->conn->query("INSERT INTO `inventory_logs` SET
					`tenant_id` = '{$tenant_id}',
					`product_id` = '{$product_id}',
					`type` = 'in',
					`qty` = '{$stock_quantity}',
					`unit_cost` = '{$cost_price}',
					`reference` = 'Initial Stock',
					`notes` = 'Initial opening stock entry',
					`created_by` = '" . $this->settings->userdata('id') . "'");
			}
		} else {
			$sql = "UPDATE `product_list` SET
				`product_code` = '{$product_code}',
				`name` = '{$name}',
				`category` = '{$category}',
				`unit` = '{$unit}',
				`cost_price` = '{$cost_price}',
				`selling_price` = '{$selling_price}',
				`reorder_level` = '{$reorder_level}',
				`description` = '{$description}',
				`status` = '{$status}'
				WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
			$save = $this->conn->query($sql);
		}

		if($save){
			$resp['status'] = 'success';
			$resp['msg'] = empty($id) ? " Product added successfully." : " Product details updated successfully.";
			$this->settings->set_flashdata('success', $resp['msg']);
		} else {
			$resp['status'] = 'failed';
			$resp['msg'] = "An error occurred while saving product.";
			$resp['err'] = $this->conn->error;
		}

		return json_encode($resp);
	}

	function delete_product(){
		extract($_POST);
		$id = $this->conn->real_escape_string($id);
		$tenant_id = $this->settings->active_tenant_id();

		$del = $this->conn->query("UPDATE `product_list` SET `delete_flag` = 1 WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
		if($del){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success', " Product deleted successfully.");
		} else {
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);
	}

	function save_payment(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$user_id = $this->settings->userdata('id');
		$id = !empty($id) ? $this->conn->real_escape_string($id) : '';

		$payment_no = !empty($payment_no) ? $this->conn->real_escape_string($payment_no) : 'REC-'.date('Ym').'-'.rand(1000,9999);
		$type = !empty($type) ? $this->conn->real_escape_string($type) : 'receipt';
		$party_id = $this->conn->real_escape_string($party_id);
		$bank_account_id = !empty($bank_account_id) ? (int)$bank_account_id : null;
		$payment_date = !empty($payment_date) ? $this->conn->real_escape_string($payment_date) : date('Y-m-d');
		$amount = isset($amount) ? (float)$amount : 0;
		$method = !empty($method) ? $this->conn->real_escape_string($method) : 'cash';
		$reference = isset($reference) ? $this->conn->real_escape_string($reference) : '';
		$notes = isset($notes) ? $this->conn->real_escape_string($notes) : '';
		$status = !empty($status) ? $this->conn->real_escape_string($status) : 'posted';
		$currency = !empty($currency) ? $this->conn->real_escape_string($currency) : 'FJD';

		// Get party details
		$party_qry = $this->conn->query("SELECT * FROM `party_list` WHERE id = '{$party_id}' AND tenant_id = '{$tenant_id}'");
		if(!$party_qry || $party_qry->num_rows == 0){
			return json_encode(['status' => 'failed', 'msg' => 'Selected Customer / Party not found.']);
		}
		$party = $party_qry->fetch_assoc();

		// Accounts for Journal Entry (Cash/Bank Dr, Accounts Receivable Cr)
		$cash_acc = $this->conn->query("SELECT id FROM `account_list` WHERE `name` LIKE '%cash%' AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
		$cash_acc_id = !empty($bank_account_id) ? $bank_account_id : (($cash_acc && $cash_acc->num_rows > 0) ? $cash_acc->fetch_assoc()['id'] : 1);

		$ar_acc = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%receivable%' OR `name` LIKE '%asset%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
		$ar_acc_id = ($ar_acc && $ar_acc->num_rows > 0) ? $ar_acc->fetch_assoc()['id'] : 1;

		// Create/Update Journal
		$prefix = date("Ym-");
		$code = sprintf("%'.05d",1);
		while(true){
			$check = $this->conn->query("SELECT * FROM `journal_entries` where `code` = '{$prefix}{$code}' and tenant_id = '{$tenant_id}' ")->num_rows;
			if($check > 0){
				$code = sprintf("%'.05d",ceil($code) + 1);
			}else{
				break;
			}
		}
		$journal_code = $prefix.$code;
		$journal_date = $payment_date;
		$description = $this->conn->real_escape_string("Payment Receipt {$payment_no} from {$party['name']} - Amount: \${$amount}");

		if(empty($id)){
			$j_sql = "INSERT INTO `journal_entries` (`tenant_id`, `code`, `journal_date`, `description`, `user_id`) VALUES ('{$tenant_id}', '{$journal_code}', '{$journal_date}', '{$description}', '{$user_id}')";
			$this->conn->query($j_sql);
			$journal_id = $this->conn->insert_id;

			$j_items = "INSERT INTO `journal_items` (`journal_id`, `account_id`, `group_id`, `amount`) VALUES
				('{$journal_id}', '{$cash_acc_id}', '1', '{$amount}'),
				('{$journal_id}', '{$ar_acc_id}', '2', '{$amount}')";
			$this->conn->query($j_items);

			$bank_account_sql = $bank_account_id ? "'{$bank_account_id}'" : "NULL";

			$sql = "INSERT INTO `payment_list` SET
				`tenant_id` = '{$tenant_id}',
				`payment_no` = '{$payment_no}',
				`type` = '{$type}',
				`party_id` = '{$party_id}',
				`bank_account_id` = {$bank_account_sql},
				`payment_date` = '{$payment_date}',
				`amount` = '{$amount}',
				`method` = '{$method}',
				`reference` = '{$reference}',
				`notes` = '{$notes}',
				`status` = '{$status}',
				`currency` = '{$currency}',
				`journal_id` = '{$journal_id}',
				`created_by` = '{$user_id}'";
			$save = $this->conn->query($sql);
			$payment_id = $this->conn->insert_id;
		} else {
			$existing = $this->conn->query("SELECT journal_id FROM `payment_list` WHERE id = '{$id}'")->fetch_assoc();
			$journal_id = isset($existing['journal_id']) ? $existing['journal_id'] : null;
			if($journal_id){
				$this->conn->query("UPDATE `journal_entries` SET `description` = '{$description}', `journal_date` = '{$journal_date}' WHERE id = '{$journal_id}'");
				$this->conn->query("DELETE FROM `journal_items` WHERE journal_id = '{$journal_id}'");
				$j_items = "INSERT INTO `journal_items` (`journal_id`, `account_id`, `group_id`, `amount`) VALUES
					('{$journal_id}', '{$cash_acc_id}', '1', '{$amount}'),
					('{$journal_id}', '{$ar_acc_id}', '2', '{$amount}')";
				$this->conn->query($j_items);
			}

			$bank_account_sql = $bank_account_id ? "'{$bank_account_id}'" : "NULL";

			$sql = "UPDATE `payment_list` SET
				`payment_no` = '{$payment_no}',
				`type` = '{$type}',
				`party_id` = '{$party_id}',
				`bank_account_id` = {$bank_account_sql},
				`payment_date` = '{$payment_date}',
				`amount` = '{$amount}',
				`method` = '{$method}',
				`reference` = '{$reference}',
				`notes` = '{$notes}',
				`status` = '{$status}',
				`currency` = '{$currency}'
				WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
			$save = $this->conn->query($sql);
			$payment_id = $id;
		}

		if($save){
			// Clear old allocations & recalculate affected invoice balances
			$old_allocs = $this->conn->query("SELECT invoice_id FROM `payment_allocations` WHERE payment_id = '{$payment_id}'");
			$affected_invoices = [];
			while($oa = $old_allocs->fetch_assoc()){
				$affected_invoices[] = $oa['invoice_id'];
			}
			$this->conn->query("DELETE FROM `payment_allocations` WHERE payment_id = '{$payment_id}'");

			// Save Payment Allocations across invoice(s)
			if(isset($invoice_alloc) && is_array($invoice_alloc)){
				foreach($invoice_alloc as $inv_id => $alloc_amt){
					$alloc_amt = (float)$alloc_amt;
					if($alloc_amt <= 0) continue;

					$inv_id = (int)$inv_id;
					$affected_invoices[] = $inv_id;

					$alloc_sql = "INSERT INTO `payment_allocations` SET
						`payment_id` = '{$payment_id}',
						`invoice_id` = '{$inv_id}',
						`amount` = '{$alloc_amt}'";
					$this->conn->query($alloc_sql);
				}
			}

			// Update total paid and balance for all affected invoices
			$affected_invoices = array_unique($affected_invoices);
			foreach($affected_invoices as $inv_id){
				$sum_alloc = $this->conn->query("SELECT SUM(amount) as total_paid FROM `payment_allocations` WHERE invoice_id = '{$inv_id}'")->fetch_assoc();
				$tot_paid = isset($sum_alloc['total_paid']) ? (float)$sum_alloc['total_paid'] : 0;

				$inv_data = $this->conn->query("SELECT total FROM `invoice_list` WHERE id = '{$inv_id}'")->fetch_assoc();
				$inv_total = isset($inv_data['total']) ? (float)$inv_data['total'] : 0;

				$new_bal = max(0, $inv_total - $tot_paid);
				$new_status = 'unpaid';
				if($tot_paid >= $inv_total && $inv_total > 0){
					$new_status = 'paid';
				} elseif($tot_paid > 0 && $tot_paid < $inv_total){
					$new_status = 'partially_paid';
				}

				$this->conn->query("UPDATE `invoice_list` SET `paid_amount` = '{$tot_paid}', `balance` = '{$new_bal}', `status` = '{$new_status}' WHERE id = '{$inv_id}'");
			}

			$resp['status'] = 'success';
			$resp['msg'] = empty($id) ? " Payment Receipt created and allocated successfully." : " Payment details updated successfully.";
			$this->settings->set_flashdata('success', $resp['msg']);
		} else {
			$resp['status'] = 'failed';
			$resp['msg'] = "An error occurred while saving payment.";
			$resp['err'] = $this->conn->error;
		}

		return json_encode($resp);
	}

	function delete_payment(){
		extract($_POST);
		$id = $this->conn->real_escape_string($id);
		$tenant_id = $this->settings->active_tenant_id();

		// Get affected invoices before deleting
		$old_allocs = $this->conn->query("SELECT invoice_id FROM `payment_allocations` WHERE payment_id = '{$id}'");
		$affected_invoices = [];
		while($oa = $old_allocs->fetch_assoc()){
			$affected_invoices[] = $oa['invoice_id'];
		}

		$pay = $this->conn->query("SELECT journal_id FROM `payment_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc();
		if(!empty($pay['journal_id'])){
			$this->conn->query("DELETE FROM `journal_entries` WHERE id = '{$pay['journal_id']}'");
		}

		$del = $this->conn->query("DELETE FROM `payment_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
		if($del){
			// Recalculate invoice balances
			foreach(array_unique($affected_invoices) as $inv_id){
				$sum_alloc = $this->conn->query("SELECT SUM(amount) as total_paid FROM `payment_allocations` WHERE invoice_id = '{$inv_id}'")->fetch_assoc();
				$tot_paid = isset($sum_alloc['total_paid']) ? (float)$sum_alloc['total_paid'] : 0;

				$inv_data = $this->conn->query("SELECT total FROM `invoice_list` WHERE id = '{$inv_id}'")->fetch_assoc();
				$inv_total = isset($inv_data['total']) ? (float)$inv_data['total'] : 0;

				$new_bal = max(0, $inv_total - $tot_paid);
				$new_status = 'unpaid';
				if($tot_paid >= $inv_total && $inv_total > 0){
					$new_status = 'paid';
				} elseif($tot_paid > 0 && $tot_paid < $inv_total){
					$new_status = 'partially_paid';
				}

				$this->conn->query("UPDATE `invoice_list` SET `paid_amount` = '{$tot_paid}', `balance` = '{$new_bal}', `status` = '{$new_status}' WHERE id = '{$inv_id}'");
			}

			$resp['status'] = 'success';
			$this->settings->set_flashdata('success', " Payment receipt deleted successfully.");
		} else {
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);
	}

	function save_invoice(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$user_id = $this->settings->userdata('id');
		$id = !empty($id) ? $this->conn->real_escape_string($id) : '';

		$invoice_no = !empty($invoice_no) ? $this->conn->real_escape_string($invoice_no) : 'INV-'.date('Ym').'-'.rand(1000,9999);
		$party_id = $this->conn->real_escape_string($party_id);
		$invoice_date = !empty($invoice_date) ? $this->conn->real_escape_string($invoice_date) : date('Y-m-d');
		$due_date = !empty($due_date) ? $this->conn->real_escape_string($due_date) : date('Y-m-d', strtotime('+30 days'));
		$subtotal = isset($subtotal) ? (float)$subtotal : 0;
		$tax_amount = isset($tax_amount) ? (float)$tax_amount : 0;
		$discount = isset($discount) ? (float)$discount : 0;
		$total = isset($total) ? (float)$total : 0;
		$notes = isset($notes) ? $this->conn->real_escape_string($notes) : '';
		$status = !empty($status) ? $this->conn->real_escape_string($status) : 'unpaid';

		// Get party details
		$party_qry = $this->conn->query("SELECT * FROM `party_list` WHERE id = '{$party_id}' AND tenant_id = '{$tenant_id}'");
		if(!$party_qry || $party_qry->num_rows == 0){
			return json_encode(['status' => 'failed', 'msg' => 'Selected Customer / Party not found.']);
		}
		$party = $party_qry->fetch_assoc();

		// Calculate paid_amount and balance
		$paid_amount = 0;
		if(!empty($id)){
			$curr_inv = $this->conn->query("SELECT paid_amount FROM `invoice_list` WHERE id = '{$id}'")->fetch_assoc();
			$paid_amount = isset($curr_inv['paid_amount']) ? (float)$curr_inv['paid_amount'] : 0;
		}
		$balance = max(0, $total - $paid_amount);
		if($paid_amount >= $total && $total > 0){
			$status = 'paid';
		} elseif($paid_amount > 0 && $paid_amount < $total){
			$status = 'partially_paid';
		}

		// Accounts for Journal Entry (Accounts Receivable Dr, Sales Revenue Cr)
		$ar_acc = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%receivable%' OR `name` LIKE '%asset%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
		$ar_acc_id = ($ar_acc && $ar_acc->num_rows > 0) ? $ar_acc->fetch_assoc()['id'] : 1;

		$rev_acc = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%sales%' OR `name` LIKE '%revenue%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
		$rev_acc_id = ($rev_acc && $rev_acc->num_rows > 0) ? $rev_acc->fetch_assoc()['id'] : 2;

		// Create/Update Journal
		$prefix = date("Ym-");
		$code = sprintf("%'.05d",1);
		while(true){
			$check = $this->conn->query("SELECT * FROM `journal_entries` where `code` = '{$prefix}{$code}' and tenant_id = '{$tenant_id}' ")->num_rows;
			if($check > 0){
				$code = sprintf("%'.05d",ceil($code) + 1);
			}else{
				break;
			}
		}
		$journal_code = $prefix.$code;
		$journal_date = $invoice_date;
		$description = $this->conn->real_escape_string("Sales Invoice {$invoice_no} to {$party['name']} - Total: \${$total}");

		if(empty($id)){
			$j_sql = "INSERT INTO `journal_entries` (`tenant_id`, `code`, `journal_date`, `description`, `user_id`) VALUES ('{$tenant_id}', '{$journal_code}', '{$journal_date}', '{$description}', '{$user_id}')";
			$this->conn->query($j_sql);
			$journal_id = $this->conn->insert_id;

			$j_items = "INSERT INTO `journal_items` (`journal_id`, `account_id`, `group_id`, `amount`) VALUES
				('{$journal_id}', '{$ar_acc_id}', '1', '{$total}'),
				('{$journal_id}', '{$rev_acc_id}', '2', '{$total}')";
			$this->conn->query($j_items);

			$sql = "INSERT INTO `invoice_list` SET
				`tenant_id` = '{$tenant_id}',
				`invoice_no` = '{$invoice_no}',
				`party_id` = '{$party_id}',
				`invoice_date` = '{$invoice_date}',
				`due_date` = '{$due_date}',
				`subtotal` = '{$subtotal}',
				`tax_amount` = '{$tax_amount}',
				`discount` = '{$discount}',
				`total` = '{$total}',
				`paid_amount` = '{$paid_amount}',
				`balance` = '{$balance}',
				`status` = '{$status}',
				`notes` = '{$notes}',
				`journal_id` = '{$journal_id}',
				`created_by` = '{$user_id}'";
			$save = $this->conn->query($sql);
			$invoice_id = $this->conn->insert_id;
		} else {
			$existing = $this->conn->query("SELECT journal_id FROM `invoice_list` WHERE id = '{$id}'")->fetch_assoc();
			$journal_id = isset($existing['journal_id']) ? $existing['journal_id'] : null;
			if($journal_id){
				$this->conn->query("UPDATE `journal_entries` SET `description` = '{$description}', `journal_date` = '{$journal_date}' WHERE id = '{$journal_id}'");
				$this->conn->query("DELETE FROM `journal_items` WHERE journal_id = '{$journal_id}'");
				$j_items = "INSERT INTO `journal_items` (`journal_id`, `account_id`, `group_id`, `amount`) VALUES
					('{$journal_id}', '{$ar_acc_id}', '1', '{$total}'),
					('{$journal_id}', '{$rev_acc_id}', '2', '{$total}')";
				$this->conn->query($j_items);
			}

			$sql = "UPDATE `invoice_list` SET
				`invoice_no` = '{$invoice_no}',
				`party_id` = '{$party_id}',
				`invoice_date` = '{$invoice_date}',
				`due_date` = '{$due_date}',
				`subtotal` = '{$subtotal}',
				`tax_amount` = '{$tax_amount}',
				`discount` = '{$discount}',
				`total` = '{$total}',
				`paid_amount` = '{$paid_amount}',
				`balance` = '{$balance}',
				`status` = '{$status}',
				`notes` = '{$notes}'
				WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
			$save = $this->conn->query($sql);
			$invoice_id = $id;
		}

		if($save){
			// Save Invoice Line Items
			$this->conn->query("DELETE FROM `invoice_items` WHERE invoice_id = '{$invoice_id}'");
			if(isset($item_name) && is_array($item_name)){
				foreach($item_name as $k => $v){
					$i_name = $this->conn->real_escape_string($v);
					if(empty($i_name)) continue;

					$i_desc = isset($item_desc[$k]) ? $this->conn->real_escape_string($item_desc[$k]) : '';
					$i_qty = isset($item_qty[$k]) ? (float)$item_qty[$k] : 1;
					$i_price = isset($item_price[$k]) ? (float)$item_price[$k] : 0;
					$i_tax_rate = isset($item_tax_rate[$k]) ? (float)$item_tax_rate[$k] : 0;
					$i_tax_amt = round(($i_qty * $i_price) * ($i_tax_rate / 100), 2);
					$i_amount = round(($i_qty * $i_price) + $i_tax_amt, 2);

					$item_sql = "INSERT INTO `invoice_items` SET
						`invoice_id` = '{$invoice_id}',
						`item_name` = '{$i_name}',
						`description` = '{$i_desc}',
						`qty` = '{$i_qty}',
						`unit_price` = '{$i_price}',
						`tax_rate` = '{$i_tax_rate}',
						`tax_amount` = '{$i_tax_amt}',
						`amount` = '{$i_amount}'";
					$this->conn->query($item_sql);
				}
			}

			$resp['status'] = 'success';
			$resp['msg'] = empty($id) ? " Invoice created successfully." : " Invoice updated successfully.";
			$this->settings->set_flashdata('success', $resp['msg']);
		} else {
			$resp['status'] = 'failed';
			$resp['msg'] = "An error occurred while saving invoice.";
			$resp['err'] = $this->conn->error;
		}

		return json_encode($resp);
	}

	function delete_invoice(){
		extract($_POST);
		$id = $this->conn->real_escape_string($id);
		$tenant_id = $this->settings->active_tenant_id();

		$inv = $this->conn->query("SELECT journal_id FROM `invoice_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc();
		if(!empty($inv['journal_id'])){
			$this->conn->query("DELETE FROM `journal_entries` WHERE id = '{$inv['journal_id']}'");
		}

		$del = $this->conn->query("DELETE FROM `invoice_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
		if($del){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success', " Invoice deleted successfully.");
		} else {
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);
	}
	function save_account(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$data = "";
		foreach($_POST as $k =>$v){
			if(!in_array($k,array('id'))){
				if(!is_numeric($v))
					$v = $this->conn->real_escape_string($v);
				if(!empty($data)) $data .=",";
				$data .= " `{$k}`='{$v}' ";
			}
		}
		if(empty($id)){
			$sql = "INSERT INTO `account_list` set `tenant_id`='{$tenant_id}', {$data} ";
		}else{
			$sql = "UPDATE `account_list` set {$data} where id = '{$id}' and tenant_id = '{$tenant_id}' ";
		}
		$check = $this->conn->query("SELECT * FROM `account_list` where `name` ='{$name}' and tenant_id = '{$tenant_id}' and delete_flag = 0 ".($id > 0 ? " and id != '{$id}' " : ""))->num_rows;
		if($check > 0){
			$resp['status'] = 'failed';
			$resp['msg'] = " Account's Name already exists.";
		}else{
			$save = $this->conn->query($sql);
			if($save){
				$rid = !empty($id) ? $id : $this->conn->insert_id;
				$resp['status'] = 'success';
				if(empty($id))
					$resp['msg'] = " Account has successfully added.";
				else
					$resp['msg'] = " Account has been updated successfully.";
			}else{
				$resp['status'] = 'failed';
				$resp['msg'] = "An error occured.";
				$resp['err'] = $this->conn->error."[{$sql}]";
			}
			if($resp['status'] =='success')
			$this->settings->set_flashdata('success',$resp['msg']);
		}
		return json_encode($resp);
	}
	function delete_account(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$id = intval($id);
		$del = $this->conn->query("UPDATE `account_list` set delete_flag = 1 where id = '{$id}' and tenant_id = '{$tenant_id}'");
		if($del){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success'," Account has been deleted successfully.");

		}else{
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);
	}
	function save_journal(){
		$tenant_id = $this->settings->active_tenant_id();
		if(empty($_POST['id'])){
			$prefix = date("Ym-");
			$code = sprintf("%'.05d",1);
			while(true){
				$check = $this->conn->query("SELECT * FROM `journal_entries` where `code` = '{$prefix}{$code}' and tenant_id = '{$tenant_id}' ")->num_rows;
				if($check > 0){
					$code = sprintf("%'.05d",ceil($code) + 1);
				}else{
					break;
				}
			}
			$_POST['code'] = $prefix.$code;
			$_POST['user_id'] = $this->settings->userdata('id');
		}
		extract($_POST);
		$data = "";
		foreach($_POST as $k =>$v){
			if(!in_array($k,array('id'))  && !is_array($_POST[$k])){
				if(!is_numeric($v) && !is_null($v))
					$v = $this->conn->real_escape_string($v);
				if(!empty($data)) $data .=",";
				if(!is_null($v))
				$data .= " `{$k}`='{$v}' ";
				else
				$data .= " `{$k}`= NULL ";
			}
		}
		if(empty($id)){
			$sql = "INSERT INTO `journal_entries` set `tenant_id`='{$tenant_id}', {$data} ";
		}else{
			$sql = "UPDATE `journal_entries` set {$data} where id = '{$id}' and tenant_id = '{$tenant_id}' ";
		}
		$save = $this->conn->query($sql);
		if($save){
			$jid = !empty($id) ? $id : $this->conn->insert_id;
			$data = "";
			$this->conn->query("DELETE FROM `journal_items` where journal_id = '{$jid}'");
			foreach($account_id as $k=>$v){
				if(!empty($data)) $data .=", ";
				$data .= "('{$jid}','{$v}','{$group_id[$k]}','{$amount[$k]}')";
			}
			if(!empty($data)){
				$sql = "INSERT INTO `journal_items` (`journal_id`,`account_id`,`group_id`,`amount`) VALUES {$data}";
				$save2 = $this->conn->query($sql);
				if($save2){
					$resp['status'] = 'success';
					if(empty($id)){
						$resp['msg'] = " Journal Entry has successfully added.";
					}else
						$resp['msg'] = " Journal Entry has been updated successfully.";
				}else{
					$resp['status'] = 'failed';
					if(empty($id)){
						$resp['msg'] = " Journal Entry has failed to save.";
						$this->conn->query("DELETE FROM `journal_entries` where id = '{$jid}'");
					}else
						$resp['msg'] = " Journal Entry has failed to update.";
					$resp['error'] = $this->conn->error;
				}
			}else{
				$resp['status'] = 'failed';
				if(empty($id)){
					$resp['msg'] = " Journal Entry has failed to save.";
					$this->conn->query("DELETE FROM `journal_entries` where id = '{$jid}'");
				}else
					$resp['msg'] = " Journal Entry has failed to update.";
				$resp['error'] = "Journal Items is empty";
			}
		}else{
			$resp['status'] = 'failed';
			$resp['msg'] = "An error occured.";
			$resp['err'] = $this->conn->error."[{$sql}]";
		}
		if($resp['status'] =='success')
			$this->settings->set_flashdata('success',$resp['msg']);
		return json_encode($resp);
	}
	function delete_journal(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$id = intval($id);
		$del = $this->conn->query("DELETE FROM `journal_entries` where id = '{$id}' and tenant_id = '{$tenant_id}'");
		if($del){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success'," Journal Entry has been deleted successfully.");

		}else{
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);
	}
	function cancel_journal(){
		extract($_POST);
		$del = $this->conn->query("UPDATE `journal_entries` set `status` = '3' where id = '{$id}'");
		if($del){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success'," journaling has successfully cancelled.");

		}else{
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);
	}
	function save_reservation(){
		$_POST['journal'] = $_POST['date'] ." ".$_POST['time'];
		extract($_POST);
		$capacity = $this->conn->query("SELECT `".($seat_type == 1 ? "first_class_capacity" : "economy_capacity")."` FROM group_list where id in (SELECT group_id FROM `journal_entries` where id ='{$journal_id}') ")->fetch_array()[0];
		$reserve = $this->conn->query("SELECT * FROM `reservation_list` where journal_id = '{$journal_id}' and journal='{$journal}' and seat_type='$seat_type'")->num_rows;
		$slot = $capacity - $reserve;
		if(count($firstname) > $slot){
			$resp['status'] = "failed";
			$resp['msg'] = "This journal has only [{$slot}] left for the selected seat type/group";
			return json_encode($resp);
		}
		$data = "";
		$sn = [];
		$prefix = $seat_type == 1 ? "FC-" : "E-";
		$seat = sprintf("%'.03d",1);
		foreach($firstname as $k=>$v){
			while(true){
				$check = $this->conn->query("SELECT * FROM `reservation_list` where journal_id = '{$journal_id}' and journal='{$journal}' and seat_num = '{$prefix}{$seat}' and seat_type='$seat_type'")->num_rows;
				if($check > 0){
					$seat = sprintf("%'.03d",ceil($seat) + 1);
				}else{
					break;
				}
			}
			$seat_num = $prefix.$seat;
			$seat = sprintf("%'.03d",ceil($seat) + 1);
			$sn[] = $seat_num;
			if(!empty($data)) $data .= ", ";
			$data .= "('{$seat_num}','{$journal_id}','{$journal}','{$v}','{$middlename[$k]}','{$lastname[$k]}','{$seat_type}','{$fare_amount}')";
		}
		if(!empty($data)){
			$sql = "INSERT INTO `reservation_list` (`seat_num`,`journal_id`,`journal`,`firstname`,`middlename`,`lastname`,`seat_type`,`fare_amount`) VALUES {$data}";
			$save_all = $this->conn->query($sql);
			if($save_all){
				$resp['status'] = 'success';
				$resp['msg'] = "Reservation successfully submitted.";
				$get_ids = $this->conn->query("SELECT id from `reservation_list` where `journal_id` = '{$journal_id}' and `journal` = '{$journal}' and seat_type='{$seat_type}' and seat_num in ('".(implode("','",$sn))."') ");
				$res = $get_ids->fetch_all(MYSQLI_ASSOC);
				$ids = array_column($res,'id');
				$ids = implode(",",$ids);
				$resp['ids'] = $ids;
			}else{
				$resp['status'] = 'failed';
				$resp['msg'] = "An error occured while saving the data. Error: ".$this->conn->error;
				$resp['sql'] = $sql;
			}
		}else{
			$resp['status'] = "failed";
			$resp['msg'] = "No Data to save.";
		}
		

		if($resp['status'] =='success')
		$this->settings->set_flashdata('success',$resp['msg']);
		return json_encode($resp);
	}
	function delete_reservation(){
		extract($_POST);
		$del = $this->conn->query("DELETE FROM `reservation_list` where id = '{$id}'");
		if($del){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success',"Reservation Details has been deleted successfully.");
		}else{
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);
	}
	function update_reservation_status(){
		extract($_POST);
		$del = $this->conn->query("UPDATE `reservation_list` set `status` = '{$status}' where id = '{$id}'");
		if($del){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success',"reservation Request status has successfully updated.");

		}else{
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);
	}

	/* Employee Management Functions */
	function save_employee(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$data = "";
		foreach($_POST as $k =>$v){
			if(!in_array($k,array('id'))){
				if(!is_numeric($v))
					$v = $this->conn->real_escape_string($v);
				if(!empty($data)) $data .=",";
				$data .= " `{$k}`='{$v}' ";
			}
		}
		if(empty($id)){
			$sql = "INSERT INTO `employee_list` set `tenant_id`='{$tenant_id}', {$data} ";
		}else{
			$sql = "UPDATE `employee_list` set {$data} where id = '{$id}' and tenant_id = '{$tenant_id}' ";
		}
		$check = $this->conn->query("SELECT * FROM `employee_list` where `employee_code` = '{$employee_code}' and tenant_id = '{$tenant_id}' and delete_flag = 0 ".($id > 0 ? " and id != '{$id}'" : ""))->num_rows;
		if($check > 0){
			$resp['status'] = 'failed';
			$resp['msg'] = " Employee Code already exists.";
		}else{
			$save = $this->conn->query($sql);
			if($save){
				$resp['status'] = 'success';
				if(empty($id))
					$resp['msg'] = " Employee has been successfully added.";
				else
					$resp['msg'] = " Employee details have been updated successfully.";
			}else{
				$resp['status'] = 'failed';
				$resp['msg'] = "An error occurred.";
				$resp['err'] = $this->conn->error."[{$sql}]";
			}
		}
		if($resp['status'] =='success')
			$this->settings->set_flashdata('success',$resp['msg']);
		return json_encode($resp);
	}

	function delete_employee(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$id = intval($id);
		$del = $this->conn->query("UPDATE `employee_list` set delete_flag = 1 where id = '{$id}' and tenant_id = '{$tenant_id}'");
		if($del){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success'," Employee has been deleted successfully.");
		}else{
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);
	}

	/* Attendance Management Functions */
	function save_attendance(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$employee_id = $this->conn->real_escape_string($employee_id);
		$attendance_date = $this->conn->real_escape_string($attendance_date);
		$status = $this->conn->real_escape_string($status);

		$check = $this->conn->query("SELECT * FROM `attendance_list` where `employee_id` = '{$employee_id}' and `attendance_date` = '{$attendance_date}' and tenant_id = '{$tenant_id}' ".(!empty($id) && $id > 0 ? " and id != '{$id}'" : ""))->num_rows;
		if($check > 0){
			$resp['status'] = 'failed';
			$resp['msg'] = " Attendance record for this employee on the selected date already exists.";
			return json_encode($resp);
		}
		$check_in = !empty($check_in) ? "'".$this->conn->real_escape_string($check_in)."'" : "NULL";
		$check_out = !empty($check_out) ? "'".$this->conn->real_escape_string($check_out)."'" : "NULL";

		if(empty($id)){
			$sql = "INSERT INTO `attendance_list` (`tenant_id`, `employee_id`, `attendance_date`, `check_in`, `check_out`, `status`) VALUES ('{$tenant_id}', '{$employee_id}', '{$attendance_date}', {$check_in}, {$check_out}, '{$status}')";
		}else{
			$sql = "UPDATE `attendance_list` SET `employee_id`='{$employee_id}', `attendance_date`='{$attendance_date}', `check_in`={$check_in}, `check_out`={$check_out}, `status`='{$status}' WHERE id = '{$id}' and tenant_id = '{$tenant_id}'";
		}
		$save = $this->conn->query($sql);
		if($save){
			$resp['status'] = 'success';
			$resp['msg'] = empty($id) ? " Attendance record saved successfully." : " Attendance record updated successfully.";
		}else{
			$resp['status'] = 'failed';
			$resp['msg'] = "An error occurred while saving attendance.";
			$resp['err'] = $this->conn->error;
		}
		if($resp['status'] =='success')
			$this->settings->set_flashdata('success',$resp['msg']);
		return json_encode($resp);
	}

	function delete_attendance(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$id = intval($id);
		$del = $this->conn->query("DELETE FROM `attendance_list` where id = '{$id}' and tenant_id = '{$tenant_id}'");
		if($del){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success'," Attendance record deleted successfully.");
		}else{
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);
	}

	/* Leave Management Functions */
	function save_leave(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$data = "";
		foreach($_POST as $k =>$v){
			if(!in_array($k,array('id'))){
				if(!is_numeric($v))
					$v = $this->conn->real_escape_string($v);
				if(!empty($data)) $data .=",";
				$data .= " `{$k}`='{$v}' ";
			}
		}
		if(empty($id)){
			$sql = "INSERT INTO `leave_list` set `tenant_id`='{$tenant_id}', {$data} ";
		}else{
			$sql = "UPDATE `leave_list` set {$data} where id = '{$id}' and tenant_id = '{$tenant_id}' ";
		}
		$save = $this->conn->query($sql);
		if($save){
			$resp['status'] = 'success';
			$resp['msg'] = empty($id) ? " Leave request submitted successfully." : " Leave request updated successfully.";
		}else{
			$resp['status'] = 'failed';
			$resp['msg'] = "An error occurred while saving leave request.";
			$resp['err'] = $this->conn->error;
		}
		if($resp['status'] =='success')
			$this->settings->set_flashdata('success',$resp['msg']);
		return json_encode($resp);
	}

	function update_leave_status(){
		extract($_POST);
		$id = $this->conn->real_escape_string($id);
		$status = $this->conn->real_escape_string($status);
		$update = $this->conn->query("UPDATE `leave_list` set `status` = '{$status}' where id = '{$id}'");
		if($update){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success'," Leave status updated successfully.");
		}else{
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);
	}

	function delete_leave(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$id = intval($id);
		$del = $this->conn->query("DELETE FROM `leave_list` where id = '{$id}' and tenant_id = '{$tenant_id}'");
		if($del){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success'," Leave request deleted successfully.");
		}else{
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);
	}

	/* Payroll & Journal Integration Functions */
	function calculate_payroll_ajax(){
		require_once(base_app.'classes/FijiPayroll.php');
		$calc = FijiPayroll::calculate($_POST);
		return json_encode(['status' => 'success', 'data' => $calc]);
	}

	function save_payroll(){
		require_once(base_app.'classes/FijiPayroll.php');
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();

		$payroll_run_ref = !empty($payroll_run_ref) ? $this->conn->real_escape_string($payroll_run_ref) : 'RUN-'.date('Ym').'-'.rand(100,999);
		$salary_month = !empty($salary_month) ? $this->conn->real_escape_string($salary_month) : date('F Y');
		$pay_frequency = !empty($pay_frequency) ? $this->conn->real_escape_string($pay_frequency) : 'Monthly';
		$pay_date = !empty($pay_date) ? $this->conn->real_escape_string($pay_date) : date('Y-m-d');
		$period_start = !empty($period_start) ? $this->conn->real_escape_string($period_start) : date('Y-m-01');
		$period_end = !empty($period_end) ? $this->conn->real_escape_string($period_end) : date('Y-m-t');

		$employee_id = $this->conn->real_escape_string($employee_id);

		// Get Employee details
		$emp_qry = $this->conn->query("SELECT *, concat(firstname, ' ', lastname) as name FROM `employee_list` WHERE id = '{$employee_id}' and tenant_id = '{$tenant_id}'");
		if(!$emp_qry || $emp_qry->num_rows == 0){
			return json_encode(['status' => 'failed', 'msg' => 'Employee not found.']);
		}
		$emp_data = $emp_qry->fetch_assoc();

		$basic_salary = isset($basic_salary) ? (float)$basic_salary : (float)$emp_data['salary'];
		$overtime = isset($overtime) ? (float)$overtime : 0;
		$allowances = isset($allowances) ? (float)$allowances : 0;
		$other_earnings = isset($other_earnings) ? (float)$other_earnings : 0;
		$deductions = isset($deductions) ? (float)$deductions : 0;
		$is_resident = isset($is_resident) ? (int)$is_resident : (int)$emp_data['is_resident'];

		// Calculate Fiji Payroll
		$calc = FijiPayroll::calculate([
			'basic_salary' => $basic_salary,
			'overtime' => $overtime,
			'allowances' => $allowances,
			'other_earnings' => $other_earnings,
			'deductions' => $deductions,
			'is_resident' => $is_resident,
			'pay_frequency' => $pay_frequency
		]);

		$gross_salary = $calc['gross_salary'];
		$fnpf_base = $calc['fnpf_base'];
		$employee_fnpf = $calc['employee_fnpf'];
		$employer_fnpf = $calc['employer_fnpf'];
		$taxable_income = $calc['taxable_income'];
		$paye_tax = $calc['paye_tax'];
		$srt_tax = $calc['srt_tax'];
		$net_salary = $calc['net_salary'];
		$workcare_levy = $calc['workcare_levy'];
		$training_levy = $calc['training_levy'];
		$employer_cost = $calc['employer_cost'];

		$tin = $this->conn->real_escape_string($emp_data['tin']);
		$fnpf_no = $this->conn->real_escape_string($emp_data['fnpf_no']);
		$tax_code = $this->conn->real_escape_string($emp_data['tax_code']);
		$bank_code = $this->conn->real_escape_string($emp_data['bank_code']);
		$bank_account = $this->conn->real_escape_string($emp_data['bank_account']);

		// Accounts for Journal Entry
		$salary_acc = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%salary%' OR `name` LIKE '%expense%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
		$salary_acc_id = ($salary_acc && $salary_acc->num_rows > 0) ? $salary_acc->fetch_assoc()['id'] : 1;

		$cash_acc = $this->conn->query("SELECT id FROM `account_list` WHERE `name` LIKE '%cash%' AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
		$cash_acc_id = ($cash_acc && $cash_acc->num_rows > 0) ? $cash_acc->fetch_assoc()['id'] : 1;

		$group_debit_id = 1;
		$group_credit_id = 2;

		// Create Journal Entry
		$prefix = date("Ym-");
		$code = sprintf("%'.05d",1);
		while(true){
			$check = $this->conn->query("SELECT * FROM `journal_entries` where `code` = '{$prefix}{$code}' and tenant_id = '{$tenant_id}' ")->num_rows;
			if($check > 0){
				$code = sprintf("%'.05d",ceil($code) + 1);
			}else{
				break;
			}
		}
		$journal_code = $prefix.$code;
		$user_id = $this->settings->userdata('id');
		$journal_date = $pay_date;

		$description = $this->conn->real_escape_string("Fiji Payroll Disbursement for {$emp_data['name']} ({$salary_month}) - Gross: \${$gross_salary}, Net: \${$net_salary}");

		if(empty($id)){
			$j_sql = "INSERT INTO `journal_entries` (`tenant_id`, `code`, `journal_date`, `description`, `user_id`) VALUES ('{$tenant_id}', '{$journal_code}', '{$journal_date}', '{$description}', '{$user_id}')";
			$this->conn->query($j_sql);
			$journal_id = $this->conn->insert_id;

			$j_items = "INSERT INTO `journal_items` (`journal_id`, `account_id`, `group_id`, `amount`) VALUES
				('{$journal_id}', '{$salary_acc_id}', '{$group_debit_id}', '{$net_salary}'),
				('{$journal_id}', '{$cash_acc_id}', '{$group_credit_id}', '{$net_salary}')";
			$this->conn->query($j_items);

			$sql = "INSERT INTO `payroll_list` SET
				`tenant_id` = '{$tenant_id}',
				`employee_id` = '{$employee_id}',
				`journal_id` = '{$journal_id}',
				`payroll_run_ref` = '{$payroll_run_ref}',
				`salary_month` = '{$salary_month}',
				`pay_frequency` = '{$pay_frequency}',
				`pay_date` = '{$pay_date}',
				`period_start` = '{$period_start}',
				`period_end` = '{$period_end}',
				`basic_salary` = '{$basic_salary}',
				`overtime` = '{$overtime}',
				`allowances` = '{$allowances}',
				`other_earnings` = '{$other_earnings}',
				`gross_salary` = '{$gross_salary}',
				`fnpf_base` = '{$fnpf_base}',
				`employee_fnpf` = '{$employee_fnpf}',
				`employer_fnpf` = '{$employer_fnpf}',
				`taxable_income` = '{$taxable_income}',
				`paye_tax` = '{$paye_tax}',
				`srt_tax` = '{$srt_tax}',
				`deductions` = '{$deductions}',
				`net_salary` = '{$net_salary}',
				`workcare_levy` = '{$workcare_levy}',
				`training_levy` = '{$training_levy}',
				`employer_cost` = '{$employer_cost}',
				`tin` = '{$tin}',
				`fnpf_no` = '{$fnpf_no}',
				`tax_code` = '{$tax_code}',
				`is_resident` = '{$is_resident}',
				`bank_code` = '{$bank_code}',
				`bank_account` = '{$bank_account}'";
		} else {
			$existing = $this->conn->query("SELECT journal_id FROM `payroll_list` WHERE id = '{$id}'")->fetch_assoc();
			$journal_id = isset($existing['journal_id']) ? $existing['journal_id'] : null;
			if($journal_id){
				$this->conn->query("UPDATE `journal_entries` SET `description` = '{$description}', `journal_date` = '{$journal_date}' WHERE id = '{$journal_id}'");
				$this->conn->query("DELETE FROM `journal_items` WHERE journal_id = '{$journal_id}'");
				$j_items = "INSERT INTO `journal_items` (`journal_id`, `account_id`, `group_id`, `amount`) VALUES
					('{$journal_id}', '{$salary_acc_id}', '{$group_debit_id}', '{$net_salary}'),
					('{$journal_id}', '{$cash_acc_id}', '{$group_credit_id}', '{$net_salary}')";
				$this->conn->query($j_items);
			}

			$sql = "UPDATE `payroll_list` SET
				`employee_id` = '{$employee_id}',
				`payroll_run_ref` = '{$payroll_run_ref}',
				`salary_month` = '{$salary_month}',
				`pay_frequency` = '{$pay_frequency}',
				`pay_date` = '{$pay_date}',
				`period_start` = '{$period_start}',
				`period_end` = '{$period_end}',
				`basic_salary` = '{$basic_salary}',
				`overtime` = '{$overtime}',
				`allowances` = '{$allowances}',
				`other_earnings` = '{$other_earnings}',
				`gross_salary` = '{$gross_salary}',
				`fnpf_base` = '{$fnpf_base}',
				`employee_fnpf` = '{$employee_fnpf}',
				`employer_fnpf` = '{$employer_fnpf}',
				`taxable_income` = '{$taxable_income}',
				`paye_tax` = '{$paye_tax}',
				`srt_tax` = '{$srt_tax}',
				`deductions` = '{$deductions}',
				`net_salary` = '{$net_salary}',
				`workcare_levy` = '{$workcare_levy}',
				`training_levy` = '{$training_levy}',
				`employer_cost` = '{$employer_cost}',
				`tin` = '{$tin}',
				`fnpf_no` = '{$fnpf_no}',
				`tax_code` = '{$tax_code}',
				`is_resident` = '{$is_resident}',
				`bank_code` = '{$bank_code}',
				`bank_account` = '{$bank_account}'
				WHERE id = '{$id}'";
		}

		$save = $this->conn->query($sql);
		if($save){
			$resp['status'] = 'success';
			$resp['msg'] = empty($id) ? " Fiji Payroll processed and Journal Entry created successfully." : " Payroll updated successfully.";
		}else{
			$resp['status'] = 'failed';
			$resp['msg'] = "An error occurred while saving payroll.";
			$resp['err'] = $this->conn->error;
		}
		if($resp['status'] =='success')
			$this->settings->set_flashdata('success',$resp['msg']);
		return json_encode($resp);
	}

	function save_batch_payroll(){
		require_once(base_app.'classes/FijiPayroll.php');
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();

		if(empty($employee_ids) || !is_array($employee_ids)){
			return json_encode(['status' => 'failed', 'msg' => 'No employees selected for batch payroll processing.']);
		}

		$payroll_run_ref = !empty($payroll_run_ref) ? $this->conn->real_escape_string($payroll_run_ref) : 'RUN-'.date('Ym').'-'.rand(100,999);
		$salary_month = !empty($salary_month) ? $this->conn->real_escape_string($salary_month) : date('F Y');
		$pay_frequency = !empty($pay_frequency) ? $this->conn->real_escape_string($pay_frequency) : 'Monthly';
		$pay_date = !empty($pay_date) ? $this->conn->real_escape_string($pay_date) : date('Y-m-d');
		$period_start = !empty($period_start) ? $this->conn->real_escape_string($period_start) : date('Y-m-01');
		$period_end = !empty($period_end) ? $this->conn->real_escape_string($period_end) : date('Y-m-t');

		$processed_count = 0;

		foreach($employee_ids as $emp_id){
			$emp_id = $this->conn->real_escape_string($emp_id);

			$emp_qry = $this->conn->query("SELECT *, concat(firstname, ' ', lastname) as name FROM `employee_list` WHERE id = '{$emp_id}' and tenant_id = '{$tenant_id}'");
			if(!$emp_qry || $emp_qry->num_rows == 0) continue;

			$emp_data = $emp_qry->fetch_assoc();

			$basic_salary = isset($emp_basic[$emp_id]) ? (float)$emp_basic[$emp_id] : (float)$emp_data['salary'];
			$overtime = isset($emp_overtime[$emp_id]) ? (float)$emp_overtime[$emp_id] : 0;
			$allowances = isset($emp_allowances[$emp_id]) ? (float)$emp_allowances[$emp_id] : 0;
			$other_earnings = isset($emp_other_earnings[$emp_id]) ? (float)$emp_other_earnings[$emp_id] : 0;
			$deductions = isset($emp_deductions[$emp_id]) ? (float)$emp_deductions[$emp_id] : 0;
			$is_resident = (int)$emp_data['is_resident'];

			$calc = FijiPayroll::calculate([
				'basic_salary' => $basic_salary,
				'overtime' => $overtime,
				'allowances' => $allowances,
				'other_earnings' => $other_earnings,
				'deductions' => $deductions,
				'is_resident' => $is_resident,
				'pay_frequency' => $pay_frequency
			]);

			$gross_salary = $calc['gross_salary'];
			$fnpf_base = $calc['fnpf_base'];
			$employee_fnpf = $calc['employee_fnpf'];
			$employer_fnpf = $calc['employer_fnpf'];
			$taxable_income = $calc['taxable_income'];
			$paye_tax = $calc['paye_tax'];
			$srt_tax = $calc['srt_tax'];
			$net_salary = $calc['net_salary'];
			$workcare_levy = $calc['workcare_levy'];
			$training_levy = $calc['training_levy'];
			$employer_cost = $calc['employer_cost'];

			$tin = $this->conn->real_escape_string($emp_data['tin']);
			$fnpf_no = $this->conn->real_escape_string($emp_data['fnpf_no']);
			$tax_code = $this->conn->real_escape_string($emp_data['tax_code']);
			$bank_code = $this->conn->real_escape_string($emp_data['bank_code']);
			$bank_account = $this->conn->real_escape_string($emp_data['bank_account']);

			// Journal
			$salary_acc = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%salary%' OR `name` LIKE '%expense%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
			$salary_acc_id = ($salary_acc && $salary_acc->num_rows > 0) ? $salary_acc->fetch_assoc()['id'] : 1;

			$cash_acc = $this->conn->query("SELECT id FROM `account_list` WHERE `name` LIKE '%cash%' AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
			$cash_acc_id = ($cash_acc && $cash_acc->num_rows > 0) ? $cash_acc->fetch_assoc()['id'] : 1;

			$prefix = date("Ym-");
			$code = sprintf("%'.05d",1);
			while(true){
				$check = $this->conn->query("SELECT * FROM `journal_entries` where `code` = '{$prefix}{$code}' and tenant_id = '{$tenant_id}' ")->num_rows;
				if($check > 0){
					$code = sprintf("%'.05d",ceil($code) + 1);
				}else{
					break;
				}
			}
			$journal_code = $prefix.$code;
			$user_id = $this->settings->userdata('id');
			$journal_date = $pay_date;

			$description = $this->conn->real_escape_string("Fiji Batch Payroll Disbursement for {$emp_data['name']} ({$salary_month}) - Ref: {$payroll_run_ref}");

			$j_sql = "INSERT INTO `journal_entries` (`tenant_id`, `code`, `journal_date`, `description`, `user_id`) VALUES ('{$tenant_id}', '{$journal_code}', '{$journal_date}', '{$description}', '{$user_id}')";
			$this->conn->query($j_sql);
			$journal_id = $this->conn->insert_id;

			$j_items = "INSERT INTO `journal_items` (`journal_id`, `account_id`, `group_id`, `amount`) VALUES
				('{$journal_id}', '{$salary_acc_id}', '1', '{$net_salary}'),
				('{$journal_id}', '{$cash_acc_id}', '2', '{$net_salary}')";
			$this->conn->query($j_items);

			$sql = "INSERT INTO `payroll_list` SET
				`tenant_id` = '{$tenant_id}',
				`employee_id` = '{$emp_id}',
				`journal_id` = '{$journal_id}',
				`payroll_run_ref` = '{$payroll_run_ref}',
				`salary_month` = '{$salary_month}',
				`pay_frequency` = '{$pay_frequency}',
				`pay_date` = '{$pay_date}',
				`period_start` = '{$period_start}',
				`period_end` = '{$period_end}',
				`basic_salary` = '{$basic_salary}',
				`overtime` = '{$overtime}',
				`allowances` = '{$allowances}',
				`other_earnings` = '{$other_earnings}',
				`gross_salary` = '{$gross_salary}',
				`fnpf_base` = '{$fnpf_base}',
				`employee_fnpf` = '{$employee_fnpf}',
				`employer_fnpf` = '{$employer_fnpf}',
				`taxable_income` = '{$taxable_income}',
				`paye_tax` = '{$paye_tax}',
				`srt_tax` = '{$srt_tax}',
				`deductions` = '{$deductions}',
				`net_salary` = '{$net_salary}',
				`workcare_levy` = '{$workcare_levy}',
				`training_levy` = '{$training_levy}',
				`employer_cost` = '{$employer_cost}',
				`tin` = '{$tin}',
				`fnpf_no` = '{$fnpf_no}',
				`tax_code` = '{$tax_code}',
				`is_resident` = '{$is_resident}',
				`bank_code` = '{$bank_code}',
				`bank_account` = '{$bank_account}'";

			if($this->conn->query($sql)){
				$processed_count++;
			}
		}

		$resp['status'] = 'success';
		$resp['msg'] = "Batch payroll run ({$payroll_run_ref}) successfully created for {$processed_count} employees.";
		$this->settings->set_flashdata('success', $resp['msg']);
		return json_encode($resp);
	}

	function save_party(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$id = !empty($id) ? $this->conn->real_escape_string($id) : '';

		$party_code = $this->conn->real_escape_string($party_code);
		$name = $this->conn->real_escape_string($name);
		$type = !empty($type) ? $this->conn->real_escape_string($type) : 'customer';
		$email = isset($email) ? $this->conn->real_escape_string($email) : '';
		$phone = isset($phone) ? $this->conn->real_escape_string($phone) : '';
		$tin = isset($tin) ? $this->conn->real_escape_string($tin) : '';
		$address = isset($address) ? $this->conn->real_escape_string($address) : '';
		$status = isset($status) ? (int)$status : 1;

		$check = $this->conn->query("SELECT * FROM `party_list` WHERE `party_code` = '{$party_code}' AND tenant_id = '{$tenant_id}' AND delete_flag = 0 " . (!empty($id) ? " AND id != '{$id}'" : ""))->num_rows;
		if($check > 0){
			return json_encode(['status' => 'failed', 'msg' => 'Party Code already exists.']);
		}

		if(empty($id)){
			$sql = "INSERT INTO `party_list` SET
				`tenant_id` = '{$tenant_id}',
				`party_code` = '{$party_code}',
				`name` = '{$name}',
				`type` = '{$type}',
				`email` = '{$email}',
				`phone` = '{$phone}',
				`tin` = '{$tin}',
				`address` = '{$address}',
				`status` = '{$status}'";
		} else {
			$sql = "UPDATE `party_list` SET
				`party_code` = '{$party_code}',
				`name` = '{$name}',
				`type` = '{$type}',
				`email` = '{$email}',
				`phone` = '{$phone}',
				`tin` = '{$tin}',
				`address` = '{$address}',
				`status` = '{$status}'
				WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'";
		}

		$save = $this->conn->query($sql);
		if($save){
			$resp['status'] = 'success';
			$resp['msg'] = empty($id) ? " Party successfully created." : " Party details updated successfully.";
			$this->settings->set_flashdata('success', $resp['msg']);
		} else {
			$resp['status'] = 'failed';
			$resp['msg'] = "An error occurred while saving party.";
			$resp['err'] = $this->conn->error;
		}
		return json_encode($resp);
	}

	function delete_party(){
		extract($_POST);
		$id = $this->conn->real_escape_string($id);
		$tenant_id = $this->settings->active_tenant_id();

		$del = $this->conn->query("UPDATE `party_list` SET `delete_flag` = 1 WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'");
		if($del){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success', " Party deleted successfully.");
		} else {
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);
	}

	function delete_payroll(){
		extract($_POST);
		$tenant_id = $this->settings->active_tenant_id();
		$id = intval($id);
		$existing = $this->conn->query("SELECT journal_id FROM `payroll_list` WHERE id = '{$id}' and tenant_id = '{$tenant_id}'")->fetch_assoc();
		if(!empty($existing['journal_id'])){
			$this->conn->query("DELETE FROM `journal_entries` WHERE id = '{$existing['journal_id']}' and tenant_id = '{$tenant_id}'");
		}
		$del = $this->conn->query("DELETE FROM `payroll_list` WHERE id = '{$id}' and tenant_id = '{$tenant_id}'");
		if($del){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success'," Payroll record deleted successfully.");
		}else{
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);
	}

	/* Tenant Management Functions */
	function save_tenant(){
		extract($_POST);
		$data = "";
		foreach($_POST as $k =>$v){
			if(!in_array($k,array('id'))){
				if(!is_numeric($v))
					$v = $this->conn->real_escape_string($v);
				if(!empty($data)) $data .=",";
				$data .= " `{$k}`='{$v}' ";
			}
		}
		if(empty($id)){
			$sql = "INSERT INTO `tenants` set {$data} ";
		}else{
			$sql = "UPDATE `tenants` set {$data} where id = '{$id}' ";
		}
		$check = $this->conn->query("SELECT * FROM `tenants` where `tenant_code` = '{$tenant_code}' and delete_flag = 0 ".($id > 0 ? " and id != '{$id}'" : ""))->num_rows;
		if($check > 0){
			$resp['status'] = 'failed';
			$resp['msg'] = " Tenant Code already exists.";
		}else{
			$save = $this->conn->query($sql);
			if($save){
				$resp['status'] = 'success';
				$resp['msg'] = empty($id) ? " Tenant added successfully." : " Tenant details updated successfully.";
			}else{
				$resp['status'] = 'failed';
				$resp['msg'] = "An error occurred.";
				$resp['err'] = $this->conn->error;
			}
		}
		if($resp['status'] =='success')
			$this->settings->set_flashdata('success',$resp['msg']);
		return json_encode($resp);
	}

	function delete_tenant(){
		extract($_POST);
		$id = $this->conn->real_escape_string($id);
		$del = $this->conn->query("UPDATE `tenants` set delete_flag = 1 where id = '{$id}'");
		if($del){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success'," Tenant deleted successfully.");
		}else{
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
		}
		return json_encode($resp);
	}
}

$Master = new Master();
$action = !isset($_GET['f']) ? 'none' : strtolower($_GET['f']);
$sysset = new SystemSettings();
switch ($action) {
	case 'save_reservation':
		echo $Master->save_reservation();
	break;
	case 'delete_reservation':
		echo $Master->delete_reservation();
	break;
	case 'update_reservation_status':
		echo $Master->update_reservation_status();
	break;
	case 'save_message':
		echo $Master->save_message();
	break;
	case 'delete_message':
		echo $Master->delete_message();
	break;
	case 'save_group':
		echo $Master->save_group();
	break;
	case 'delete_group':
		echo $Master->delete_group();
	break;
	case 'save_account':
		echo $Master->save_account();
	break;
	case 'delete_account':
		echo $Master->delete_account();
	break;
	case 'save_journal':
		echo $Master->save_journal();
	break;
	case 'delete_journal':
		echo $Master->delete_journal();
	break;
	case 'cancel_journal':
		echo $Master->cancel_journal();
	break;
	case 'save_employee':
		echo $Master->save_employee();
	break;
	case 'delete_employee':
		echo $Master->delete_employee();
	break;
	case 'save_attendance':
		echo $Master->save_attendance();
	break;
	case 'delete_attendance':
		echo $Master->delete_attendance();
	break;
	case 'save_leave':
		echo $Master->save_leave();
	break;
	case 'update_leave_status':
		echo $Master->update_leave_status();
	break;
	case 'delete_leave':
		echo $Master->delete_leave();
	break;
	case 'calculate_payroll_ajax':
		echo $Master->calculate_payroll_ajax();
	break;
	case 'save_payroll':
		echo $Master->save_payroll();
	break;
	case 'save_batch_payroll':
		echo $Master->save_batch_payroll();
	break;
	case 'delete_payroll':
		echo $Master->delete_payroll();
	break;
	case 'save_party':
		echo $Master->save_party();
	break;
	case 'delete_party':
		echo $Master->delete_party();
	break;
	case 'save_invoice':
		echo $Master->save_invoice();
	break;
	case 'delete_invoice':
		echo $Master->delete_invoice();
	break;
	case 'save_payment':
		echo $Master->save_payment();
	break;
	case 'delete_payment':
		echo $Master->delete_payment();
	break;
	case 'save_product':
		echo $Master->save_product();
	break;
	case 'delete_product':
		echo $Master->delete_product();
	break;
	case 'save_sales_order':
		echo $Master->save_sales_order();
	break;
	case 'confirm_sales_order':
		echo $Master->confirm_sales_order();
	break;
	case 'delete_sales_order':
		echo $Master->delete_sales_order();
	break;
	case 'save_stock_adjustment':
		echo $Master->save_stock_adjustment();
	break;
	case 'save_api_key':
		echo $Master->save_api_key();
	break;
	case 'delete_api_key':
		echo $Master->delete_api_key();
	break;
	case 'save_role':
		echo $Master->save_role();
	break;
	case 'delete_role':
		echo $Master->delete_role();
	break;
	case 'email_invoice':
		echo $Master->email_invoice();
	break;
	case 'email_payment_receipt':
		echo $Master->email_payment_receipt();
	break;
	case 'email_payslip':
		echo $Master->email_payslip();
	break;
	case 'save_tenant':
		echo $Master->save_tenant();
	break;
	case 'delete_tenant':
		echo $Master->delete_tenant();
	break;
	default:
		// echo $sysset->index();
		break;
}