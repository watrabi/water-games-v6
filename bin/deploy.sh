#!/usr/bin/env bash
# puts a branch of the repo live. run it ON THE SERVER, as root:
#
#   bin/deploy.sh                        deploys claude/keen-fermat-93ac9e (or $BRANCH)
#   BRANCH=main bin/deploy.sh            another branch
#   DRY_RUN=1 bin/deploy.sh              shows what would change, touches nothing
#
# what it does, in order. it stops at the first thing that fails:
#   1. backs up the database (bin/backup.sh) and the code (to /www/backup/site/, without vendor and game files)
#   2. clones the branch into a temp folder
#   3. copies in the new migrations and runs them, before the code that needs them goes live
#   4. copies the code over. these are never touched: .env, phinx.php, vendor/, storage/, public/uploads/,
#      public/game-files/ (prod has games that aren't in the repo), public/.user.ini, public/.well-known/
#   5. composer install, only if composer.lock changed
#   6. clears the twig cache, fixes ownership
#   7. restarts watr-realtime, only if realtime/ changed
#
# nothing is deleted from the site: a file removed from the repo stays on the server until you remove it.

set -euo pipefail

SITE="${SITE:-/www/wwwroot/games.watr.lol}"
REPO="${REPO:-https://github.com/watrabi/water-games-v6.git}"
BRANCH="${BRANCH:-claude/keen-fermat-93ac9e}"
BACKUPS="${BACKUPS:-/www/backup/site}"
OWNER="${OWNER:-www:www}"
PHP="${PHP:-php}"
DRY_RUN="${DRY_RUN:-}"

say(){ echo "$(date +%H:%M:%S) $*"; }

if [ "$(id -u)" != "0" ]; then
    echo "run this as root (it fixes file ownership and restarts the realtime service)" >&2
    exit 1
fi
if [ ! -f "$SITE/.env" ]; then
    echo "$SITE doesn't look like the site (no .env there)" >&2
    exit 1
fi

TMP="$(mktemp -d /tmp/watr-deploy-XXXXXX)"
trap 'rm -rf "$TMP"' EXIT

EXCLUDES=(
    --exclude=.git/ --exclude=.github/ --exclude=.env --exclude=phinx.php --exclude=vendor/ --exclude=storage/
    --exclude=public/uploads/ --exclude=public/game-files/ --exclude=public/.user.ini --exclude=public/.well-known/
    --exclude=.well-known/ --exclude=.user.ini --exclude=test.sqlite3 --exclude='*.txt'
)

# ---------- 1. backups ----------
STAMP="$(date +%Y%m%d-%H%M%S)"
if [ -z "$DRY_RUN" ]; then
    say "backing up the database"
    bash "$SITE/bin/backup.sh"
    mkdir -p "$BACKUPS"
    say "backing up the code to $BACKUPS/games.watr.lol-$STAMP.tar.gz"
    tar -czf "$BACKUPS/games.watr.lol-$STAMP.tar.gz" -C "$SITE" \
        --exclude=./vendor --exclude=./public/game-files --exclude=./public/uploads --exclude=./storage/backups --exclude=./storage/cache .
fi

# ---------- 2. the new code ----------
say "cloning $BRANCH"
git clone --quiet --depth 1 --branch "$BRANCH" "$REPO" "$TMP/src"
COMMIT="$(git -C "$TMP/src" log -1 --format='%h %s')"
say "deploying $COMMIT"

for f in $(cd "$TMP/src" && find classes routes init.php public/index.php bin -name '*.php'); do
    "$PHP" -l "$TMP/src/$f" > /dev/null || { echo "syntax error in $f, stopping" >&2; exit 1; }
done

if [ -n "$DRY_RUN" ]; then
    say "dry run, this is what would change:"
    rsync -rlcni "${EXCLUDES[@]}" "$TMP/src/" "$SITE/" | grep -v '/$' || true
    exit 0
fi

LOCK_CHANGED=""
cmp -s "$TMP/src/composer.lock" "$SITE/composer.lock" || LOCK_CHANGED=1
REALTIME_CHANGED=""
diff -rq "$TMP/src/realtime" "$SITE/realtime" --exclude=node_modules > /dev/null 2>&1 || REALTIME_CHANGED=1

# ---------- 3. migrations first ----------
say "running migrations"
rsync -a "$TMP/src/db/" "$SITE/db/"
if ! MIGRATE_OUT="$(cd "$SITE" && "$PHP" vendor/bin/phinx migrate -c phinx.php 2>&1)"; then
    echo "$MIGRATE_OUT" >&2
    echo "migrations failed, stopping before the code goes live. the old code is still running" >&2
    exit 1
fi
echo "$MIGRATE_OUT" | grep -E "==|All Done" || true

# ---------- 4. the code ----------
say "copying the code"
rsync -rlc "${EXCLUDES[@]}" "$TMP/src/" "$SITE/"

# ---------- 5. dependencies ----------
if [ -n "$LOCK_CHANGED" ]; then
    say "composer.lock changed, installing"
    (cd "$SITE" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --optimize-autoloader --quiet)
fi

# ---------- 6. tidy ----------
find "$SITE/storage/cache" -mindepth 1 -delete 2>/dev/null || true
chown -R "$OWNER" "$SITE"
chmod +x "$SITE"/bin/*.sh

# ---------- 7. realtime ----------
if [ -n "$REALTIME_CHANGED" ] && systemctl list-unit-files watr-realtime.service > /dev/null 2>&1; then
    say "realtime/ changed, restarting watr-realtime"
    (cd "$SITE/realtime" && npm install --omit=dev --silent)
    chown -R "$OWNER" "$SITE/realtime"
    systemctl restart watr-realtime
fi

say "done: $COMMIT"
say "if something's wrong: the code backup is $BACKUPS/games.watr.lol-$STAMP.tar.gz, the database one is in $SITE/storage/backups"
