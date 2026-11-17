<p align="center"><img src="dist/img/nuviserpx-logo.png" alt="Nuvis ERPX" width="160"></p>

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
- **Ticket service targets (this release).** *Helpdesk > Service targets* sets, per priority, how many hours a ticket may wait for a first staff reply and for resolution (defaults: urgent 1/4, high 4/8, medium 8/24, low 24/72; calendar hours). The ticket list and each ticket show Met, On track, At risk (last quarter of the time), Breached or Met late for both clocks, with due times. The first reply is the first staff reply on the ticket; the resolution clock pauses while a ticket is *Waiting Client*, stops when it is resolved or closed, and restarts if it is reopened. Replying to a ticket now also checks that it belongs to your company. Migration `034_ticket_sla_crm_contacts.sql`.
- **CRM contacts, lead source and lost reason (this release).** *CRM > Contacts* keeps the people at each customer (title, email, phone, one primary per customer) and a deal can name its contact. Deals record a lead source (referral, website, walk-in, social, outreach, event, advertising, other) and a deal cannot be marked lost without a reason (price, competitor, budget, no decision, timing, no response, requirements, other), including when dragged to *Closed Lost* on the board. The CRM page shows deals, win rate and value won by lead source, and a count of why deals are lost.
- **Supplier withholding tax (this release).** Set a *Withholding tax rate* on a supplier. On *Supplier Payments* the rate pre-fills the tax to keep back (you can change the amount), and the page shows what the bank will actually pay. The journal is *Dr Accounts Payable (full amount settled), Cr Bank (net), Cr Withholding Tax Payable (tax kept back)*, so the bills are settled in full while the tax stays as a liability; the account is created the first time it is needed. Voiding a payment reverses all three lines. Not available on payments from a foreign-currency bank account. *Purchases > Withholding Tax* lists tax withheld per payment and supplier (with TIN) for a date range, with CSV export, ready for paying over to the tax authority. Migration `033_supplier_wht.sql`.
- **Batch / lot and expiry tracking (#172).** Tick *Track batch / lot numbers and expiry dates* on a product. Goods receipts and stock-in adjustments then take a batch number and expiry date (left blank, the batch is named after the receipt or adjustment). Whenever stock leaves a location, whether by sale, delivery, adjustment or a stock take shortage, it comes off the batch that expires first (FEFO); transfers between warehouses move the batches with the stock. *Stock > Batches & Expiry* lists batches with quantity, cost and status (OK, expires within 30 days, expired, no expiry), with filters and a one-click *write off expired* that posts a normal stock adjustment. Batches sit on top of the normal stock figures and never block a sale: stock from before tracking was switched on, and units restored by returns, are simply not in a batch. Expired stock stays on hand until written off and is used first. Migration `032_batches_expiry.sql`.
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
- **Posted data no longer becomes variables (this release).** The 15 form handlers in `classes/Master.php`, the client login and the client profile save used `extract($_POST)`, so a crafted form field such as `tenant_id` could overwrite the company or user the controller had just taken from the session (for example when saving a fixed asset, which could write into another company). Each handler now reads only the fields it uses, and a test fails if `extract()` returns to these controllers. The service classes were converted too, so `extract()` is no longer used anywhere in controllers or services (a test enforces it). The client profile save, delete and verify actions in `classes/Users.php` previously had no sign-in, CSRF or permission check and wrote every posted field into the client record (including into the signed-in session). They now need a signed-in staff user with *users_manage* (or, for the profile save, the client editing their own record), a CSRF token, and only accept name, gender, contact, email and address; a client changing their own password must give the old one.
- **Audit coverage (#166).** Changes to customers/suppliers, products, purchase orders, bills, employees, roles, API keys and companies are written to the audit log with before and after values; secrets are never logged. Employee saves now accept only real columns.
- **MySQL compatibility (#161, #162, #163, #164).** The migration runner works on MySQL as well as MariaDB (no reliance on `ADD COLUMN IF NOT EXISTS`, comment-aware statement splitting, a one-time repair pass) and relaxes `ONLY_FULL_GROUP_BY` per connection.
- **Gapless document numbers and uniqueness (#157).** Document numbers are unique per company and generated through one sequence service.
- **Login protection and OTP (#156).** Throttling of repeated failed logins and email OTP handling hardened (`022_auth_throttle.sql`).
- **Payroll and leave (#151, #152, #153, #155).** Rules-driven payroll gaps closed, and leave entitlement proration and balances added (`018`-`021` migrations).

---

## System Requirements

- **PHP:** Version 8.2 or newer
- **Database:** MySQL 8.0+ or MariaDB 10.5+ recommended (older versions do not enforce CHECK constraints)
- **Web Server:** Apache 2.4 (with `mod_rewrite` enabled) or Nginx
- **Required PHP Extensions:**
  - `mysqli`
  - `gd`
  - `mbstring`
  - `session`
  - `json`
  - `openssl` (encrypts stored secrets)

Backups additionally need the `mysqldump`, `mysql`, `gzip`, `tar` and `sha256sum` command-line tools.

---

## Installation

The browser installer does the database and configuration for you. Nothing needs to be edited by hand.

1. **Get the code** into the web server folder (not a world-writable one):
   ```bash
   git clone https://github.com/ishaana10/nuviserpx.git nuvis-erpx
   cd nuvis-erpx
   ```
2. **Create an empty MySQL/MariaDB database and a dedicated user** for it (do not use `root`). See [Database users](docs/DEPLOYMENT.md#database-users).
3. **Give the web server write access only where it is needed:** `uploads/` and `logs/`, plus the app folder itself until the installer has written `.env` (take that back afterwards, step 6):
   ```bash
   sudo chown -R www-data:www-data uploads logs
   sudo chmod -R u+rwX,g+rX,o-rwx uploads logs
   ```
4. **Enable Apache rewrite and headers** (`sudo a2enmod rewrite headers`) and set `AllowOverride All` for the folder. For Nginx see [Web server](docs/DEPLOYMENT.md#web-server).
5. **Open the site in your browser.** It redirects to `/install/`. Enter the database details and the Super Administrator login. The installer writes `.env` (including a random `APP_KEY`), loads the schema and creates `install/installed.lock`.
6. **After installing, remove the installer and lock the app folder:**
   ```bash
   rm -r install/
   chmod 640 .env
   ```
7. **Set up backups, cron and monitoring** before entering real data: follow [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) (the checklist at the end is the short version). Before the first real customer, work through [docs/PILOT.md](docs/PILOT.md).

All settings live in `.env` (see `.env.example`); `initialize.php` only reads them.

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

Schema changes live in `database/migrations/*.sql` and are applied in order by `src/Database/MigrationRunner.php`; legacy catch-up checks in `src/Database/LegacySchemaCatchup.php` create core tables first. The runner is strict: it takes a lock so only one process migrates at a time, stops at the first real error (naming the file and the SQL), and records a migration only after every statement in it has succeeded, with a checksum so a file edited after it was applied is flagged. A fresh install builds the schema with `SchemaInstaller` and verifies the required tables exist. On a live system set `AUTO_MIGRATE=0` in `.env` (the installer does this for new installs) so web requests never change the schema; if the database is behind the code, the site shows an "update in progress" page until you apply migrations from the command line (`bin/deploy.sh` does this in maintenance mode):

```bash
php bin/migrate.php --status   # lists applied and pending migrations, changes nothing
php bin/migrate.php            # applies anything pending
```

`php health.php` also reports pending migrations. Statement splitting ignores semicolons in comments, and column additions are checked against `information_schema` so the same files run on MySQL and MariaDB. If a migration fails, `bin/migrate.php` exits non-zero and prints the failing file and statement; fix it and run it again, it resumes where it stopped (statements that already took effect are recognised and skipped).

---

## Running the Tests

Tests are standalone PHP scripts in `tests/` and need no database. Run them all:

```bash
for t in tests/*.php; do php "$t" > /tmp/out.txt 2>&1 || { echo "FAILED $t"; grep -v '^PASS' /tmp/out.txt; }; done
```

Each prints `PASS`/`FAIL` per check and exits non-zero on failure. They cover calculations, validation and wiring; behaviour against a live database should still be checked after an upgrade.

---

## Logging In

Access the administrator portal at: `http://localhost/nuvis-erpx/admin/`

There are no default credentials: the Super Administrator username and
password are chosen during installation (minimum 8 characters with letters
and numbers). If an old installation still uses a short or well-known
password, change it under *Access & Users* now.

---

## Troubleshooting

**Blank page or HTTP 500.** Errors are written to `logs/php_errors.log` (also visible under Developer > Error Logs) and to the web server log. Do not turn on `display_errors` on a live server: it shows file paths and queries to visitors. Reproduce on a staging copy instead.

- **"Service temporarily unavailable" page:** the application cannot reach its database. The reason is in `logs/php_errors.log`. Check the `DB_*` values in `.env` and that the database service is running (`systemctl status mysql`).
- **HTTP 500 right after setup:** make sure Apache has `mod_rewrite` and `mod_headers` enabled (`sudo a2enmod rewrite headers && sudo systemctl restart apache2`) and `AllowOverride All` is set.
- **"We'll be right back" page:** maintenance mode is on (a `maintenance.flag` file exists). `bin/deploy.sh` turns it off when a release finishes; to reopen manually run `rm maintenance.flag` after `php health.php` reports `ok`.
- **Something is wrong but you are not sure what:** run `php health.php` on the server. It checks the database, pending migrations, disk space, folder permissions, the encryption key and the age of the last backup.
- **Web server logs:** Apache `/var/log/apache2/error.log` (Debian/Ubuntu) or `/var/log/httpd/error_log` (RHEL), PHP-FPM `/var/log/php8.x-fpm.log`.

---

## License & Branding

**Nuvis ERPX** is a property of **Nuvis Technologies** ([nuvistechnologies.com.fj/ERPX](https://nuvistechnologies.com.fj/ERPX)). All rights reserved.
