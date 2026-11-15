# Changelog

All notable changes to Nuvis ERPX. Versions are stamped automatically on each
release build (see `src/Support/AppVersion.php`); dates are Fiji time.

## [Unreleased] - 2026-11-15 (3)

### Fixed
- **Customer portal sign-in/activation pages had an invisible title and no logo.** The title was white on the white card; both pages now show the company logo, a dark "Customer Portal" title and the company name.
- **Portal menu invisible on narrow screens.** The header bar stayed at a fixed height, so the opened hamburger menu (account name, Log Out) spilled out as white text on the light page. The bar now grows to fit.
- **Customer portal dashboard sent visitors to the staff login.** The staff-session guard exempted `/portal/<page>.php` but not the folder address `/portal/` that login and the brand link use, so the portal menu and Log Out seemed dead. The folder address is now exempt too.

## [Unreleased] - 2026-11-16 (2)

### Added
- **Change password link on the customer sign-in page.** A new page lets a customer change their portal password by entering their email, current password and a new one (at least 8 characters with letters and numbers, different from the current one). It does not sign them in, is rate-limited like sign-in, and is recorded in the portal activity log.

### Changed
- **Portals show the NuvisERPX logo when the company has none.** The customer and employee portals (sign-in, activation, headers, invoice view) show the company's own logo, and fall back to the NuvisERPX logo instead of a generic placeholder or nothing. The portal headers now show the logo too.

### Fixed
- **Invisible text on the portal sign-in/activation cards.** The sign-in message, activation alerts and the "Back to Login" line were white on the white card.
## [Unreleased] - 2026-11-16

### Added
- **Email the signed delivery note to the customer.** Once a delivery note is signed, an "Email to customer" button sends it (with a PDF copy showing the receiver's name and signature) to the customer's registered email, which can be changed before sending. A PDF download button is added too, and the note shows when and to whom it was last emailed. Migration 050 records that.
- **Real PDF download in the customer portal.** The portal's PDF buttons now download a server-built invoice PDF (company letterhead, colours, tax lines), instead of opening the browser's print dialog. A customer can only fetch their own issued invoices (never drafts or cancelled); nothing is stored on the server.
- **Digital signing on delivery notes.** On a delivery note, staff type the receiver's name and capture their signature on a signature pad (finger, stylus or mouse). The signed name, date and signature replace the blank lines on the printout, and the delivery note list shows a SIGNED badge. A signature is locked once saved; cancelled notes cannot be signed. Migration 049 adds the columns.

## [Unreleased] - 2026-11-15 (2)

### Fixed
- **Portal activation page warning.** It read a non-existent `users.name` column; it now shows the company name read-only.
- **Payslip page deprecations.** A missing pay date or brand field no longer raises PHP 8.5 messages, and an unknown payslip answers 404.
- **Draft invoices were counted in the ledger.** Saving an invoice as a draft posted its journal, so the Trial Balance, Balance Sheet and Profit & Loss included receivables and revenue for invoices that had not been issued (the Dashboard already left them out). A draft invoice now saves a draft journal; issuing it makes it count. Migration 048 repairs existing draft invoices (journals in a closed period are left alone).
- **New installs started with a wrong chart of accounts.** The installer left ~50 untyped demo accounts (Cash, Accounts Receivable, Accounts Payable...) labelled "Expense", so a new company had no asset, liability or income accounts and could not set Default Accounts or invoice. The installer now sets up the standard chart for a new company. It never replaces a chart that has any journal, invoice, payment or default account.

## [Unreleased] - 2026-11-15

### Fixed
- **Backups were being discarded on any real database.** The check that a database dump is intact quit early and, with `pipefail`, reported every dump larger than about 64 KB as damaged, so `bin/backup.php` failed. The check now reads the whole dump. Test uses a 2 MB dump.
- **Customer Portal invitation emails linked to the sign-in page**, which ignored the token, so customers could not activate their account. The link now opens the activation page, and old links are forwarded there.
- **Customer Portal showed draft and cancelled invoices and payments** (draft invoices as "Unpaid"), and the balance counted them. Customers now see issued invoices and posted payments only; Balance Due is the sum of open invoice balances (credit notes included). Amounts show the company currency instead of a hard-coded `$`.
- Customer Portal: revoking a portal user or deleting the customer now ends a signed-in session at once; activation is rate-limited, signs in with a fresh session id, and needs a password of 8+ characters with a letter and a number. Invitation tokens are stored hashed (invitations already sent keep working until they expire). An email address containing an apostrophe no longer breaks sending an invitation.
- Proposal signing page: a stored script injection through the signature field. Only a PNG signature image is accepted and it is escaped on display; signer name and email are validated.
- Web server: directory listings are off and `tests/` and `database/` are not served. The installer's session cookie is HttpOnly. Expense Reports no longer logs a PHP warning when no status filter is given.
- Static analysis (PHPStan) is green again on `main`.

## [Unreleased] - 2026-11-14

### Added
- Employee Portal sign-in: company logo, a readable "Employee Portal" title (it was only visible on hover) and a "Back to main portal" link.
- Employee Portal: employees can change their own password (Change Password in the menu). It asks for the current password, applies the usual password rules, and wrong guesses count towards the sign-in lock-out.
- Employee Portal: My Payslips now shows the same payslip as payroll (company logo, year-to-date, leave balances,
  employer contributions). My Leave has Balances / New Request / View Requests / Calendar tabs; the request form checks
  the balance, working days, public holidays and overlaps live and will not submit a request that cannot be granted, and
  a calendar shows your pending and approved leave and public holidays and can be used to pick dates.
- Leave requests can carry one attachment (e.g. a sick-leave medical certificate): PDF, JPG or PNG up to 2 MB, photos
  are shrunk to 1600 px, stored privately in uploads/leave_docs/ (not web-accessible) and shown to HR and the approver.
  The file is deleted when the request is cancelled or deleted, to save server space. Migration 044.
- Employee Portal v2: a side menu (Home, My Leave, My Payslips, My Details) that works on phones. Home shows leave balances
  and the latest payslip. Employees apply for leave, see balances and request history, and cancel pending requests
  (same rules as staff entry: balance check, half days, public holidays). My Details is read-only. Employees are emailed
  when a leave request is approved or rejected. Company and employee come only from the portal sign-in, and access
  revoked by HR takes effect immediately, even for a signed-in employee.

### Fixed
- Employee Portal My Leave: the live check and Submit now always answer cleanly; an unexpected server error shows its status and a short detail instead of a generic message.
- Employee Portal pages looked unstyled after an update because the browser kept an old stylesheet for the rest of the day; the CSS and script are now versioned by file change. My Leave tabs, form and calendar spacing polished.
- Employee Portal pages returned HTTP 500: the leave queries bound read-only properties by reference, which PHP rejects.
- Employee and customer portal pages no longer redirect to the staff login: the staff session check ran on every portal
  page, so employees were sent to the main login after setting their password, and a signed-in admin was bounced away
  from the portal sign-in page.
- Employees were confused about where to sign in: the staff login page now links to the Employee Portal sign-in, and the
  invitation email gives the portal sign-in address.
- Employee portal: the sign-in username (the employee's email address) is now shown on the set-password page and the
  sign-in page, included in the invitation email, and added to the "email could not be sent" message HR sees.
- Dashboard on phones: the header (date range, Customize, New invoice) and dashboard tabs no longer stretch the page
  wider than the screen, so the greeting no longer runs under the profile picture and nothing is cut off at the right.
- Employee portal invitations were never emailed: the code skipped sending when the mail class was not loaded. It now
  always attempts the send. The customer portal invitation had a wrong file path for the same class and is fixed too.
- When an invitation email cannot be sent, HR now gets the sign-up link in a copyable box (with a Copy button) and the
  username, instead of a browser popup that could not be copied from.

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
- Invoices can be viewed or downloaded as a PDF (View PDF / Download on the invoice page, View PDF in the list),
  and the invoice email now carries the PDF as an attachment. PDFs are built in memory and are never stored on the
  server. Shared PDF layout code now lives in `src/Support/PdfDoc.php`.
- **Statement PDFs and "Last emailed"**: Customer Statements can be viewed or downloaded as a PDF,
  and every emailed statement carries a PDF copy. PDFs are built in memory and streamed or
  attached, then discarded: they are never stored on the server (a test fails the build if any
  code writes a PDF to disk). The list shows when each customer was last emailed, and "Email all"
  warns how many were already emailed in the last 7 days. Emails now support in-memory
  attachments (5 MB cap). Bundles FPDF 1.9 (`libs/fpdf`, Helvetica only).
- **Customer Statements report**: Reports > Customer Statements lists every customer with unpaid
  invoices, with a printable per-customer statement (invoices, balances, age). Email a statement to
  one customer (address editable before sending) or to everyone who has an email address, with an
  optional message. Sent through the company's own email settings and recorded in its email log;
  customers without an address are listed, not skipped silently.
- **Phone-friendly lists, the rest (UX polish, slice 6)**: Payments, Purchase Orders, Vendor Bills,
  Bank Reconciliation, Attendance, Leaves, Stock Adjustments, Roles, Tenants, API Keys and Webhooks
  lists use the stacked-card layout below 768px. (Workflows is left as-is: its "Actions" count
  column would be mistaken for the row-action menu.)
- **Invoice form save bar (UX polish, slice 5)**: on phones the Save / Back bar stays pinned
  to the bottom of the screen while the long invoice form scrolls. (The employee and payroll
  forms are modals whose Save button is already pinned, so they are unchanged.)
- **More phone-friendly lists (UX polish, slice 4)**: Sales Orders, Parties, Products,
  Employees and Payroll lists now use the stacked-card layout below 768px.
- **Invoices list (UX polish, slice 3)**: friendly empty state with a Create button, invoice
  numbers link to the invoice, and unpaid/partially paid invoices past their due date show
  "N days overdue" under the due date. (The Dashboard already had phone-tuned controls, so
  it is unchanged.)
- **Phone-friendly lists (UX polish, slice 1)**: tables marked `data-nv-cards` turn into
  stacked, labelled cards below 768px (desktop unchanged); list-page action buttons are
  full-width touch targets on phones. Piloted on the Invoices list. See
  `docs/superpowers/specs/2026-11-14-ux-polish-design.md`.
- **Quick jump (UX polish, slice 2)**: a search icon in the top bar (and Ctrl/Cmd+K) opens an
  overlay that jumps to any menu page or "Create new" action. It reads the already
  permission-filtered menu, so it never lists a page the user cannot open; works on phones
  without opening the side drawer.
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
  suite under a PHPUnit bridge, and PHPStan level 5 on `src/` (blocking for new findings; existing ones are baselined).

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
