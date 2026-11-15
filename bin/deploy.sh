#!/usr/bin/env bash
# Release a new version safely.
#
#   bin/deploy.sh <git-tag-or-branch>        e.g.  bin/deploy.sh v2.0.180
#
# Order: check clean -> backup -> maintenance ON -> fetch + switch code -> migrate -> health check -> maintenance OFF.
# If anything fails the site STAYS in maintenance mode and the steps to recover are printed (never a half-working live site).
set -euo pipefail

REF="${1:-}"
[ -n "$REF" ] || { echo "Usage: bin/deploy.sh <git-tag-or-branch>"; exit 2; }
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP"
mkdir -p .deploy

step() { printf '\n==> %s\n' "$*"; }
recover() {
  cat <<MSG

DEPLOY FAILED at: ${CURRENT:-unknown}
The site is still in MAINTENANCE MODE so nobody sees a half-updated system.

  1. Look at the error above.
  2. Put the old code back:      bin/rollback.sh
  3. If the database was changed: bin/rollback.sh --with-db <backup file from ${BACKUP_FILE:-the backup folder}>
  4. Check:                      php health.php
  5. Reopen the site:            rm "$APP/maintenance.flag"
MSG
}
trap 'recover' ERR

CURRENT="pre-flight"
step "Checking the working copy is clean"
if [ -n "$(git status --porcelain)" ]; then
  echo "There are uncommitted changes on this server:"; git status --short; exit 3
fi
PREV="$(git rev-parse HEAD)"
echo "$PREV" > .deploy/previous_commit

CURRENT="backup"
step "Backing up the database and uploads"
BACKUP_OUT="$(php bin/backup.php)"
echo "$BACKUP_OUT"
BACKUP_FILE="$(printf '%s\n' "$BACKUP_OUT" | awk '/^Created nuviserpx-db-/{print $2; exit}')"

CURRENT="maintenance on"
step "Switching maintenance mode ON"
php -r 'define("base_app", getcwd()."/"); require "src/Support/Maintenance.php"; exit(\App\Support\Maintenance::on() ? 0 : 1);'

CURRENT="fetch and switch code"
step "Fetching and switching to $REF"
git fetch --tags --prune origin
git checkout --detach "$REF"
NEW="$(git rev-parse HEAD)"
echo "$PREV -> $NEW"

CURRENT="syntax check"
step "Checking the PHP files load"
for f in config.php initialize.php classes/DBConnection.php health.php; do php -l "$f" >/dev/null; done

CURRENT="migrations"
step "Applying database migrations"
php bin/migrate.php

CURRENT="health check"
step "Health check"
php health.php

CURRENT="maintenance off"
step "Switching maintenance mode OFF"
rm -f maintenance.flag
echo "$(date -u +%FT%TZ) $PREV -> $NEW ($REF) ok" >> .deploy/history.log
trap - ERR
echo; echo "Deployed $REF ($NEW). Previous version kept as $PREV for bin/rollback.sh."
