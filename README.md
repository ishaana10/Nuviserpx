# Nuvis ERPX - Multi-Tenant Enterprise Resource Planning & Accounting System

**Property of Nuvis Technologies** ([nuvistechnologies.com.fj](https://nuvistechnologies.com.fj))

Nuvis ERPX is a modern, high-performance, multi-tenant Enterprise Resource Planning (ERP) and Financial Accounting software suite engineered for regional and international enterprise compliance, featuring localized Fiji Payroll, Commercial Invoicing, Inventory & Stock Movement, General Ledger Financial Reporting, RESTful API v1 Engine, Email Notifications, and Granular Role-Based Access Control (RBAC).

---

## 🚀 Key Features & Modules

### 1. 🇫🇯 Localized Fiji Compliance Payroll Engine (`classes/FijiPayroll.php`)
- **PAYE Progressive Tax Brackets**: Automatically calculates Fiji resident progressive tax (0%, 18%, 20%) across Monthly, Fortnightly, Weekly, and Bi-weekly frequencies.
- **Social Responsibility Tax (SRT)**: Progressive SRT calculation for annual high earners above FJD $270,000.
- **Non-Resident Flat Tax**: 20% flat PAYE tax calculation for non-resident employees.
- **FNPF Contributions**: 8% Employee and 8% Employer FNPF deduction calculation on eligible wage base.
- **Workcare & Training Levies**: 1% Workcare levy calculations for employer liability compliance.
- **Compliance CSV Exporters**:
  - **FRCS TPOS PAYE Return Export**
  - **FRCS EMS Year-End Summary Export**
  - **FNPF Schedule Return Export**
  - **Bank File Exporters**: Direct CSV file generation for BSP, ANZ, HFC, BRED, and generic bank ABA formats.

### 2. 🧾 Invoicing & Payment Receipts (`admin/invoices/`, `admin/payments/`)
- Tax Invoices with line item VAT calculations (15% default), discounts, subtotal, and total due tracking.
- Multi-invoice payment receipting with partial and full balance allocations.
- Automated double-entry General Ledger journal postings on invoice creation and payment receipt posting.
- Printable, professional PDF/HTML payment receipts and tax invoices.

### 3. 📦 Commercial Stock & On-The-Go Mobile Order Taking (`admin/products/`, `admin/sales_orders/`)
- Product SKU catalog with cost price, selling price, units, stock quantities, and low stock warnings.
- Mobile-responsive sales order taker and proforma invoice generator.
- Automatic inventory stock deduction and COGS journal entries upon sale confirmation.
- Inventory movement audit logs for stock adjustments and receiving.

### 4. 📊 Financial Accounting & Reporting (`admin/reports/`)
- Real-time **General Ledger (GL)** query and drill-down.
- **Balance Sheet**: Assets = Liabilities + Equity balance verification.
- **Income Statement (P&L)**: Revenue vs Expense reporting.
- **Accounts Receivable (AR) Aging**: Outstanding balance aging breakdown (0-30, 31-60, 61-90, 90+ days).
- **Payroll Accounting Summary**: Total gross, PAYE, FNPF, and employer cost summaries.

### 5. 🛡️ Security, RBAC & Multi-Tenant Scoping
- **Password Hashing**: Bcrypt (`password_hash()`) security with seamless backward-compatible migration from legacy MD5 hashes upon login.
- **CSRF Protection**: Form & AJAX token headers (`X-CSRF-TOKEN`).
- **Session Hardening**: `HttpOnly` and `SameSite=Lax` cookie flags.
- **Granular RBAC**: Module permission matrix allowing custom role templates per company.
- **Multi-Tenant Scoping**: All database queries strictly scoped by `$tenant_id`.

### 6. 🌐 RESTful API v1 & Webhooks (`api/v1/`)
- Token/API Key authenticated RESTful JSON endpoints (`/api/v1/products.php`, `/invoices.php`, `/payments.php`, `/payroll.php`, `/journals.php`, `/attendance.php`, `/health.php`).
- HMAC-SHA256 signature calculation (`X-Hub-Signature-256`) for webhook delivery.
- OpenAPI 3.0 specification in `api/v1/openapi.json`.

---

## 🏗️ Architecture & Modern PHP 8.3 Design

```
                     ┌─────────────────────────────┐
                     │   Nginx / Web Browser / API │
                     └──────────────┬──────────────┘
                                    │
                     ┌──────────────▼──────────────┐
                     │   App\Core\Container (DI)   │
                     └──────────────┬──────────────┘
                                    │
        ┌───────────────────────────┼───────────────────────────┐
        │                           │                           │
┌───────▼────────┐          ┌───────▼────────┐          ┌───────▼────────┐
│ InvoiceService │          │ PayrollService │          │JournalService  │
└───────┬────────┘          └───────┬────────┘          └───────┬────────┘
        │                           │                           │
        └───────────────────────────┼───────────────────────────┘
                                    │
                     ┌──────────────▼──────────────┐
                     │    MySQL 8.0 / SQLite DB    │
                     └─────────────────────────────┘
```

The system uses PSR-4 autoloading (`App\`) with a lightweight Reflection-based Dependency Injection Container (`App\Core\Container`), domain services under `src/Services/`, Enums (`App\Enums\*`), and versioned SQL migrations in `database/migrations/`.

---

## 🛠️ Installation & Setup

### Prerequisites
- PHP 8.2 or 8.3+
- Composer 2.x
- MySQL 8.0+ or MariaDB 10.5+

### Quick Start
```bash
# Clone the repository
git clone https://github.com/nuvis/erpx.git
cd erpx

# Install dependencies
composer install

# Configure database in initialize.php or .env
cp .env.example .env

# Run PHPUnit tests
./vendor/bin/phpunit

# Run PHPStan static analysis
./vendor/bin/phpstan analyse
```

### Docker Compose Setup
```bash
docker-compose up -d --build
```
Access the application at `http://localhost:8080/admin/`.

---

## 🧪 Testing & CI Pipeline

Run tests and static analysis:
```bash
# Unit & Integration Tests
./vendor/bin/phpunit

# Static Analysis (Level 5)
./vendor/bin/phpstan analyse
```

Continuous Integration is powered by GitHub Actions in `.github/workflows/ci.yml`.

---

## 📄 License
Nuvis ERPX is proprietary software owned by **Nuvis Technologies**. All rights reserved.
