#!/usr/bin/env bash
# Back up the blog (database daily, uploads weekly) to S3. Runs ON the server from cron.
#
#   infra/backup.sh [--dry-run] [--weekly] <staging|live>
#
# Reads the git-ignored infra/.env.backup (override: BACKUP_ENV_FILE); see
# infra/.env.backup.example. The file must be chmod 600 (the run is refused otherwise).
# Objects (all server-side encrypted, each with a .sha256 sidecar):
#   <S3_PREFIX>/<env>/db/YYYY/MM/<env>-db-<UTC timestamp>.sql.gz         every run
#   <S3_PREFIX>/<env>/uploads/<env>-uploads-<UTC timestamp>.tar.gz       Sundays (IST) or --weekly
# The IAM user is write-only, so retention (30 daily DB, 8 weekly uploads) is an S3 lifecycle
# rule, never a delete from this script. See infra/restore.md.
#
# Failures (including a missing or unreadable env file) are written to the log and trigger an
# alert email to ALERT_EMAIL, falling back to $ALERT_EMAIL from the environment and then to the
# cPanel contact address in ~/.contactemail. Alerts and logs hold no secrets.
# S3 keys reach infra/s3put.py through its process environment only (never argv). The DB
# password never leaves WP-CLI.
# Exit: 0 ok, 2 config error, 3 another run in progress, 1 any other failure.
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
    -h|--help) sed -n '2,19p' "${BASH_SOURCE[0]}"; exit 0 ;;
    -*) echo "ERROR: unknown option: $a" >&2; exit 2 ;;
    *) [ -z "$TARGET" ] || { echo "ERROR: one environment only" >&2; exit 2; }; TARGET="$a" ;;
  esac
done
case "$TARGET" in staging|live) ;; *) echo "usage: infra/backup.sh [--dry-run] [--weekly] <staging|live>" >&2; exit 2 ;; esac

# cron has a minimal PATH; REMOTE_PATH_PREFIX (the PHP 8.3 dir) is prepended after the env file loads.
export PATH="/usr/local/bin:/usr/bin:/bin:${PATH:-}"

# ---- logging, alerting and cleanup are armed BEFORE any config is read ----
LOG_FILE="${BACKUP_LOG:-$HOME/logs/mf-backup.log}"
ALERT_FALLBACK="${ALERT_EMAIL:-}"
if [ -z "$ALERT_FALLBACK" ] && [ -r "$HOME/.contactemail" ]; then ALERT_FALLBACK="$(head -n 1 "$HOME/.contactemail" | tr -d '[:space:]')"; fi
ALERT_EMAIL="$ALERT_FALLBACK"

FAIL_STAGE="startup"
LOCKED=0
WORK=""
DB_STATUS="not attempted"
UP_STATUS="not attempted"

mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true
if [ -f "$LOG_FILE" ] && [ "$(wc -c < "$LOG_FILE")" -gt "$LOG_MAX_BYTES" ]; then mv -f "$LOG_FILE" "$LOG_FILE.1"; fi
log() { printf '%s [%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$TARGET" "$*" >> "$LOG_FILE" 2>/dev/null || true; printf '[backup] %s\n' "$*"; }

send_alert() { # $1 = headline word (FAILED|PARTIAL), $2 = exit code. No secrets in subject or body.
  [ -n "${ALERT_EMAIL:-}" ] || { log "no ALERT_EMAIL (or ~/.contactemail) available; alert not sent"; return 0; }
  local subject="Blog backup $1 ($TARGET)" body
  body="The $TARGET blog backup run ended with $1 (exit code $2) at stage '$FAIL_STAGE', at $(date -u +%Y-%m-%dT%H:%M:%SZ).
Database backup: $DB_STATUS
Uploads backup : $UP_STATUS
See the backup log on the server for details."
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
  if [ "$rc" -ne 0 ] && [ "$DRY_RUN" -eq 0 ]; then
    if [ "$rc" -eq 3 ]; then
      log "not run: another backup holds the lock"
    else
      log "FAILED at stage '$FAIL_STAGE' (exit $rc); db: $DB_STATUS; uploads: $UP_STATUS"
      send_alert FAILED "$rc"
    fi
  fi
  exit "$rc"
}
trap on_exit EXIT

fail() { log "ERROR: $*"; exit 1; }
cfgfail() { log "CONFIG ERROR: $*"; exit 2; }

# ---- config ----
FAIL_STAGE="config"
[ -f "$ENV_FILE" ] || cfgfail "env file is missing: $ENV_FILE (copy infra/.env.backup.example)"
[ -r "$ENV_FILE" ] || cfgfail "env file is not readable: $ENV_FILE"
if [ -n "$(find "$ENV_FILE" -perm /077 2>/dev/null)" ]; then
  cfgfail "env file is readable by group/others; run: chmod 600 $ENV_FILE (refusing to run)"
fi
# shellcheck disable=SC1090
source "$ENV_FILE" || cfgfail "env file could not be loaded: $ENV_FILE"
[ -z "${REMOTE_PATH_PREFIX:-}" ] || export PATH="$REMOTE_PATH_PREFIX:$PATH"
ALERT_EMAIL="${ALERT_EMAIL:-$ALERT_FALLBACK}"
[ -z "${BACKUP_LOG:-}" ] || [ "$BACKUP_LOG" = "$LOG_FILE" ] || { LOG_FILE="$BACKUP_LOG"; mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true; }

: "${BACKUP_ENV:=$TARGET}"
[ "$BACKUP_ENV" = "$TARGET" ] || cfgfail "$ENV_FILE is for '$BACKUP_ENV', not '$TARGET'"
S3_PREFIX="${S3_PREFIX:-blog-backups}"; S3_PREFIX="${S3_PREFIX%/}"
AWS_REGION="${AWS_REGION:-ap-south-1}"
WP_BIN="${WP_BIN:-wp}"
PYTHON_BIN="${PYTHON_BIN:-python3}"
LOCK_FILE="${BACKUP_LOCK:-$HOME/.mf-backup-$TARGET.lock}"
S3PUT="$SCRIPT_DIR/s3put.py"

for v in DOCROOT S3_BUCKET AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY; do
  [ -n "${!v:-}" ] || cfgfail "$v is not set in $ENV_FILE"
done
[ -d "$DOCROOT/wp-content" ] || cfgfail "DOCROOT has no wp-content"
[ -f "$S3PUT" ] || cfgfail "missing $S3PUT"
read -r -a WPCMD <<<"$WP_BIN"

TS="$(date -u +%Y%m%dT%H%M%SZ)"           # one date call: timestamp, year and month all derive from it
YEAR="${TS:0:4}"; MONTH="${TS:4:2}"
IST_DOW="$(date -u -d '+5 hours 30 minutes' +%u)"   # 7 = Sunday in IST, independent of the server timezone
WEEKLY=0
{ [ "$FORCE_WEEKLY" -eq 1 ] || [ "$IST_DOW" = 7 ]; } && WEEKLY=1
DB_KEY="$S3_PREFIX/$TARGET/db/$YEAR/$MONTH/$TARGET-db-$TS.sql.gz"
UP_KEY="$S3_PREFIX/$TARGET/uploads/$TARGET-uploads-$TS.tar.gz"
[ "$WEEKLY" -eq 1 ] || UP_STATUS="not due (not Sunday IST)"

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
  alerts      : $([ -n "${ALERT_EMAIL:-}" ] && echo "email on failure" || echo "none (no ALERT_EMAIL and no ~/.contactemail)")
PLAN
  exit 0
fi

# ---- single instance ----
FAIL_STAGE="lock"
command -v flock >/dev/null || cfgfail "flock is not available"
exec 9>"$LOCK_FILE"
flock -n 9 || exit 3
LOCKED=1

FAIL_STAGE="setup"
WORK="$(mktemp -d "${TMPDIR:-$HOME}/.mf-backup.XXXXXXXX")"
chmod 700 "$WORK"
log "start (weekly=$WEEKLY, ts=$TS)"

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
  upload "$f" "$k" || return 1
  upload "$f.sha256" "$k.sha256" || return 1
  log "uploaded $k ($(wc -c < "$f") bytes, sha256 $sum)"
}

# ---- database ----
FAIL_STAGE="database dump"
DB_STATUS="FAILED (dump)"
DB_FILE="$WORK/$TARGET-db-$TS.sql.gz"
"${WPCMD[@]}" --path="$DOCROOT" db export - --single-transaction 2>>"$LOG_FILE" | gzip -c > "$DB_FILE" \
  || fail "database export failed"
gzip -t "$DB_FILE" || fail "database dump is not a valid gzip"
[ "$(wc -c < "$DB_FILE")" -gt 1024 ] || fail "database dump is suspiciously small"
gzip -dc "$DB_FILE" | tail -n 5 | grep -q 'Dump completed' || fail "database dump is incomplete (no completion marker)"
FAIL_STAGE="database upload"
DB_STATUS="FAILED (upload)"
ship "$DB_FILE" "$DB_KEY" || fail "upload failed for $DB_KEY"
DB_STATUS="ok ($DB_KEY)"
rm -f "$DB_FILE" "$DB_FILE.sha256"

# ---- uploads (weekly) ----
if [ "$WEEKLY" -eq 1 ]; then
  FAIL_STAGE="uploads archive"
  UP_STATUS="FAILED (archive)"
  [ -d "$DOCROOT/wp-content/uploads" ] || fail "wp-content/uploads does not exist"
  need_kb="$(du -sk "$DOCROOT/wp-content/uploads" | cut -f1)"
  have_kb="$(df -Pk "$WORK" | awk 'NR==2 {print $4}')"
  if [ "$have_kb" -lt $(( need_kb * 12 / 10 )) ]; then
    UP_STATUS="SKIPPED (not enough free disk: need ~$(( need_kb * 12 / 10 / 1024 )) MB, have $(( have_kb / 1024 )) MB)"
    FAIL_STAGE="uploads archive (skipped)"
    log "WARNING: uploads backup skipped: $UP_STATUS"
    send_alert PARTIAL 0
    log "BACKUP PARTIAL $TARGET $TS (database ok, uploads skipped)"
    echo "BACKUP PARTIAL $TARGET $TS"
    exit 0
  fi
  UP_FILE="$WORK/$TARGET-uploads-$TS.tar.gz"
  rc=0
  tar czf "$UP_FILE" -C "$DOCROOT/wp-content" uploads 2>>"$LOG_FILE" || rc=$?
  [ "$rc" -le 1 ] || fail "tar failed (exit $rc)"   # 1 = a file changed while reading; the archive is still usable
  gzip -t "$UP_FILE" || fail "uploads archive is not a valid gzip"
  FAIL_STAGE="uploads upload"
  UP_STATUS="FAILED (upload)"
  ship "$UP_FILE" "$UP_KEY" || fail "upload failed for $UP_KEY"
  UP_STATUS="ok ($UP_KEY)"
fi

FAIL_STAGE="done"
log "BACKUP OK $TARGET $TS"
echo "BACKUP OK $TARGET $TS"
