#!/usr/bin/env bash
# Smoke checks for the Moneyfrog blog. Usage: tests/smoke/smoke.sh <base-url>
# Each check prints exactly "PASS <name>" or "FAIL <name>"; local-only checks
# print "SKIP <name>" when the base URL is not localhost.
#
# Against staging/LIVE (any non-local base) the HTTP-only checks run; WP-CLI-dependent
# ones are skipped. Optional environment:
#   SMOKE_AUTH=user:pass     HTTP basic auth (staging); passed to curl, never printed
#   SMOKE_SAMPLE_POST=/slug/ path of a published post; enables the single-post checks remotely
#   SMOKE_BOM_PATH=/slug%ef%bb%bf/  an old BOM URL that must 301 to SMOKE_SAMPLE_POST
#   EXPECT_SHA=<40-hex>      the release the server must report in X-MF-Release
BASE="${1:-http://localhost:8080}"
BASE="${BASE%/}"
FAILS=0
PASSES=0
SKIPS=0

# Every curl call goes through this wrapper so SMOKE_AUTH applies to all of them.
curl() {
  if [ -n "${SMOKE_AUTH:-}" ]; then command curl -u "$SMOKE_AUTH" "$@"; else command curl "$@"; fi
}

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

# Remote-only check (the local site has fixtures instead).
check_remote() {
  local name="$1"; shift
  if is_local; then echo "SKIP $name"; SKIPS=$((SKIPS + 1)); else check "$name" "$@"; fi
}

# Runs locally always; remotely only when SMOKE_SAMPLE_POST names a published post.
check_sample() {
  local name="$1"; shift
  if is_local || [ -n "${SMOKE_SAMPLE_POST:-}" ]; then check "$name" "$@"; else echo "SKIP $name"; SKIPS=$((SKIPS + 1)); fi
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
  if ! is_local; then printf '%s' "${SMOKE_SAMPLE_POST:-}"; return; fi
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
remove_fixtures() { bash tests/fixtures/seed.sh --remove "$BASE" >/dev/null 2>&1; }
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

# ---- single post page (real imported posts; local only) ----
SINGLE_DEC=/market-outlook-december-2025/
SINGLE_H2_POST=/tax-benefit-on-home-loan-section-24-80ee-80c/   # real post with 3 <h2>, so a TOC
SINGLE_IMG_POST=/market-outlook-may-2023/                       # real post with a featured image
# Remotely the generic single-post checks run against the published post named by SMOKE_SAMPLE_POST.
is_local || SINGLE_DEC="${SMOKE_SAMPLE_POST:-/}"
single_html() { curl -s --max-time 30 "$BASE$1"; }
single_flat() { single_html "$1" | tr '\n' ' ' | tr -s ' '; }
single_has_disclaimer() {
  single_flat "$SINGLE_DEC" | grep -qF 'Mutual Fund Disclaimer: Mutual Fund investments are subject to market risks. Please read all scheme related documents carefully before investing. Past performance is not an indicator of future returns. Insurance Disclaimer: Insurance is a subject matter of the solicitation. For more details on policy terms, conditions, exclusions, limitations, please refer/read policy brochure carefully before concluding sale. Articles on this blog are for education only and are not investment advice.'
}
single_toc() {
  local h id
  h="$(single_flat "$SINGLE_H2_POST")"
  id="$(grep -o '<h2 id="[^"]*"' <<<"$h" | head -n1 | sed 's/<h2 id="//; s/"$//')"
  [ -n "$id" ] || return 1
  grep -q 'aria-label="On this page"' <<<"$h" || return 1
  grep -q "href=\"#$id\"" <<<"$h"
}
single_toc_absent_ok() {
  local h
  [ "$(http_status "$SINGLE_DEC")" = 200 ] || return 1
  h="$(single_flat "$SINGLE_DEC")"
  ! grep -q 'aria-label="On this page"' <<<"$h" && ! grep -qiE '(Fatal error|Warning|Notice|Deprecated):' <<<"$h" && grep -q 'class="entry-content"' <<<"$h"
}
# A paragraph is "German-looking" when it has 3+ German stopwords from 2+ distinct words (no spam brand is named anywhere).
looks_german() {
  local para n d words='und|der|die|das|nicht|ist|sind|mit|für|auch|ein|eine|wir|bei|von|zu|den|dem|des|sich|auf'
  while IFS= read -r para; do
    n="$(grep -owiE "\b($words)\b" <<<"$para" | wc -l | tr -d ' ')"
    d="$(grep -owiE "\b($words)\b" <<<"$para" | tr 'A-Z' 'a-z' | sort -u | wc -l | tr -d ' ')"
    if [ "$n" -ge 3 ] && [ "$d" -ge 2 ]; then return 0; fi
  done < <(sed 's#</p>#\n#g; s#<[^>]*>##g' <<<"$1")
  return 1
}
single_no_spam() {
  local h; h="$(single_html "$SINGLE_DEC")"
  [ -n "$h" ] || return 1
  ! grep -qi 'casino' <<<"$h" && ! looks_german "$h"     && looks_german '<p>Dies ist ein Text und die Seite ist nicht für den Spieler mit dem Bonus</p>'     && ! looks_german '<p>Markets rose in the month and the index closed at a record high for the year</p>'
}
single_byline() {
  local h; h="$(single_flat "$SINGLE_DEC")"
  if ! is_local; then
    grep -qE 'href="[^"]*/author/[a-z0-9-]+/"' <<<"$h" && ! grep -qE 'href="[^"]*/(author|wp-user)/[a-z0-9_]*[0-9]+/?"' <<<"$h"
    return
  fi
  grep -q 'Mushtaq Kazi' <<<"$h" && grep -q '>MK<' <<<"$h" && grep -q 'href="[^"]*/author/mushtaq-kazi/"' <<<"$h"
}
single_jsonld() {
  local ld
  if ! is_local; then
    ld="$(single_html "$SINGLE_DEC" | tr '\n' ' ' | sed -n 's#.*<script type="application/ld+json">\(.*\)</script>.*#\1#p' | sed 's#</script>.*##')"
    grep -q '"@type":"BlogPosting"' <<<"$ld" && grep -q '"datePublished":"[0-9-]*T[0-9:]*+05:30"' <<<"$ld" \
      && grep -q '"author":{"@type":"Person","name":"[^"]\+"}' <<<"$ld" && grep -q '"publisher":{"@type":"Organization","name":"Moneyfrog"' <<<"$ld"
    return
  fi
  ld="$(single_html "$SINGLE_DEC" | tr '\n' ' ' | sed -n 's#.*<script type="application/ld+json">\(.*\)</script>.*#\1#p' | sed 's#</script>.*##')"
  grep -q '"@type":"BlogPosting"' <<<"$ld" && grep -q '"datePublished":"2025-12-[0-9T:]*+05:30"' <<<"$ld" \
    && grep -q '"dateModified":"[0-9T:-]*+05:30"' <<<"$ld" && grep -q '"headline":"Market Outlook – December 2025"' <<<"$ld" \
    && grep -q '"author":{"@type":"Person","name":"Mushtaq Kazi"}' <<<"$ld" && grep -q '"publisher":{"@type":"Organization","name":"Moneyfrog"' <<<"$ld"
}
single_no_comments() {
  local h; h="$(single_flat "$SINGLE_DEC")"
  [ -n "$h" ] && ! grep -qiE '\brespond\b|id="comments"|comment-form|wp-comments-post' <<<"$h"
}
single_old_bom_url_redirects() {
  local code loc
  if ! is_local; then
    [ -n "${SMOKE_BOM_PATH:-}" ] || return 1
    code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$BASE$SMOKE_BOM_PATH")"
    loc="$(curl -s -o /dev/null -w '%{redirect_url}' --max-time 20 "$BASE$SMOKE_BOM_PATH")"
    [ "$code" = 301 ] && [ "$loc" = "$BASE$SINGLE_DEC" ] && [ "$(http_status "$SINGLE_DEC")" = 200 ]
    return
  fi
  code="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$BASE/market-outlook-december-2025%ef%bb%bf/")"
  loc="$(curl -s -o /dev/null -w '%{redirect_url}' --max-time 20 "$BASE/market-outlook-december-2025%ef%bb%bf/")"
  [ "$code" = 301 ] && [ "$loc" = "$BASE$SINGLE_DEC" ] && [ "$(http_status "$SINGLE_DEC")" = 200 ]
}
single_hero() {
  local a b
  a="$(single_flat "$SINGLE_DEC")"; b="$(single_flat "$SINGLE_IMG_POST")"
  ! grep -q 'class="post-hero"' <<<"$a" && ! grep -q 'mf-cover--hero' <<<"$a" || return 1
  grep -q 'class="post-hero"' <<<"$b" && grep -qE '<img[^>]*class="[^"]*wp-post-image' <<<"$b" && grep -qE 'wp-post-image[^>]*alt="[^"]+"|alt="[^"]+"[^>]*wp-post-image' <<<"$b"
}
single_share() {
  local h; h="$(single_flat "$SINGLE_DEC")"
  grep -q 'https://www.linkedin.com/sharing/share-offsite/?url=' <<<"$h" && grep -q 'https://wa.me/?text=' <<<"$h" \
    && grep -q 'https://x.com/intent/post?url=' <<<"$h" && grep -q 'data-copy-link' <<<"$h" && grep -q 'aria-live="polite"' <<<"$h" \
    && [ "$(grep -o 'rel="noopener noreferrer"' <<<"$h" | wc -l | tr -d ' ')" -ge 3 ]
}
single_progress() {
  local h; h="$(single_flat "$SINGLE_DEC")"
  grep -q 'role="progressbar"' <<<"$h" && grep -q 'assets/js/progress.js' <<<"$h" && grep -q 'assets/js/copy-link.js' <<<"$h" \
    && curl -s --max-time 20 "$BASE/wp-content/themes/moneyfrog/assets/js/progress.js" | grep -q 'prefers-reduced-motion'
}
single_related() {
  local h; h="$(single_flat "$SINGLE_DEC")"
  grep -q '>Keep reading<' <<<"$h" && [ "$(grep -o 'class="card card--default"' <<<"$h" | wc -l | tr -d ' ')" = 3 ] \
    && grep -q 'aria-label="Previous and next articles"' <<<"$h" && grep -q 'Talk to an advisor' <<<"$h"
}
single_meta_description() {
  local h d
  h="$(single_html "$SINGLE_DEC")"
  d="$(grep -o '<meta name="description" content="[^"]*"' <<<"$h" | sed 's/.*content="//; s/"$//; s/&amp;/\&/g')"
  [ -n "$d" ] && [ "$(printf '%s' "$d" | LC_ALL=C tr -d '\200-\277' | LC_ALL=C wc -c | tr -d ' ')" -le 160 ] && [ "$(grep -c '<meta name="description"' <<<"$h")" = 1 ]
}
single_og_tags() {
  local h; h="$(single_html "$SINGLE_DEC")"
  if ! is_local; then
    grep -q '<meta property="og:type" content="article">' <<<"$h" && grep -q '<meta property="og:title" content="[^"]\+">' <<<"$h" \
      && grep -q "<meta property=\"og:url\" content=\"$BASE$SINGLE_DEC\">" <<<"$h" && grep -q '<meta property="og:site_name" content="Moneyfrog Insights">' <<<"$h" \
      && grep -q '<meta property="article:published_time" content="[0-9T:-]*+05:30">' <<<"$h" && grep -q '<meta name="twitter:card" content="summary_large_image">' <<<"$h"
    return
  fi
  grep -q '<meta property="og:type" content="article">' <<<"$h"     && grep -q '<meta property="og:title" content="Market Outlook – December 2025">' <<<"$h"     && grep -q '<meta property="og:description" content="[^"]\+">' <<<"$h"     && grep -q "<meta property=\"og:url\" content=\"$BASE$SINGLE_DEC\">" <<<"$h"     && grep -q '<meta property="og:site_name" content="Moneyfrog Insights">' <<<"$h"     && grep -q '<meta property="og:image" content="[^"]*/wp-content/uploads/2025/12/' <<<"$h"     && grep -q '<meta property="article:published_time" content="2025-12-[0-9T:]*+05:30">' <<<"$h"     && grep -q '<meta property="article:modified_time" content="[0-9T:-]*+05:30">' <<<"$h"     && grep -q '<meta name="twitter:card" content="summary_large_image">' <<<"$h"
}
category_meta_description() {
  curl -s --max-time 20 "$BASE/category/market-outlook/" | grep -q '<meta name="description" content="[^"]\+">'     && home_html | grep -q '<meta name="description" content="[^"]\+">'
}
single_one_h1() { [ "$(single_html "$SINGLE_DEC" | grep -o '<h1[ >]' | wc -l | tr -d ' ')" = 1 ]; }

release_header_value() { curl -s -D - -o /dev/null --max-time 20 "$BASE/" | tr -d '\r' | sed -n 's/^[Xx]-[Mm][Ff]-[Rr]elease: *//p' | head -n1; }
# Remote: X-MF-Release must equal the first 12 chars of EXPECT_SHA. Local: the header appears with a
# temporary wp-content/.mf-release and is absent without one.
release_header() {
  if ! is_local; then
    [[ "${EXPECT_SHA:-}" =~ ^[0-9a-f]{40}$ ]] && [ "$(release_header_value)" = "${EXPECT_SHA:0:12}" ]
    return
  fi
  local sha=0123456789abcdef0123456789abcdef01234567 with without
  wp eval '@unlink(WP_CONTENT_DIR . "/.mf-release");' >/dev/null 2>&1
  without="$(release_header_value)"
  wp eval 'file_put_contents(WP_CONTENT_DIR . "/.mf-release", "'"$sha"'\n");' >/dev/null 2>&1 || return 1
  with="$(release_header_value)"
  wp eval '@unlink(WP_CONTENT_DIR . "/.mf-release");' >/dev/null 2>&1
  [ -z "$without" ] && [ "$with" = "${sha:0:12}" ]
}
# Behaviour test of `wp mf audit`: an editor without two-factor is reported, one with it is not,
# and the importer's service account is never reported. Uses temporary users and removes them.
audit_missing() { wp mf audit 2>/dev/null | tr -d '\n ' | sed -n 's/.*"two_factor_missing":\[\([^]]*\)\].*/\1/p'; }
two_factor_enforced() {
  local out ok=1 made_svc=0
  wp user get mf-import >/dev/null 2>&1 || { wp user create mf-import mf-import@example.invalid --role=editor --porcelain >/dev/null 2>&1 && made_svc=1; }
  wp user create mf_codespark_qa_2fa qa-2fa@example.invalid --role=editor --porcelain >/dev/null 2>&1 || return 1
  out="$(audit_missing)"
  grep -q 'mf_codespark_qa_2fa' <<<"$out" || ok=0
  grep -q 'mf-import' <<<"$out" && ok=0
  wp user meta update mf_codespark_qa_2fa _two_factor_enabled_providers '["Two_Factor_Totp"]' --format=json >/dev/null 2>&1
  out="$(audit_missing)"
  grep -q 'mf_codespark_qa_2fa' <<<"$out" && ok=0
  wp user delete mf_codespark_qa_2fa --yes >/dev/null 2>&1
  [ "$made_svc" = 1 ] && wp user delete mf-import --yes >/dev/null 2>&1
  [ "$ok" = 1 ]
}
audit_runs() { wp mf audit 2>/dev/null | tr -d '\n ' | grep -q '"php":"8\.3[^"]*".*"blog_public":'; }
remote_listing_pages() { [ "$(http_status /category/mutual-funds/)" = 200 ] && [ "$(http_status '/?s=SIP')" = 200 ]; }

# shellcheck source=tests/smoke/import-checks.sh
source "$(dirname "$0")/import-checks.sh"

# ---- checks ----
# Real-content checks first, on a site with no QA fixtures; fixtures are seeded after and removed again at exit.
is_local && remove_fixtures
check_sample single_has_disclaimer           single_has_disclaimer
check_local single_toc                      single_toc
check_local single_toc_absent_ok            single_toc_absent_ok
check_sample single_no_spam                  single_no_spam
check_sample single_byline                   single_byline
check_sample single_jsonld                   single_jsonld
check_sample single_no_comments              single_no_comments
if is_local || [ -n "${SMOKE_BOM_PATH:-}" ]; then check single_old_bom_url_redirects single_old_bom_url_redirects; else echo "SKIP single_old_bom_url_redirects"; SKIPS=$((SKIPS + 1)); fi
check_local single_hero                     single_hero
check_sample single_share                    single_share
check_sample single_progress                 single_progress
check_local single_related                  single_related
check_sample single_one_h1                   single_one_h1
check_sample single_meta_description        single_meta_description
check_sample single_og_tags                 single_og_tags
check_local category_meta_description     category_meta_description

is_local && seed_fixtures
trap 'is_local && remove_fixtures' EXIT
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
check_sample no_comment_form        no_comment_form
check_sample pingback_header_absent pingback_header_absent
check_sample trackback_closed       trackback_closed
check       users_sitemap_gone     users_sitemap_gone
check_local rest_embed_no_author   rest_embed_no_author
check_local file_edit_disabled     file_edit_disabled
check_local two_factor_enforced     two_factor_enforced
check_local audit_runs              audit_runs
if is_local || [ -n "${EXPECT_SHA:-}" ]; then check release_header release_header; else echo "SKIP release_header"; SKIPS=$((SKIPS + 1)); fi
check_remote listing_pages          remote_listing_pages
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
check_local import_setup_failure_aborts        import_setup_failure_aborts
check_local import_post_fields                    import_post_fields
check_local import_keeps_upload_path              import_keeps_upload_path
check_local service_user_login_blocked            service_user_login_blocked

# ---- summary ----
echo "Summary: $PASSES passed, $FAILS failed, $SKIPS skipped"
[ "$FAILS" -eq 0 ]
