#!/usr/bin/env bash
# Put the previous code back.
#
#   bin/rollback.sh                         code only (safe: migrations only ever ADD things)
#   bin/rollback.sh --with-db <backup.gz>   code AND database (replaces ALL current data with the backup; asks first)
#
# The database is restored only when you ask for it, because that discards everything entered since the backup.
set -euo pipefail
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$APP"

PREV_FILE=.deploy/previous_commit
[ -f "$PREV_FILE" ] || { echo "No previous version recorded (.deploy/previous_commit). Nothing to roll back to."; exit 2; }
PREV="$(cat "$PREV_FILE")"

DB_BACKUP=""
if [ "${1:-}" = "--with-db" ]; then
  DB_BACKUP="${2:-}"
  [ -f "$DB_BACKUP" ] || { echo "Usage: bin/rollback.sh --with-db <database backup file>"; exit 2; }
fi

php -r 'define("base_app", getcwd()."/"); require "src/Support/Maintenance.php"; \App\Support\Maintenance::on();'
echo "Maintenance mode ON."

echo "Switching code back to $PREV"
git checkout --detach "$PREV"

if [ -n "$DB_BACKUP" ]; then
  echo
  echo "About to REPLACE THE LIVE DATABASE with $DB_BACKUP."
  echo "Everything entered after that backup was taken will be lost."
  read -r -p "Type RESTORE to continue: " ANSWER
  [ "$ANSWER" = "RESTORE" ] || { echo "Cancelled. Code is rolled back; database untouched. Site is still in maintenance mode."; exit 4; }
  php bin/restore.php "$DB_BACKUP" --yes
fi

php health.php || { echo "Health check reports problems (above). Site left in maintenance mode."; exit 5; }
rm -f maintenance.flag
echo "$(date -u +%FT%TZ) rollback to $PREV ${DB_BACKUP:+(database restored from $DB_BACKUP)}" >> .deploy/history.log
echo "Rolled back to $PREV. Maintenance mode OFF."
