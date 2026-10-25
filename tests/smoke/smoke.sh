#!/usr/bin/env bash
# Smoke checks for the Moneyfrog blog. Usage: tests/smoke/smoke.sh <base-url>
# Each check prints exactly "PASS <name>" or "FAIL <name>"; local-only checks
# print "SKIP <name>" when the base URL is not localhost.
BASE="${1:-http://localhost:8080}"
BASE="${BASE%/}"
FAILS=0
PASSES=0
SKIPS=0

is_local() { case "$BASE" in http://localhost*|http://127.0.0.1*) return 0 ;; *) return 1 ;; esac; }

check() {
  local name="$1"; shift
  if ( "$@" ) >/dev/null 2>&1; then
    echo "PASS $name"; PASSES=$((PASSES + 1))
  else
    echo "FAIL $name"; FAILS=$((FAILS + 1))
  fi
}

check_local() {
  local name="$1"; shift
  if is_local; then check "$name" "$@"; else echo "SKIP $name"; SKIPS=$((SKIPS + 1)); fi
}

# helpers
http_status() { curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$BASE$1"; }
status_is() { [ "$(http_status "$1")" = "$2" ]; }
header_has() { curl -s -D - -o /dev/null --max-time 20 "$BASE$1" | tr -d '\r' | grep -qi "$2"; }
wp() { docker compose run --rm -T wpcli wp "$@"; }
php_version_ok() { [[ "$(wp eval 'echo PHP_VERSION;' | tail -n1)" == 8.3* ]]; }

# ---- checks ----
check       home_200         status_is / 200
check_local php_8_3          php_version_ok
check       mu_plugin_loaded header_has /wp-json/ '^X-MF-Core: 1'

# ---- summary ----
echo "Summary: $PASSES passed, $FAILS failed, $SKIPS skipped"
[ "$FAILS" -eq 0 ]
