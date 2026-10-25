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
bom_variant_redirects() {
  local path code loc
  path="$(sample_post_path)"
  [ -n "$path" ] || return 1
  code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$BASE${path%/}%ef%bb%bf/")"
  loc="$(curl -s -o /dev/null -w '%{redirect_url}' --max-time 20 "$BASE${path%/}%ef%bb%bf/?utm_source=qa")"
  [ "$code" = 301 ] && [ "$loc" = "$BASE$path?utm_source=qa" ]
}
core_author_archive_hidden() {
  local id
  id="$(sample_post_id)"
  [ "${id:-0}" != 0 ] || return 1
  # Give the sample post to the admin login so the archive is not 404 merely for being empty.
  wp eval '$u = get_user_by("login", "mf_codespark_qa"); wp_update_post(["ID" => '"$id"', "post_author" => $u->ID]);' >/dev/null 2>&1 || return 1
  [ "$(http_status /author/mf_codespark_qa/)" = 404 ] && [ "$(http_status /wp-user/mf_codespark_qa/)" = 404 ]
}
byline_archive() {
  local id html
  id="$(sample_post_id)"
  [ "${id:-0}" != 0 ] || return 1
  wp eval '
    if (!term_exists("codespark-qa", "mf_byline")) { wp_insert_term("Codespark QA", "mf_byline", ["slug" => "codespark-qa"]); }
    wp_set_object_terms('"$id"', ["codespark-qa"], "mf_byline");' >/dev/null 2>&1 || return 1
  [ "$(http_status /author/codespark-qa/)" = 200 ] || return 1
  html="$(curl -s --max-time 20 "$BASE/author/codespark-qa/")"
  grep -q 'Codespark QA sample' <<<"$html"
}
byline_rest_no_user_data() {
  local body
  body="$(curl -s --max-time 20 "$BASE/wp-json/wp/v2/mf_byline")"
  grep -q '"slug":"codespark-qa"' <<<"$body" && ! grep -q 'mf_codespark_qa' <<<"$body" && ! grep -q '"author"' <<<"$body"
}

home_html() { curl -s --max-time 20 "$BASE/"; }
theme_active() { home_html | grep -q 'themes/moneyfrog/assets/css/tokens.css'; }
gtm_present() { [ "$(home_html | grep -o 'GTM-M9PS8WZT' | wc -l | tr -d ' ')" = 2 ]; }
no_google_fonts() { local h; h="$(home_html)"; [ -n "$h" ] && ! grep -qE 'fonts\.(googleapis|gstatic)\.com' <<<"$h"; }
nav_links_to_site() {
  local h; h="$(home_html)"
  grep -q 'href="https://moneyfrog.in/login"' <<<"$h" && grep -q 'href="https://moneyfrog.in/signup"' <<<"$h"
}
footer_registration() {
  home_html | grep -qF 'Moneyfrog Financial Services Private Limited | CIN: U66000MH2011PTC216613 | AMFI Registered Mutual Fund Distributor: ARN 94798 | IRDAI Registered Corporate Agent: CA0450'
}
not_found_themed() {
  [ "$(http_status /codespark-qa-missing/)" = 404 ] && curl -s --max-time 20 "$BASE/codespark-qa-missing/" | grep -q 'class="blog-bar"'
}

seed_fixtures() { bash tests/fixtures/seed.sh "$BASE" >/dev/null 2>&1; }
home_sections() {
  local h; h="$(home_html)"
  grep -q 'Codespark QA – Market Outlook: October 2026' <<<"$h" || return 1
  [ "$(grep -o 'class="card card--compact' <<<"$h" | wc -l | tr -d ' ')" = 4 ] || return 1
  grep -q 'Browse by topic' <<<"$h" || return 1
  [ "$(grep -o 'class="topic-tile" href="[^"]*/category/[a-z-]*/"' <<<"$h" | wc -l | tr -d ' ')" = 6 ]
}
cover_for_no_image() { home_html | grep -q 'class="mf-cover'; }
category_page() {
  local h
  [ "$(http_status /category/mutual-funds/)" = 200 ] || return 1
  h="$(curl -s --max-time 20 "$BASE/category/mutual-funds/")"
  grep -q 'articles' <<<"$h" && grep -q 'class="pagination"' <<<"$h" || return 1
  [ "$(http_status "/category/mutual-funds/?order=asc&tag=sip")" = 200 ] || return 1
  curl -s --max-time 20 "$BASE/category/mutual-funds/?order=asc" | grep -q 'class="pagination"'
}
search_works() {
  local h
  [ "$(http_status '/?s=SIP')" = 200 ] || return 1
  h="$(curl -s --max-time 20 "$BASE/?s=SIP")"
  grep -q 'Results for' <<<"$h" && grep -q 'SIP vs' <<<"$h"
}
newsletter_hidden() { ! home_html | grep -q 'type="email"'; }
advisor_cta() { home_html | tr '
' ' ' | grep -q 'class="advisor-cta".*href="https://moneyfrog.in/contact-us"'; }

# shellcheck source=tests/smoke/import-checks.sh
source "$(dirname "$0")/import-checks.sh"

# ---- checks ----
is_local && seed_fixtures
is_local && import_scenario
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
check_local bom_variant_redirects      bom_variant_redirects
check_local core_author_archive_hidden core_author_archive_hidden
check_local byline_archive          byline_archive
check_local byline_rest_no_user_data byline_rest_no_user_data
check       theme_active          theme_active
check       gtm_present           gtm_present
check       no_google_fonts       no_google_fonts
check       nav_links_to_site     nav_links_to_site
check       footer_registration   footer_registration
check       404_themed            not_found_themed

check_local home_sections      home_sections
check_local cover_for_no_image cover_for_no_image
check_local category_page      category_page
check_local search_works       search_works
check       newsletter_hidden  newsletter_hidden
check       advisor_cta        advisor_cta

check_local import_dry_run_writes_nothing        import_dry_run_writes_nothing
check_local import_first_run_creates              import_first_run_creates
check_local import_second_run_no_duplicates       import_second_run_no_duplicates
check_local import_missing_image_does_not_abort   import_missing_image_does_not_abort
check_local import_slug_taken_and_per_item_errors import_slug_taken_and_per_item_errors
check_local import_bad_rerun_creates_nothing   import_bad_rerun_creates_nothing
check_local import_content_is_allow_listed     import_content_is_allow_listed
check_local import_requires_india_timezone     import_requires_india_timezone
check_local import_post_fields                    import_post_fields
check_local import_keeps_upload_path              import_keeps_upload_path
check_local service_user_login_blocked            service_user_login_blocked

# ---- summary ----
echo "Summary: $PASSES passed, $FAILS failed, $SKIPS skipped"
[ "$FAILS" -eq 0 ]
