<?php
require_once(__DIR__ . '/../../config.php');
if(isset($_GET['id']) && is_numeric($_GET['id']) && $_GET['id'] > 0){
    $id = intval($_GET['id']);
    $qry = $conn->query("SELECT p.*, e.employee_code, e.firstname, e.lastname, e.department, e.designation, e.phone, e.tin as emp_tin, e.fnpf_no as emp_fnpf, e.bank_code as emp_bank_code, e.bank_account as emp_bank_acc from `payroll_list` p inner join `employee_list` e on p.employee_id = e.id where p.id = '{$id}' ");
    if($qry && $qry->num_rows > 0){
        foreach($qry->fetch_assoc() as $k => $v){
            $$k=$v;
        }
    }
}
?>
<style>
    @media print {
        body * {
            visibility: hidden;
        }
        #print-area, #print-area * {
            visibility: visible;
        }
        #print-area {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
        }
    }
</style>
<div class="container-fluid" id="print-area">
    <div class="card p-4 shadow-none border">
        <!-- Header -->
        <div class="row border-bottom pb-3 mb-3">
            <div class="col-8">
                <h3 class="font-weight-bold text-navy mb-0"><?php echo $_settings->info('name') ?></h3>
                <p class="text-muted mb-1 small"><?php echo $_settings->info('address') ?: 'Fiji Islands' ?></p>
                <p class="text-muted mb-0 small"><b>Contact:</b> <?php echo $_settings->info('contact') ?> | <b>Email:</b> <?php echo $_settings->info('email') ?></p>
            </div>
            <div class="col-4 text-right">
                <h4 class="text-primary font-weight-bold mb-1">SALARY SLIP</h4>
                <span class="badge badge-primary px-3 py-1 font-weight-normal"><?php echo isset($salary_month) ? $salary_month : '' ?></span>
                <p class="small text-muted mt-2 mb-0"><b>Run Ref:</b> <?php echo !empty($payroll_run_ref) ? $payroll_run_ref : 'N/A' ?></p>
            </div>
        </div>

        <!-- Employee & Pay Period Details -->
        <div class="row mb-3 bg-light p-3 rounded">
            <div class="col-md-6">
                <p class="mb-1"><b>Employee Code:</b> <?php echo isset($employee_code) ? $employee_code : '' ?></p>
                <p class="mb-1"><b>Employee Name:</b> <?php echo isset($firstname) ? $firstname . ' ' . $lastname : '' ?></p>
                <p class="mb-1"><b>Department / Position:</b> <?php echo isset($department) ? $department : '' ?> / <?php echo isset($designation) ? $designation : '' ?></p>
                <p class="mb-1"><b>Bank Details:</b> <?php echo !empty($bank_code) ? strtoupper($bank_code) : strtoupper($emp_bank_code ?? 'BSP') ?> - <?php echo !empty($bank_account) ? $bank_account : ($emp_bank_acc ?? 'N/A') ?></p>
            </div>
            <div class="col-md-6">
                <p class="mb-1"><b>Tax Identification No (TIN):</b> <?php echo !empty($tin) ? $tin : ($emp_tin ?? 'N/A') ?></p>
                <p class="mb-1"><b>FNPF Number:</b> <?php echo !empty($fnpf_no) ? $fnpf_no : ($emp_fnpf ?? 'N/A') ?></p>
                <p class="mb-1"><b>Tax Code & Status:</b> <?php echo !empty($tax_code) ? $tax_code : 'P' ?> (<?php echo (isset($is_resident) && $is_resident == 0) ? 'Non-Resident' : 'Resident' ?>)</p>
                <p class="mb-1"><b>Pay Date:</b> <?php echo !empty($pay_date) ? date("M d, Y", strtotime($pay_date)) : date("M d, Y") ?></p>
            </div>
        </div>

        <!-- Earnings and Deductions Tables -->
        <div class="row">
            <!-- Earnings -->
            <div class="col-md-6">
                <div class="card card-outline card-success mb-3">
                    <div class="card-header py-1 bg-success text-white">
                        <h6 class="card-title m-0 font-weight-bold">Earnings (FJD)</h6>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm table-striped m-0">
                            <tbody>
                                <tr>
                                    <td>Basic Salary</td>
                                    <td class="text-right font-weight-bold"><?php echo number_format($basic_salary, 2) ?></td>
                                </tr>
                                <tr>
                                    <td>Overtime</td>
                                    <td class="text-right"><?php echo number_format($overtime, 2) ?></td>
                                </tr>
                                <tr>
                                    <td>Allowances</td>
                                    <td class="text-right"><?php echo number_format($allowances, 2) ?></td>
                                </tr>
                                <tr>
                                    <td>Other Earnings</td>
                                    <td class="text-right"><?php echo number_format($other_earnings, 2) ?></td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="bg-light">
                                    <th class="font-weight-bold">Gross Earnings</th>
                                    <th class="text-right font-weight-bold text-success"><?php echo number_format($gross_salary > 0 ? $gross_salary : ($basic_salary + $overtime + $allowances + $other_earnings), 2) ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Deductions -->
            <div class="col-md-6">
                <div class="card card-outline card-danger mb-3">
                    <div class="card-header py-1 bg-danger text-white">
                        <h6 class="card-title m-0 font-weight-bold">Deductions & Taxes (FJD)</h6>
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm table-striped m-0">
                            <tbody>
                                <tr>
                                    <td>FNPF Employee Contribution (8%)</td>
                                    <td class="text-right text-info"><?php echo number_format($employee_fnpf, 2) ?></td>
                                </tr>
                                <tr>
                                    <td>PAYE Income Tax</td>
                                    <td class="text-right text-warning"><?php echo number_format($paye_tax, 2) ?></td>
                                </tr>
                                <tr>
                                    <td>Social Responsibility Tax (SRT)</td>
                                    <td class="text-right text-warning"><?php echo number_format($srt_tax, 2) ?></td>
                                </tr>
                                <tr>
                                    <td>Other Deductions</td>
                                    <td class="text-right text-danger"><?php echo number_format($deductions, 2) ?></td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="bg-light">
                                    <th class="font-weight-bold">Total Deductions</th>
                                    <th class="text-right font-weight-bold text-danger"><?php echo number_format($employee_fnpf + $paye_tax + $srt_tax + $deductions, 2) ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Net Salary Summary Box -->
        <div class="card bg-navy text-white p-3 mb-3">
            <div class="row align-items-center">
                <div class="col-8">
                    <h5 class="m-0 font-weight-bold text-uppercase"><i class="fas fa-coins text-warning"></i> Net Salary Payable</h5>
                    <small class="text-light">Direct credit to employee account (FJD)</small>
                </div>
                <div class="col-4 text-right">
                    <h3 class="m-0 font-weight-bold text-warning">$<?php echo number_format($net_salary, 2) ?></h3>
                </div>
            </div>
        </div>

        <!-- Employer Contributions Summary -->
        <div class="p-3 bg-light border rounded mb-4">
            <h6 class="font-weight-bold text-secondary mb-2"><i class="fas fa-building"></i> Employer Statutory Contributions Summary</h6>
            <div class="row small">
                <div class="col-md-4">
                    <span><b>FNPF Employer Contribution (8%):</b> $<?php echo number_format($employer_fnpf, 2) ?></span>
                </div>
                <div class="col-md-4">
                    <span><b>Workcare Levy (1%):</b> $<?php echo number_format($workcare_levy, 2) ?></span>
                </div>
                <div class="col-md-4 text-right">
                    <span><b>Total Employer Cost:</b> $<?php echo number_format($employer_cost > 0 ? $employer_cost : ($gross_salary + $employer_fnpf + $workcare_levy), 2) ?></span>
                </div>
            </div>
        </div>

        <!-- Signatures -->
        <div class="row mt-4 pt-4">
            <div class="col-6 text-center">
                <p class="border-top pt-2 mb-0 font-weight-bold">Employee Signature</p>
            </div>
            <div class="col-6 text-center">
                <p class="border-top pt-2 mb-0 font-weight-bold">Authorized Signature & Stamp</p>
            </div>
        </div>
    </div>
</div>

<div class="text-right mt-3">
    <button class="btn btn-flat btn-primary" type="button" id="print-payslip"><i class="fa fa-print"></i> Print Payslip</button>
    <button class="btn btn-flat btn-secondary" type="button" data-dismiss="modal">Close</button>
</div>

<script>
    $(document).ready(function(){
        $('#print-payslip').click(function(){
            var head = $('head').clone();
            var p = $('#print-area').clone();
            var el = $('<div>');
            el.append(head);
            el.append(p);
            var nw = window.open('','_blank','width=800,height=600');
            nw.document.write(el.html());
            nw.document.close();
            setTimeout(() => {
                nw.print();
                setTimeout(() => {
                    nw.close();
                }, 500);
            }, 500);
        })
    })
</script>