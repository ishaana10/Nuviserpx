# Changelog

All notable changes to Nuvis ERPX. Versions are stamped automatically on each
release build (see `src/Support/AppVersion.php`); dates are Fiji time.

## [Unreleased] - 2026-11-14

### Security
- Tenant isolation: the payments API verifies an invoice belongs to the API
  key's company before allocating against it; by-id API follow-up queries
  carry the tenant clause; a signed-in user with no home company is denied
  instead of falling back to tenant 1; super-admin company switches and all
  logins (with IP, super tier flagged) are audited; a ratchet test fails CI
  if any file gains a tenant-unscoped query on a core business table.
- CSRF verification added to the remaining user endpoints (client save, own
  password change); every state-changing endpoint now verifies the token.
- Database error details (`mysqli::$error`) no longer reach the browser from
  any endpoint; they are logged server-side (Developer > Error Logs) and
  responses carry a plain message (`App\Support\Db`).
- Remaining interpolated integer lists in bulk payroll-approval and
  credit-note queries are now cast per id.
- Hardened session cookies: secure-only on HTTPS (proxy-aware), strict mode,
  4-hour idle lifetime.

### Added
- **PHPStan CI now meaningful**: analysis scans `classes/` and a bootstrap
  defines runtime constants, cutting 784 false positives to 77 recorded in
  `phpstan-baseline.neon`; the job now fails only on new findings.
- **Annual gross salary**: employee pay is captured as an annual gross
  figure; fortnightly, weekly and monthly basics derive from it
  automatically at run time (26/52/12 periods) and re-derive when the
  run's frequency changes. Existing monthly figures migrated (x12).
- **Fiji-standard payslip**: named FNPF/P.A.Y.E./SRT deductions with tax
  code, year-to-date balances, FNPF superannuation (employee + employer),
  leave balances, employer levies by name, net pay in words, company logo.
- **Per-tenant branding**: each company sets its own name, contact details
  and logo (Settings > Company Profile), carried on its invoices, receipts,
  payslips and customer emails. The platform's own branding (System
  Information) is developer-only and is never printed on tenant documents.
- **Per-tenant company email (SMTP)**: each company can send its customer
  notifications from its own email account (Settings > Company Email), with
  the system SMTP as fallback. Passwords are encrypted at rest (set APP_KEY
  in .env for a dedicated key); SMTP server replies are now verified, and a
  per-tenant email log records every send - recipient, transport, sent or
  failed with the exact server error - with a Send Test Email button.
- Continuous integration (GitHub Actions): repo-wide `php -l`, the full test
  suite under a PHPUnit bridge, and PHPStan level 5 on `src/` (advisory).

## 2026-11-13

### Added
- **Module system**: full dependency graph between tenant modules, wired per
  company; activation pulls requirements in, deactivation switches dependents
  off, and switches now apply to every user tier. Read-only "Modules &
  Features" page for tenants. (PRs #202)
- **Email registration & verification**: users register an email, receive a
  welcome/verification link (48h, hashed token), state visible on My Account,
  user form and user list, with resend. Public `verify_email.php` endpoint.
  (PRs #203-#205)
- **In-app User Guide** covering every module and all navigation pages, with
  a contextual "?" helper on each page. (PRs #206, #208-#210)
- **Developer Guide** under the Developer tab: architecture, security
  conventions, tenancy/modules, migrations, add-a-feature checklist, UI
  layer, email, diagnostics, testing and deployment. (PR #207)
- Outgoing mail carries Message-ID and Reply-To headers. (PR #205)

### Fixed
- Chart gap-fill also retypes standard accounts stuck on the legacy default
  type "Expense", so Accounts Receivable appears under Default Accounts.
  (PR #201)
- Quick-added products appear immediately in invoice and order lines
  (save_product now returns the id). Sales/purchase orders use the same
  merged item combobox as invoices. (PR #211)

## 2026-11-12 and earlier (highlights)

- Ticket SLAs and CRM contacts; side-nav scrolling; list-page UI (breadcrumbs,
  summary cards, filters); invoice item combobox; audit trail fixes with Fiji
  timestamps; PHP/JS error logs under Developer; table alignment; login page
  redesign with version stamp; dashboard query fix.
- Security hardening round 1 (CSRF on Master dispatch, central permission
  gates) and the structural shrink of Master.php / DBConnection.php into
  `src/Services` and focused modules (PRs #177-#200).
- Full history: `git log` and the merged pull requests on GitHub.
