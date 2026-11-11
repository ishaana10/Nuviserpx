# Nuvis ERPX — Enterprise Resource Planning & Accounting System

**Nuvis ERPX** is a complete, multi-tenant Enterprise Resource Planning (ERP), Accounting Journal Management, and HR/Payroll system developed by **Nuvis Technologies** ([nuvistechnologies.com.fj/ERPX](https://nuvistechnologies.com.fj/ERPX)).

---

## Key Features

- **Multi-Tenant Architecture:** Multi-company support with instant active tenant switching and complete data isolation.
- **Accounting & Journal Management:** Double-entry journal vouchers, account groups, chart of accounts, trial balance, and working trial balance reports.
- **Payroll & Human Resources:** Employee master profiles, attendance logging, leave request workflow, rules-driven payroll (pension rates, tax bands, overtime tiers and employer levies are settings with effective dates, not code), retroactive pay, printable payslips, and bank, tax and pension export files.
- **Automated Journal Postings:** Payroll posts balanced journals with the full employer cost (wages, employer pension, employer levies) against what is owed to employees, the pension fund and the tax authority; remittances then pay those liabilities down.
- **Accounting controls:** period locks, immutable posted entries with a reversal-only workflow, a journal audit trail, maker/checker approvals, gapless document numbering, and database-level integrity (exact `DECIMAL` money, explicit debit/credit side, enforced account types and codes, tenant foreign keys). See [docs/ACCOUNTING_CONTROLS.md](docs/ACCOUNTING_CONTROLS.md).
- **Executive ApexCharts Dashboard:** Interactive real-time analytics for financial movement (Debits vs. Credits), department payroll expense distribution, and workforce attendance analytics.
- **Modern UI & Security:** Glassmorphism dashboard cards, responsive navigation, email OTP password resets, and SMTP settings.
- **Sales & receivables:** quotations with versions and expiry, sales orders, delivery notes, invoices, credit notes with restock, price lists, credit limits and terms, recurring contract invoices, automatic payment reminders and late fees.
- **Purchasing & payables:** purchase orders, goods receipts, vendor bills (cancel with ledger reversal), supplier payments, landed cost allocation.
- **Inventory:** products, warehouses with per-location stock, stock movements and adjustments, physical stock take, optional inventory accounting (weighted-average cost, cost of sales, stock value journals).
- **Expenses & CRM:** employee expense claims with approval and reimbursement, CRM leads, opportunities and activities.
- **Data tools:** bulk CSV import for customers/suppliers, products and the chart of accounts, with a check-before-import preview.

---

## What's New

Newest first. Each item names the pull request it shipped in.

### Inventory and purchasing
- **Batch / lot and expiry tracking (this release).** Tick *Track batch / lot numbers and expiry dates* on a product. Goods receipts and stock-in adjustments then take a batch number and expiry date (left blank, the batch is named after the receipt or adjustment). Whenever stock leaves a location, whether by sale, delivery, adjustment or a stock take shortage, it comes off the batch that expires first (FEFO); transfers between warehouses move the batches with the stock. *Stock > Batches & Expiry* lists batches with quantity, cost and status (OK, expires within 30 days, expired, no expiry), with filters and a one-click *write off expired* that posts a normal stock adjustment. Batches sit on top of the normal stock figures and never block a sale: stock from before tracking was switched on, and units restored by returns, are simply not in a batch. Expired stock stays on hand until written off and is used first. Migration `032_batches_expiry.sql`.
- **Landed cost (#170).** On any goods receipt (Purchase Order > receipt > *Landed cost*), enter freight, duty, insurance or similar charges, spread them over the receipt's items by goods value or by quantity, preview, then post. The split is exact to the cent. Only the part of each item still on hand is added to its cost; units already sold keep their old cost and that share stays in the account the charge was booked to. With inventory accounting on, it posts *Dr Inventory / Cr the account the charges were booked to* (you choose that account), and the journal is reversed if saving fails. Locked per receipt, audited, history shown per receipt. An entry cannot be reversed from the screen yet. Migration `031_landed_costs.sql`.
- **Stock take (#169).** *Stock > Stock Take.* Start a count for a warehouse (items with stock there, or all active products); the system quantities are recorded at that moment. Enter counted quantities over as many sessions as needed, see the difference and its value live, and print the count sheet. *Post differences* applies each line as a normal stock adjustment, so the movement log, inventory accounting and period locks all apply. The adjustment is the difference against the recorded quantity, so stock sold or received while counting is kept. Posting is locked per count, finished lines never repeat, failed lines are shown and retried on the next post, blank rows are skipped, and a count can be cancelled only before anything was adjusted. Migration `030_stock_counts.sql`.
- **Vendor bill cancellation (#167).** Posted vendor bills are cancelled rather than deleted: the journal is reversed, the status becomes *cancelled* and the balance is cleared. Only draft or cancelled bills can be deleted, and a cancelled bill cannot be edited. Bills cancelled before this release may still have a live journal and need a manual reversal.

### Sales and receivables
- **Payment reminders and late fees (#165).** *Reminders* page: choose the days after the due date to email customers (for example 3, 10, 20) and optionally a late fee (percent or fixed, grace days, cap) raised once as its own invoice. Each reminder stage and each late fee happens once per invoice, enforced by unique keys. Run `cron/payment_reminders.php` daily. Late fees post to the normal sales revenue account. Migration `029_payment_reminders.sql`.
- **Recurring contract invoices (#159).** Contracts carry a billing frequency and tax; `cron/contract_invoices.php` raises the invoice for every period that has come due (catching up at most 12 periods) and bills each contract period once. A *Generate invoices* action is also on the contracts page. Migration `027_contract_billing.sql`.

### Expenses, CRM and data
- **Bulk CSV import (#168).** *Settings > Import Data.* Import customers/suppliers, products and chart-of-accounts rows from CSV (up to 2 MB, headings matched flexibly, Excel and Windows-1252 files handled). *Check file* shows what would be created, updated, skipped or rejected before anything is saved; you choose to leave existing records unchanged or update them with the filled-in cells. Opening stock on new products is posted properly. A template can be downloaded for each type.
- **Expense claims (#158).** Employees submit claims by category, managers approve, finance reimburses, and the ledger entries are posted at approval and reimbursement. Migration `026_expense_claims.sql`.
- **CRM and navigation fixes (#160).** The CRM page no longer opens blank (the migration runner had mis-split SQL comments, so two tables were never created; fixed with catch-up migration `028`). The sidebar now scrolls when the menu is long and keeps the active item in view.

### Platform, data safety and security
- **Audit coverage (#166).** Changes to customers/suppliers, products, purchase orders, bills, employees, roles, API keys and companies are written to the audit log with before and after values; secrets are never logged. Employee saves now accept only real columns.
- **MySQL compatibility (#161, #162, #163, #164).** The migration runner works on MySQL as well as MariaDB (no reliance on `ADD COLUMN IF NOT EXISTS`, comment-aware statement splitting, a one-time repair pass) and relaxes `ONLY_FULL_GROUP_BY` per connection.
- **Gapless document numbers and uniqueness (#157).** Document numbers are unique per company and generated through one sequence service.
- **Login protection and OTP (#156).** Throttling of repeated failed logins and email OTP handling hardened (`022_auth_throttle.sql`).
- **Payroll and leave (#151, #152, #153, #155).** Rules-driven payroll gaps closed, and leave entitlement proration and balances added (`018`-`021` migrations).

---

## System Requirements

- **PHP:** Version 8.2 or newer
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
3. Import the SQL file located at `database/nuviserpx.sql`:
   ```bash
   mysql -u root -p nuviserpx < database/nuviserpx.sql
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

## Scheduled Jobs (cron)

These scripts run for every active company and are safe to run more than once (each unit of work happens once). Replace the path with your install.

| Script | Purpose | Suggested schedule |
| --- | --- | --- |
| `cron/auto_reverse_journals.php` | Posts reversals of manual journals whose "Reverse on" date has arrived | `15 0 * * *` |
| `cron/contract_invoices.php` | Raises invoices for contract billing periods that have come due | `0 5 * * *` |
| `cron/invoice_overdue_sweep.php` | Fires the overdue-invoice event (webhooks, workflows) once per invoice per day | `0 6 * * *` |
| `cron/payment_reminders.php` | Emails overdue-invoice reminders and raises late fees | `0 7 * * *` |
| `cron/webhook_worker.php` | Delivers queued webhooks with retry and backoff | `* * * * *` |

Example: `0 7 * * * php /path/to/nuviserpx/cron/payment_reminders.php >> /var/log/erpx_reminders.log 2>&1`

---

## Database Migrations

Schema changes live in `database/migrations/*.sql` and are applied in order by `src/Database/MigrationRunner.php`, with runtime `check_*` methods in `classes/DBConnection.php` creating core tables first. Latest: `030_stock_counts.sql`, `031_landed_costs.sql`, `032_batches_expiry.sql`. Statement splitting ignores semicolons inside comments, and column additions are checked against `information_schema` so the same files run on MySQL and MariaDB. Failed statements are logged, not fatal, so check the PHP error log after upgrading.

---

## Running the Tests

Tests are standalone PHP scripts in `tests/` and need no database. Run them all:

```bash
for t in tests/*.php; do php "$t" > /tmp/out.txt 2>&1 || { echo "FAILED $t"; grep -v '^PASS' /tmp/out.txt; }; done
```

Each prints `PASS`/`FAIL` per check and exits non-zero on failure. They cover calculations, validation and wiring; behaviour against a live database should still be checked after an upgrade.

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

**Nuvis ERPX** is a property of **Nuvis Technologies** ([nuvistechnologies.com.fj/ERPX](https://nuvistechnologies.com.fj/ERPX)). All rights reserved.
