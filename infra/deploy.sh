#!/usr/bin/env bash
# Deploy a PUSHED commit of the blog to staging or LIVE.
#
#   infra/deploy.sh [--dry-run] <staging|live> <sha>
#
# Ships only wp-content/mu-plugins, wp-content/themes/moneyfrog, composer.json and
# composer.lock (via `git archive`), installs the Composer plugins on the server, then
# syncs ONLY these three directories into the target docroot:
#   wp-content/mu-plugins/   wp-content/themes/moneyfrog/   wp-content/plugins/two-factor/
# Uploads, wp-config.php and WordPress core are never touched.
#
# Gates: clean working tree; staging sha reachable from DEPLOY_ALLOWED_REFS; LIVE sha
# reachable from origin/main ONLY. DEPLOYED is printed only when the server reports the
# sentinel "MF_REMOTE_OK <full sha>" read back from wp-content/.mf-release.
#
# All server details come from the git-ignored infra/.env.deploy (see
# infra/.env.deploy.example). Nothing secret is ever printed.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="${DEPLOY_ENV_FILE:-$SCRIPT_DIR/.env.deploy}"

# Staging: a sha must be reachable from one of these remote refs (drop feat/blog-build after it merges).
STAGING_ALLOWED_REFS="${DEPLOY_ALLOWED_REFS:-origin/main origin/feat/blog-build}"
# LIVE: main only. Deliberately not overridable.
LIVE_ALLOWED_REFS="origin/main"
KEEP_RELEASES=5
SHIP_PATHS=(wp-content/mu-plugins wp-content/themes/moneyfrog composer.json composer.lock)

die() { echo "ERROR: $*" >&2; exit 1; }
say() { echo "[deploy] $*"; }

DRY_RUN=0
POS=()
for a in "$@"; do
  case "$a" in
    --dry-run) DRY_RUN=1 ;;
    -h|--help) sed -n '2,16p' "${BASH_SOURCE[0]}"; exit 0 ;;
    -*) die "unknown option: $a" ;;
    *) POS+=("$a") ;;
  esac
done
[ "${#POS[@]}" -eq 2 ] || die "usage: infra/deploy.sh [--dry-run] <staging|live> <sha>"
TARGET="${POS[0]}"
SHA_IN="${POS[1]}"
case "$TARGET" in staging|live) ;; *) die "target must be 'staging' or 'live', got '$TARGET'" ;; esac

# ---- config (never echo values other than non-secret target names) ----
[ -f "$ENV_FILE" ] || die "missing $ENV_FILE (copy infra/.env.deploy.example and fill it in)"
set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a
: "${DEPLOY_PORT:=22}"
REMOTE_PATH_PREFIX="${REMOTE_PATH_PREFIX:-}"   # optional dir holding the PHP 8.3 CLI on the server
WP_BIN="${WP_BIN:-wp}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
# Host key policy: 'yes' (default) requires the key to be pinned in known_hosts first (see provision.md).
STRICT_HOST_KEY="${SSH_STRICT_HOST_KEY_CHECKING:-yes}"
case "$STRICT_HOST_KEY" in yes|accept-new|no|ask) ;; *) die "SSH_STRICT_HOST_KEY_CHECKING must be yes, accept-new, ask or no" ;; esac
if [ "$TARGET" = staging ]; then
  ROOT="${STAGING_ROOT:-}"; URL="${STAGING_URL:-}"; ALLOWED_REFS="$STAGING_ALLOWED_REFS"
else
  ROOT="${LIVE_ROOT:-}"; URL="${LIVE_URL:-}"; ALLOWED_REFS="$LIVE_ALLOWED_REFS"
fi
for v in DEPLOY_HOST DEPLOY_USER; do
  [ -n "${!v:-}" ] || die "$v is not set in $ENV_FILE"
done
[ -n "$ROOT" ] || die "$( [ "$TARGET" = staging ] && echo STAGING_ROOT || echo LIVE_ROOT ) is not set in $ENV_FILE"

# ---- git gates: clean tree, sha on an allowed remote ref ----
cd "$REPO_DIR"
[ -z "$(git status --porcelain)" ] || die "working tree is not clean; commit or stash first"
if ! git fetch origin --quiet; then
  if [ "$DRY_RUN" -eq 1 ]; then say "warning: git fetch failed (dry run continues with the last known remote refs)"; else die "git fetch origin failed; cannot verify the sha is on the remote"; fi
fi
SHA="$(git rev-parse --verify --quiet "${SHA_IN}^{commit}")" || die "unknown commit: $SHA_IN"
ON_REF=""
for ref in $ALLOWED_REFS; do
  if git rev-parse --verify --quiet "$ref" >/dev/null && git merge-base --is-ancestor "$SHA" "$ref"; then ON_REF="$ref"; break; fi
done
[ -n "$ON_REF" ] || die "$SHA is not reachable from any of: $ALLOWED_REFS (push/merge it first)"

SHORT="${SHA:0:12}"
STAMP="$(date -u +%Y%m%d%H%M%S)"
REL_NAME="$TARGET-$SHORT-$STAMP"
REL_BASE="$ROOT/../releases"
TARBALL="$REL_BASE/$REL_NAME.tar.gz"

if [ "$DRY_RUN" -eq 1 ]; then
  cat <<PLAN
[deploy] DRY RUN plan. No server is contacted and nothing is deployed
         (the only network call made was 'git fetch origin' to refresh the remote refs).
  target       : $TARGET ${URL:+($URL)}
  commit       : $SHA (on $ON_REF)
  allowed refs : $ALLOWED_REFS
  host key     : StrictHostKeyChecking=$STRICT_HOST_KEY
  ship paths   : ${SHIP_PATHS[*]}
  1. check the server: $ROOT/wp-content and wp-config.php (in or above the docroot) exist
  2. git archive $SHORT -> tar.gz; upload to $REL_BASE/ (size and sha256 verified)
  3. on the server, under flock $REL_BASE/.deploy.lock: extract into $REL_BASE/$REL_NAME
  4. $COMPOSER_BIN install --no-dev --no-interaction; php -l on every PHP file of the release
  5. rsync --dry-run --itemize-changes --delete for the three targets; planned deletions printed
  6. apply (rsync -a --delete --delay-updates), ONLY:
       wp-content/mu-plugins/   wp-content/themes/moneyfrog/   wp-content/plugins/two-factor/
     any failure -> all three re-synced from the previous release; exit non-zero; .mf-release untouched
  7. verify the targets match the release, $WP_BIN cache flush, $WP_BIN rewrite flush
  8. write $ROOT/wp-content/.mf-release = $SHA, keep the newest $KEEP_RELEASES '$TARGET-*' releases
  9. print DEPLOYED $SHA $TARGET only if the server answers: MF_REMOTE_OK $SHA
PLAN
  exit 0
fi

command -v ssh >/dev/null || die "ssh not found"

# ---- ssh plumbing (password auth; optional SSH_ASKPASS helper script) ----
SSH_OPTS=(-p "$DEPLOY_PORT" -o BatchMode=no -o "StrictHostKeyChecking=$STRICT_HOST_KEY" -o ConnectTimeout=20)
if [ -n "${SSH_ASKPASS_SCRIPT:-}" ]; then
  [ -f "$SSH_ASKPASS_SCRIPT" ] || die "SSH_ASKPASS_SCRIPT does not exist"
  export SSH_ASKPASS="$SSH_ASKPASS_SCRIPT" SSH_ASKPASS_REQUIRE=force DISPLAY="${DISPLAY:-mf-deploy}"
fi
DEST="$DEPLOY_USER@$DEPLOY_HOST"
rssh() { ssh "${SSH_OPTS[@]}" "$DEST" "$@"; }
q() { printf '%q' "$1"; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# ---- 0. validate the server target BEFORE creating or uploading anything ----
say "checking the $TARGET docroot"
rssh "test -d $(q "$ROOT/wp-content") && { test -f $(q "$ROOT/wp-config.php") || test -f $(q "$ROOT/../wp-config.php"); }" </dev/null \
  || die "the $TARGET docroot is not a WordPress install (no wp-content, or no wp-config.php in or above it); check ${TARGET^^}_ROOT"

# ---- 1. build the archive ----
say "archiving $SHORT"
git archive --format=tar "$SHA" -- "${SHIP_PATHS[@]}" | gzip -9 > "$WORK/release.tar.gz" || die "git archive failed"
[ -s "$WORK/release.tar.gz" ] || die "empty archive"
LOCAL_SUM="$(sha256sum "$WORK/release.tar.gz" | cut -d' ' -f1)"

# ---- 2. upload (over ssh stdin, so spaces in remote paths are safe) ----
say "uploading to $TARGET"
rssh "mkdir -p $(q "$REL_BASE") && cat > $(q "$TARBALL")" < "$WORK/release.tar.gz" || die "upload failed"
REMOTE_SUM="$(rssh "sha256sum $(q "$TARBALL")" </dev/null | cut -d' ' -f1)" || die "could not checksum the upload"
[ "$REMOTE_SUM" = "$LOCAL_SUM" ] || die "uploaded archive checksum mismatch"

# ---- 3-8. remote install ----
say "installing on the server"
REMOTE_SCRIPT='
set -euo pipefail
ROOT=$1; REL_BASE=$2; REL_NAME=$3; SHA=$4; TARGET=$5; KEEP=$6; PREFIX=$7; WP_BIN=$8; COMPOSER_BIN=$9
[ -z "$PREFIX" ] || export PATH="$PREFIX:$PATH"
REL_DIR="$REL_BASE/$REL_NAME"
DIRS="wp-content/mu-plugins wp-content/themes/moneyfrog wp-content/plugins/two-factor"
RELFILE="$ROOT/wp-content/.mf-release"

[ -d "$ROOT/wp-content" ] || { echo "target docroot has no wp-content: refusing" >&2; exit 11; }
[ -f "$ROOT/wp-config.php" ] || [ -f "$ROOT/../wp-config.php" ] || { echo "no wp-config.php: refusing" >&2; exit 11; }
command -v flock >/dev/null || { echo "flock is not available on the server" >&2; exit 20; }
exec 9>"$REL_BASE/.deploy.lock"
flock -n 9 || { echo "another deploy is already running (lock $REL_BASE/.deploy.lock)" >&2; exit 20; }

PREV_SHA=""; PREV_DIR=""
if [ -f "$RELFILE" ]; then
  p=$(tr -d "[:space:]" < "$RELFILE")
  if printf "%s" "$p" | grep -Eq "^[0-9a-f]{40}$"; then
    PREV_SHA=$p
    PREV_DIR=$(ls -1dt "$REL_BASE/$TARGET-${p:0:12}"-*/ 2>/dev/null | head -n 1 || true)
    PREV_DIR=${PREV_DIR%/}
  fi
fi
echo "previous release: ${PREV_SHA:-none}${PREV_DIR:+ ($PREV_DIR)}"

mkdir -p "$REL_DIR"
tar -xzf "$REL_BASE/$REL_NAME.tar.gz" -C "$REL_DIR" </dev/null
rm -f "$REL_BASE/$REL_NAME.tar.gz"
cd "$REL_DIR"
php -r "exit(PHP_VERSION_ID >= 80300 ? 0 : 1);" </dev/null || { echo "the server CLI php is older than 8.3 (set REMOTE_PATH_PREFIX)" >&2; exit 12; }
$COMPOSER_BIN install --no-dev --no-interaction --no-progress </dev/null
[ -d wp-content/plugins/two-factor ] || { echo "two-factor was not installed by composer" >&2; exit 12; }

echo "== php -l on the release =="
n=0
while IFS= read -r -d "" f; do
  php -l "$f" >/dev/null </dev/null || { echo "PHP syntax error in $f" >&2; exit 13; }
  n=$((n + 1))
done < <(find wp-content/mu-plugins wp-content/themes/moneyfrog wp-content/plugins/two-factor -name "*.php" -print0)
echo "linted $n files"

echo "== planned changes (rsync dry run) =="
for d in $DIRS; do
  echo "-- $d"
  if [ -d "$ROOT/$d" ]; then
    rsync -a --delete --dry-run --itemize-changes "$REL_DIR/$d/" "$ROOT/$d/" </dev/null || { echo "rsync dry run failed for $d" >&2; exit 14; }
  else
    echo "(new directory)"
  fi
done
echo "== planned deletions =="
for d in $DIRS; do
  if [ -d "$ROOT/$d" ]; then
    rsync -a --delete --dry-run --itemize-changes "$REL_DIR/$d/" "$ROOT/$d/" </dev/null | grep "^\*deleting" | sed "s#^#$d: #" || true
  fi
done

sync_from() { # $1 = source release dir; stops at the first failure
  local d
  for d in $DIRS; do
    mkdir -p "$ROOT/$d" || return 1
    rsync -a --delete --delay-updates "$1/$d/" "$ROOT/$d/" </dev/null || return 1
  done
}
verify_matches() { # every target equals the release
  local d out
  for d in $DIRS; do
    out=$(rsync -a --delete --dry-run --itemize-changes --omit-dir-times "$REL_DIR/$d/" "$ROOT/$d/" </dev/null) || return 1
    if printf "%s\n" "$out" | grep -Eq "^(\*deleting|[<>ch.*]f)"; then echo "drift after sync in $d" >&2; return 1; fi
  done
}
flush_caches() {
  $WP_BIN --path="$ROOT" cache flush </dev/null || return 1
  $WP_BIN --path="$ROOT" rewrite flush </dev/null || return 1
}
apply_all() {
  sync_from "$REL_DIR" || return 1
  verify_matches || return 1
  flush_caches || return 1
}

echo "== applying =="
if ! apply_all; then
  echo "apply FAILED" >&2
  if [ -n "$PREV_DIR" ] && [ -d "$PREV_DIR" ]; then
    if sync_from "$PREV_DIR"; then
      flush_caches || true
      echo "rolled back to $PREV_SHA (.mf-release untouched)" >&2
      exit 30
    fi
    echo "ROLLBACK FAILED: the docroot is in a mixed state; re-sync from $PREV_DIR by hand" >&2
    exit 32
  fi
  echo "no previous release to roll back to; the docroot may be partly updated" >&2
  exit 31
fi

printf "%s\n" "$SHA" > "$RELFILE.tmp"
mv -f "$RELFILE.tmp" "$RELFILE"

cd "$REL_BASE"
ls -1dt "$TARGET"-*/ 2>/dev/null | tail -n +$((KEEP + 1)) | while read -r old; do rm -rf -- "${old%/}"; done || true
echo "MF_REMOTE_OK $(tr -d "[:space:]" < "$RELFILE")"
'
rssh "bash -s -- $(printf '%q ' "$ROOT" "$REL_BASE" "$REL_NAME" "$SHA" "$TARGET" "$KEEP_RELEASES" "$REMOTE_PATH_PREFIX" "$WP_BIN" "$COMPOSER_BIN")" <<<"$REMOTE_SCRIPT" 2>&1 | tee "$WORK/remote.log" \
  || die "remote install failed (see the output above; a failed apply is rolled back to the previous release)"

grep -qxF "MF_REMOTE_OK $SHA" "$WORK/remote.log" \
  || die "the server did not confirm release $SHA (no MF_REMOTE_OK sentinel); NOT deployed"

echo "DEPLOYED $SHA $TARGET"
[ -z "$URL" ] || say "verify: EXPECT_SHA=$SHA SMOKE_SAMPLE_POST=/<post>/ tests/smoke/smoke.sh $URL"
