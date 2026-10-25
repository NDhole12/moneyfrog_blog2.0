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
# All server details come from the git-ignored infra/.env.deploy (see
# infra/.env.deploy.example). Nothing secret is ever printed.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="${DEPLOY_ENV_FILE:-$SCRIPT_DIR/.env.deploy}"

# A sha must be reachable from one of these remote refs (drop feat/blog-build after it merges).
ALLOWED_REFS="${DEPLOY_ALLOWED_REFS:-origin/main origin/feat/blog-build}"
KEEP_RELEASES=5
SHIP_PATHS=(wp-content/mu-plugins wp-content/themes/moneyfrog composer.json composer.lock)

die() { echo "ERROR: $*" >&2; exit 1; }
say() { echo "[deploy] $*"; }

DRY_RUN=0
POS=()
for a in "$@"; do
  case "$a" in
    --dry-run) DRY_RUN=1 ;;
    -h|--help) sed -n '2,13p' "${BASH_SOURCE[0]}"; exit 0 ;;
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
if [ "$TARGET" = staging ]; then ROOT="${STAGING_ROOT:-}"; URL="${STAGING_URL:-}"; else ROOT="${LIVE_ROOT:-}"; URL="${LIVE_URL:-}"; fi
for v in DEPLOY_HOST DEPLOY_USER; do
  [ -n "${!v:-}" ] || die "$v is not set in $ENV_FILE"
done
[ -n "$ROOT" ] || die "$( [ "$TARGET" = staging ] && echo STAGING_ROOT || echo LIVE_ROOT ) is not set in $ENV_FILE"

# ---- git gates: clean tree, sha on an allowed remote ref ----
cd "$REPO_DIR"
[ -z "$(git status --porcelain)" ] || die "working tree is not clean; commit or stash first"
if ! git fetch origin --quiet; then
  [ "$DRY_RUN" -eq 1 ] && say "warning: git fetch failed (dry run continues)" || die "git fetch origin failed; cannot verify the sha is on the remote"
fi
SHA="$(git rev-parse --verify --quiet "${SHA_IN}^{commit}")" || die "unknown commit: $SHA_IN"
ON_REF=""
for ref in $ALLOWED_REFS; do
  if git rev-parse --verify --quiet "$ref" >/dev/null && git merge-base --is-ancestor "$SHA" "$ref"; then ON_REF="$ref"; break; fi
done
[ -n "$ON_REF" ] || die "$SHA is not reachable from any of: $ALLOWED_REFS (push it first)"

SHORT="${SHA:0:12}"
STAMP="$(date -u +%Y%m%d%H%M%S)"
REL_NAME="$TARGET-$SHORT-$STAMP"
REL_BASE="$ROOT/../releases"
REL_DIR="$REL_BASE/$REL_NAME"
TARBALL="$REL_BASE/$REL_NAME.tar.gz"

if [ "$DRY_RUN" -eq 1 ]; then
  cat <<PLAN
[deploy] DRY RUN plan (nothing is contacted or changed)
  target       : $TARGET ${URL:+($URL)}
  commit       : $SHA (on $ON_REF)
  ship paths   : ${SHIP_PATHS[*]}
  1. git archive $SHORT -> tar.gz (local temp dir)
  2. scp tarball -> <server>:$REL_BASE/
  3. extract into <server>:$REL_DIR
  4. $COMPOSER_BIN install --no-dev --no-interaction (in the release dir; installs two-factor)
  5. rsync -a --delete --delay-updates ONLY:
       $REL_DIR/wp-content/mu-plugins/            -> $ROOT/wp-content/mu-plugins/
       $REL_DIR/wp-content/themes/moneyfrog/      -> $ROOT/wp-content/themes/moneyfrog/
       $REL_DIR/wp-content/plugins/two-factor/    -> $ROOT/wp-content/plugins/two-factor/
  6. write $ROOT/wp-content/.mf-release = $SHA
  7. $WP_BIN cache flush ; $WP_BIN rewrite flush (--path=$ROOT)
  8. keep the newest $KEEP_RELEASES '$TARGET-*' releases under $REL_BASE
  9. print: DEPLOYED $SHA $TARGET
PLAN
  exit 0
fi

command -v ssh >/dev/null && command -v scp >/dev/null || die "ssh/scp not found"

# ---- ssh plumbing (password auth; optional SSH_ASKPASS helper script) ----
SSH_OPTS=(-p "$DEPLOY_PORT" -o BatchMode=no -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20)
SCP_OPTS=(-P "$DEPLOY_PORT" -o BatchMode=no -o StrictHostKeyChecking=accept-new -o ConnectTimeout=20)
if [ -n "${SSH_ASKPASS_SCRIPT:-}" ]; then
  [ -f "$SSH_ASKPASS_SCRIPT" ] || die "SSH_ASKPASS_SCRIPT does not exist"
  export SSH_ASKPASS="$SSH_ASKPASS_SCRIPT" SSH_ASKPASS_REQUIRE=force DISPLAY="${DISPLAY:-mf-deploy}"
fi
DEST="$DEPLOY_USER@$DEPLOY_HOST"
rssh() { ssh "${SSH_OPTS[@]}" "$DEST" "$@"; }

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# ---- 1. build the archive ----
say "archiving $SHORT"
git archive --format=tar "$SHA" -- "${SHIP_PATHS[@]}" | gzip -9 > "$WORK/release.tar.gz" || die "git archive failed"
[ -s "$WORK/release.tar.gz" ] || die "empty archive"

# ---- 2. upload ----
say "uploading to $TARGET"
rssh "mkdir -p $(printf '%q' "$REL_BASE")" || die "could not create the releases dir on the server"
scp "${SCP_OPTS[@]}" "$WORK/release.tar.gz" "$DEST:$TARBALL" || die "upload failed"

# ---- 3-8. remote install ----
say "installing on the server"
REMOTE_SCRIPT='
set -euo pipefail
ROOT=$1; REL_BASE=$2; REL_NAME=$3; SHA=$4; TARGET=$5; KEEP=$6; PREFIX=$7; WP_BIN=$8; COMPOSER_BIN=$9
[ -z "$PREFIX" ] || export PATH="$PREFIX:$PATH"
REL_DIR="$REL_BASE/$REL_NAME"
[ -d "$ROOT/wp-content" ] || { echo "target docroot has no wp-content: refusing" >&2; exit 11; }
mkdir -p "$REL_DIR"
tar -xzf "$REL_BASE/$REL_NAME.tar.gz" -C "$REL_DIR"
cd "$REL_DIR"
$COMPOSER_BIN install --no-dev --no-interaction --no-progress
[ -d wp-content/plugins/two-factor ] || { echo "two-factor was not installed by composer" >&2; exit 12; }
for d in wp-content/mu-plugins wp-content/themes/moneyfrog wp-content/plugins/two-factor; do
  mkdir -p "$ROOT/$d"
  rsync -a --delete --delay-updates "$REL_DIR/$d/" "$ROOT/$d/"
done
printf "%s\n" "$SHA" > "$ROOT/wp-content/.mf-release.tmp"
mv -f "$ROOT/wp-content/.mf-release.tmp" "$ROOT/wp-content/.mf-release"
$WP_BIN --path="$ROOT" cache flush
$WP_BIN --path="$ROOT" rewrite flush
cd "$REL_BASE"
ls -1dt "$TARGET"-*/ 2>/dev/null | tail -n +$((KEEP + 1)) | while read -r old; do rm -rf -- "${old%/}" "${old%/}.tar.gz"; done
echo "remote ok"
'
rssh "bash -s -- $(printf '%q ' "$ROOT" "$REL_BASE" "$REL_NAME" "$SHA" "$TARGET" "$KEEP_RELEASES" "$REMOTE_PATH_PREFIX" "$WP_BIN" "$COMPOSER_BIN")" <<<"$REMOTE_SCRIPT" \
  || die "remote install failed (the docroot may be partly updated; re-run, or roll back from $REL_BASE)"

echo "DEPLOYED $SHA $TARGET"
[ -z "$URL" ] || say "verify: EXPECT_SHA=$SHA tests/smoke/smoke.sh $URL"
