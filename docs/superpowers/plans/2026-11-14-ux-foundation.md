# UX Foundation (Slice 1) Implementation Plan

> For agentic workers: use superpowers:executing-plans. Native execution chosen (single small slice).

**Goal:** Add phone-friendly stacked-card tables and a consistent page-action bar, piloted on the Invoices list.
**Architecture:** Opt-in `data-nv-cards` attribute on a DataTable. `nuvis-ui.js::enhanceRows` writes `data-label` on each cell from its column header on every draw; CSS below 768px turns rows into cards using `td::before{content:attr(data-label)}`. CSS-only action bar styling.
**Tech Stack:** PHP 8.2 pages, jQuery/DataTables, plain CSS in `dist/css/nuvis-ui.css`.
**Spec:** docs/superpowers/specs/2026-11-14-ux-polish-design.md

## Global Constraints
- No framework/build changes; desktop tables unchanged (cards only `max-width: 767.98px`).
- Respect existing `nv-` naming, theme variables (`--nv-*`) and dark mode.
- Tests are standalone PHP scripts with `check()` pinning file contents; CHANGELOG entry required.

## Review Focus
- Utility columns (index `#`, Action) must not become a blank-labelled line: hide the label when the header is empty/utility.
- Cell labels must be set via `.attr()` (never HTML) so header text cannot inject markup.
- Rows re-sorted/filtered/paged must keep labels (draw hook).
- Empty-table row (`dataTables_empty`) must not render as a labelled card.

### Task 1: Card mode for tables
**Files:** Modify `dist/js/nuvis-ui.js` (enhanceRows), `dist/css/nuvis-ui.css`; Test `tests/test_ui_cards.php`
**Produces:** `labelCells(api)` called from `enhanceRows`; CSS hooks `table.nv-table[data-nv-cards]`.
- [ ] Write failing test pinning: `function labelCells`, `data-nv-cards` check, `.attr('data-label'`, skip of `isUtilityHeader`, `dataTables_empty` guard, media query block in CSS, `attr(data-label)` content rule.
- [ ] Implement labelCells in JS and CSS block.
- [ ] Run the test and the full suite.

### Task 2: Page action bar styling
**Files:** Modify `dist/css/nuvis-ui.css`; Test `tests/test_ui_cards.php`
- [ ] Test pins `.nv-card-actions` rules; implement: card-header on list pages uses flex, `card-tools` right-aligned, primary action full width on phones, `btn-sm` buttons get standard height on phones (touch target >= 40px).

### Task 3: Pilot on Invoices
**Files:** Modify `admin/invoices/index.php` (table gets `data-nv-cards`, `btn-sm` action kept), CHANGELOG.md
- [ ] Test pins `data-nv-cards` on the invoices table; implement; run suite; commit; PR.
