# Pilot checklist and capacity notes

For the first live companies. Everything here was measured or built in the hardening phases; nothing is assumed.

## Before the first customer
- [ ] `.env` has `AUTO_MIGRATE=0`, a random `APP_KEY`, `ALLOW_WEB_GIT` unset, `HEALTH_TOKEN` set (see `.env.example`).
- [ ] Database user for the web app has only `SELECT, INSERT, UPDATE, DELETE, LOCK TABLES`; a separate user runs `bin/migrate.php` (see `docs/DEPLOYMENT.md`).
- [ ] `php bin/backup.php` works, a backup is copied off the server, and `php bin/restore-check.php` has passed once on a different database.
- [ ] `php health.php` reports no problems; an uptime monitor calls `health.php` every minute.
- [ ] `php bin/migrate.php --status` says nothing is pending.
- [ ] Deploy and rollback have been rehearsed once on a copy (`bin/deploy.sh`, `bin/rollback.sh`).
- [ ] HTTPS is on, and `uploads/` returns 403 for a direct file request.

## API keys
Keys are stored as a hash and shown once, when created. Existing keys keep working and are upgraded the first time they are used. One release after this ships, run `php bin/scrub-api-keys.php` to see how many plain-text keys remain, then `--yes` to remove them. Old backups still contain the plain keys.

## What was measured (MariaDB 10.11, one company, 120,000 journal lines)
| Operation | Time |
|---|---|
| Account ledger for one account, one year | about 15 ms |
| Monthly movement, 12 months | about 7 ms |
| Trial balance / any report built from all balances | about 650 ms, grows in step with the number of lines |

The trial balance reads every journal line in range, so expect roughly 5 ms per 1,000 lines: 1 million lines is about 5 seconds. Extra indexes were tried and gave only about 15%, so none were added. If a company approaches that size, the next step is a stored balance per account per month, not more indexes.

List pages for invoices, sales orders and payments show the newest 2,000 rows by default, with a banner and a "Show all" link, because the pages send every row to the browser.

`tests/db/test_db_performance.php` checks query plans, so a change that stops using an index fails CI.

## Watch during the pilot
- `php health.php` and the uptime monitor.
- `logs/php_errors.log` daily for the first two weeks.
- Disk space and backup age (both are in the health check).
- Slow requests: turn on the database slow query log (`long_query_time = 1`) for the pilot period.

## Not covered yet
- Load testing with many simultaneous users (single-user timings only).
- Stored period balances for very large ledgers.
- A strict Content-Security-Policy (needs the inline scripts moved out; only plugin and framing limits are set).
- VMS / FRCS compliance (deferred by decision).
