<?php
/**
 * Centralized Emailing & Notification Engine for Nuvis ERPX
 * Nuvis Technologies
 */


class Emailer {

    /**
     * Send email using configured SMTP or PHP mail()
     */
    /** Last transport error, for the per-tenant email log. */
    protected static $last_error = '';

    public static function send_email($to, $subject, $body_html, $from_email = null, $from_name = null) {
        global $_settings, $conn;

        if (empty($to)) return false;

        $has = isset($_settings) && method_exists($_settings, 'info');
        $tenant_id = ($has && method_exists($_settings, 'active_tenant_id')) ? (int)$_settings->active_tenant_id() : 0;

        // The company's own SMTP (Settings > Company Email), when enabled,
        // sends this tenant's notifications; the system SMTP is the fallback.
        $tcfg = null;
        if ($tenant_id > 0 && isset($conn) && $conn instanceof \mysqli && class_exists('App\\Services\\TenantMailService')) {
            try {
                $tcfg = (new \App\Services\TenantMailService($conn, $_settings))->config($tenant_id);
            } catch (\Throwable $e) {
                $tcfg = null;
            }
        }

        $sys_from_email = $from_email ?: ($has ? $_settings->info('email') : 'no-reply@nuvistechnologies.com.fj');
        $sys_from_name  = $from_name  ?: ($has ? $_settings->info('name')  : 'Nuvis ERPX');

        $attempts = [];
        if ($tcfg !== null) {
            $attempts[] = ['tenant-smtp', $tcfg['host'], $tcfg['port'], $tcfg['user'], $tcfg['pass'], $tcfg['encryption'], $from_email ?: $tcfg['from_email'], $from_name ?: $tcfg['from_name']];
        }
        $sh = $has ? $_settings->info('smtp_host') : ''; $su = $has ? $_settings->info('smtp_user') : ''; $sp = $has ? $_settings->info('smtp_pass') : '';
        if (!empty($sh) && !empty($su) && !empty($sp)) {
            $attempts[] = ['system-smtp', $sh, $has ? $_settings->info('smtp_port') : 587, $su, $sp, $has ? $_settings->info('smtp_encryption') : 'tls', $sys_from_email, $sys_from_name];
        }

        $ok = false; $used = 'mail()'; $errors = [];
        foreach ($attempts as $a) {
            self::$last_error = '';
            if (self::send_smtp($to, $subject, $body_html, $a[1], $a[2], $a[3], $a[4], $a[5], $a[6], $a[7])) {
                $ok = true; $used = $a[0];
                break;
            }
            $errors[] = $a[0] . ': ' . (self::$last_error ?: 'send failed');
        }
        if (!$ok && $attempts === []) {
            $ok = self::send_native($to, $subject, $body_html, $sys_from_email, $sys_from_name);
            if (!$ok) { $errors[] = 'mail(): refused by the local mailer'; }
        }

        if ($tenant_id > 0 && isset($conn) && $conn instanceof \mysqli && class_exists('App\\Services\\TenantMailService')) {
            \App\Services\TenantMailService::logSend($conn, $tenant_id, (string)$to, (string)$subject, $used, $ok, implode(' | ', $errors));
        }
        return $ok;
    }

    /**
     * Socket SMTP Sender
     */
    protected static function send_smtp($to, $subject, $body_html, $host, $port, $user, $pass, $encryption, $from_email, $from_name) {
        try {
            $prefix = ($encryption == 'ssl') ? 'ssl://' : '';
            $socket = @fsockopen($prefix . $host, $port, $errno, $errstr, 15);

            if (!$socket) {
                self::$last_error = "could not connect to {$host}:{$port} - {$errstr} ({$errno})";
                return false;
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
            self::expect($socket, '334', 'AUTH');

            fputs($socket, base64_encode($user) . "\r\n");
            self::expect($socket, '334', 'username');

            fputs($socket, base64_encode($pass) . "\r\n");
            self::expect($socket, '235', 'authentication');

            fputs($socket, "MAIL FROM: <{$from_email}>\r\n");
            self::expect($socket, '250', 'MAIL FROM');

            fputs($socket, "RCPT TO: <{$to}>\r\n");
            self::expect($socket, '250', 'RCPT TO');

            fputs($socket, "DATA\r\n");
            self::expect($socket, '354', 'DATA');

            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "From: {$from_name} <{$from_email}>\r\n";
            $headers .= "To: <{$to}>\r\n";
            $headers .= "Subject: {$subject}\r\n";
            $headers .= "Date: " . date("r") . "\r\n";
            $from_domain = substr(strrchr($from_email, '@'), 1) ?: 'localhost';
            $headers .= "Message-ID: <" . bin2hex(random_bytes(16)) . "@" . $from_domain . ">\r\n";
            $headers .= "Reply-To: {$from_email}\r\n";

            $message  = $headers . "\r\n" . $body_html . "\r\n.\r\n";

            fputs($socket, $message);
            self::expect($socket, '250', 'message delivery');

            fputs($socket, "QUIT\r\n");
            fclose($socket);

            return true;
        } catch (Exception $e) {
            self::$last_error = $e->getMessage();
            return false;
        }
    }

    /** Read the server's reply and fail loudly when it is not the expected code. */
    protected static function expect($socket, $code, $step) {
        $response = self::get_smtp_response($socket);
        if (strpos((string)$response, (string)$code) !== 0) {
            throw new Exception("SMTP {$step} failed: " . trim((string)$response));
        }
        return $response;
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
            <p style='margin: 4px 0; color: #dc2626;'><b>Balance Due:</b> $" . number_format($invoice['balance'], 2) . " " . htmlspecialchars($sys_info['currency_code'] ?? '') . "</p>
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
            <h3 style='margin: 8px 0; color: #1e3a8a;'>Total Amount: $" . number_format($invoice['total'], 2) . " " . htmlspecialchars($sys_info['currency_code'] ?? '') . "</h3>
        </div>
        <p>Please arrange payment by the due date. If you have any questions, feel free to contact us.</p>";

        $html = self::wrap_template("Tax Invoice #" . $invoice['invoice_no'], $body, $company_name);
        return self::send_email($to, $subject, $html);
    }

    /**
     * Payment reminder for an overdue invoice. $sys: name, email, currency (symbol or code).
     */
    public static function send_payment_reminder_email($to, $invoice, $daysOverdue, $sys_info = [], $note = '') {
        $company = $sys_info['name'] ?? 'Nuvis ERPX';
        $cur = $sys_info['currency'] ?? '';
        $subject = "Payment reminder: invoice #" . $invoice['invoice_no'] . " is " . (int)$daysOverdue . " day" . ((int)$daysOverdue === 1 ? '' : 's') . " overdue";
        $noteHtml = trim((string)$note) !== '' ? "<p>" . nl2br(htmlspecialchars((string)$note)) . "</p>" : '';
        $body = "
        <h3>Dear " . htmlspecialchars((string)$invoice['party_name']) . ",</h3>
        <p>Our records show the invoice below is past its due date. If you have already paid, please disregard this reminder.</p>
        <div style='background: #f8fafc; padding: 15px; border-radius: 6px; margin-bottom: 20px;'>
            <p style='margin: 4px 0;'><b>Invoice:</b> #" . htmlspecialchars((string)$invoice['invoice_no']) . "</p>
            <p style='margin: 4px 0;'><b>Invoice date:</b> " . date('d M Y', strtotime((string)$invoice['invoice_date'])) . "</p>
            <p style='margin: 4px 0;'><b>Due date:</b> " . date('d M Y', strtotime((string)$invoice['due_date'])) . "</p>
            <p style='margin: 4px 0; color: #dc2626;'><b>Amount outstanding:</b> " . htmlspecialchars((string)$cur) . number_format((float)$invoice['balance'], 2) . "</p>
        </div>
        {$noteHtml}
        <p>Please arrange payment as soon as you can, or contact us if there is a problem with the invoice.</p>";
        $html = self::wrap_template("Payment reminder", $body, $company);
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
            <h2 style='margin: 5px 0; color: #15803d;'>$" . number_format($payment['amount'], 2) . " " . htmlspecialchars($sys_info['currency_code'] ?? '') . "</h2>
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
    public static function send_portal_invitation($to, $party_name, $invite_url, $sys_info = []) {
        $company_name = $sys_info['name'] ?? 'Nuvis ERPX';
        $subject = "Customer Portal Access Invitation - " . $company_name;

        $body = "
        <h3>Dear " . htmlspecialchars($party_name) . ",</h3>
        <p>You have been invited to access your Customer Self-Service Portal. You can view your invoices, payment receipts, and account balance online anytime.</p>

        <div style='text-align: center; margin: 30px 0;'>
            <a href='" . htmlspecialchars($invite_url) . "' style='background-color: #0284c7; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px; display: inline-block;'>Activate Customer Account &rarr;</a>
        </div>

        <p style='font-size: 13px; color: #666;'>Or copy and paste this URL into your browser:<br><a href='" . htmlspecialchars($invite_url) . "'>" . htmlspecialchars($invite_url) . "</a></p>
        <p style='font-size: 13px; color: #666;'>This invitation link is valid for 7 days.</p>";

        $html = self::wrap_template("Customer Portal Invitation", $body, $company_name);
        return self::send_email($to, $subject, $html);
    }

    public static function send_employee_portal_invitation($to, $employee_name, $invite_url, $sys_info = []) {
        $company_name = $sys_info['name'] ?? 'Nuvis ERPX';
        $subject = "Employee Self-Service Access - " . $company_name;

        $body = "
        <h3>Dear " . htmlspecialchars($employee_name) . ",</h3>
        <p>You have been invited to the Employee Self-Service portal, where you can view and print your payslips online.</p>

        <div style='text-align: center; margin: 30px 0;'>
            <a href='" . htmlspecialchars($invite_url) . "' style='background-color: #0284c7; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px; display: inline-block;'>Set Up My Account &rarr;</a>
        </div>

        <p style='font-size: 13px; color: #666;'>Or copy and paste this URL into your browser:<br><a href='" . htmlspecialchars($invite_url) . "'>" . htmlspecialchars($invite_url) . "</a></p>
        <p style='font-size: 13px; color: #666;'>This link is valid for 7 days and can be used once. If you did not expect this email, you can ignore it.</p>";

        $html = self::wrap_template("Employee Self-Service Invitation", $body, $company_name);
        return self::send_email($to, $subject, $html);
    }

    /**
     * Welcome / email-verification message for a system user account.
     * $is_new = true sends the "account created for you" wording.
     */
    public static function send_email_verification($to, $name, $username, $verify_url, $sys_info = [], $is_new = false) {
        $company_name = $sys_info['name'] ?? 'Nuvis ERPX';
        $subject = ($is_new ? "Your account - " : "Verify your email - ") . $company_name;

        $intro = $is_new
            ? "<p>An account has been created for you on <b>" . htmlspecialchars($company_name) . "</b>.</p>
               <p>Your login username is <b>" . htmlspecialchars($username) . "</b>. Your administrator will provide your password if you don't have it yet.</p>
               <p>Please confirm this email address so we know we can reach you:</p>"
            : "<p>Please confirm that this email address belongs to your <b>" . htmlspecialchars($company_name) . "</b> account (<b>" . htmlspecialchars($username) . "</b>):</p>";

        $body = "
        <h3>Dear " . htmlspecialchars($name) . ",</h3>
        {$intro}
        <div style='text-align: center; margin: 30px 0;'>
            <a href='" . htmlspecialchars($verify_url) . "' style='background-color: #0284c7; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px; display: inline-block;'>Verify My Email &rarr;</a>
        </div>
        <p style='font-size: 13px; color: #666;'>Or copy and paste this URL into your browser:<br><a href='" . htmlspecialchars($verify_url) . "'>" . htmlspecialchars($verify_url) . "</a></p>
        <p style='font-size: 13px; color: #666;'>This link is valid for 48 hours. If you did not expect this email, you can ignore it.</p>";

        $html = self::wrap_template($is_new ? "Welcome" : "Email Verification", $body, $company_name);
        return self::send_email($to, $subject, $html);
    }

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
            <h3 style='margin: 8px 0; color: #166534;'>Net Salary Payable: $" . number_format($payroll['net_salary'], 2) . " " . htmlspecialchars($sys_info['currency_code'] ?? '') . "</h3>
        </div>
        <p>This is a confidential communication regarding your personal compensation.</p>";

        $html = self::wrap_template("Salary Slip - " . $payroll['salary_month'], $body, $company_name);
        return self::send_email($to, $subject, $html);
    }
}
?>