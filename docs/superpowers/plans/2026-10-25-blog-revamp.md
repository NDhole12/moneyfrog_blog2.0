# blog.moneyfrog.in Revamp Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A brand-new, hardened WordPress blog at `blog.moneyfrog.in` (GoDaddy cPanel, PHP 8.3) in the moneyfrog.in design, holding every old post, Moneyfrog School page and image, cleaned of spam, with no broken URLs and comments fully off.

**Architecture:** WordPress core is *not* in the repo. The repo holds (1) a must-use plugin `moneyfrog-core` (hardening, comments-off, bylines, redirects, content helpers, WP-CLI importer), (2) a classic PHP theme `moneyfrog`, (3) a Node migration pipeline that reads the old blog's public REST API, sanitises and maps it into one `bundle.json`, and (4) infra scripts for local Docker, deploy and backups. Pure logic lives in WordPress-free PHP/JS classes so it is unit-tested; WordPress-facing behaviour is checked by an HTTP smoke suite run against local, staging and LIVE.

**Tech Stack:** WordPress (latest stable) · PHP 8.3 · MariaDB 11 · WP-CLI · Composer (PHPUnit 11, wpackagist) · Node 20+ (`sanitize-html`, `cheerio`, `vitest`) · Docker Compose (local only) · cPanel + SSH (GoDaddy) · AWS S3 (backups).

**Spec:** `docs/superpowers/specs/2026-10-25-blog-revamp-design.md` (approved 2026-10-25). Server facts: `docs/private/infra-and-security-notes.md` (git-ignored).

## Global Constraints

- PUBLIC repo: never commit IPs, cPanel user, DB names/passwords, server paths, `.env`, `wp-config.php`, SQL dumps, or migration output. Those go in `docs/private/` or the account-level credentials ledger (outside every repo).
- Branding: only "Codespark" appears as the tool/agency name in code, comments, commits and test data. Test data uses `Codespark QA`.
- Commits: conventional (`feat:`, `fix:`, `test:`, `chore:`, `docs:`), no attribution trailer. Push after each task; deploy only a pushed SHA.
- Old blog is **never touched**: read only via `https://blog.moneyfrog.in/wp-json/wp/v2/*` and public URLs. No writes, no logins, no file access on the old install or the old GoDaddy copy.
- PHP ≥ 8.2 (target 8.3) on every environment; the CRM's PHP version on the shared account must not change.
- Comments: off everywhere; `wp-comments-post.php`, comment feeds, trackbacks, pingbacks and XML-RPC answer 403/404.
- No page builder. Plugins allowed: `two-factor` (wordpress.org) only, unless a task says otherwise.
- Fonts self-hosted (Fraunces, Instrument Sans, IBM Plex Mono woff2 copied from `d:\moneyfrog_client_front` branch `feat/site-redesign-index2`, `public/index2-assets/fonts/`). No Google Fonts requests.
- Third-party scripts: only GTM container `GTM-M9PS8WZT` (same as moneyfrog.in).
- Design tokens (copy exactly): bg `#f9f8f4`, band `#f3efe5`, topbar `#eae3d9`, surface `#ffffff`, card `#fcfaf5`, line `#e7e2d8`, line-2 `#ddd6c7`, ink `#16191d`, ink-2 `#4c525a`, gold-ink `#8a6a1f`, gold `#c29a2c`, gold-soft `#e3c66a`, btn-ink `#241b05`, night `#0b0f14`, radius `12px`, gold gradient `linear-gradient(160deg,#e2c46b 0%,#d4b24f 45%,#c29c35 100%)`. Small text uses ink-2, never `#838a93` (fails contrast).
- Disclaimer (exact copy, every post, `template-parts/disclaimer.php`): "Mutual Fund Disclaimer: Mutual Fund investments are subject to market risks. Please read all scheme related documents carefully before investing. Past performance is not an indicator of future returns. Insurance Disclaimer: Insurance is a subject matter of the solicitation. For more details on policy terms, conditions, exclusions, limitations, please refer/read policy brochure carefully before concluding sale. Articles on this blog are for education only and are not investment advice."
- Footer registration line (exact): "Moneyfrog Financial Services Private Limited | CIN: U66000MH2011PTC216613 | AMFI Registered Mutual Fund Distributor: ARN 94798 | IRDAI Registered Corporate Agent: CA0450".
- Advisor CTA URL constant `MF_BOOK_CALL_URL = https://moneyfrog.in/contact-us`; main site base `MF_SITE_URL = https://moneyfrog.in`.
- Topics (slug → name): `market-outlook` Market Outlook · `mutual-funds` Mutual Funds · `tax` Tax · `insurance` Insurance · `retirement` Retirement · `financial-planning` Financial Planning · `guides` Guides.
- Newsletter band is built but hidden (`MF_NEWSLETTER_ENABLED = false`); no email is collected.
- Done = deployed to LIVE `blog.moneyfrog.in`, the new install provably serving, smoke + URL-inventory suites green there.

## Review Focus

1. **Slug collisions** after cleaning BOM slugs or turning Moneyfrog School pages into posts (two items want one slug): every old URL must still reach *its own* new post. Test: `slugs.test.mjs › collision keeps both and maps each old URL` (Task 9).
2. **Re-running the importer** (it will run on local, staging and LIVE, and again after fixes): no duplicate posts, media or bylines; existing ones update in place. Test: `ImporterTest::test_second_run_updates_not_duplicates` (Task 11).
3. **Legitimate text that looks like spam**: Hinglish titles ("Kitna Sahi Hai", "Health Cover Zaroori Hai") and finance uses of "bonus" must survive (at most flagged), while the German casino paragraph is removed. Test: `sanitize.test.mjs › keeps hinglish and finance bonus, removes german casino paragraph` (Task 8).
4. **Images that fail to download** (404 on the old host, hot-linked third-party host): the post still imports, the image is dropped or left external per rule, and the failure is in the report. Test: `ImporterTest::test_missing_image_does_not_abort_post` (Task 11).
5. **Old-URL variants** arriving from Google and moneyfrog.in: missing trailing slash, upper-case letters, raw or percent-encoded BOM, `?utm_*` query: all still 301 once (query kept) to a 200. Test: `RedirectsTest::test_variants_resolve_to_same_target` (Task 4) and the inventory check (Task 13).

---

## File Structure

```
docker-compose.yml                      local WP (php8.3-apache) + mariadb + wpcli
.env.example                            local DB/env placeholders (no real values)
composer.json, phpunit.xml.dist         PHPUnit + wpackagist (two-factor)
wp-content/mu-plugins/moneyfrog-core.php            loader: require src/*, register hooks
wp-content/mu-plugins/moneyfrog-core/src/
  Config.php        constants (MF_SITE_URL, MF_BOOK_CALL_URL, GTM id, topics, newsletter flag)
  Hardening.php     headers, xmlrpc, users REST, author enum, login limits, file-edit
  CommentsOff.php   every comment/ping surface off
  Content.php       PURE: readingTime(), toc(), addHeadingIds(), coverPhrase()
  Redirects.php     PURE: normalisePath(), lookup(); hooks in RedirectHooks.php
  RedirectHooks.php template_redirect → 301 using data/redirects.php
  Byline.php        taxonomy mf_byline (+ bio term meta), helpers for templates
  Cli/ImportCommand.php   `wp mf import <bundle.json> [--dry-run]`
  Cli/Importer.php        idempotent upsert of posts, media, bylines, topics
wp-content/mu-plugins/moneyfrog-core/data/redirects.php   GENERATED by migration (committed: paths only)
wp-content/themes/moneyfrog/
  style.css functions.php index.php front-page.php category.php search.php single.php 404.php
  header.php footer.php
  inc/icons.php inc/gtm.php inc/queries.php
  template-parts/ blog-bar.php card.php card-row.php cover.php disclaimer.php sidebar.php
                  author-box.php share.php newsletter.php advisor-cta.php pagination.php
  assets/css/ tokens.css base.css components.css pages.css
  assets/js/ nav.js progress.js copy-link.js
  assets/fonts/*.woff2   assets/img/moneyfrog-logo.svg
tests/php/ ContentTest.php RedirectsTest.php ImporterTest.php bootstrap.php
tests/smoke/smoke.sh                    curl suite: ./smoke.sh <base-url>
tests/smoke/inventory.sh                every old URL → 200 or one 301→200
migration/ package.json
  src/ fetch.mjs sanitize.mjs topics.mjs slugs.mjs build.mjs allowlist.mjs
  test/ sanitize.test.mjs topics.test.mjs slugs.test.mjs build.test.mjs fixtures/
  output/   (git-ignored: raw/, bundle.json, report.html, review.csv, inventory.txt)
infra/ deploy.sh provision.md backup.sh restore.md
```

---

## Phase 0 — Gate

### Task 1: Confirm the GoDaddy account can run PHP 8.3 (read-only)

**Files:**
- Modify: `docs/private/infra-and-security-notes.md` (git-ignored) — add a "PHP readiness" section.

**Interfaces:**
- Produces: the PHP 8.3 binary/handler name usable for a new docroot (e.g. `ea-php83`), whether WP-CLI can run over SSH, and whether `rsync`/`tar`/`unzip` exist. Later infra tasks read this.

- [ ] **Step 1:** SSH in with the cPanel account (creds: ledger S5). Run read-only: `php -v; ls -d /opt/cpanel/ea-php*/ 2>/dev/null; which rsync tar unzip; uname -a`. Do not change anything.
- [ ] **Step 2:** If no `ea-php82`/`ea-php83` exists or MultiPHP stays off: stop the plan and report to the owner with the exact ask for GoDaddy support ("enable MultiPHP + PHP 8.3 for one new addon domain; do not change the account default"). Expected to continue only with PHP ≥ 8.2 available per docroot.
- [ ] **Step 3:** Read-only webshell scan of the account (spec §10): `find ~ -name '*.php' -newer ~/public_html/crm.moneyfrog.in/index.php -mtime -120 | head -200` plus `grep -rlE 'eval\(base64_decode|gzinflate\(base64|assert\(\$_(POST|GET)' ~/public_html --include=*.php | head`. Record findings only. Report them to the owner; remove nothing.
- [ ] **Step 4:** Record results in the private notes. No commit (file is ignored).

---

## Phase 1 — Local foundation

### Task 2: Local WordPress on PHP 8.3 + test harnesses

**Files:**
- Create: `docker-compose.yml`, `.env.example`, `composer.json`, `phpunit.xml.dist`, `tests/php/bootstrap.php`, `tests/smoke/smoke.sh`, `migration/package.json`, `migration/vitest.config.mjs`, `wp-content/mu-plugins/moneyfrog-core.php`, `wp-content/mu-plugins/moneyfrog-core/src/Config.php`
- Modify: `.gitignore` (add `migration/output/`, `vendor/`, `wp-content/plugins/` except none tracked)

**Interfaces:**
- Produces: `docker compose up -d` serves `http://localhost:8080` (image `wordpress:php8.3-apache`, DB `mariadb:11`, service `wpcli` image `wordpress:cli-php8.3`), `wp-content/mu-plugins` and `wp-content/themes/moneyfrog` bind-mounted. `composer test` runs PHPUnit on `tests/php`. `npm test` (in `migration/`) runs vitest. `tests/smoke/smoke.sh <base>` exits non-zero on any failed check and prints one `PASS|FAIL <check>` line each.
- Produces: `MoneyfrogCore\Config` with `const SITE_URL`, `BOOK_CALL_URL`, `GTM_ID = 'GTM-M9PS8WZT'`, `NEWSLETTER_ENABLED = false`, `TOPICS` (slug ⇒ name map from Global Constraints).

- [ ] **Step 1:** Write `tests/smoke/smoke.sh` with first checks: `home_200` (GET `/` = 200), `php_8_3` (`wp eval 'echo PHP_VERSION;'` via compose starts with `8.3`, local only, skipped when base is not localhost), `mu_plugin_loaded` (`/wp-json/` index response header `X-MF-Core: 1`).
- [ ] **Step 2:** Run `./tests/smoke/smoke.sh http://localhost:8080` before compose exists. Expected: FAIL (connection refused).
- [ ] **Step 3:** Add compose, `.env.example` (placeholders only), install core via `docker compose run --rm wpcli wp core install --url=http://localhost:8080 --title="Moneyfrog Insights" --admin_user=mf_codespark_qa --admin_email=qa@code-spark.com --skip-email`, loader that requires `src/*.php` and sends `X-MF-Core: 1` on REST responses.
- [ ] **Step 4:** Run smoke. Expected: 3× PASS. Run `composer test` and `npm test` with one trivial placeholder test each. Expected: green.
- [ ] **Step 5:** Commit `chore: local WordPress 8.3 stack and test harnesses`; push.

---

## Phase 2 — Must-use plugin

### Task 3: Hardening + comments fully off

**Files:**
- Create: `src/Hardening.php`, `src/CommentsOff.php` (under `wp-content/mu-plugins/moneyfrog-core/`)
- Modify: `tests/smoke/smoke.sh`

**Interfaces:**
- Produces: `Hardening::register(): void`, `CommentsOff::register(): void` (called from the loader).

- [ ] **Step 1: Add smoke checks** (each PASS only on the stated result):
  `xmlrpc_closed` POST `/xmlrpc.php` → 403 or 404 · `comments_post_closed` POST `/wp-comments-post.php` → 403 or 404 · `comment_feed_gone` GET `/comments/feed/` → 404 · `users_rest_hidden` GET `/wp-json/wp/v2/users` → 401/404 and body has no `"slug"` · `author_enum_blocked` GET `/?author=1` → not a 301 to `/author/<login>/` (403 or 404) · `headers` home has `Strict-Transport-Security` (non-localhost only), `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Content-Security-Policy` containing `https://www.googletagmanager.com` and no `fonts.googleapis.com` · `no_comment_form` a published sample post's HTML has no `id="respond"` and no `comment` form · `pingback_header_absent` no `X-Pingback` header · `file_edit_disabled` `wp eval 'echo (int) DISALLOW_FILE_EDIT;'` = 1 (local).
- [ ] **Step 2:** Run smoke. Expected: these new checks FAIL.
- [ ] **Step 3:** Implement. CommentsOff: close default comment/ping status, `remove_post_type_support(*, 'comments'|'trackbacks')`, filters `comments_open`/`pings_open` → false, `comments_array` → [], remove comment feeds + `X-Pingback`, block `wp-comments-post.php` and `xmlrpc.php` (403) via `init` early check, remove admin menu/bar comment items. Hardening: `xmlrpc_enabled` false, REST `rest_endpoints` drop `/wp/v2/users*` for non-logged-in, `?author=N` → 404, security headers on `send_headers`, login rate-limit (5 failures / 15 min / IP via transient), `define` guards documented for `wp-config` (`DISALLOW_FILE_EDIT`, `DISALLOW_FILE_MODS`, `WP_AUTO_UPDATE_CORE = 'minor'`).
- [ ] **Step 4:** Run smoke. Expected: all PASS.
- [ ] **Step 5:** Commit `feat: hardening and comments fully off`; push.

### Task 4: Content helpers + redirects (pure, unit-tested)

**Files:**
- Create: `src/Content.php`, `src/Redirects.php`, `src/RedirectHooks.php`, `data/redirects.php` (initially `<?php return [];`)
- Test: `tests/php/ContentTest.php`, `tests/php/RedirectsTest.php`

**Interfaces:**
- Produces:
  - `Content::readingTime(string $html, int $wpm = 200): int` — minutes, minimum 1.
  - `Content::addHeadingIds(string $html): string` — every `<h2>` gets a stable `id` slugified from its text, de-duplicated with `-2`, `-3`; existing ids kept.
  - `Content::toc(string $html): array` — `list<array{id:string,text:string}>` of H2s after `addHeadingIds`; `[]` when fewer than 2 H2s.
  - `Content::coverPhrase(string $title, string $topicName): string` — ≤ 22 chars: text before the first `–`/`-`/`:`/`?` if it fits, else first 3 words, else `$topicName`.
  - `Redirects::normalisePath(string $uri): string` — strip query, lowercase, decode percent-encoding, strip U+FEFF, collapse `//`, ensure leading and trailing `/`.
  - `Redirects::lookup(string $uri, array $map): ?string` — `$map` keys are normalised old paths, values are new paths/URLs; returns target with the original query string appended, or null.
  - `RedirectHooks::register(): void` — on `template_redirect` (and before 404), 301 to `lookup()` result if not null and target ≠ current path.

- [ ] **Step 1: Write failing tests.**
  `test_reading_time_rounds_up_and_min_one` (`600 words → 3`, `10 words → 1`) · `test_heading_ids_are_stable_and_unique` (two `<h2>Positives</h2>` → `positives`, `positives-2`) · `test_toc_empty_with_fewer_than_two_h2` · `test_toc_lists_h2_in_order` · `test_cover_phrase` (`"SIP vs. Lump sum – Which is better?" → "SIP vs. Lump sum"`, `"Market Outlook – December 2025" → "Market Outlook"`, a 60-char title with no separator → first 3 words) · `test_normalise_path_strips_bom_raw_and_encoded` (`/market-outlook-december-2025%ef%bb%bf/` and `/market-outlook-december-2025\u{FEFF}` → `/market-outlook-december-2025/`) · `test_variants_resolve_to_same_target` (`/Moneyfrog-School/Gilt-Fund`, `/moneyfrog-school/gilt-fund/?utm_source=x` → `/gilt-fund/` and `/gilt-fund/?utm_source=x`) · `test_lookup_returns_null_for_unknown`.
- [ ] **Step 2:** `composer test`. Expected: FAIL (classes missing).
- [ ] **Step 3:** Implement with `DOMDocument` (UTF-8 safe wrapper) for heading work; no WordPress calls in `Content`/`Redirects`.
- [ ] **Step 4:** `composer test`. Expected: PASS. Add smoke check `redirect_hook_live`: with a temporary map entry `/codespark-qa-old/ → /` (local only via `MF_REDIRECTS_EXTRA` filter), GET returns 301 to `/`.
- [ ] **Step 5:** Commit `feat: content helpers and redirect engine`; push.

### Task 5: Bylines (author names without logins)

**Files:**
- Create: `src/Byline.php`
- Modify: `tests/smoke/smoke.sh`

**Interfaces:**
- Produces: taxonomy `mf_byline` (public, rewrite slug `author`, non-hierarchical, REST visible without user data), term meta `mf_bio` (string), `mf_role` (string, e.g. "Co-founder, Moneyfrog"). Helpers: `Byline::forPost(int $postId): ?\WP_Term`, `Byline::initials(string $name): string` (`"Mushtaq Kazi" → "MK"`, single word → first 2 letters upper).
- Core `/author/<login>/` archives disabled (404); `/author/<byline-slug>/` is the byline archive.

- [ ] **Step 1:** Add PHPUnit `test_initials` and smoke `byline_archive` (create term `codespark-qa` via wpcli, assign to a sample post, GET `/author/codespark-qa/` = 200 and contains the post title; GET `/author/mf_codespark_qa/` (the admin login) = 404).
- [ ] **Step 2:** Run both. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run both. Expected: PASS.
- [ ] **Step 5:** Commit `feat: byline taxonomy replaces author accounts`; push.

---

## Phase 3 — Theme (match the approved canvas)

Each theme task: build to the canvas artboard, then check at 1440 px and 390 px (no horizontal scroll, no console errors, all images load) with a Playwright screenshot script `tests/visual/shoot.mjs <url> <name>` saved to `migration/output/shots/` (ignored). Visual sign-off is by the owner on staging.

### Task 6: Theme shell — tokens, fonts, header, blog bar, footer, GTM

**Files:**
- Create: `wp-content/themes/moneyfrog/{style.css,functions.php,index.php,header.php,footer.php,404.php}`, `inc/{icons.php,gtm.php}`, `template-parts/{blog-bar.php,disclaimer.php}`, `assets/css/{tokens.css,base.css,components.css}`, `assets/js/nav.js`, `assets/fonts/*.woff2`, `assets/img/moneyfrog-logo.svg`, `tests/visual/shoot.mjs`
- Modify: `tests/smoke/smoke.sh`

**Interfaces:**
- Consumes: `Config::SITE_URL`, `Config::GTM_ID`, `Config::TOPICS`.
- Produces: `mf_icon(string $name, string $class = 'icon'): string` (inline `<svg><use href="#i-…">` from the sprite in `inc/icons.php`, copied from the site's `_icons.blade.php`; names as there, e.g. `i-arrow`, `i-chevron`, `i-menu`, `i-close`, `i-linkedin`, `i-whatsapp`, `i-x`); `get_template_part('template-parts/blog-bar', null, ['current' => ?string])`; `get_template_part('template-parts/disclaimer')`.

- [ ] **Step 1:** Smoke checks: `theme_active` (home HTML has `<link` to `themes/moneyfrog/assets/css/tokens.css`), `gtm_present` (home contains `GTM-M9PS8WZT` exactly twice: head script + noscript), `no_google_fonts` (no `fonts.googleapis.com`/`fonts.gstatic.com`), `nav_links_to_site` (header has `href="https://moneyfrog.in/login"` and the Sign Up URL as on the site), `footer_registration` (exact registration line from Global Constraints), `404_themed` (GET `/codespark-qa-missing/` = 404 and contains `Insights`).
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement: header/top bar/footer port the site partials (links absolute to `MF_SITE_URL`; Resources › Blogs → `/`, Market Outlook → `/category/market-outlook/`); blog bar = "Insights." wordmark + topic pills from `Config::TOPICS` with `aria-current` on the active one + `<form role="search">`; mobile drawer via `nav.js` (Esc closes, focus trapped, `aria-expanded`); `theme.json` not used; `add_theme_support('title-tag','post-thumbnails','html5')`; image sizes `mf-card` 640×400 crop, `mf-hero` 1600×686 crop.
- [ ] **Step 4:** Run smoke; shoot `/` at 1440/390. Expected: PASS, header/footer match canvas.
- [ ] **Step 5:** Commit `feat(theme): shell, tokens, header, blog bar, footer`; push.

### Task 7: Cards, covers, home, category, search

**Files:**
- Create: `front-page.php`, `category.php`, `search.php`, `inc/queries.php`, `template-parts/{card.php,card-row.php,cover.php,sidebar.php,pagination.php,newsletter.php,advisor-cta.php}`, `assets/css/pages.css`
- Modify: `tests/smoke/smoke.sh`

**Interfaces:**
- Consumes: `Content::readingTime`, `Content::coverPhrase`, `Byline::forPost`, `Config::NEWSLETTER_ENABLED`, `Config::BOOK_CALL_URL`.
- Produces: `mf_home_sections(): array{featured:?WP_Post, picks:WP_Post[], outlook:WP_Post[], latest:WP_Query, guides:WP_Post[]}` in `inc/queries.php` — featured = newest `market-outlook` post; picks = posts tagged `editors-pick` (newest 4; fallback newest 4 non-outlook); outlook = newest 4 `market-outlook` excluding featured; latest = paged 6 excluding the above; guides = posts tagged `start-here` ordered by meta `mf_order` (fallback oldest 5 in `guides`). `template-parts/cover` args `['title','topic','variant' => 'cream'|'night']` (variant alternates by post ID parity).

- [ ] **Step 1:** Smoke (with wpcli-seeded fixtures: 8 posts across topics, half without thumbnails, 2 H2s in one): `home_sections` (home has the featured title, 4 outlook cards, "Browse by topic" with 6 tile links to `/category/<slug>/`), `cover_for_no_image` (a post without thumbnail renders `class="mf-cover` in its card), `category_page` (`/category/mutual-funds/` = 200, shows count text "articles", pagination when > 10), `search_works` (`/?s=SIP` = 200 lists the SIP fixture), `newsletter_hidden` (home has no `type="email"` input), `advisor_cta` (home has link to `https://moneyfrog.in/contact-us`).
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement per canvas artboards 1 and 2 (sidebar: Invest card with MF risk line, popular = tag `popular` newest 3, other topics). Category sub-topic pills = the tags used inside that topic (`?tag=<slug>` filter); sort select = `?order=asc|desc` on date (default desc), kept across pagination; first post tagged `start-here` in the topic is pinned on page 1.
- [ ] **Step 4:** Run smoke; shoot home and `/category/mutual-funds/` at 1440/390. Expected: PASS and matches canvas.
- [ ] **Step 5:** Commit `feat(theme): home, category, search, cards and covers`; push.

---

## Phase 4 — Migration pipeline (Node, reads old site only)

### Task 8: Fetch + sanitise

**Files:**
- Create: `migration/src/fetch.mjs`, `migration/src/sanitize.mjs`, `migration/src/allowlist.mjs`, `migration/test/sanitize.test.mjs`, `migration/test/fixtures/{dec-2025.html,hinglish.html,finance-bonus.html,iframe-youtube.html}`

**Interfaces:**
- Produces:
  - `fetchAll({ base, outDir }): Promise<{posts:number,pages:number,media:number,categories:number,users:number}>` — paginates `/wp-json/wp/v2/{posts,pages,media,categories,tags,users}?per_page=100&context=view`, writes `outDir/raw/<type>.json`, 1 req/sec, User-Agent `CodesparkQA-migration`, retries 3× on 5xx, never sends auth.
  - `sanitize(html: string, { allow: string[] }): { html: string, removed: Array<{kind:'script'|'iframe'|'hidden'|'handler'|'link'|'paragraph', detail:string}>, flags: Array<{kind:'non-english'|'keyword', excerpt:string}> }`.
  - Rules: drop `<script>`, `<style>`, `<object>`, `<embed>`, `on*` attributes, elements hidden by inline style (`display:none`, `visibility:hidden`, `font-size:0`, off-screen positioning) or `hidden` attr; `<iframe>` kept only for `youtube.com`/`youtube-nocookie.com`; outbound `<a>` to a non-allow-listed domain is unwrapped (text kept) and logged; a paragraph is **removed** when it contains a gambling/betting term (`casino|betting|sportsbook|poker|slot machine|jackpot|bonus code|freispiele|wett`) AND either a non-allow-listed link or ≥ 3 German stopwords (`und|der|die|das|nicht|sich|mit|für|ist|eine|wie|zum`); a paragraph with ≥ 3 German stopwords or the keyword alone is only **flagged**.
- Allow-list (`allowlist.mjs`, builder may extend): `moneyfrog.in`, `blog.moneyfrog.in`, `amfiindia.com`, `sebi.gov.in`, `rbi.org.in`, `incometax.gov.in`, `irdai.gov.in`, `npstrust.org.in`, `india.gov.in`, `youtube.com`, `youtu.be`, plus any `*.gov.in`.

- [ ] **Step 1: Write failing tests** with fixtures:
  `removes german casino paragraph` (dec-2025 fixture: output lacks `betalice`/`casino`; `removed` has one `paragraph`; the three real "Negatives in November" bullets remain) · `keeps hinglish and finance bonus` (hinglish fixture unchanged; finance-bonus "Pay hike or Bonus" unchanged, at most one `keyword` flag) · `strips scripts handlers and hidden` · `keeps youtube iframe drops others` · `unwraps non-allowlisted links keeps allowlisted`.
- [ ] **Step 2:** `npm test`. Expected: FAIL.
- [ ] **Step 3:** Implement with `cheerio` (+ `sanitize-html` for the final whitelist pass).
- [ ] **Step 4:** `npm test`. Expected: PASS. Then run `node src/fetch.mjs` against the live old blog once. Expected counts ≈ posts 240, pages 73, media 314, categories 23, users 9 (record actuals in the report).
- [ ] **Step 5:** Commit `feat(migration): read-only fetch and sanitiser`; push (output stays ignored).

### Task 9: Topics, slugs, redirect map, review CSV

**Files:**
- Create: `migration/src/topics.mjs`, `migration/src/slugs.mjs`, `migration/test/topics.test.mjs`, `migration/test/slugs.test.mjs`

**Interfaces:**
- Produces:
  - `mapTopic({ type, title, categorySlugs }): { topic: string, tags: string[] }` — first match wins: `type==='page'` (Moneyfrog School) → `guides`; categories `infographics|faq` → `guides`; title `/market (outlook|update|commentary)|sensex|nifty|midcap|budget 20\d\d.*market/i` or category `market` → `market-outlook`; categories `mutual-funds` or title `/mutual fund|\bsip\b|elss|debt fund|nfo/i` → `mutual-funds` (+ tags `sip` / `debt-funds` / `equity-funds` / `direct-vs-regular` by title); `tax|budget` → `tax`; `insurance|health-insurance|life-insurance` → `insurance`; `retirement` or title `/retire|nps|pension/i` → `retirement`; else `financial-planning`.
  - `cleanSlug(slug: string): string` — decode, strip U+FEFF and `%ef%bb%bf`, lowercase, `[^a-z0-9-]` → `-`, trim dashes.
  - `assignSlugs(items: Array<{oldId, type, slug, oldPath}>): Array<{oldId, type, newSlug, oldPaths: string[]}>` — collisions get `-2`, `-3` in date order (oldest keeps the bare slug); every item lists all old paths (raw BOM form, cleaned form, `/moneyfrog-school/<slug>/` for pages).
  - `buildRedirects(assigned, categoryMap, authorMap): Record<string,string>` — normalised old path → `/<newSlug>/`; old category path → `/category/<topic>/` (`/category/market/` → `/category/market-outlook/`, `/category/financial-planning/` → itself); old `/category/<parent>/<child>/` → topic; old `/author/<old-user-slug>/` → `/author/<byline-slug>/` (e.g. `/author/mushtaq/` → `/author/mushtaq-kazi/`); skips identity entries.
  - `writeReviewCsv(rows, file)` columns `old_id,type,date,title,old_categories,new_topic,new_tags,new_slug,flags`.

- [ ] **Step 1: Write failing tests:** `market outlooks leave financial planning` ("Market Outlook – December 2025" with category `financial-planning` → `market-outlook`) · `school page goes to guides` · `hinglish mf title maps to mutual-funds` ("Kitna Sahi Hai Mutual Fund") · `bom slug cleaned` · `collision keeps both and maps each old URL` (post `gilt-fund` + school page `gilt-fund` → `gilt-fund` (older) and `gilt-fund-2`; `/moneyfrog-school/gilt-fund/` → `/gilt-fund-2/` when the page is newer) · `category redirects`.
- [ ] **Step 2:** `npm test`. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** `npm test` PASS; run on real raw data → `output/review.csv`. **Gate:** the owner (Saurabh or nominee) signs off the CSV and the sanitise report before Task 11 runs anywhere but local.
- [ ] **Step 5:** Commit `feat(migration): topic mapping, slugs and redirect map`; push.

### Task 10: Bundle builder

**Files:**
- Create: `migration/src/build.mjs`, `migration/test/build.test.mjs`

**Interfaces:**
- Consumes: Tasks 8–9 functions.
- Produces: `output/bundle.json` shaped `{ generatedAt, source, items: Item[], bylines: Byline[], media: Media[] }` where `Item = { oldId, oldType:'post'|'page', newSlug, title, html, excerpt, date, modified, topic, tags[], byline:slug, featuredMediaOldId|null, oldPaths[] }`, `Byline = { slug, name, bio, role }` (bios from the old post-footer author blurbs where present, else empty), `Media = { oldId, sourceUrl, alt, oldPaths[] }`; `output/report.html` (per-item removals and flags, counts); `wp-content/mu-plugins/moneyfrog-core/data/redirects.php` (`<?php return [ '/old/' => '/new/', … ];` paths only, sorted); `output/inventory.txt` (every old public URL: posts, pages, categories, author archives, image URLs).
- Bylines map old user display names to slugs (`mushtaq-kazi`, …). No logins, emails or user IDs are written.

- [ ] **Step 1: Write failing test** `bundle has no secrets and every old path redirects` (fixture raw set: no key named `email`/`password`/`user_login` anywhere in the bundle; every `oldPaths` entry is either the item's own new path or a key in redirects).
- [ ] **Step 2:** `npm test`. Expected: FAIL.
- [ ] **Step 3:** Implement; `node src/build.mjs` → files above.
- [ ] **Step 4:** `npm test` PASS; real run: items = posts + pages, report reviewed.
- [ ] **Step 5:** Commit `feat(migration): bundle and redirect map builder` plus the generated `data/redirects.php` (paths only); push.

### Task 11: WP-CLI importer (idempotent)

**Files:**
- Create: `wp-content/mu-plugins/moneyfrog-core/src/Cli/ImportCommand.php`, `src/Cli/Importer.php`, `tests/smoke/inventory.sh`
- Test: `tests/php/ImporterTest.php` (pure parts) + smoke checks against local

`inventory.sh <base-url> <inventory.txt>`: for each old URL (host rewritten to `<base-url>`), follow at most one redirect; PASS only if the final status is 200 and there was at most one 301; prints a FAIL line per bad URL and a total; exit non-zero on any FAIL.

**Interfaces:**
- Consumes: `bundle.json` (Task 10), `Byline` (Task 5), `Content::addHeadingIds` (Task 4).
- Produces: `wp mf import <bundle> [--dry-run] [--only=<oldId>]` printing `created|updated|skipped|failed` counts and exiting non-zero if any `failed`. Upsert keys: posts by meta `_mf_old_id` + `_mf_old_type`; media by meta `_mf_old_media_id`; bylines by term slug; topics as categories by slug with names from `Config::TOPICS`. Post author = one service user `mf-import` (role `author`, random password, 2FA, not shown publicly). Dates kept (`post_date`, `post_modified` set from bundle). Images: download `sourceUrl`, re-encode via `wp_get_image_editor()` → save as new file, set alt; rewrite in-body `src`/`srcset` from old URLs to new attachment URLs; failed downloads → image tag removed, logged, post still imported.
- Pure helper: `Importer::rewriteImageUrls(string $html, array $oldToNew): string` (unit-tested).

- [ ] **Step 1: Write failing tests:** `test_rewrite_image_urls_handles_src_and_srcset` · `test_second_run_updates_not_duplicates` (smoke-level: import fixture bundle twice locally; `wp post list --post_type=post --format=count` same after both; media count same) · `test_missing_image_does_not_abort_post` (fixture with a 404 image URL → post exists, report line `failed-image`, exit code 0).
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run full real bundle locally. Expected: created = items count, 0 failed; second run: 0 created; smoke + `tests/smoke/inventory.sh http://localhost:8080 migration/output/inventory.txt` all PASS.
- [ ] **Step 5:** Commit `feat: idempotent WP-CLI importer`; push.

### Task 12: Single post template

**Files:**
- Create: `wp-content/themes/moneyfrog/single.php`, `template-parts/{author-box.php,share.php}`, `assets/js/{progress.js,copy-link.js}`
- Modify: `tests/smoke/smoke.sh`

**Interfaces:**
- Consumes: `Content::toc`, `Content::addHeadingIds` (applied on `the_content` filter in the plugin), `Content::readingTime`, `Byline::forPost`, `Byline::initials`, disclaimer part.
- Produces: post page per canvas artboard 3. Share links: LinkedIn `https://www.linkedin.com/sharing/share-offsite/?url=`, WhatsApp `https://wa.me/?text=`, X `https://x.com/intent/post?url=`; copy-link button (`navigator.clipboard`, visible "Copied" state, works without JS as a plain link). TOC shown only when `toc()` non-empty. Previous/next = same topic. "Keep reading" = 3 newest same-topic excluding self. JSON-LD `BlogPosting` (headline, datePublished, dateModified, author name from byline, publisher Moneyfrog).

- [ ] **Step 1:** Smoke on an imported real post (Market Outlook – December 2025, clean slug): `single_has_disclaimer` (exact disclaimer copy), `single_toc` (links `#positives-in-november`), `single_no_spam` (no `betalice`, no `casino`), `single_byline` (`Mushtaq Kazi`, `MK`), `single_jsonld` (`"@type":"BlogPosting"`), `single_no_comments` (no `respond`), `single_old_bom_url_redirects` (old BOM URL → 301 → this post, 200).
- [ ] **Step 2:** Run. Expected: FAIL.
- [ ] **Step 3:** Implement.
- [ ] **Step 4:** Run smoke; shoot the post at 1440/390. Expected: PASS and matches canvas.
- [ ] **Step 5:** Commit `feat(theme): single post page`; push.

---

## Phase 5 — Ship

### Task 13: Staging on GoDaddy

**Files:**
- Create: `infra/deploy.sh`, `infra/provision.md`
- Modify: `wp-content/mu-plugins/moneyfrog-core.php` (send `X-MF-Release: <sha>` read from `wp-content/.mf-release` when present), `tests/smoke/smoke.sh` (`two_factor_enforced`: every user with `edit_posts` has two-factor enabled, via `wp user list` + `wp user meta get … _two_factor_enabled_providers`)

**Interfaces:**
- Consumes: Task 1 PHP handler; pushed SHA.
- Produces: `infra/deploy.sh <target:staging|live> <sha>` — refuses unless `<sha>` is on `origin/main` (`git branch -r --contains`); `git archive <sha> wp-content composer.json` → tar → scp to the server → extract into the target docroot's `wp-content/` (never touching anything else), `composer install --no-dev` there for `two-factor`, `wp cache flush`, then prints `DEPLOYED <sha>` and writes `wp-content/.mf-release` containing the SHA. Server host/user/paths read from a git-ignored `infra/.env.deploy`.
- `provision.md` (no secrets): create staging subdomain under `monefrog.tech`, set PHP 8.3 for its docroot only, new DB + least-privilege user, `wp core download` latest, `wp-config.php` generated with new salts, table prefix `mfb_`, the `define`s from Task 3, `wp-config.php` placed one level above docroot, basic auth + `noindex` (`blog_public=0`), AutoSSL.

- [ ] **Step 1:** Provision staging per `provision.md` (owner-approved account; creds into the private notes + ledger, never git).
- [ ] **Step 2:** `infra/deploy.sh staging <pushed sha>`. Verify independently: `.mf-release` on the server = SHA and the `X-MF-Release` response header matches; `wp eval 'echo PHP_VERSION;'` = 8.3.x; `wp core version` = latest. Create the real editor accounts the owner names, each with two-factor set up before first publish.
- [ ] **Step 3:** Copy `bundle.json` up (scp, not git), run `wp mf import`, then run again (expect 0 created).
- [ ] **Step 4:** `tests/smoke/smoke.sh https://<staging>` and `tests/smoke/inventory.sh https://<staging> migration/output/inventory.txt` (rewriting host). Expected: all PASS, inventory 100% 200/one-301. Shoot 10 posts across years + home + every topic at 1440/390.
- [ ] **Step 5:** Owner reviews staging. Commit `chore: deploy script and provisioning guide`; push.

### Task 14: Backups + restore drill

**Files:**
- Create: `infra/backup.sh`, `infra/restore.md`

**Interfaces:**
- Produces: `backup.sh` (runs on the server via cPanel cron): `mysqldump` (single-transaction) gz + weekly `tar` of `wp-content/uploads`, upload to S3 prefix `blog-backups/<env>/` with a write-only IAM user (keys in server-side env file, never git), keep 30 daily / 8 weekly, exit non-zero and email the cPanel contact on failure.

- [ ] **Step 1:** Configure the bucket prefix and IAM user (owner-approved); cron daily 02:30 IST.
- [ ] **Step 2:** Run once manually on staging; verify the objects in S3.
- [ ] **Step 3:** Restore drill per `restore.md` into a scratch DB/docroot on staging; smoke suite PASS against it.
- [ ] **Step 4:** Commit `chore: backups and restore drill`; push.

### Task 15: LIVE cut-over (blocked on Cloudflare access)

**Files:**
- Modify: `docs/private/infra-and-security-notes.md` (cut-over log)

- [ ] **Step 1:** Provision the LIVE docroot like staging (no basic auth, `blog_public=1`), deploy the same SHA, import the signed-off bundle twice (second run 0 created).
- [ ] **Step 2:** Before switching DNS, test LIVE via host override (`curl --resolve blog.moneyfrog.in:443:<new-ip>`): smoke + inventory all PASS.
- [ ] **Step 3:** Owner (or Cloudflare holder) points `blog.moneyfrog.in` at the new origin; purge Cloudflare cache.
- [ ] **Step 4:** Verify from outside: response shows new theme + `.mf-release` SHA via `X-MF-Release` header; smoke + inventory PASS on `https://blog.moneyfrog.in`; moneyfrog.in header/footer/Learning Academy links land (200); old BOM URL 301s; `/wp-json/wp/v2/users`, xmlrpc, comments closed; submit `/wp-sitemap.xml` in Search Console.
- [ ] **Step 5:** Record evidence (SHA, timestamps, check output) in the private notes; rollback = re-point origin to the old server.
