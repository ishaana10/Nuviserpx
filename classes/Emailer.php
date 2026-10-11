<?php
/**
 * Centralized Emailing & Notification Engine for Nuvis ERPX
 * Nuvis Technologies (nuvistechnologies.com.fj)
 */


class Emailer {

    /**
     * Send email using configured SMTP or PHP mail()
     */
    public static function send_email($to, $subject, $body_html, $from_email = null, $from_name = null) {
        global $_settings;

        if (empty($to)) return false;

        $host = (isset($_settings) && method_exists($_settings, 'info')) ? $_settings->info('smtp_host') : 'smtp.gmail.com';
        $port = (isset($_settings) && method_exists($_settings, 'info')) ? $_settings->info('smtp_port') : 587;
        $user = (isset($_settings) && method_exists($_settings, 'info')) ? $_settings->info('smtp_user') : '';
        $pass = (isset($_settings) && method_exists($_settings, 'info')) ? $_settings->info('smtp_pass') : '';
        $encryption = (isset($_settings) && method_exists($_settings, 'info')) ? $_settings->info('smtp_encryption') : 'tls';

        $from_email = $from_email ?: ((isset($_settings) && method_exists($_settings, 'info')) ? $_settings->info('email') : 'no-reply@nuvistechnologies.com.fj');
        $from_name  = $from_name  ?: ((isset($_settings) && method_exists($_settings, 'info')) ? $_settings->info('name')  : 'Nuvis ERPX');

        // Check if SMTP is configured with host and username
        if (!empty($host) && !empty($user) && !empty($pass)) {
            return self::send_smtp($to, $subject, $body_html, $host, $port, $user, $pass, $encryption, $from_email, $from_name);
        } else {
            return self::send_native($to, $subject, $body_html, $from_email, $from_name);
        }
    }

    /**
     * Socket SMTP Sender
     */
    protected static function send_smtp($to, $subject, $body_html, $host, $port, $user, $pass, $encryption, $from_email, $from_name) {
        try {
            $prefix = ($encryption == 'ssl') ? 'ssl://' : '';
            $socket = @fsockopen($prefix . $host, $port, $errno, $errstr, 15);

            if (!$socket) {
                return self::send_native($to, $subject, $body_html, $from_email, $from_name);
            }

            self::get_smtp_response($socket);

            fputs($socket, "EHLO " . gethostname() . "\r\n");
            self::get_smtp_response($socket);

            if ($encryption == 'tls') {
                fputs($socket, "STARTTLS\r\n");
                self::get_smtp_response($socket);
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                fputs($socket, "EHLO " . gethostname() . "\r\n");
                self::get_smtp_response($socket);
            }

            fputs($socket, "AUTH LOGIN\r\n");
            self::get_smtp_response($socket);

            fputs($socket, base64_encode($user) . "\r\n");
            self::get_smtp_response($socket);

            fputs($socket, base64_encode($pass) . "\r\n");
            self::get_smtp_response($socket);

            fputs($socket, "MAIL FROM: <{$from_email}>\r\n");
            self::get_smtp_response($socket);

            fputs($socket, "RCPT TO: <{$to}>\r\n");
            self::get_smtp_response($socket);

            fputs($socket, "DATA\r\n");
            self::get_smtp_response($socket);

            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: {$from_name} <{$from_email}>\r\n";
            $headers .= "To: <{$to}>\r\n";
            $headers .= "Subject: {$subject}\r\n";
            $headers .= "Date: " . date("r") . "\r\n";

            $message  = $headers . "\r\n" . $body_html . "\r\n.\r\n";

            fputs($socket, $message);
            self::get_smtp_response($socket);

            fputs($socket, "QUIT\r\n");
            fclose($socket);

            return true;
        } catch (Exception $e) {
            return self::send_native($to, $subject, $body_html, $from_email, $from_name);
        }
    }

    protected static function get_smtp_response($socket) {
        $response = "";
        while ($str = fgets($socket, 515)) {
            $response .= $str;
            if (substr($str, 3, 1) == " ") break;
        }
        return $response;
    }

    /**
     * Native mail() Fallback
     */
    protected static function send_native($to, $subject, $body_html, $from_email, $from_name) {
        $headers  = "MIME-Version: 1.0" . "\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
        $headers .= "From: {$from_name} <{$from_email}>" . "\r\n";
        $headers .= "Reply-To: {$from_email}" . "\r\n";

        return @mail($to, $subject, $body_html, $headers);
    }

    /**
     * Base HTML Email Wrapper Template
     */
    protected static function wrap_template($title, $body_content, $company_name = 'Nuvis ERPX') {
        return "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <title>{$title}</title>
            <style>
                body { font-family: 'Segoe UI', Arial, sans-serif; background-color: #f4f6f9; margin: 0; padding: 20px; color: #333; }
                .email-container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05); border: 1px solid #e1e8ed; }
                .email-header { background: #1e3a8a; color: #ffffff; padding: 20px; text-align: center; }
                .email-header h2 { margin: 0; font-size: 20px; font-weight: 600; }
                .email-body { padding: 25px; line-height: 1.6; }
                .email-footer { background: #f8fafc; padding: 15px; text-align: center; font-size: 12px; color: #64748b; border-top: 1px solid #e2e8f0; }
                .btn { display: inline-block; background: #2563eb; color: #ffffff !important; padding: 10px 20px; text-decoration: none; border-radius: 5px; font-weight: bold; margin-top: 15px; }
                table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                th, td { padding: 10px; border: 1px solid #e2e8f0; text-align: left; font-size: 14px; }
                th { background: #f1f5f9; font-weight: bold; }
            </style>
        </head>
        <body>
            <div class='email-container'>
                <div class='email-header'>
                    <h2>{$company_name}</h2>
                </div>
                <div class='email-body'>
                    {$body_content}
                </div>
                <div class='email-footer'>
                    &copy; " . date('Y') . " {$company_name} &bull; Powered by Nuvis ERPX
                </div>
            </div>
        </body>
        </html>";
    }

    /**
     * Send Password Reset OTP Email
     */
    public static function send_auth_otp($to, $name, $otp_code) {
        $subject = "Password Reset OTP Code - Nuvis ERPX";
        $body = "
        <h3>Hello " . htmlspecialchars($name) . ",</h3>
        <p>You recently requested to reset your password on <b>Nuvis ERPX</b>.</p>
        <p>Your 6-digit One-Time Verification Code (OTP) is:</p>
        <div style='background: #f1f5f9; border: 2px dashed #2563eb; font-size: 28px; font-weight: bold; letter-spacing: 5px; text-align: center; padding: 15px; margin: 20px 0; color: #1e3a8a;'>
            {$otp_code}
        </div>
        <p>This code is valid for <b>15 minutes</b>. If you did not request a password reset, please ignore this email.</p>";

        $html = self::wrap_template("Password Reset OTP", $body);
        return self::send_email($to, $subject, $html);
    }

    /**
     * Send Invoice Email to Customer
     */
    public static function send_invoice_email($to, $invoice, $items, $sys_info = []) {
        $company_name = $sys_info['name'] ?? 'Nuvis ERPX';
        $subject = "Tax Invoice #" . $invoice['invoice_no'] . " from " . $company_name;

        $item_rows = "";
        foreach ($items as $item) {
            $item_rows .= "<tr>
                <td>" . htmlspecialchars($item['item_name']) . "</td>
                <td style='text-align:center;'>" . number_format($item['qty'], 2) . "</td>
                <td style='text-align:right;'>$" . number_format($item['unit_price'], 2) . "</td>
                <td style='text-align:right;'>$" . number_format($item['amount'], 2) . "</td>
            </tr>";
        }

        $body = "
        <h3>Dear " . htmlspecialchars($invoice['party_name']) . ",</h3>
        <p>Thank you for your business. Please find your invoice details below:</p>
        <div style='background: #f8fafc; padding: 15px; border-radius: 6px; margin-bottom: 20px;'>
            <p style='margin: 4px 0;'><b>Invoice Number:</b> #" . htmlspecialchars($invoice['invoice_no']) . "</p>
            <p style='margin: 4px 0;'><b>Invoice Date:</b> " . date('d M Y', strtotime($invoice['invoice_date'])) . "</p>
            <p style='margin: 4px 0;'><b>Due Date:</b> " . ($invoice['due_date'] ? date('d M Y', strtotime($invoice['due_date'])) : '—') . "</p>
            <p style='margin: 4px 0; color: #dc2626;'><b>Balance Due:</b> $" . number_format($invoice['balance'], 2) . " FJD</p>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th style='text-align:center;'>Qty</th>
                    <th style='text-align:right;'>Price</th>
                    <th style='text-align:right;'>Amount</th>
                </tr>
            </thead>
            <tbody>
                {$item_rows}
            </tbody>
        </table>

        <div style='text-align: right; margin-top: 15px;'>
            <p style='margin: 4px 0;'>Subtotal: <b>$" . number_format($invoice['subtotal'], 2) . "</b></p>
            <p style='margin: 4px 0;'>Tax / VAT: <b>$" . number_format($invoice['tax_amount'], 2) . "</b></p>
            <h3 style='margin: 8px 0; color: #1e3a8a;'>Total Amount: $" . number_format($invoice['total'], 2) . " FJD</h3>
        </div>
        <p>Please arrange payment by the due date. If you have any questions, feel free to contact us.</p>";

        $html = self::wrap_template("Tax Invoice #" . $invoice['invoice_no'], $body, $company_name);
        return self::send_email($to, $subject, $html);
    }

    /**
     * Send Payment Receipt Email to Customer
     */
    public static function send_payment_receipt_email($to, $payment, $allocations, $sys_info = []) {
        $company_name = $sys_info['name'] ?? 'Nuvis ERPX';
        $subject = "Payment Receipt #" . $payment['payment_no'] . " from " . $company_name;

        $alloc_rows = "";
        foreach ($allocations as $alloc) {
            $alloc_rows .= "<tr>
                <td>#" . htmlspecialchars($alloc['invoice_no']) . "</td>
                <td>" . date('d M Y', strtotime($alloc['invoice_date'])) . "</td>
                <td style='text-align:right;'>$" . number_format($alloc['invoice_total'], 2) . "</td>
                <td style='text-align:right; font-weight:bold;'>$" . number_format($alloc['allocated_amount'], 2) . "</td>
            </tr>";
        }

        $body = "
        <h3>Dear " . htmlspecialchars($payment['party_name']) . ",</h3>
        <p>We have received your payment. Thank you!</p>
        <div style='background: #f0fdf4; border: 1px solid #bbf7d0; padding: 15px; border-radius: 6px; margin-bottom: 20px; text-align: center;'>
            <p style='margin: 0; color: #166534; font-size: 14px;'>AMOUNT RECEIVED</p>
            <h2 style='margin: 5px 0; color: #15803d;'>$" . number_format($payment['amount'], 2) . " FJD</h2>
            <p style='margin: 0; color: #166534; font-size: 13px;'>Receipt #" . htmlspecialchars($payment['payment_no']) . " &bull; " . date('d M Y', strtotime($payment['payment_date'])) . "</p>
        </div>

        <h4>Allocated Invoices</h4>
        <table>
            <thead>
                <tr>
                    <th>Invoice No</th>
                    <th>Date</th>
                    <th style='text-align:right;'>Invoice Total</th>
                    <th style='text-align:right;'>Amount Applied</th>
                </tr>
            </thead>
            <tbody>
                {$alloc_rows}
            </tbody>
        </table>";

        $html = self::wrap_template("Payment Receipt #" . $payment['payment_no'], $body, $company_name);
        return self::send_email($to, $subject, $html);
    }

    /**
     * Send Confidential Payslip Email to Employee
     */
    public static function send_payslip_email($to, $payroll, $sys_info = []) {
        $company_name = $sys_info['name'] ?? 'Nuvis ERPX';
        $subject = "Confidential Salary Slip - " . $payroll['salary_month'] . " (" . $company_name . ")";

        $body = "
        <h3>Dear " . htmlspecialchars($payroll['emp_name']) . ",</h3>
        <p>Your salary slip for <b>" . htmlspecialchars($payroll['salary_month']) . "</b> is now available.</p>

        <div style='background: #f8fafc; padding: 15px; border-radius: 6px; margin-bottom: 20px;'>
            <p style='margin: 4px 0;'><b>Gross Earnings:</b> $" . number_format($payroll['gross_salary'], 2) . "</p>
            <p style='margin: 4px 0;'><b>FNPF Employee Deduction (8%):</b> $" . number_format($payroll['employee_fnpf'], 2) . "</p>
            <p style='margin: 4px 0;'><b>PAYE Income Tax:</b> $" . number_format($payroll['paye_tax'], 2) . "</p>
            <p style='margin: 4px 0;'><b>Other Deductions:</b> $" . number_format($payroll['deductions'], 2) . "</p>
            <h3 style='margin: 8px 0; color: #166534;'>Net Salary Payable: $" . number_format($payroll['net_salary'], 2) . " FJD</h3>
        </div>
        <p>This is a confidential communication regarding your personal compensation.</p>";

        $html = self::wrap_template("Salary Slip - " . $payroll['salary_month'], $body, $company_name);
        return self::send_email($to, $subject, $html);
    }
}
?>