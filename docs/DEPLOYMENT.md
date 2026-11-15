# Deploying and operating Nuvis ERPX

This guide covers what has to be in place before real company data goes in: server setup, backups you have proven you can restore, safe releases, and monitoring. Commands assume Linux, Apache or Nginx, PHP 8.2+ and MySQL 8 / MariaDB 10.5+.

## Web server

The application folder should not be writable by the web server, except `uploads/` and `logs/`. After the installer has written `.env`, make it readable by the web user only: `chown root:www-data .env && chmod 640 .env`. Delete `install/`.

**Apache:** enable `mod_rewrite` and `mod_headers`, set `AllowOverride All`. The bundled `.htaccess` files block dotfiles, SQL/log files and the `bin/`, `uploads/assets`, `uploads/expenses` and `logs` folders, and stop scripts from running in `uploads/`.

**Nginx** does not read `.htaccess`, so these rules are required (adjust the PHP socket):

```nginx
server {
    root /var/www/nuviserpx;
    index index.php;
    client_max_body_size 12m;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options SAMEORIGIN always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;
    add_header Strict-Transport-Security "max-age=31536000" always;

    # Never serve these
    location ~ /\.(?!well-known)            { deny all; }
    location ~* (\.(sql|log|bak|lock|md|neon)|composer\.json)$ { deny all; }
    location ~ ^/(bin|logs|database|src|tests|vendor|docs|install)/ { deny all; }
    location ~ ^/uploads/(assets|expenses|leave_docs)/ { deny all; }

    # Uploads may be images only: no scripts
    location ~* ^/uploads/.*\.(php[0-9]?|phtml|phar|html?|svg|js)$ { deny all; }

    location / { try_files $uri $uri/ /index.php?page=$uri&$args; }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

Serve everything over HTTPS only (Let's Encrypt is fine). The app sets secure session cookies automatically when it sees HTTPS or `X-Forwarded-Proto: https`.

## Database users

Create a dedicated user, never `root`:

```sql
CREATE DATABASE nuviserpx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'nuvis_app'@'localhost' IDENTIFIED BY '<long random password>';
GRANT ALL PRIVILEGES ON nuviserpx.* TO 'nuvis_app'@'localhost';
-- for the restore drill (bin/restore-check.php) only:
GRANT ALL PRIVILEGES ON `nuviserpx\_restorecheck`.* TO 'nuvis_app'@'localhost';
```

`GRANT ALL` on that one database is needed today because the application still applies schema changes (ALTER/CREATE) itself when it starts. Once migrations are moved fully out of web requests, the runtime user can be reduced to `SELECT, INSERT, UPDATE, DELETE, LOCK TABLES` and a second user used only by `bin/migrate.php`. Until then, do not give the user access to any other database.

## Cron

```cron
# Application jobs (see README, "Scheduled Jobs")
15 0 * * *  php /var/www/nuviserpx/cron/auto_reverse_journals.php
0  5 * * *  php /var/www/nuviserpx/cron/contract_invoices.php
0  6 * * *  php /var/www/nuviserpx/cron/invoice_overdue_sweep.php
0  7 * * *  php /var/www/nuviserpx/cron/payment_reminders.php
*  * * * *  php /var/www/nuviserpx/cron/webhook_worker.php
# Nightly backup, 02:30
30 2 * * *  php /var/www/nuviserpx/bin/backup.php >> /var/log/nuviserpx-backup.log 2>&1
# Monthly restore drill, 1st of the month 03:30
30 3 1 * *  php /var/www/nuviserpx/bin/restore-check.php >> /var/log/nuviserpx-restore.log 2>&1
```

## Backups

`php bin/backup.php` makes, in `BACKUP_DIR` (default: a folder next to the app, never inside the web root):

- `nuviserpx-db-<date>.sql.gz`: a consistent database dump (no locking of live users) that is checked for damage straight away,
- `nuviserpx-files-<date>.tar.gz`: everything in `uploads/`,
- a `.sha256` checksum for each.

Settings in `.env`: `BACKUP_KEEP_DAYS` (default 14; the newest 3 of each kind are always kept), `BACKUP_PASSPHRASE` (encrypts the files with AES-256, **recommended** because they contain payroll and customer data), and `BACKUP_UPLOAD_CMD`, which copies each file off the server, for example:

```
BACKUP_UPLOAD_CMD=rclone copy {file} remote:nuvis-backups/
```

A backup that exists only on the same server is not a backup: a failed copy makes `bin/backup.php` exit with an error, and the log line `WARNING: BACKUP_UPLOAD_CMD is not set` tells you it is missing.

**Also keep, separately from the backups:** your `.env` (above all `APP_KEY` and `BACKUP_PASSPHRASE`) in a password manager. Without `APP_KEY`, stored SMTP passwords cannot be read; without `BACKUP_PASSPHRASE`, encrypted backups cannot be opened.

The "Download SQL Backup" button under Settings is a convenience for small databases. It builds the dump in the web server's memory and is not a substitute for the nightly job.

## Restore drill

An untested backup is a hope, not a plan. Once a month (cron above), and after any change to how backups are made:

```bash
php bin/restore-check.php                    # newest backup
php bin/restore-check.php /path/to/nuviserpx-db-....sql.gz.enc
```

It verifies the checksum, loads the backup into a scratch database named `<db>_restorecheck`, compares tables and key row counts with the live database, drops the scratch database, and prints `RESTORE CHECK PASSED` or `FAILED` (exit code 1). The result shows up in `php health.php`. Also try a restore on a different machine at least once, to prove you can rebuild from nothing: install PHP and MySQL, create the database, `gunzip -c backup.sql.gz | mysql nuviserpx`, unpack the files archive into the app folder, restore `.env`.

To actually restore the live database (destructive): `php bin/restore.php <backup file>` shows what it would do; add `--yes` to run it. It takes a safety dump of the current database first.

## Deploying a new version

Use a staging copy (same PHP and database versions, a recent restored backup) first. On production:

```bash
cd /var/www/nuviserpx
bin/deploy.sh v2.0.180        # a tag or branch from origin
```

It refuses to run if the server has uncommitted changes, then: takes a backup, switches **maintenance mode** on, switches the code to the requested version, applies migrations, runs the health check, and switches maintenance mode off. If any step fails the site **stays closed** and the exact recovery steps are printed.

Maintenance mode is a file called `maintenance.flag`. While it exists visitors get a 503 "back shortly" page and API calls get a 503 JSON reply; `/health.php` keeps answering, and addresses listed in `MAINTENANCE_ALLOW_IPS` can still use the site to test.

The previous version is remembered in `.deploy/previous_commit`; the history is in `.deploy/history.log`.

## Rolling back

```bash
bin/rollback.sh                                   # put the old code back
bin/rollback.sh --with-db /path/to/backup.sql.gz  # also restore the database (asks you to type RESTORE)
```

Code-only rollback is safe in most cases because migrations only add things. Restore the database only if a migration damaged data; it discards everything entered since the backup, so use the backup taken at the start of that deployment.

## Monitoring

- **Uptime:** point your monitor (UptimeRobot, Better Stack, etc.) at `https://your-domain/health.php`. It returns `{"status":"ok"}` (200) or `{"status":"degraded"}` (503) and nothing else to the public.
- **Details:** send the header `X-Health-Token: <HEALTH_TOKEN from .env>` to get the full checklist (database, pending migrations, disk space, backup age, last restore drill, encryption key, folder permissions, version). On the server, `php health.php` prints the same.
- Set `HEALTH_REQUIRE_BACKUP=1` so a missing or day-and-a-half-old backup turns the probe red. That makes the monitor your alarm for failed backups.
- **Errors:** `logs/php_errors.log` (rotated at 8 MB, also in Developer > Error Logs). Ship it to your log service or review it weekly.
- **Backup/cron output:** keep the `>> /var/log/...` redirects above and rotate them with logrotate.

## Checklist before real data goes in

- [ ] HTTPS only, certificate renewing; HTTP redirects to HTTPS
- [ ] `install/` deleted; `.env` mode 640; `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `APP_KEY` set; `.env`, `APP_KEY`, `BACKUP_PASSPHRASE` saved in a password manager
- [ ] Dedicated database user (not root), database not reachable from the internet
- [ ] `ALLOW_WEB_GIT=0`, `API_CORS_ORIGINS` and `API_ALLOW_QUERY_KEY` reviewed
- [ ] Nightly backup running with `BACKUP_UPLOAD_CMD` set and `BACKUP_PASSPHRASE` set
- [ ] Restore drill run successfully at least once, and a full rebuild on another machine tried once
- [ ] `/health.php` monitored with `HEALTH_REQUIRE_BACKUP=1`
- [ ] Cron jobs installed; logs rotated
- [ ] Staging environment exists; releases go through `bin/deploy.sh`
- [ ] Super Administrator password is long and unique; each person has their own login and the lowest role that fits
- [ ] Two-company isolation test and payroll/ledger checks passed (see the production-readiness plan)
