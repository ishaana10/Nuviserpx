# Nuvis ERPX UX polish — design

Status: DRAFT, awaiting owner approval. Nothing is built yet.

## Intent
Make day-to-day use of Nuvis ERPX (the admin app) feel attractive, consistent and easy,
especially on a phone. "Customers" = the people using the app. Owner works mobile-first.

## What already exists (do not rebuild)
- Theme tokens, accent colour, dark mode, density, text size (`nuvis-theme.css`, `ui_prefs.php`).
- `nuvis-ui.js` auto-enhances DataTables: summary stat cards, advanced filters, status pills,
  numeric alignment, density, export buttons, unsaved-changes guard on forms.
- Page heading (breadcrumb + title) rendered by `admin/index.php`; `alert_toast()`; `.nv-empty`.
- Dashboard with greeting, `nuvis-dashboard.css`; in-app User Guide + page helper.

## Gaps found (from reading the code; live UI not yet reviewed with the owner)
1. Pages are inconsistent: list pages like `invoices/index.php` still use the old
   `card-outline` header + `table-bordered table-striped`, so they miss the newer look.
2. Page header duplicates the title (breadcrumb title AND card title) and the primary
   action sits in a small `btn-sm` inside the card header.
3. Tables are wide and horizontally scrolled on phones; no card layout on small screens.
4. Empty states, loading feedback and save/error feedback vary per page.
5. No quick way to jump to a page (the sidebar is long: 12 menu sections).
6. Forms (invoice, payroll run, employee) are long single-column pages with weak grouping.

## Goals
- One consistent look for every list and form page.
- Phone-friendly lists (stacked cards) and a sticky primary action.
- Faster navigation (quick-search / command box).
- Clear empty, loading, success and error states.

## Non-goals
- No framework swap (no leaving AdminLTE/Bootstrap 4), no new build tooling.
- No behaviour or permission changes; no DB changes.
- Not touching the public `portal/` pages in this project.

## Anti-goals
- A redesign that breaks existing pages or user theme choices (dark mode, density, accent).
- Anything that removes functionality to look cleaner.

## Approaches considered
- A. CSS-only polish: safe, but leaves inconsistency and mobile problems. Rejected.
- B. Shared patterns, then top workflows (CHOSEN by owner): extend existing components, adopt
  them page by page, one safe PR per slice.
- C. Full redesign / framework swap: too risky on a live app without browser testing. Rejected.

## Design (approach B)
Slice 1 — Foundation (CSS + small include): `nv-page-actions` header bar (title area with the
primary action right-aligned, collapses to a full-width button on phones); remove duplicate
card title via a shared helper; unified `.nv-card` surface; responsive "table to cards" mode
for tables marked `data-nv-cards` (CSS + a few lines in `nuvis-ui.js`, labels from `<th>`).
Slice 2 — Navigation: quick-search box in the top bar (Ctrl/Cmd+K and a search icon on phones)
filtering the permitted menu items client-side from the existing menu markup.
Slice 3 — Dashboard + Invoices: adopt slice 1 patterns; invoice list in card mode on phones.
Slice 4 — Sales Orders, Parties, Products, Employees, Payroll lists.
Slice 5 — Long forms: group into titled sections with a sticky bottom Save bar on phones.
Slice 6 — Sweep remaining list pages using the same helper.
Each slice is its own PR with a test pinning the new markup/CSS hooks and a CHANGELOG entry.

## Constraints
PHP 8.2, no composer/build step, deployed by git pull; Bootstrap 4/AdminLTE 3; must respect
tenant module gating and permissions (quick-search only lists items the user may open);
tests are standalone PHP scripts; nothing here can be browser-tested in this environment, so
each PR states that and the owner checks it on the live site.

## My calls (not yet confirmed by the owner)
- Quick-search is client-side only (no new endpoint).
- Card mode below 768px only; desktop tables unchanged.
- Order of slices as above; Slice 1 ships first.

## Left to the builder
Exact class names, breakpoints and icon choices, following existing `nv-` conventions.
