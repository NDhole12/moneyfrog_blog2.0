#!/usr/bin/env bash
# Importer smoke helpers, sourced by smoke.sh (local only). Runs the fixture bundle
# in tests/fixtures/import through `wp mf import` (dry run, then twice), records what
# happened, removes everything it created, and lets smoke.sh assert on the records.

IMPORT_TMP=""
IMPORT_WORK_DIR="migration/output/qa-import"        # on the host; mounted at /migration in the wpcli container
IMPORT_BUNDLE_IN_CONTAINER="/migration/output/qa-import/bundle.json"
IMPORT_MEDIA_IN_CONTAINER="/migration/output/qa-import/media"

wp_nopath() { MSYS_NO_PATHCONV=1 wp "$@"; }

import_cleanup() {
  wp_nopath eval '
    foreach (get_posts(["post_type" => "post", "post_status" => "any", "numberposts" => -1, "meta_key" => "_mf_old_id", "fields" => "ids"]) as $id) {
      if ((int) get_post_meta($id, "_mf_old_id", true) >= 900000) { wp_delete_post($id, true); }
    }
    foreach (get_posts(["post_type" => "attachment", "post_status" => "any", "numberposts" => -1, "meta_key" => "_mf_old_media_id", "fields" => "ids"]) as $id) {
      if ((int) get_post_meta($id, "_mf_old_media_id", true) >= 990000) { wp_delete_attachment($id, true); }
    }
    foreach (get_posts(["post_type" => "post", "post_status" => "any", "name" => "codespark-qa-slug-owner", "numberposts" => -1, "fields" => "ids"]) as $id) { wp_delete_post($id, true); }
    $t = get_term_by("slug", "codespark-qa-import", "mf_byline");
    if ($t) { wp_delete_term($t->term_id, "mf_byline"); }
    $t = get_term_by("name", "Codespark QA Tag", "post_tag");
    if ($t) { wp_delete_term($t->term_id, "post_tag"); }
  ' >/dev/null 2>&1
}

# Prints one JSON object describing the fixture import state.
import_state() {
  wp_nopath eval '
    $q = get_posts(["post_type" => "post", "post_status" => "any", "numberposts" => -1, "meta_key" => "_mf_old_id", "fields" => "ids"]);
    $ids = array_values(array_filter($q, fn ($id) => (int) get_post_meta($id, "_mf_old_id", true) >= 900000));
    $att = array_filter(get_posts(["post_type" => "attachment", "post_status" => "any", "numberposts" => -1, "meta_key" => "_mf_old_media_id", "fields" => "ids"]), fn ($id) => (int) get_post_meta($id, "_mf_old_media_id", true) >= 990000);
    $byl = get_terms(["taxonomy" => "mf_byline", "hide_empty" => false, "slug" => "codespark-qa-import"]);
    $one = get_posts(["post_type" => "post", "post_status" => "any", "name" => "codespark-qa-import-one", "numberposts" => 1]);
    $two = get_posts(["post_type" => "post", "post_status" => "any", "name" => "codespark-qa-import-two", "numberposts" => 1]);
    $one = $one ? $one[0] : null; $two = $two ? $two[0] : null;
    $u = wp_upload_dir();
    $svc = get_user_by("login", "mf-import");
    echo "\nJSON:" . wp_json_encode([
      "posts" => count($ids), "attachments" => count($att), "bylines" => is_array($byl) ? count($byl) : 0,
      "title" => $one ? $one->post_title : null, "excerpt" => $one ? $one->post_excerpt : null,
      "content" => $one ? $one->post_content : null, "modified" => $one ? $one->post_modified : null,
      "date" => $one ? $one->post_date : null, "status" => $one ? $one->post_status : null,
      "comment_status" => $one ? $one->comment_status : null,
      "author_login" => $one ? get_userdata($one->post_author)->user_login : null,
      "byline" => $one ? wp_list_pluck(wp_get_object_terms($one->ID, "mf_byline"), "slug") : null,
      "category" => $one ? wp_list_pluck(wp_get_post_categories($one->ID, ["fields" => "all"]), "slug") : null,
      "thumb_one" => $one ? (int) get_post_thumbnail_id($one->ID) : null,
      "thumb_two" => $two ? (int) get_post_thumbnail_id($two->ID) : null,
      "old_type_two" => $two ? get_post_meta($two->ID, "_mf_old_type", true) : null,
      "file_exists" => file_exists($u["basedir"] . "/2026/01/codespark-qa-pixel.png"),
      "service_role" => $svc ? $svc->roles : null,
    ]);
  ' 2>/dev/null | tr -d '\r' | sed -n 's/^JSON://p' | tail -n1
}

# Runs the whole scenario once; results land in $IMPORT_TMP.
import_scenario() {
  IMPORT_TMP="$(mktemp -d)"
  rm -rf "$IMPORT_WORK_DIR"; mkdir -p "$IMPORT_WORK_DIR"
  cp -r tests/fixtures/import/bundle.json tests/fixtures/import/media "$IMPORT_WORK_DIR/"
  import_cleanup
  wp_nopath mf import "$IMPORT_BUNDLE_IN_CONTAINER" "--media-dir=$IMPORT_MEDIA_IN_CONTAINER" --dry-run >"$IMPORT_TMP/dry.out" 2>&1; echo $? >"$IMPORT_TMP/dry.code"
  [ -f "$IMPORT_WORK_DIR/import-log.json" ] || [ -f "$IMPORT_WORK_DIR/import-log-dry-run.json" ] && echo yes >"$IMPORT_TMP/dry.logfile"
  import_state >"$IMPORT_TMP/after-dry.json"
  wp_nopath mf import "$IMPORT_BUNDLE_IN_CONTAINER" "--media-dir=$IMPORT_MEDIA_IN_CONTAINER" >"$IMPORT_TMP/run1.out" 2>&1; echo $? >"$IMPORT_TMP/run1.code"
  import_state >"$IMPORT_TMP/after1.json"
  cp "$IMPORT_WORK_DIR/import-log.json" "$IMPORT_TMP/log1.json" 2>/dev/null
  wp_nopath mf import "$IMPORT_BUNDLE_IN_CONTAINER" "--media-dir=$IMPORT_MEDIA_IN_CONTAINER" >"$IMPORT_TMP/run2.out" 2>&1; echo $? >"$IMPORT_TMP/run2.code"
  import_state >"$IMPORT_TMP/after2.json"
  cp tests/fixtures/import/bundle-bad.json "$IMPORT_WORK_DIR/bundle-bad.json"
  wp_nopath eval 'wp_insert_post(["post_title" => "Codespark QA Slug Owner", "post_name" => "codespark-qa-slug-owner", "post_status" => "publish", "post_content" => "owner"]);' >/dev/null 2>&1
  local bad="/migration/output/qa-import/bundle-bad.json"
  wp_nopath mf import "$bad" "--media-dir=$IMPORT_MEDIA_IN_CONTAINER" >"$IMPORT_TMP/bad1.out" 2>&1; echo $? >"$IMPORT_TMP/bad1.code"
  cp "$IMPORT_WORK_DIR/import-log.json" "$IMPORT_TMP/badlog.json" 2>/dev/null
  wp_nopath mf import "$bad" "--media-dir=$IMPORT_MEDIA_IN_CONTAINER" >"$IMPORT_TMP/bad2.out" 2>&1; echo $? >"$IMPORT_TMP/bad2.code"
  wp_nopath eval '
    $o = get_posts(["post_type" => "post", "name" => "codespark-qa-slug-owner", "numberposts" => -1]);
    $n = 0; foreach (get_posts(["post_type" => "post", "post_status" => "any", "numberposts" => -1, "meta_key" => "_mf_old_id", "fields" => "ids"]) as $id) { if ((int) get_post_meta($id, "_mf_old_id", true) >= 900000) { $n++; } }
    $g = get_posts(["post_type" => "post", "name" => "codespark-qa-good-after-bad", "numberposts" => 1]);
    echo "
JSON:" . wp_json_encode(["owners" => count($o), "owner_title" => $o ? $o[0]->post_title : null, "imported" => $n, "good_content" => $g ? $g[0]->post_content : null]);
  ' 2>/dev/null | tr -d '\r' | sed -n 's/^JSON://p' | tail -n1 >"$IMPORT_TMP/after-bad.json"
  wp_nopath option update timezone_string UTC >/dev/null 2>&1
  wp_nopath mf import "$IMPORT_BUNDLE_IN_CONTAINER" "--media-dir=$IMPORT_MEDIA_IN_CONTAINER" --dry-run >"$IMPORT_TMP/tz.out" 2>&1; echo $? >"$IMPORT_TMP/tz.code"
  wp_nopath option update timezone_string Asia/Kolkata >/dev/null 2>&1
  IMPORT_PIXEL_HTTP="$(curl -s -o "$IMPORT_TMP/pixel.png" -w '%{http_code}' --max-time 20 "$BASE/wp-content/uploads/2026/01/codespark-qa-pixel.png")"
  echo "$IMPORT_PIXEL_HTTP" >"$IMPORT_TMP/pixel.code"
  import_cleanup
  rm -rf "$IMPORT_WORK_DIR"
}

# ---- assertions (each runs in a subshell via check_local) ----
jget() { node -e 'const j=JSON.parse(require("fs").readFileSync(process.argv[1],"utf8"));const v=process.argv[2].split(".").reduce((o,k)=>o==null?o:o[k],j);console.log(typeof v==="object"?JSON.stringify(v):String(v))' "$1" "$2"; }

import_dry_run_writes_nothing() {
  [ ! -f "$IMPORT_TMP/dry.logfile" ] && grep -q '"events"' "$IMPORT_TMP/dry.out" && [ "$(jget "$IMPORT_TMP/after-dry.json" file_exists)" = false ]     && [ "$(cat "$IMPORT_TMP/dry.code")" = 0 ] && grep -q 'posts: created=2' "$IMPORT_TMP/dry.out" \
    && [ "$(jget "$IMPORT_TMP/after-dry.json" posts)" = 0 ] && [ "$(jget "$IMPORT_TMP/after-dry.json" attachments)" = 0 ]
}
import_first_run_creates() {
  [ "$(jget "$IMPORT_TMP/after1.json" posts)" = 2 ] && [ "$(jget "$IMPORT_TMP/after1.json" attachments)" = 1 ] \
    && [ "$(jget "$IMPORT_TMP/after1.json" bylines)" = 1 ] && grep -q 'posts: created=2 updated=0 skipped=0 failed=0' "$IMPORT_TMP/run1.out"
}
import_second_run_no_duplicates() {
  [ "$(cat "$IMPORT_TMP/run2.code")" = 0 ] \
    && grep -q 'posts: created=0' "$IMPORT_TMP/run2.out" && grep -q 'media: created=0' "$IMPORT_TMP/run2.out" && grep -q 'bylines: created=0' "$IMPORT_TMP/run2.out" \
    && [ "$(jget "$IMPORT_TMP/after2.json" posts)" = 2 ] && [ "$(jget "$IMPORT_TMP/after2.json" attachments)" = 1 ] \
    && [ "$(jget "$IMPORT_TMP/after2.json" bylines)" = 1 ]
}
import_missing_image_does_not_abort() {
  [ "$(cat "$IMPORT_TMP/run1.code")" = 0 ] && grep -q 'failed-image: 2' "$IMPORT_TMP/run1.out" \
    && grep -q 'missing-featured: 1' "$IMPORT_TMP/run1.out" \
    && grep -q '"type": "failed-image"' "$IMPORT_TMP/log1.json" || return 1
  local content; content="$(jget "$IMPORT_TMP/after1.json" content)"
  [ "$(jget "$IMPORT_TMP/after1.json" status)" = publish ] && grep -q 'Tail text' <<<"$content" && grep -q 'Missing image above' <<<"$content" \
    && ! grep -q 'codespark-qa-absent' <<<"$content" && ! grep -q 'hotlink.invalid' <<<"$content" && ! grep -q 'blog.moneyfrog.in' <<<"$content" \
    && grep -q '/wp-content/uploads/2026/01/codespark-qa-pixel.png' <<<"$content" && grep -q 'href="/codespark-qa-import-two/"' <<<"$content" \
    && ! grep -q 'wp-image-77' <<<"$content" && [ "$(jget "$IMPORT_TMP/after1.json" thumb_one)" = 0 ]
}
import_post_fields() {
  local j="$IMPORT_TMP/after1.json"
  [ "$(jget "$j" title)" = 'Codespark QA – Import & Rerun' ] && [ "$(jget "$j" excerpt)" = "Tom & Jerry's excerpt" ] \
    && [ "$(jget "$j" date)" = '2015-03-04 10:20:30' ] && [ "$(jget "$j" modified)" = '2016-05-06 07:08:09' ] \
    && [ "$(jget "$j" comment_status)" = closed ] && [ "$(jget "$j" author_login)" = mf-import ] \
    && [ "$(jget "$j" byline)" = '["codespark-qa-import"]' ] && [ "$(jget "$j" category)" = '["mutual-funds"]' ] \
    && [ "$(jget "$j" old_type_two)" = page ] && [ "$(jget "$j" thumb_two)" != 0 ] \
    && [ "$(jget "$j" service_role)" = '["author"]' ] && [ "$(jget "$IMPORT_TMP/after2.json" modified)" = '2016-05-06 07:08:09' ]
}
import_keeps_upload_path() {
  [ "$(jget "$IMPORT_TMP/after1.json" file_exists)" = true ] && [ "$(cat "$IMPORT_TMP/pixel.code")" = 200 ] \
    && ! cmp -s "$IMPORT_TMP/pixel.png" tests/fixtures/import/media/2026/01/codespark-qa-pixel.png
}
service_user_login_blocked() {
  local tmpw jar code body
  tmpw="CodesparkQA-$(date +%s)-Xy9!"
  wp_nopath eval '\MoneyfrogCore\ServiceUser::ensure();' >/dev/null 2>&1 || return 1
  wp_nopath user update mf-import "--user_pass=$tmpw" >/dev/null 2>&1 || return 1
  jar="$(mktemp)"
  body="$(curl -s -c "$jar" -b "$jar" --max-time 20 -d "log=mf-import&pwd=$tmpw&wp-submit=Log+In" -H 'Cookie: wordpress_test_cookie=WP%20Cookie%20check' "$BASE/wp-login.php")"
  code="$(grep -c 'wordpress_logged_in' "$jar" || true)"
  wp_nopath eval '\MoneyfrogCore\ServiceUser::rotatePassword();' >/dev/null 2>&1
  rm -f "$jar"
  [ "$code" = 0 ] && grep -q 'cannot sign in' <<<"$body"
}

import_slug_taken_and_per_item_errors() {
  [ "$(cat "$IMPORT_TMP/bad1.code")" != 0 ] && [ -s "$IMPORT_TMP/badlog.json" ]     && grep -q 'posts: created=1 updated=0 skipped=0 failed=3' "$IMPORT_TMP/bad1.out"     && grep -q 'media: created=0 updated=0 skipped=0 failed=1' "$IMPORT_TMP/bad1.out"     && grep -q '"type": "slug-taken"' "$IMPORT_TMP/badlog.json" && grep -q '"type": "blocked-extension"' "$IMPORT_TMP/badlog.json"     && grep -q 'owned by post' "$IMPORT_TMP/badlog.json"     && ! grep -Eq 'Stack|#0 ' "$IMPORT_TMP/badlog.json"     && [ "$(jget "$IMPORT_TMP/after-bad.json" owners)" = 1 ] && [ "$(jget "$IMPORT_TMP/after-bad.json" owner_title)" = 'Codespark QA Slug Owner' ]     && [ "$(jget "$IMPORT_TMP/after-bad.json" imported)" = 3 ]
}
import_bad_rerun_creates_nothing() {
  grep -q 'posts: created=0 updated=0 skipped=1 failed=3' "$IMPORT_TMP/bad2.out" && [ "$(cat "$IMPORT_TMP/bad2.code")" != 0 ]
}
import_content_is_allow_listed() {
  local c; c="$(jget "$IMPORT_TMP/after-bad.json" good_content)"
  grep -q 'Safe' <<<"$c" && ! grep -Eq 'onclick|<script|alert[(]1[)]|evil.example' <<<"$c" && grep -q 'youtube.com/embed/abc' <<<"$c"
}
import_requires_india_timezone() {
  [ "$(cat "$IMPORT_TMP/tz.code")" != 0 ] && grep -q 'Asia/Kolkata' "$IMPORT_TMP/tz.out"
}
