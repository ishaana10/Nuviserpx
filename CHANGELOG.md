# Changelog

All notable changes to Nuvis ERPX. Versions are stamped automatically on each
release build (see `src/Support/AppVersion.php`); dates are Fiji time.

## [Unreleased] - 2026-11-25 (8)

### Changed
- A2 Hosting confirmed its current shared platform does not run Redis server processes (the old `~/.redis.on` start daemon is gone; Redis needs a Managed VPS or an external Redis service). The automatic Redis check now gives up for good after one logged explanation instead of retrying and repeating the error, and the wording no longer talks about Turbo plans. Turning the checks on from Developer > Redis makes it try again.

## [Unreleased] - 2026-11-25 (7)

### Changed
- Redis is optional and the Redis tab now says so. The app itself never used Redis (sessions are files); only the Developer monitor looks at it. When the start flag has been in place 15+ minutes and the host has not started Redis (the usual sign the hosting plan does not include it), the tab shows a calm "Redis is not running, and that is OK" note with **Stop checking for Redis**, which saves `redis_auto = 0` (also turns the automatic starter and its log lines off). When off, the card reads "Not in use (checks are off)" with a button to turn the checks back on.

## [Unreleased] - 2026-11-25 (6)

### Fixed
- Redis now starts by itself. A watcher (RedisWatch, run from config.php, at most once every five minutes) checks whether Redis answers, clears a dead socket file, makes sure the A2 start flag `~/.redis.on` exists, and logs everything to `logs/php_errors.log` so it shows on Developer > Error Logs as `[Redis] ...` lines: what it did, recovery, a missing PHP redis extension, a flag that cannot be created, and - if A2 has not started Redis 15 minutes after the flag exists - an error saying Redis is only provided on A2 Turbo/managed plans and to check the plan or ask A2 support. Each message is logged at most once every six hours. Turn it off with a `redis_auto` setting of 0. The Redis tab shows the start-flag state.

## [Unreleased] - 2026-11-25 (5)

### Fixed
- Redis "Socket found but not answering" after the server move: a dead socket file copied over from the old server can sit in the home folder with nothing listening, which stops a fresh Redis from starting. The Redis tab now says so ("Dead socket file left from the old server"), and "Start A2 Hosting Redis Daemon" removes such a file (only when nothing is listening on it, never a live one) before creating the start flag. The benchmark result box is readable (white, not red on teal).

## [Unreleased] - 2026-11-25 (4)

### Fixed
- Redis showed "Socket Offline" after moving to the new A2 Hosting server because the socket path and the `~/.redis.on` start flag were fixed to the old account name. They now follow the account the app runs under (or a `redis_socket` setting if you need to override it). "Start A2 Hosting Redis Daemon" creates the flag in the real home folder and says plainly if it could not, that A2 starts Redis about five minutes later, and if the PHP redis extension is missing. The offline message now says which of these applies.

## [Unreleased] - 2026-11-25 (3)

### Changed
- The top bar shows the company you are working in ("Nuvis ERPX - Demo") instead of a fixed "Admin", which was wrong for accountants and other staff.

## [Unreleased] - 2026-11-25 (2)

### Changed
- FRCS VMS v3.0 is removed from this release and will return in a later version: no Settings menu entry, no VMS card in System & Branding, no VMS SDC Inspector in the Developer menu, the two pages are refused by direct URL, and the VMS actions (save settings, test connection, simulate, upload certificate) refuse. The code stays in place behind a single switch, `App\Support\Features::vms()`.

## [Unreleased] - 2026-11-25

### Fixed / Security
- A company (tenant) administrator no longer sees developer-level screens. The Settings menu now shows only company-level pages (My Plan, Invoice Templates, Financial Settings, Import, Currencies, Exchange Rates, FRCS VMS, Custom Extrafields, Audit Trail, Webhooks, Automation Workflows), each gated by the matching permission. Security & 2FA policy, Email Templates, System & Branding, Active User Sessions, DB Backup and Tenants / Companies are developer-only in the menu and by direct URL. The company switcher and Developer menu were already developer-only.
- Financial Settings: the installation-wide defaults (shared by every company) show for the developer only; a company administrator gets "Close the books" for their own company.
- Audit Trail hides platform-level entries from company administrators; the session list hides developer accounts.
- User form explains how to create a company administrator: Staff User with the Company Administrator role. Super Administrator is the developer tier.
- Creating a company now creates its administrator too: a Staff User (never the developer tier) holding a "Company Administrator" role of that company (all modules plus users, settings, API keys, webhooks, workflows). The form asks for the administrator's details and checks them before anything is created. Older companies get the role on demand when the developer opens the user form; to fix a company administrator who was created as Super Administrator, edit the user, choose Staff User and the Company Administrator role.

## [Unreleased] - 2026-11-23

### Fixed / Security
- Employees whose leaving date has passed are now deactivated automatically: they cannot sign in to the portal and any open portal session ends the day after the leaving date, without waiting for anyone to run a job. The record is tidied (status set to inactive, portal access removed and logged) by a sweep that runs at most once a day from the HR employee list and portal sign-in, or on demand with `php bin/deactivate-leavers.php` (optional cron).
- Portal sessions are now validated on every request: they end after 30 minutes idle or 8 hours total, and are tied to the password, so changing the password (or HR removing access) signs out every other session while the one that changed it stays signed in.

## [Unreleased]

### Fixed (accounting audit)
- Bank reconciliation could not be started: the bank/cash account lookup used a table alias that did not exist, so every account was refused and the account list was empty.
- Vendor bills chose their expense and payable accounts by name only; on a standard chart an ordinary expense bill (e.g. electricity) was debited to the Inventory asset. They now pick by account type (Purchases, then other expense accounts), only from the company's own chart, and refuse to post if the chart has no payable or expense account instead of falling back to a fixed account id.
- The VAT return ignored credit notes and debit notes, so output/input VAT and taxable sales/purchases disagreed with the VAT accounts in the ledger. Notes now reduce them and show as negative lines on the VAT report.

- Vendor bills for stock items now bring the stock in. A bill on a stock product (without a purchase order) increases the quantity and weighted-average cost, editing the bill adjusts it, and cancelling takes it back out (refused if the stock has since been sold). Previously the ledger showed the inventory purchase but the stock list and valuation stayed unchanged, so they drifted apart. Bills held for approval receive the stock when approved. Migration 055 adds `vendor_bill_stock`; run Database Updates.
- Payroll: after redundancy pay or a leave payout, the following months' PAYE was overstated because the one-off pay was counted as regular year-to-date salary. One-off pay and its tax are now left out of the year-to-date position.
- Payroll: with no rules configured, the preset FNPF employer rate was a flat 8%. It now follows the law by pay date: 10%, 8% for wages from 1 Aug 2026 to 31 Jul 2027 (Budget 2026-27), then 10% again. Companies with their own Payroll Rules Settings are unaffected.

### Fixed (employee portal audit)
- Sign-in was only limited per account. Wrong guesses are now also limited per address and per username across all accounts (the same limits as the customer portal), and an unknown username takes as long to answer as a wrong password. Invitation links are limited the same way.
- An invitation link could still be activated for an employee who had become inactive or been deleted; it is now refused.
- Signed-in portal actions (request leave, cancel, change password) skipped the security-token check. They now require it, like the rest of the app.
- The server now enforces the leave date window the form already showed (up to 60 days back and 2 years ahead), so a request cannot be backdated or set far in the future.
- Passwords that are common, or contain the part of the email before the @, are refused.
- Portal pages (payslips, details) are marked do-not-cache, so they cannot be shown again from the browser's back button after signing out.

### Fixed (HR audit)
- Leave balances could fail with a database "commands out of sync" error: a result was left unread while the next query was prepared. Each result is now read in full first.
- Employee form: a blank employee code or name, a leaving date before the joining date, a negative salary, an approver who is the employee themselves, from another company or who has left, and an email already used by another employee are now refused.
- Leave: HR-entered requests are now checked like self-service ones: an employee is required (not a deleted or other company's one), dates may not overlap the person's pending or approved leave, and must fall within their employment. Approved leave can now be cancelled by the approver or an administrator (the days go back to the balance); employees can still cancel only their own pending requests.
- Attendance: the employee must belong to the company, the status must be valid, check-out cannot be before check-in, and dates cannot be in the future, before joining or after leaving.
- Shift rosters: employee and shift must belong to the company, end date cannot precede start, a person cannot have two overlapping rosters, and a break must be shorter than the shift.
- Leavers: an employee who is inactive or deleted can no longer sign in to or use self-service (payslips, leave); deleting an employee removes their portal access and clears them as anyone's approver; employees with payroll history cannot be deleted (make them inactive); self-service invitations are refused for people who have left.
- Security: leave reasons (typed by employees in self-service), names, codes, TIN/FNPF, department and other HR fields were printed unescaped on the employee, leave and attendance pages, so a crafted entry could run script in an administrator's browser. They are now escaped.

### Added
- `tests/db/test_db_employee_portal_scenario.php`: the portal from the employee's side (activation, sign-in and lock-outs, password change, own posted payslips only, own leave only, cancelling, revoked access, date window).
- `tests/db/test_db_hr_scenario.php` and `tests/test_hr_output_escaping.php`: HR end to end (employee records, leave requests, overlaps, approval rights, cancelling approved leave and balances, attendance, rosters, leavers and self-service).
- `tests/db/test_db_payroll_scenario.php`: payroll end to end on a real chart: twelve monthly runs whose PAYE adds up to the annual tax, balanced journals, payment, tax and pension remittances clearing their liabilities, reversal and void rules, duplicate-run and period-lock protection, unpaid days, overtime tiers, redundancy, termination, tenant isolation and the dated FNPF rate.
- `tests/db/test_db_accounting_scenario.php`: an end-to-end accountant's walk-through (VAT-exclusive/inclusive/no-tax sales, receipts, bills, payments, credit and debit notes, edits, cancels, voids, period lock, stock and COGS, foreign currency with realised gain, fixed-asset depreciation, bank reconciliation, tenant isolation) asserting after each step that every journal balances, AR/AP/VAT/inventory sub-ledgers equal their control accounts, and the trial balance, income statement, balance sheet and cash flow reconcile.
### Security
- Admin form, modal and export files (e.g. `admin/invoices/manage_invoice.php?id=1`) could be opened without signing in and returned the default company's data. Every file under `/admin/` now needs a staff session (401 otherwise); employee-portal sessions are no longer treated as staff there either.
- Legacy `inc/navigation.php` echoed `?page=` into JavaScript unescaped (reflected XSS); now JSON-encoded.
- Fiscalisation `.p12`/`.pfx` certificates are stored with a random name in a web-closed, non-world-writable folder, and `uploads/.htaccess` refuses key/certificate extensions (protects certificates already uploaded).

### Fixed
- Pages felt slow after the session-expiry check was added: a link click after ~15s idle waited for a server round trip before navigating. The check now runs in the background and never delays a click.
- Developer menu no longer checks for pending database updates on every page; the result is cached for 5 minutes. - 2026-11-19

### Fixed
- **Phone layout.** The account photo was an oversized pill that spilled off the right of the top bar and hid behind the help button. It is now a small round photo; the palette moves into the account menu, "Create new" and the company picker get the room, the help button sits low in the corner, and My Plan's invoice and payment lists become stacked cards instead of cut-off columns.

### Added
- **Tenant invoices open in a window, as the real sales invoice.** On My Plan & Billing (and the developer's Tenant Invoices), View / print opens the invoice in a window with Download and Print. It is the same PDF the owner company prints from Sales, with its letterhead, TIN/VAT and "How to pay".
- **Receipts for tenants.** Every payment on a tenant invoice, including ones recorded from the owner's Sales or Payments screen, has a Receipt button that opens a receipt PDF (amount, method, reference, invoice and balance after) with Download and Print.
- **Database updates button.** Developer, Database Updates (also a banner on the Developer dashboard when something is pending) applies pending database updates from the browser, the same as `php bin/migrate.php`, and reports any failure without recording it as done. It stays reachable for the developer even when the rest of the app shows "Update in progress". The billing database changes are in migration 054 (053 stays as first released) and are also created on demand, so the "bills the other tenants" setting saves on a server that was updated before 054 existed.
- **Session-expired warning.** When you are signed out (session ended, or you signed out in another tab), clicking a menu item or link now shows a "Your session has ended" box with Sign in again, instead of a page that hangs. The check is a read-only ping that does not extend the session.
- **My Plan & Billing.** The plan badge in the top bar (and Settings, My Plan & Billing) opens the tenant's plan, status and renewal date, invoices from Nuvis Technologies, payments received and the outstanding balance. Each invoice can be viewed and printed with its payment status.
- **Tenant billing runs through the owner company's books.** Tick "This company bills the other tenants" on your own company (Tenants). Developer, Tenant Invoices then raises an ordinary sales invoice in that company for any customer tenant (created as a linked customer on first use): receivable, sales and tax journals, Sales list, Customer Statements and reports all pick it up. Payments are ordinary receipts in the owner's books into a chosen bank or cash account; unpaid invoices are cancelled with a reversal. Each invoice is tagged with its product (ERPX, MedcareX, FlipEx, other) and the page shows revenue by product. Tenants see their own invoices, payments and status read live from those books. Ticking "Raise an invoice for this tenant after saving" on a new tenant opens the form for it. Migration 053 adds the owner flag, customer link and product tag.
- **Breadcrumbs no longer link to pages that do not exist.** A folder without a page of its own (Billing, Developer, Settings, Tax) shows as plain text, so clicking it cannot land on "Page not found"; Billing now has a page.
- **Clone an invoice.** A Clone button on the invoice page and in the invoice list opens a new invoice with the same customer, lines, discount, tax setting, reference and notes. The number is new, the issue date is today and the due date keeps the original terms. Nothing is saved until you press Save.
- **Company profile: more details and a live preview.** Tax (TIN) and VAT registration numbers, website and "how to pay" bank details can now be set. They print in the document header and on invoices ("How to pay"), and a preview beside the form shows how the letterhead will look. The logo can be dragged in and previews before saving, and a completeness bar shows what is still missing.
- **Items can be services.** The Track inventory, Sell and Purchase options on an item are now saved (the full item form has them too).

### Fixed
- **A service added from an invoice was still counted as stock.** The Track inventory tick in the New item pop-up was never saved, so every item was treated as stock and showed 0.00 pcs with a Low Stock warning. Items without Track inventory are now services: no stock quantity, no low-stock warning, no stock check when you sell them, no stock movement on purchase or adjustment, and they are left out of the inventory valuation and reorder reports. Items that are not marked Sell stay off sales forms, and items not marked Purchase stay off purchase forms. Existing items stay as stock; open one and untick Track inventory to make it a service (its stock must be zero first).

### Removed
- **Modules Manager (Settings).** It saved company-wide switches that nothing in the app read, so it did nothing, and it duplicated the module assignment each company already has on the Tenants page. The read-only Modules & Features page stays.

## [Unreleased] - 2026-11-15 (3)

### Fixed
- **Purchase forms: pressing Enter on an empty search no longer opens "Create new".** The "+ Add new vendor" and "+ Create new item" entries now sit at the end of the list. The admin sign-in page also stops requesting two script files from a wrong path (a harmless 404 on every visit).
- **Delivery notes print "Delivered by" automatically.** The name is the login username of the user who created the delivery note, on both the printout and the PDF (blank line if unknown).
- **Customer portal sign-in/activation pages had an invisible title and no logo.** The title was white on the white card; both pages now show the company logo, a dark "Customer Portal" title and the company name.
- **Portal menu invisible on narrow screens.** The header bar stayed at a fixed height, so the opened hamburger menu (account name, Log Out) spilled out as white text on the light page. The bar now grows to fit.
- **Customer portal dashboard sent visitors to the staff login.** The staff-session guard exempted `/portal/<page>.php` but not the folder address `/portal/` that login and the brand link use, so the portal menu and Log Out seemed dead. The folder address is now exempt too.

## [Unreleased] - 2026-11-16 (2)

### Added
- **Download a purchase order as a PDF.** A Download PDF button on the purchase order page and in the purchase order list produces a PDF with your company logo, name and contact details in the header, the vendor, the lines, totals and notes. If no logo is set it simply leaves the logo out. It is built on the fly and only for your own company's orders.
- **Purchase forms: add a vendor or item without leaving the page.** On purchase orders, vendor bills and supplier payments the vendor box is now a search-and-add combo, and each line uses a single item combo (pick from the catalogue, create a new item, or type a one-off item) instead of a dropdown plus a name box. New vendors and items are saved to your company and appear in the lists straight away with no refresh. The vendor and item codes number themselves, and purchase orders and bills now refuse products or purchase orders that belong to another company.
- **Forgot password link on the customer sign-in page.** Customers enter their email and are sent a one-hour link to choose a new password. The reply is the same whether or not the email has an account, only active portal accounts get a link, each link works once, and requests and resets are rate-limited and recorded in the portal activity log. Resetting does not sign the customer in.

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
