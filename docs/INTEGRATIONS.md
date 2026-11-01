# Nuvis ERPX — Integrations, Automation & Developer Dashboard

This document covers the integration layer added to the Invoice module: outgoing
webhooks, the REST API, the customisable workflow engine, and the super-admin
developer dashboard.

---

## 1. Events

All integrations are driven by a single catalogue of events
(`src/Support/IntegrationEvents.php`). Adding an event there makes it available
to webhooks, workflows and the API docs automatically.

| Event | Fires when |
|-------|-----------|
| `invoice.created` | A new invoice is saved (UI or API) |
| `invoice.updated` | An existing invoice is edited |
| `invoice.paid` | An invoice balance reaches zero |
| `invoice.partially_paid` | A payment is applied but a balance remains |
| `invoice.cancelled` | An invoice is marked cancelled |
| `invoice.overdue` | An unpaid invoice passes its due date (daily sweep) |
| `invoice.deleted` | An invoice is deleted |
| `payment.received` | A customer payment receipt is recorded |

Events are emitted through `ModuleManager::trigger_hook($event, $payload, $tenant_id)`,
which runs in-process listeners, evaluates workflows, and queues webhooks — all
guarded so an integration failure never breaks the core transaction.

---

## 2. Outgoing Webhooks

**Admin → API Keys → Webhooks** (`admin/?page=webhooks`).

* Subscribe a URL to any event. Deliveries are **queued** (`webhook_deliveries`)
  and sent by a background worker, so saving an invoice is never blocked on HTTP.
* Failed deliveries retry with exponential backoff (1m, 5m, 15m, 1h, 6h) up to the
  per-webhook retry limit.
* Every attempt is logged (**Delivery Log**) with status, HTTP code and response.
* Payloads are signed: header `X-ERPX-Signature: sha256=<hex>` is the HMAC-SHA256
  of the raw JSON body using the webhook's secret token. Also sent:
  `X-ERPX-Event`, `X-ERPX-Delivery`.

### Running the worker (required for delivery)

```cron
* * * * * php /path/to/nuviserpx/cron/webhook_worker.php >> /var/log/erpx_webhooks.log 2>&1
```

Flags: `--limit=N` (max rows per run, default 100), `--loop=S` (keep draining for S
seconds).

### Overdue sweep (optional)

```cron
0 6 * * * php /path/to/nuviserpx/cron/invoice_overdue_sweep.php >> /var/log/erpx_overdue.log 2>&1
```

Fires `invoice.overdue` once per invoice per day.

### Verifying a signature (receiver side)

```php
$body = file_get_contents('php://input');
$sig  = $_SERVER['HTTP_X_ERPX_SIGNATURE'] ?? '';
$calc = 'sha256=' . hash_hmac('sha256', $body, $your_secret);
if (!hash_equals($calc, $sig)) { http_response_code(401); exit; }
```

---

## 3. REST API

Base path: `/api/v1/`. Authenticate with header `X-API-Key: <key>` or
`Authorization: Bearer <key>`. Keys and scopes are managed under
**Admin → API Keys**.

### Invoices — `api/v1/invoices.php`

| Method | Path | Scope | Purpose |
|--------|------|-------|---------|
| GET | `/invoices.php` | `read_invoices` | List (pagination `?page`,`?per_page`; filters `?status`,`?party_id`,`?invoice_no`,`?from`,`?to`) |
| GET | `/invoices.php?id=123` | `read_invoices` | One invoice + line items |
| POST | `/invoices.php` | `write_invoices` | Create (fires `invoice.created`) |
| PUT/PATCH | `/invoices.php?id=123` | `write_invoices` | Update (fires `invoice.updated` / `invoice.cancelled`) |
| POST | `/invoices.php?id=123&action=pay` | `write_payments` | Record a payment (fires `payment.received` + paid events) |
| DELETE | `/invoices.php?id=123` | `write_invoices` | Delete (fires `invoice.deleted`) |

### Webhooks — `api/v1/webhooks.php` (scope `manage_webhooks`)

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/webhooks.php` | List this tenant's webhooks + available events |
| POST | `/webhooks.php` | Subscribe `{ event, target_url, name?, secret_token? }` |
| DELETE | `/webhooks.php?id=123` | Unsubscribe |

All API traffic is recorded in `api_request_logs` for the developer dashboard.

---

## 4. Workflow Automation

**Admin → Automation Workflows** (`admin/?page=workflows`).

A workflow is **trigger + conditions + actions**, fully editable with no code:

* **Trigger** — any event above.
* **Conditions** — field / operator / value rows, matched with ALL or ANY.
  Operators: equals, not equals, greater/less than, contains, starts with,
  is (not) empty, changed to.
* **Actions** (run in order):
  * **Send Email** — to a fixed address or the customer; subject/body support
    `{{field}}` placeholders.
  * **Call a URL / Webhook** — one-off signed POST.
  * **Create Support Ticket** — opens a ticket (feeds Customer Requests).
  * **Log only**.

Every run is recorded in **Run History** (`workflow_logs`).

---

## 5. Developer Dashboard (Super Admin)

**Developer → Developer Dashboard** (`admin/?page=developer/dashboard`). Restricted
to super administrators (`users.type = 1`). Platform-wide, across all tenants:

* **Tenant performance** — per-company users, invoices, revenue, outstanding, API
  usage and plan/quota.
* **Application performance** — webhook success rate & queue, API volume/latency/
  errors, workflow runs — with 14-day trend charts.
* **Server performance** — PHP/MySQL versions, DB & uploads size, disk, memory,
  OPcache and loaded extensions.

### Customer Plans (`developer/customer_plans`)

Create SaaS subscription tiers (`tenant_plans`) with price, billing cycle and
limits (users / invoices / API calls), then assign a plan, status and expiry to
each tenant. Three default plans (Starter / Professional / Enterprise) are seeded.

### Customer Requests (`developer/customer_requests`)

A platform-wide inbox of support tickets raised across all tenants, with open /
new / urgent / resolved counters.

---

## 6. Schema

New tables (auto-created by `DBConnection` on first run, also in
`database/nuviserpx.sql` and `database/migrations/004_integrations_automation.sql`):

`webhook_deliveries`, `api_request_logs`, `workflows`, `workflow_logs`,
`tenant_plans`. The `webhooks` table gains name/retry/health columns, and
`tenants` gains `plan_id`, `plan_started_at`, `plan_expires_at`, `plan_status`.
