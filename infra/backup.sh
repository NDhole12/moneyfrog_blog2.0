#!/usr/bin/env bash
# Back up the blog (database daily, uploads weekly) to S3. Runs ON the server from cron.
#
#   infra/backup.sh [--dry-run] [--weekly] <staging|live>
#
# Reads the git-ignored infra/.env.backup (override: BACKUP_ENV_FILE); see
# infra/.env.backup.example. Objects (all server-side encrypted, each with a .sha256 sidecar):
#   <S3_PREFIX>/<env>/db/YYYY/MM/<env>-db-<UTC timestamp>.sql.gz         every run
#   <S3_PREFIX>/<env>/uploads/<env>-uploads-<UTC timestamp>.tar.gz       Sundays (IST) or --weekly
# The IAM user is write-only, so retention (30 daily DB, 8 weekly uploads) is an S3 lifecycle
# rule, never a delete from this script. See infra/restore.md.
#
# Secrets: S3 keys are read from the env file and handed to infra/s3put.py through its process
# environment only (never argv). The DB password never leaves WP-CLI (mysqldump reads it from
# its own environment). Alert emails and the log hold no secrets.
# Exit: 0 ok, non-zero on any failure (an alert email is attempted first).
set -euo pipefail
umask 077

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="${BACKUP_ENV_FILE:-$SCRIPT_DIR/.env.backup}"
LOG_MAX_BYTES=1048576

DRY_RUN=0; FORCE_WEEKLY=0; TARGET=""
for a in "$@"; do
  case "$a" in
    --dry-run) DRY_RUN=1 ;;
    --weekly) FORCE_WEEKLY=1 ;;
    -h|--help) sed -n '2,15p' "${BASH_SOURCE[0]}"; exit 0 ;;
    -*) echo "ERROR: unknown option: $a" >&2; exit 2 ;;
    *) [ -z "$TARGET" ] || { echo "ERROR: one environment only" >&2; exit 2; }; TARGET="$a" ;;
  esac
done
case "$TARGET" in staging|live) ;; *) echo "usage: infra/backup.sh [--dry-run] [--weekly] <staging|live>" >&2; exit 2 ;; esac

# cron has a minimal PATH; REMOTE_PATH_PREFIX (the PHP 8.3 dir) is prepended after the env file loads.
export PATH="/usr/local/bin:/usr/bin:/bin:${PATH:-}"

[ -f "$ENV_FILE" ] || { echo "ERROR: missing $ENV_FILE (copy infra/.env.backup.example)" >&2; exit 2; }
# shellcheck disable=SC1090
source "$ENV_FILE"
[ -z "${REMOTE_PATH_PREFIX:-}" ] || export PATH="$REMOTE_PATH_PREFIX:$PATH"

: "${BACKUP_ENV:=$TARGET}"
[ "$BACKUP_ENV" = "$TARGET" ] || { echo "ERROR: $ENV_FILE is for '$BACKUP_ENV', not '$TARGET'" >&2; exit 2; }
S3_PREFIX="${S3_PREFIX:-blog-backups}"; S3_PREFIX="${S3_PREFIX%/}"
AWS_REGION="${AWS_REGION:-ap-south-1}"
WP_BIN="${WP_BIN:-wp}"
PYTHON_BIN="${PYTHON_BIN:-python3}"
LOG_FILE="${BACKUP_LOG:-$HOME/logs/mf-backup.log}"
LOCK_FILE="${BACKUP_LOCK:-$HOME/.mf-backup-$TARGET.lock}"
S3PUT="$SCRIPT_DIR/s3put.py"

FAIL_STAGE="startup"
LOCKED=0
WORK=""

mkdir -p "$(dirname "$LOG_FILE")"
if [ -f "$LOG_FILE" ] && [ "$(wc -c < "$LOG_FILE")" -gt "$LOG_MAX_BYTES" ]; then mv -f "$LOG_FILE" "$LOG_FILE.1"; fi
log() { printf '%s [%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$TARGET" "$*" >> "$LOG_FILE"; printf '[backup] %s\n' "$*"; }

send_alert() { # $1 = exit code. No secrets: environment, stage, code, time, log location only.
  [ -n "${ALERT_EMAIL:-}" ] || { log "no ALERT_EMAIL set; alert not sent"; return 0; }
  local subject="Blog backup FAILED ($TARGET)"
  local body
  body="The $TARGET blog backup failed at stage '$FAIL_STAGE' (exit code $1) at $(date -u +%Y-%m-%dT%H:%M:%SZ).
See the backup log on the server for details. No backup was completed by this run."
  if command -v mail >/dev/null 2>&1; then
    printf '%s\n' "$body" | mail -s "$subject" "$ALERT_EMAIL" || log "mail command failed"
  elif command -v sendmail >/dev/null 2>&1 || [ -x /usr/sbin/sendmail ]; then
    printf 'To: %s\nSubject: %s\n\n%s\n' "$ALERT_EMAIL" "$subject" "$body" | "$(command -v sendmail || echo /usr/sbin/sendmail)" -t || log "sendmail failed"
  else
    log "neither mail nor sendmail is available; alert not sent"
  fi
}

on_exit() {
  local rc=$?
  trap - EXIT
  [ -z "$WORK" ] || rm -rf -- "$WORK"
  if [ "$rc" -ne 0 ] && [ "$DRY_RUN" -eq 0 ] && [ "$LOCKED" -eq 1 ]; then
    log "FAILED at stage '$FAIL_STAGE' (exit $rc)"
    send_alert "$rc"
  elif [ "$rc" -ne 0 ] && [ "$DRY_RUN" -eq 0 ]; then
    log "not run: $FAIL_STAGE (exit $rc)"
  fi
  exit "$rc"
}
trap on_exit EXIT

fail() { log "ERROR: $*"; exit 1; }

# ---- config checks ----
FAIL_STAGE="config"
for v in DOCROOT S3_BUCKET AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY; do
  [ -n "${!v:-}" ] || { echo "ERROR: $v is not set in $ENV_FILE" >&2; exit 2; }
done
[ -d "$DOCROOT/wp-content" ] || { echo "ERROR: DOCROOT has no wp-content" >&2; exit 2; }
[ -f "$S3PUT" ] || { echo "ERROR: missing $S3PUT" >&2; exit 2; }
read -r -a WPCMD <<<"$WP_BIN"

TS="$(date -u +%Y%m%dT%H%M%SZ)"
YEAR="$(date -u +%Y)"; MONTH="$(date -u +%m)"
IST_DOW="$(date -u -d '+5 hours 30 minutes' +%u)"   # 7 = Sunday in IST, independent of the server timezone
WEEKLY=0
{ [ "$FORCE_WEEKLY" -eq 1 ] || [ "$IST_DOW" = 7 ]; } && WEEKLY=1
DB_KEY="$S3_PREFIX/$TARGET/db/$YEAR/$MONTH/$TARGET-db-$TS.sql.gz"
UP_KEY="$S3_PREFIX/$TARGET/uploads/$TARGET-uploads-$TS.tar.gz"

if [ "$DRY_RUN" -eq 1 ]; then
  cat <<PLAN
[backup] DRY RUN plan (nothing is dumped or uploaded)
  environment : $TARGET
  bucket      : s3://$S3_BUCKET  (region $AWS_REGION)
  db dump     : ${WPCMD[*]} --path=<DOCROOT> db export - --single-transaction | gzip
  db object   : $DB_KEY  (+ .sha256)
  uploads     : $([ "$WEEKLY" -eq 1 ] && echo "YES (weekly): $UP_KEY (+ .sha256)" || echo "no (not Sunday IST; use --weekly to force)")
  lock        : $LOCK_FILE
  log         : $LOG_FILE
  retention   : by S3 lifecycle rule (this script never deletes)
  alerts      : $([ -n "${ALERT_EMAIL:-}" ] && echo "email on failure" || echo "none (ALERT_EMAIL unset)")
PLAN
  exit 0
fi

# ---- single instance ----
command -v flock >/dev/null || { echo "ERROR: flock is not available" >&2; exit 2; }
exec 9>"$LOCK_FILE"
if ! flock -n 9; then echo "ERROR: another backup is already running" >&2; FAIL_STAGE="lock"; exit 3; fi
LOCKED=1

WORK="$(mktemp -d "${TMPDIR:-$HOME}/.mf-backup.XXXXXXXX")"
chmod 700 "$WORK"
log "start (weekly=$WEEKLY, ts=$TS)"

if [ -f "$ENV_FILE" ] && [ -n "$(find "$ENV_FILE" -perm /077 2>/dev/null)" ]; then
  log "warning: $ENV_FILE is readable by group/others; chmod 600 it"
fi

upload() { # $1 = local file, $2 = object key; 3 attempts; credentials only via the process environment
  local f=$1 k=$2 n=1 flags=(--region "$AWS_REGION")
  [ -z "${S3_ENDPOINT:-}" ] || flags+=(--endpoint "$S3_ENDPOINT")
  [ "${S3_PATH_STYLE:-0}" != 1 ] || flags+=(--path-style)
  while :; do
    if AWS_ACCESS_KEY_ID="$AWS_ACCESS_KEY_ID" AWS_SECRET_ACCESS_KEY="$AWS_SECRET_ACCESS_KEY" \
       AWS_SESSION_TOKEN="${AWS_SESSION_TOKEN:-}" "$PYTHON_BIN" "$S3PUT" "${flags[@]}" "$f" "$S3_BUCKET" "$k" >> "$LOG_FILE" 2>&1; then
      return 0
    fi
    [ "$n" -lt 3 ] || return 1
    log "upload attempt $n failed for $k; retrying"
    sleep $((n * 10)); n=$((n + 1))
  done
}

ship() { # $1 = local file, $2 = object key: checksum sidecar + upload both
  local f=$1 k=$2 sum
  sum="$(sha256sum "$f" | cut -d' ' -f1)"
  printf '%s  %s\n' "$sum" "$(basename "$k")" > "$f.sha256"
  upload "$f" "$k" || fail "upload failed for $k"
  upload "$f.sha256" "$k.sha256" || fail "upload failed for $k.sha256"
  log "uploaded $k ($(wc -c < "$f") bytes, sha256 $sum)"
}

# ---- database ----
FAIL_STAGE="database dump"
DB_FILE="$WORK/$TARGET-db-$TS.sql.gz"
"${WPCMD[@]}" --path="$DOCROOT" db export - --single-transaction 2>>"$LOG_FILE" | gzip -c > "$DB_FILE" \
  || fail "database export failed"
gzip -t "$DB_FILE" || fail "database dump is not a valid gzip"
[ "$(wc -c < "$DB_FILE")" -gt 1024 ] || fail "database dump is suspiciously small"
gzip -dc "$DB_FILE" | tail -n 5 | grep -q 'Dump completed' || fail "database dump is incomplete (no completion marker)"
FAIL_STAGE="database upload"
ship "$DB_FILE" "$DB_KEY"
rm -f "$DB_FILE" "$DB_FILE.sha256"

# ---- uploads (weekly) ----
if [ "$WEEKLY" -eq 1 ]; then
  FAIL_STAGE="uploads archive"
  [ -d "$DOCROOT/wp-content/uploads" ] || fail "wp-content/uploads does not exist"
  UP_FILE="$WORK/$TARGET-uploads-$TS.tar.gz"
  rc=0
  tar czf "$UP_FILE" -C "$DOCROOT/wp-content" uploads 2>>"$LOG_FILE" || rc=$?
  [ "$rc" -le 1 ] || fail "tar failed (exit $rc)"   # 1 = a file changed while reading; the archive is still usable
  gzip -t "$UP_FILE" || fail "uploads archive is not a valid gzip"
  FAIL_STAGE="uploads upload"
  ship "$UP_FILE" "$UP_KEY"
fi

FAIL_STAGE="done"
log "BACKUP OK $TARGET $TS"
echo "BACKUP OK $TARGET $TS"
