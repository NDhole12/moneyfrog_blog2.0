#!/usr/bin/env bash
# Smoke checks for the Moneyfrog blog. Usage: tests/smoke/smoke.sh <base-url>
# Each check prints exactly "PASS <name>" or "FAIL <name>"; local-only checks
# print "SKIP <name>" when the base URL is not localhost.
BASE="${1:-http://localhost:8080}"
BASE="${BASE%/}"
FAILS=0
PASSES=0
SKIPS=0

is_local() {
  [[ "$BASE" =~ ^http://(localhost|127\.0\.0\.1)(:[0-9]+)?$ ]]
}

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

post_status() { curl -s -o /dev/null -w '%{http_code}' --max-time 20 -X POST "$BASE$1"; }
post_is_blocked() { local c; c="$(post_status "$1")"; [ "$c" = 403 ] || [ "$c" = 404 ]; }
users_rest_hidden() {
  local code body
  code="$(http_status /wp-json/wp/v2/users)"
  body="$(curl -s --max-time 20 "$BASE/wp-json/wp/v2/users")"
  { [ "$code" = 401 ] || [ "$code" = 404 ]; } && ! grep -q '"slug"' <<<"$body"
}
author_enum_blocked() {
  local c; c="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$BASE/?author=1")"
  [ "$c" = 403 ] || [ "$c" = 404 ]
}
headers_ok() {
  local h; h="$(curl -s -D - -o /dev/null --max-time 20 "$BASE/" | tr -d '')"
  grep -qi '^X-Content-Type-Options: nosniff' <<<"$h" || return 1
  grep -qi '^Referrer-Policy: strict-origin-when-cross-origin' <<<"$h" || return 1
  local csp; csp="$(grep -i '^Content-Security-Policy:' <<<"$h")"
  grep -q 'https://www.googletagmanager.com' <<<"$csp" || return 1
  ! grep -q 'fonts.googleapis.com' <<<"$csp" || return 1
  if ! is_local; then grep -qi '^Strict-Transport-Security:' <<<"$h" || return 1; fi
}
no_comment_form() {
  local url html
  url="$(wp eval '
    $p = get_posts(["title" => "Codespark QA sample", "post_status" => "publish", "numberposts" => 1]);
    $id = $p ? $p[0]->ID : wp_insert_post(["post_title" => "Codespark QA sample", "post_content" => "Sample body.", "post_status" => "publish"]);
    echo get_permalink($id);' | tail -n1 | tr -d '')"
  [ -n "$url" ] || return 1
  # permalink is built from the site URL; fetch via BASE regardless of the stored host
  html="$(curl -s --max-time 20 "$BASE/$(sed -E 's#^https?://[^/]+/##' <<<"$url")")"
  [ -n "$html" ] && ! grep -q 'id="respond"' <<<"$html" && ! grep -qi '<form[^>]*comment' <<<"$html"
}
file_edit_disabled() { [ "$(wp eval 'echo (int) DISALLOW_FILE_EDIT;' | tail -n1 | tr -d '')" = 1 ]; }

# ---- checks ----
check       home_200         status_is / 200
check_local php_8_3          php_version_ok
check       mu_plugin_loaded header_has /wp-json/ '^X-MF-Core: 1'
check       xmlrpc_closed          post_is_blocked /xmlrpc.php
check       comments_post_closed   post_is_blocked /wp-comments-post.php
check       comment_feed_gone      status_is /comments/feed/ 404
check       users_rest_hidden      users_rest_hidden
check       author_enum_blocked    author_enum_blocked
check       headers                headers_ok
check_local no_comment_form        no_comment_form
check       pingback_header_absent bash -c '! curl -s -D - -o /dev/null --max-time 20 "$0" | grep -qi "^X-Pingback"' "$BASE/"
check_local file_edit_disabled     file_edit_disabled

# ---- summary ----
echo "Summary: $PASSES passed, $FAILS failed, $SKIPS skipped"
[ "$FAILS" -eq 0 ]
