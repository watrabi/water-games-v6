#!/usr/bin/env bash
# database backup: dumps the site's database (from .env) into storage/backups, gzipped, and keeps the
# newest $KEEP of them. uploads (public/uploads, storage/private) are files, back those up with the
# rest of the server.
#
#   bin/backup.sh                 run it now
#   KEEP=30 bin/backup.sh         keep 30 instead of 14
#
# cron, every night at 4am (crontab -e as the site's user, or aaPanel → Cron → Shell script):
#   0 4 * * * /www/wwwroot/games.watr.lol/bin/backup.sh >> /www/wwwroot/games.watr.lol/storage/logs/backup.log 2>&1

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="$ROOT/storage/backups"
KEEP="${KEEP:-14}"

# just the DB_ lines from .env, without running the rest of it as shell
env_value(){
    grep -E "^$1=" "$ROOT/.env" | tail -n1 | cut -d= -f2- | sed -E 's/[[:space:]]+#.*$//; s/^"(.*)"$/\1/; s/^'"'"'(.*)'"'"'$/\1/'
}

DB_HOST="$(env_value DB_HOST)"
DB_NAME="$(env_value DB_NAME)"
DB_USER="$(env_value DB_USER)"
DB_PASS="$(env_value DB_PASS)"

if [ -z "$DB_NAME" ] || [ -z "$DB_USER" ]; then
    echo "$(date -Is) DB_NAME / DB_USER missing from $ROOT/.env" >&2
    exit 1
fi

DUMP="$(command -v mysqldump || command -v mariadb-dump || true)"
if [ -z "$DUMP" ]; then
    echo "$(date -Is) mysqldump isn't installed" >&2
    exit 1
fi

mkdir -p "$DEST"
chmod 750 "$DEST"

# the password goes in a temp config file so it never shows up in `ps`
CONF="$(mktemp)"
trap 'rm -f "$CONF" "$FILE.part"' EXIT
printf '[client]\nhost=%s\nuser=%s\npassword=%s\n' "${DB_HOST:-127.0.0.1}" "$DB_USER" "$DB_PASS" > "$CONF"
chmod 600 "$CONF"

FILE="$DEST/$DB_NAME-$(date +%Y%m%d-%H%M%S).sql.gz"

# single transaction: a consistent snapshot without locking the site
"$DUMP" --defaults-extra-file="$CONF" --single-transaction --quick --routines --triggers --no-tablespaces "$DB_NAME" | gzip -6 > "$FILE.part"
mv "$FILE.part" "$FILE"
chmod 640 "$FILE"

# only the newest $KEEP
ls -1t "$DEST"/"$DB_NAME"-*.sql.gz 2>/dev/null | tail -n +"$((KEEP + 1))" | xargs -r rm -f

echo "$(date -Is) backed up $DB_NAME to $FILE ($(du -h "$FILE" | cut -f1))"
