# Nuvis ERPX — Enterprise Resource Planning & Accounting System

**Nuvis ERPX** is a complete, multi-tenant Enterprise Resource Planning (ERP), Accounting Journal Management, and HR/Payroll system developed by **Nuvis Technologies** ([nuvistechnologies.com.fj](https://nuvistechnologies.com.fj)).

---

## Key Features

- **Multi-Tenant Architecture:** Multi-company support with instant active tenant switching and complete data isolation.
- **Accounting & Journal Management:** Double-entry journal vouchers, account groups, chart of accounts, trial balance, and working trial balance reports.
- **Payroll & Human Resources:** Employee master profiles, attendance logging, leave request workflow, automated payroll processing with printable payslips.
- **Automated Journal Postings:** Payroll disbursements automatically create and post balanced GL journal entries (Debits: Salaries Expense, Credits: Cash/Payable).
- **Executive ApexCharts Dashboard:** Interactive real-time analytics for financial movement (Debits vs. Credits), department payroll expense distribution, and workforce attendance analytics.
- **Modern UI & Security:** Glassmorphism dashboard cards, responsive navigation, email OTP password resets, and SMTP settings.

---

## System Requirements

- **PHP:** Version 7.4 or 8.0+
- **Database:** MySQL 5.7+ or MariaDB 10.3+
- **Web Server:** Apache 2.4 (with `mod_rewrite` enabled) or Nginx
- **Required PHP Extensions:**
  - `mysqli`
  - `gd`
  - `mbstring`
  - `session`
  - `json`

---

## Step-by-Step Setup & Installation Guide

### Step 1: Download / Clone the Repository
Clone or place the project files into your web server's root directory (e.g. `/var/www/html/nuvis-erpx` or `C:/xampp/htdocs/nuvis-erpx`).

```bash
git clone https://github.com/ishaana10/Nuvis-payrollx.git nuvis-erpx
cd nuvis-erpx
```

---

### Step 2: Create MySQL Database & Import Schema
1. Open phpMyAdmin or your MySQL CLI client.
2. Create a new database named `ajms_db` (or your preferred database name):
   ```sql
   CREATE DATABASE ajms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. Import the SQL file located at `database/ajms_db.sql`:
   ```bash
   mysql -u root -p ajms_db < database/ajms_db.sql
   ```

---

### Step 3: Configure Database & Application Settings (`initialize.php`)
Open `initialize.php` in the root directory and update your database credentials and `base_url`:

```php
<?php
if(!defined('base_url')) define('base_url','http://localhost/nuvis-erpx/'); // Update to match your domain or path
if(!defined('base_app')) define('base_app', str_replace('\\','/',__DIR__).'/' );
if(!defined('DB_SERVER')) define('DB_SERVER',"localhost");
if(!defined('DB_USERNAME')) define('DB_USERNAME',"root");       // Your Database Username
if(!defined('DB_PASSWORD')) define('DB_PASSWORD',"");           // Your Database Password
if(!defined('DB_NAME')) define('DB_NAME',"ajms_db");            // Your Database Name
?>
```

---

### Step 4: Set Directory Permissions
Ensure the web server has write permissions for upload folders and directories:

```bash
chmod -R 775 uploads/
chmod -R 775 build/
```

---

### Step 5: Web Server Configuration

#### For Apache (`.htaccess`)
Ensure `mod_rewrite` and `mod_headers` are enabled in Apache:
```bash
sudo a2enmod rewrite headers
sudo systemctl restart apache2
```

Ensure your Apache VirtualHost or Directory allows `.htaccess` overrides:
```apache
<Directory /var/www/html/nuvis-erpx>
    Options Indexes FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

---

## Default Login Credentials

Access the administrator portal at: `http://localhost/nuvis-erpx/admin/`

- **Username:** `admin`
- **Password:** `admin123`

---

## 🛠️ Troubleshooting HTTP 500 Internal Server Error

If you encounter an **HTTP 500 Internal Server Error** after installation, follow these troubleshooting steps to identify and resolve the root cause:

### 1. Enable PHP Error Display to See the Exact Error
Open `initialize.php` or `config.php` and temporarily add the following lines at the top:
```php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
```

### 2. Verify Database Connection & Credentials
HTTP 500 errors frequently occur when PHP cannot connect to MySQL due to incorrect database settings or if the database server is stopped.
- Open `initialize.php` and verify `DB_SERVER`, `DB_USERNAME`, `DB_PASSWORD`, and `DB_NAME`.
- Ensure MySQL service is running (`systemctl status mysql` or XAMPP Control Panel).
- Verify database tables exist by running `SHOW TABLES;` inside MySQL.

### 3. Check Apache `.htaccess` Directives
If `mod_headers` is disabled in Apache, the `Header set Access-Control-Allow-Origin "*"` line in `.htaccess` can cause an HTTP 500 error.
- Enable `mod_headers`: `sudo a2enmod headers && sudo systemctl restart apache2`
- Alternatively, temporarily comment out `Header set Access-Control-Allow-Origin "*"` in `.htaccess`.

### 4. Inspect Server Error Logs
Check your web server error logs for detailed PHP stack traces:
- **Apache Log (Ubuntu/Debian):** `/var/log/apache2/error.log`
- **Apache Log (CentOS/RHEL):** `/var/log/httpd/error_log`
- **XAMPP Log:** `C:/xampp/apache/logs/error.log`
- **PHP FPM Log:** `/var/log/php8.0-fpm.log`

---

## License & Branding

**Nuvis ERPX** is a property of **Nuvis Technologies** ([nuvistechnologies.com.fj](https://nuvistechnologies.com.fj)). All rights reserved.
