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
  local h; h="$(curl -s -D - -o /dev/null --max-time 20 "$BASE/" | tr -d '\r')"
  grep -qi '^X-Content-Type-Options: nosniff' <<<"$h" || return 1
  grep -qi '^Referrer-Policy: strict-origin-when-cross-origin' <<<"$h" || return 1
  local csp; csp="$(grep -i '^Content-Security-Policy:' <<<"$h")"
  grep -q 'https://www.googletagmanager.com' <<<"$csp" || return 1
  ! grep -q 'fonts.googleapis.com' <<<"$csp" || return 1
  if ! is_local; then grep -qi '^Strict-Transport-Security:' <<<"$h" || return 1; fi
}
sample_post_path() {
  wp eval '
    $p = get_posts(["title" => "Codespark QA sample", "post_status" => "publish", "numberposts" => 1]);
    $id = $p ? $p[0]->ID : wp_insert_post(["post_title" => "Codespark QA sample", "post_content" => "Sample body.", "post_status" => "publish"]);
    echo wp_make_link_relative(get_permalink($id));' | tail -n1 | tr -d '\r'
}
sample_post_id() {
  wp eval '$p = get_posts(["title" => "Codespark QA sample", "post_status" => "publish", "numberposts" => 1]); echo $p ? $p[0]->ID : 0;' | tail -n1 | tr -d '\r'
}
no_comment_form() {
  local path html
  path="$(sample_post_path)"
  [ -n "$path" ] || return 1
  html="$(curl -s --max-time 20 "$BASE$path")"
  [ -n "$html" ] && ! grep -q 'id="respond"' <<<"$html" && ! grep -qi '<form[^>]*comment' <<<"$html"
}
pingback_header_absent() {
  local path; path="$(sample_post_path)"
  [ -n "$path" ] || return 1
  curl -s -o /dev/null -D - --max-time 20 "$BASE$path" | grep -qi '^HTTP/.* 200' || return 1
  ! curl -s -o /dev/null -D - --max-time 20 "$BASE$path" | grep -qi '^X-Pingback'
}
trackback_closed() {
  local path; path="$(sample_post_path)"
  [ -n "$path" ] || return 1
  post_is_blocked /wp-trackback.php && post_is_blocked "${path}trackback/"
}
users_sitemap_gone() {
  [ "$(http_status /wp-sitemap-users-1.xml)" = 404 ] && ! curl -s --max-time 20 "$BASE/wp-sitemap.xml" | grep -q 'users'
}
rest_embed_no_author() {
  local id body
  id="$(sample_post_id)"
  [ "${id:-0}" != 0 ] || return 1
  body="$(curl -s --max-time 20 "$BASE/wp-json/wp/v2/posts/$id?_embed")"
  grep -q '"id":' <<<"$body" || return 1
  ! grep -q '"_embedded":{"author"' <<<"$body" && ! grep -q 'mf_codespark_qa' <<<"$body"
}
file_edit_disabled() { [ "$(wp eval 'echo (int) DISALLOW_FILE_EDIT;' | tail -n1 | tr -d '\r')" = 1 ]; }
redirect_hook_live() {
  local f=wp-content/mu-plugins/zz-codespark-qa-redirect.php loc code
  printf '%s\n' '<?php' "add_filter('mf_redirects_extra', static fn (\$m) => \$m + ['/codespark-qa-old/' => '/']);" > "$f"
  code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$BASE/Codespark-QA-Old?utm_source=qa")"
  loc="$(curl -s -o /dev/null -w '%{redirect_url}' --max-time 20 "$BASE/codespark-qa-old/")"
  rm -f "$f"
  [ "$code" = 301 ] && [ "$loc" = "$BASE/" ]
}

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
check_local pingback_header_absent pingback_header_absent
check_local trackback_closed       trackback_closed
check       users_sitemap_gone     users_sitemap_gone
check_local rest_embed_no_author   rest_embed_no_author
check_local file_edit_disabled     file_edit_disabled
check_local redirect_hook_live      redirect_hook_live

# ---- summary ----
echo "Summary: $PASSES passed, $FAILS failed, $SKIPS skipped"
[ "$FAILS" -eq 0 ]
