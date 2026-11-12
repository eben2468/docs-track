#!/usr/bin/env bash
#
# VVU DTS daily backup (spec §10.4): compressed MySQL dump + storage/uploads archive,
# keeping the last 14 days. Database credentials are read from the project's .env and
# passed to mysqldump through a temporary option file (never on the command line).
#
#   deploy/backup.sh                 # uses BACKUP_DIR=/home/vvu-dts/backups by default
#   BACKUP_DIR=/mnt/backups deploy/backup.sh
#
# Copy the backup folder off the server too (rclone, rsync, or the hosting provider's snapshots).

set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKUP_DIR="${BACKUP_DIR:-$HOME/backups}"
KEEP_DAYS="${KEEP_DAYS:-14}"
STAMP="$(date +%Y%m%d-%H%M)"

env_value() {
    # Value of KEY in .env, without surrounding quotes.
    local line
    line="$(grep -E "^$1=" "$APP_DIR/.env" | tail -n 1 || true)"
    line="${line#*=}"
    line="${line%\"}"; line="${line#\"}"
    line="${line%\'}"; line="${line#\'}"
    printf '%s' "$line"
}

DB_HOST="$(env_value DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="$(env_value DB_PORT)"; DB_PORT="${DB_PORT:-3306}"
DB_NAME="$(env_value DB_DATABASE)"
DB_USER="$(env_value DB_USERNAME)"
DB_PASS="$(env_value DB_PASSWORD)"

if [[ -z "$DB_NAME" || -z "$DB_USER" ]]; then
    echo "[$(date '+%F %T')] ERROR: DB_DATABASE / DB_USERNAME missing in $APP_DIR/.env" >&2
    exit 1
fi

mkdir -p "$BACKUP_DIR/db" "$BACKUP_DIR/uploads"
chmod 700 "$BACKUP_DIR"

OPTS="$(mktemp)"
trap 'rm -f "$OPTS"' EXIT
chmod 600 "$OPTS"
cat > "$OPTS" <<EOF
[client]
host=$DB_HOST
port=$DB_PORT
user=$DB_USER
password=$DB_PASS
EOF

DB_FILE="$BACKUP_DIR/db/${DB_NAME}-${STAMP}.sql.gz"
mysqldump --defaults-extra-file="$OPTS" --single-transaction --quick --routines --triggers \
    --default-character-set=utf8mb4 "$DB_NAME" | gzip -9 > "$DB_FILE"

UP_FILE="$BACKUP_DIR/uploads/uploads-${STAMP}.tar.gz"
tar -czf "$UP_FILE" -C "$APP_DIR/storage" uploads

find "$BACKUP_DIR/db" -name '*.sql.gz' -type f -mtime +"$KEEP_DAYS" -delete
find "$BACKUP_DIR/uploads" -name '*.tar.gz' -type f -mtime +"$KEEP_DAYS" -delete

echo "[$(date '+%F %T')] Backup OK: $(du -h "$DB_FILE" | cut -f1) database, $(du -h "$UP_FILE" | cut -f1) uploads."
