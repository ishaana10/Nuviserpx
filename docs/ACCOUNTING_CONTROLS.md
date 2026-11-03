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
- **Payroll**: rules (pension, tax bands, overtime, levies) are settings with effective dates; payroll posts the full employer cost and the liabilities it creates; retroactive pay is tracked per employee; remittances pay the liabilities down.

## Running the checks
`for t in tests/test_*.php; do php "$t" | tail -1; done`. The tests are pure-function tests and need no database; SQL and migrations are exercised only against a real database.
