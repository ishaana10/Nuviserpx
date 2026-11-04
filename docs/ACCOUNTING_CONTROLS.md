# Accounting controls

What protects the ledger in Nuvis ERPX, and where each control lives.

## Data model
| Control | How |
| --- | --- |
| Exact money | `journal_items.amount` is `DECIMAL(18,4)`; amounts are shown to 2 dp. No `float`/`double` columns are created (a test guards this). |
| Explicit side | `journal_items.entry_side ENUM('debit','credit')`; `group_id` is no longer a side marker. |
| Account type | The chart offers a fixed list (`AccountService::ACCOUNT_TYPES`). New writes are validated, `LedgerService::classifyStrict()` fails on unknown types, and a `CHECK` constraint enforces the list in the database once existing data satisfies it. |
| Account code | Required and unique per company; auto-assigned in a numbering band by class when not typed. `NOT NULL` and a non-empty `CHECK` are added once no account lacks a code. |
| Tenant isolation | Every table with a `tenant_id` references `tenants(id)` (added when the table is InnoDB and has no orphan rows). Each company gets its own chart of accounts. |

The two database constraints and the foreign keys are added by `check_integrity_hardening_migrations()` at most once a day, only when existing data already satisfies them. When a step is skipped the reason is written to the PHP error log (for example, "unrecognised types in use: Goodwill (3)"); fix the data and the next daily run adds it.

## Posting controls
- **Period lock**: the books can be closed up to a date; nothing dated on or before it can be created, changed or removed (`PeriodLockService`, plus database triggers).
- **Immutable posted entries**: a posted journal is reversed, never edited or deleted. Documents that create journals (invoices, payments, payroll, remittances) cannot be edited as journals.
- **Audit trail**: every journal post, edit, reversal and delete is recorded with before and after values (`JournalTrail`).
- **Approvals**: manual journals, vendor bills, supplier payments and payroll remittances can require a second person above a threshold (`ApprovalService`); the maker cannot approve their own work unless the policy allows it.
- **Document numbers**: journal codes and document numbers come from a locked sequence table (`SequenceService`), unique per company.
- **Auto-reversal**: journals with a "reverse on" date are reversed by `cron/auto_reverse_journals.php`.

## Statements and reports
Trial balance, working trial balance, general ledger, income statement, balance sheet (retained and current-year earnings), statement of cash flows (operating, investing, financing), statement of changes in equity, budget vs actual, aged receivables and payables, VAT return, and payroll accounting. All read posted entries only.

## Sub-ledgers
- **Tax**: tax-code master, VAT control accounts, auto-posting of tax on invoices and bills.
- **Multi-currency**: exchange-rate table, transaction and base amounts on journals, revaluation.
- **Fixed assets**: depreciation runs post journals; disposals post gain or loss.
- **Credit and debit notes**: first-class documents against invoices and bills.
- **Inventory**: opt-in per company under *Stock Movements > Inventory Accounting*. The company picks four accounts (inventory, cost of sales, stock adjustments, opening stock); nothing is guessed from names. When on: a sale (invoice, or a confirmed sales order) issues stock at the product's average cost and posts Dr Cost of Sales / Cr Inventory; the stock is refused if there is not enough; editing or deleting the invoice reverses the cost journal and puts the stock back at the cost it left at; bills for stock products debit Inventory instead of Purchases; stock-ins, write-offs and count differences post against the adjustments account; stock on hand when switched on is posted once as opening inventory. Each invoice line keeps its product and issue cost. The settings page shows the stock valuation against the inventory account balance; a difference is normally goods received but not yet billed.
- **Invoice lifecycle and stock integrity**: a posted invoice is cancelled, not deleted. Cancelling reverses its journal, returns its stock and cost of sales and leaves nothing owing; it is refused while receipts or credit notes exist, and a cancelled invoice cannot be edited. Only drafts and cancelled invoices can be deleted. Stock adjustments and warehouse transfers run in one transaction (total, location and movement log together), cannot take stock below zero, and a stock count sets the chosen warehouse's quantity with the product total following.
- **Receipts, default accounts and numbering**: invoices, customer receipts and credit notes post to the company's chosen Receivable, Sales Revenue and Cash/Bank accounts (Accounting > Default Accounts), never to an account guessed from its name; with none available the document is refused. A receipt is saved in one transaction, may allocate only to this customer's open invoices, no more than each has left and no more than was received. "Deleting" a receipt cancels it: the journal is reversed, the invoices owe the money again, and the receipt stays on record. Invoice and receipt numbers come from the company's sequence, cannot repeat, and a unique key backs this where existing data allows.
- **Sales returns**: a credit-note line can name a product sold on the invoice and the quantity coming back (inventory accounting on). The goods go back on the shelf at the cost they left at, Dr Inventory / Cr Cost of Sales is posted, and the quantity is capped at what the invoice sold less earlier returns. Voiding the note reverses the stock journal and takes the goods out again, and is refused while they are no longer in stock.
- **Pricing**: customer price lists (Sales > Price Lists) give a product a price, optionally by quantity break, and are assigned to customers; the invoice form fills the list price in (a typed price is left alone). Invoice totals are recomputed from the lines on save, including per-line discounts (now stored), and the save is refused if the posted subtotal, tax or total disagree with the lines.
- **Credit control**: a customer can have a credit limit (empty = none) and default payment terms in days. The invoice form sets the due date from the terms until it is edited by hand. Issuing an invoice (not a draft) that would take what the customer owes on other open invoices over their limit is refused; a user with settings permission can override it deliberately (audited).
- **Quotations**: quotes and proforma invoices carry a valid-until date (30 days by default, none for older ones) and a version. After the date a quote cannot be confirmed or signed by the customer until it is extended or revised. Revising makes a new version under the same number and marks the old one superseded, so only the current offer can be accepted and the history is kept. A quote can be marked declined with a reason, and a declined or expired quote can be revised to re-offer it. Customer-portal signing now takes the company from the quote itself and refuses quotes that are expired, superseded, declined or already accepted.
- **Delivery notes**: an order can be confirmed "for delivery" instead of invoiced at once. No stock moves and no invoice is raised until goods are sent: each delivery note issues stock for the quantities sent (with inventory accounting on, Dr Cost of Sales / Cr Inventory at average cost; otherwise units only), never more than is left on the order and never below zero stock. Delivered quantities are then invoiced in one or several invoices without issuing stock a second time; the order's discount is shared across them and adds up exactly. A delivery that has not been invoiced can be cancelled (stock returns); cancelling or deleting an invoice raised from deliveries makes those goods invoiceable again. Orders with deliveries cannot be edited or deleted, and an invoice raised from deliveries is changed by cancelling it, not editing it.
- **Payroll**: rules (pension, tax bands, overtime, levies) are settings with effective dates; payroll posts the full employer cost and the liabilities it creates; retroactive pay is tracked per employee; remittances pay the liabilities down.

## Running the checks
`for t in tests/test_*.php; do php "$t" | tail -1; done`. The tests are pure-function tests and need no database; SQL and migrations are exercised only against a real database.
