<?php

use App\Core\Container;
use App\Services\AccountService;
use App\Services\EmployeeService;
use App\Services\InventoryService;
use App\Services\InvoiceService;
use App\Services\JournalService;
use App\Services\PartyService;
use App\Services\PaymentService;
use App\Services\PayrollService;
use App\Services\RoleService;
use App\Services\TenantService;

require_once(__DIR__ . '/../config.php');

class Master extends DBConnection
{
    private $settings;
    private Container $container;

    public function __construct()
    {
        global $_settings;
        $this->settings = $_settings;
        parent::__construct();

        $this->container = Container::getInstance();
        $this->container->singleton(\mysqli::class, $this->conn);
        $this->container->singleton(SystemSettings::class, $this->settings);
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    /* Account / Group Actions */
    public function save_group()
    {
        return $this->container->make(AccountService::class)->saveGroup($_POST);
    }

    public function delete_group()
    {
        return $this->container->make(AccountService::class)->deleteGroup(intval($_POST['id'] ?? 0));
    }

    public function save_account()
    {
        return $this->container->make(AccountService::class)->saveAccount($_POST);
    }

    public function delete_account()
    {
        return $this->container->make(AccountService::class)->deleteAccount(intval($_POST['id'] ?? 0));
    }

    /* Journal Actions */
    public function save_journal()
    {
        return $this->container->make(JournalService::class)->saveJournal($_POST);
    }

    public function delete_journal()
    {
        return $this->container->make(JournalService::class)->deleteJournal(intval($_POST['id'] ?? 0));
    }

    public function cancel_journal()
    {
        return $this->container->make(JournalService::class)->cancelJournal(intval($_POST['id'] ?? 0));
    }

    /* Role / API Key Actions */
    public function save_role()
    {
        return $this->container->make(RoleService::class)->saveRole($_POST);
    }

    public function delete_role()
    {
        return $this->container->make(RoleService::class)->deleteRole(intval($_POST['id'] ?? 0));
    }

    public function save_api_key()
    {
        return $this->container->make(RoleService::class)->saveApiKey($_POST);
    }

    public function delete_api_key()
    {
        return $this->container->make(RoleService::class)->deleteApiKey(intval($_POST['id'] ?? 0));
    }

    /* Invoice Actions */
    public function save_invoice()
    {
        return $this->container->make(InvoiceService::class)->saveInvoice($_POST);
    }

    public function delete_invoice()
    {
        return $this->container->make(InvoiceService::class)->deleteInvoice(intval($_POST['id'] ?? 0));
    }

    public function email_invoice()
    {
        return $this->container->make(InvoiceService::class)->emailInvoice(intval($_POST['id'] ?? 0), $_POST['custom_email'] ?? null);
    }

    /* Payment Actions */
    public function save_payment()
    {
        return $this->container->make(PaymentService::class)->savePayment($_POST);
    }

    public function delete_payment()
    {
        return $this->container->make(PaymentService::class)->deletePayment(intval($_POST['id'] ?? 0));
    }

    public function email_payment_receipt()
    {
        return $this->container->make(PaymentService::class)->emailPaymentReceipt(intval($_POST['id'] ?? 0), $_POST['custom_email'] ?? null);
    }

    /* Inventory / Product / Sales Order Actions */
    public function save_product()
    {
        return $this->container->make(InventoryService::class)->saveProduct($_POST);
    }

    public function delete_product()
    {
        return $this->container->make(InventoryService::class)->deleteProduct(intval($_POST['id'] ?? 0));
    }

    public function save_stock_adjustment()
    {
        return $this->container->make(InventoryService::class)->saveStockAdjustment($_POST);
    }

    public function save_sales_order()
    {
        extract($_POST);
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');
        $id = !empty($id) ? $this->conn->real_escape_string((string)$id) : '';

        $order_no = !empty($order_no) ? $this->conn->real_escape_string((string)$order_no) : 'SO-' . date('Ym') . '-' . rand(1000, 9999);
        $party_id = $this->conn->real_escape_string((string)($party_id ?? 0));
        $order_date = !empty($order_date) ? $this->conn->real_escape_string((string)$order_date) : date('Y-m-d');
        $type = !empty($type) ? $this->conn->real_escape_string((string)$type) : 'proforma';
        $subtotal = isset($subtotal) ? (float)$subtotal : 0.0;
        $tax_amount = isset($tax_amount) ? (float)$tax_amount : 0.0;
        $discount = isset($discount) ? (float)$discount : 0.0;
        $total = isset($total) ? (float)$total : 0.0;
        $notes = isset($notes) ? $this->conn->real_escape_string((string)$notes) : '';
        $status = !empty($status) ? $this->conn->real_escape_string((string)$status) : 'proforma';

        if (empty($id)) {
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

        if ($save) {
            $this->conn->query("DELETE FROM `sales_order_items` WHERE sales_order_id = '{$sales_order_id}'");
            if (isset($item_name) && is_array($item_name)) {
                foreach ($item_name as $k => $v) {
                    $i_name = $this->conn->real_escape_string((string)$v);
                    if (empty($i_name)) continue;

                    $p_id = isset($product_id[$k]) && is_numeric($product_id[$k]) ? (int)$product_id[$k] : 'NULL';
                    $i_desc = isset($item_desc[$k]) ? $this->conn->real_escape_string((string)$item_desc[$k]) : '';
                    $i_qty = isset($item_qty[$k]) ? (float)$item_qty[$k] : 1.0;
                    $i_price = isset($item_price[$k]) ? (float)$item_price[$k] : 0.0;
                    $i_tax_rate = isset($item_tax_rate[$k]) ? (float)$item_tax_rate[$k] : 0.0;
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

            if ($status === 'confirmed' || $status === 'converted_to_invoice') {
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

    public function confirm_sales_order_internal($sales_order_id)
    {
        $tenant_id = $this->settings->active_tenant_id();
        $user_id = $this->settings->userdata('id');

        $order_qry = $this->conn->query("SELECT * FROM `sales_order_list` WHERE id = '{$sales_order_id}' AND tenant_id = '{$tenant_id}'");
        if (!$order_qry || $order_qry->num_rows == 0) return false;

        $order = $order_qry->fetch_assoc();

        if ($order['stock_adjusted'] == 0) {
            $items = $this->conn->query("SELECT * FROM `sales_order_items` WHERE sales_order_id = '{$sales_order_id}'");
            if ($items) {
                while ($item = $items->fetch_assoc()) {
                    $pid = (int)$item['product_id'];
                    $qty = (float)$item['qty'];

                    if ($pid > 0 && $qty > 0) {
                        $prod = $this->conn->query("SELECT cost_price, stock_quantity FROM `product_list` WHERE id = '{$pid}'")->fetch_assoc();
                        $cost = isset($prod['cost_price']) ? (float)$prod['cost_price'] : 0.0;

                        $this->conn->query("UPDATE `product_list` SET `stock_quantity` = `stock_quantity` - {$qty} WHERE id = '{$pid}'");

                        $orderNoEsc = $this->conn->real_escape_string((string)$order['order_no']);
                        $this->conn->query("INSERT INTO `inventory_logs` SET
							`tenant_id` = '{$tenant_id}',
							`product_id` = '{$pid}',
							`type` = 'out',
							`qty` = '{$qty}',
							`unit_cost` = '{$cost}',
							`reference` = '{$orderNoEsc}',
							`notes` = 'Auto stock deduction on sales order confirmation',
							`created_by` = '{$user_id}'");
                    }
                }
            }

            $this->conn->query("UPDATE `sales_order_list` SET `stock_adjusted` = 1, `status` = 'confirmed' WHERE id = '{$sales_order_id}'");

            if (empty($order['invoice_id'])) {
                $inv_no = 'INV-' . str_replace(['SO-', 'PRO-'], '', $order['order_no']);

                $ar_acc = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%receivable%' OR `name` LIKE '%asset%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
                $ar_acc_id = ($ar_acc && $ar_acc->num_rows > 0) ? $ar_acc->fetch_assoc()['id'] : 1;

                $rev_acc = $this->conn->query("SELECT id FROM `account_list` WHERE (`name` LIKE '%sales%' OR `name` LIKE '%revenue%') AND tenant_id = '{$tenant_id}' AND delete_flag = 0 LIMIT 1");
                $rev_acc_id = ($rev_acc && $rev_acc->num_rows > 0) ? $rev_acc->fetch_assoc()['id'] : 2;

                $prefix = date("Ym-");
                $code = sprintf("%'.05d", 1);
                while (true) {
                    $check = $this->conn->query("SELECT * FROM `journal_entries` where `code` = '{$prefix}{$code}' and tenant_id = '{$tenant_id}' ")->num_rows;
                    if ($check > 0) {
                        $code = sprintf("%'.05d", ceil((float)$code) + 1);
                    } else {
                        break;
                    }
                }
                $journal_code = $prefix . $code;
                $journal_date = $order['order_date'];
                $description = $this->conn->real_escape_string("Sales Invoice {$inv_no} from Order {$order['order_no']}");

                $j_sql = "INSERT INTO `journal_entries` (`tenant_id`, `code`, `journal_date`, `description`, `user_id`) VALUES ('{$tenant_id}', '{$journal_code}', '{$journal_date}', '{$description}', '{$user_id}')";
                $this->conn->query($j_sql);
                $journal_id = $this->conn->insert_id;

                $j_items = "INSERT INTO `journal_items` (`journal_id`, `account_id`, `group_id`, `amount`) VALUES
					('{$journal_id}', '{$ar_acc_id}', '1', '{$order['total']}'),
					('{$journal_id}', '{$rev_acc_id}', '2', '{$order['total']}')";
                $this->conn->query($j_items);

                $dueDate = date('Y-m-d', strtotime($order['order_date'] . ' +30 days'));
                $notesEsc = $this->conn->real_escape_string((string)$order['notes']);
                $inv_sql = "INSERT INTO `invoice_list` SET
					`tenant_id` = '{$tenant_id}',
					`invoice_no` = '{$inv_no}',
					`party_id` = '{$order['party_id']}',
					`invoice_date` = '{$order['order_date']}',
					`due_date` = '{$dueDate}',
					`subtotal` = '{$order['subtotal']}',
					`tax_amount` = '{$order['tax_amount']}',
					`discount` = '{$order['discount']}',
					`total` = '{$order['total']}',
					`paid_amount` = '0.00',
					`balance` = '{$order['total']}',
					`status` = 'unpaid',
					`notes` = '{$notesEsc}',
					`journal_id` = '{$journal_id}',
					`created_by` = '{$user_id}'";
                $this->conn->query($inv_sql);
                $invoice_id = $this->conn->insert_id;

                $items2 = $this->conn->query("SELECT * FROM `sales_order_items` WHERE sales_order_id = '{$sales_order_id}'");
                if ($items2) {
                    while ($it2 = $items2->fetch_assoc()) {
                        $itNameEsc = $this->conn->real_escape_string((string)$it2['item_name']);
                        $itDescEsc = $this->conn->real_escape_string((string)$it2['description']);
                        $this->conn->query("INSERT INTO `invoice_items` SET
							`invoice_id` = '{$invoice_id}',
							`item_name` = '{$itNameEsc}',
							`description` = '{$itDescEsc}',
							`qty` = '{$it2['qty']}',
							`unit_price` = '{$it2['unit_price']}',
							`tax_rate` = '{$it2['tax_rate']}',
							`tax_amount` = '{$it2['tax_amount']}',
							`amount` = '{$it2['amount']}'");
                    }
                }

                $this->conn->query("UPDATE `sales_order_list` SET `invoice_id` = '{$invoice_id}', `status` = 'converted_to_invoice' WHERE id = '{$sales_order_id}'");
            }
        }
        return true;
    }

    public function confirm_sales_order()
    {
        extract($_POST);
        $id = $this->conn->real_escape_string((string)$id);
        if ($this->confirm_sales_order_internal($id)) {
            $resp['status'] = 'success';
            $resp['msg'] = " Sale confirmed, auto stock adjusted, and converted to Sales Invoice successfully.";
            $this->settings->set_flashdata('success', $resp['msg']);
        } else {
            $resp['status'] = 'failed';
            $resp['msg'] = "An error occurred while confirming order.";
        }
        return json_encode($resp);
    }

    public function delete_sales_order()
    {
        extract($_POST);
        $id = $this->conn->real_escape_string((string)$id);
        $tenant_id = $this->settings->active_tenant_id();

        $order = $this->conn->query("SELECT * FROM `sales_order_list` WHERE id = '{$id}' AND tenant_id = '{$tenant_id}'")->fetch_assoc();
        if ($order) {
            if ($order['stock_adjusted'] == 1) {
                $items = $this->conn->query("SELECT * FROM `sales_order_items` WHERE sales_order_id = '{$id}'");
                if ($items) {
                    while ($it = $items->fetch_assoc()) {
                        $pid = (int)$it['product_id'];
                        $qty = (float)$it['qty'];
                        if ($pid > 0 && $qty > 0) {
                            $this->conn->query("UPDATE `product_list` SET `stock_quantity` = `stock_quantity` + {$qty} WHERE id = '{$pid}'");
                        }
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

    /* Payroll Actions */
    public function calculate_payroll_ajax()
    {
        return $this->container->make(PayrollService::class)->calculatePayrollAjax($_POST);
    }

    public function save_payroll()
    {
        return $this->container->make(PayrollService::class)->savePayroll($_POST);
    }

    public function delete_payroll()
    {
        return $this->container->make(PayrollService::class)->deletePayroll(intval($_POST['id'] ?? 0));
    }

    public function email_payslip()
    {
        return $this->container->make(PayrollService::class)->emailPayslip(intval($_POST['id'] ?? 0), $_POST['custom_email'] ?? null);
    }

    /* Employee / Attendance / Leave Actions */
    public function save_employee()
    {
        return $this->container->make(EmployeeService::class)->saveEmployee($_POST);
    }

    public function delete_employee()
    {
        return $this->container->make(EmployeeService::class)->deleteEmployee(intval($_POST['id'] ?? 0));
    }

    public function save_attendance()
    {
        return $this->container->make(EmployeeService::class)->saveAttendance($_POST);
    }

    public function delete_attendance()
    {
        return $this->container->make(EmployeeService::class)->deleteAttendance(intval($_POST['id'] ?? 0));
    }

    public function save_leave()
    {
        return $this->container->make(EmployeeService::class)->saveLeave($_POST);
    }

    public function update_leave_status()
    {
        return $this->container->make(EmployeeService::class)->updateLeaveStatus(intval($_POST['id'] ?? 0), $_POST['status'] ?? 'Pending');
    }

    public function delete_leave()
    {
        return $this->container->make(EmployeeService::class)->deleteLeave(intval($_POST['id'] ?? 0));
    }

    /* Party / Tenant Actions */
    public function save_party()
    {
        return $this->container->make(PartyService::class)->saveParty($_POST);
    }

    public function delete_party()
    {
        return $this->container->make(PartyService::class)->deleteParty(intval($_POST['id'] ?? 0));
    }

    public function save_tenant()
    {
        return $this->container->make(TenantService::class)->saveTenant($_POST);
    }

    public function delete_tenant()
    {
        return $this->container->make(TenantService::class)->deleteTenant(intval($_POST['id'] ?? 0));
    }

    public function git_pull()
    {
        if ($this->settings->userdata('type') != 1) {
            return json_encode(['status' => 'failed', 'msg' => 'Access denied']);
        }
        $branch = trim((string)shell_exec('git rev-parse --abbrev-ref HEAD 2>&1'));
        if (empty($branch)) {
            $branch = 'main';
        }
        $output = shell_exec("git pull origin {$branch} 2>&1");
        return json_encode(['status' => 'success', 'msg' => 'Git pull executed.', 'output' => $output]);
    }

    public function git_fetch()
    {
        if ($this->settings->userdata('type') != 1) {
            return json_encode(['status' => 'failed', 'msg' => 'Access denied']);
        }
        $output = shell_exec("git fetch 2>&1 && git status 2>&1");
        return json_encode(['status' => 'success', 'msg' => 'Git fetch executed.', 'output' => $output]);
    }
}

$Master = new Master();
$action = !isset($_GET['f']) ? 'none' : strtolower($_GET['f']);
switch ($action) {
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
    case 'git_pull':
        echo $Master->git_pull();
        break;
    case 'git_fetch':
        echo $Master->git_fetch();
        break;
    default:
        break;
}
