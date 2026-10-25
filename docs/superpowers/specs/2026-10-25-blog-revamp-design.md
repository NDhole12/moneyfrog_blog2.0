# blog.moneyfrog.in — Revamp Design

Date: 2026-10-25 · Status: **approved 2026-10-25** · Owner: Saurabh Dhole (Codespark)

## 1. Intent

Replace the current blog at `blog.moneyfrog.in` with a **completely new WordPress site**
that looks like the redesigned moneyfrog.in (live since 20 Oct 2026) but reads like a
blog. Only the old site's **content and images** come across. Nothing else does.

Why now:

- The old blog runs **WordPress 5.0.14** (2018, no security fixes) with theme `restimpo`.
- It contains **injected third-party spam** in at least one recent post, which is a
  compliance risk for an AMFI/IRDAI-registered brand. (Details in the private notes.)
- It looks nothing like the new website, which links to it from its header (Resources ›
  Blogs, Market Outlook), its footer, and the homepage "Learning Academy".

## 2. What exists today (recon 2026-10-25, read-only)

| Item | Fact |
|---|---|
| Public URL | `https://blog.moneyfrog.in/`, behind Cloudflare |
| Content | **240 posts** (Dec 2014 → Dec 2025), **73 pages**, of which ~70 are "Moneyfrog School" articles at `/moneyfrog-school/<slug>/`; **314 media items** |
| Post URLs | `/<post-slug>/` (some slugs contain a stray `%ef%bb%bf` BOM, e.g. `/market-outlook-december-2025%ef%bb%bf/`) |
| Category URLs | `/category/<slug>/`; moneyfrog.in links to `/category/market/` |
| Authors | 9: Manoj Chahar (85 posts), Mushtaq Kazi (73), Rohit Grover (26), Harshada Kadam (26), Shital Ufale (18), Pankti Chheda (7), Pujan Jain, Sanket, Pooja Vorani |
| Categories | 23 and messy: **141 of 240 posts sit in "Financial Planning"**, including ~60 monthly Market Outlooks; "Market" holds only 8 |
| Images | 104 of 240 posts have **no featured image** |
| Comments | Enabled on the old site |
| Old copy on GoDaddy | An earlier server migration left a stale, non-running copy of the old blog (and its database) on the target GoDaddy account. It is never used and never touched. |
| Live origin | The live old blog is still served from the old server, not GoDaddy. `moneyfrog.in` DNS is on Cloudflare. |
| Server details | Account, paths, database names and other findings are in `docs/private/` (git-ignored, kept off GitHub). |

## 3. Goals

1. A new, fully updated WordPress (latest stable) on **PHP 8.3** (8.2 minimum), hardened.
2. Three page types matching the approved mockups (section 6): blog home, category, post.
3. Every post, Moneyfrog School page and image moved over, **cleaned of injected spam**.
4. **No URL breaks**: every old post, page, category and image URL either still works or
   301-redirects to its new home. Links from moneyfrog.in and Google keep working.
5. Posts re-sorted into a clean topic structure (section 7).
6. **Comments fully off.**
7. Fast and readable on phones.

## 4. Non-goals

- No change to moneyfrog.in itself (it keeps linking to `blog.moneyfrog.in`).
- No reader accounts, logins, memberships or paywall.
- No rewriting or fact-checking of old article text beyond spam removal and broken-markup fixes.
- No new content.

## 5. Anti-goals (a failure even if it "works")

- Anything from the old install carried over: theme, plugins, `wp-config.php`, users,
  passwords, uploads that aren't images, options, widgets, cron entries. That's where the
  compromise lives.
- Building on top of the old docroot or the old database.
- Spam or hidden links surviving the move into the new site.
- A page builder (Elementor and the like) or a pile of plugins. Each plugin is attack surface.
- A design that drifts from moneyfrog.in (different fonts, colours, header or footer).
- Losing Google rankings because old URLs 404.
- Comment forms, comment counts, or a working `wp-comments-post.php` anywhere.

## 6. Design (agreed direction)

Mockups: the **"Moneyfrog Blog Redesign" design canvas** (3 artboards: Blog home,
Category page, Blog post detail; link shared separately, private until shared).

Source of truth for tokens and components: `d:\moneyfrog_client_front`, branch
`feat/site-redesign-index2`, file `public/index2-assets/css/style.css` and partials
`resources/views/site/index2/_header|_footer|_learning.blade.php`.

- Colours: bg `#f9f8f4`, band `#f3efe5`, topbar `#eae3d9`, ink `#16191d`, ink-2 `#4c525a`,
  gold-ink `#8a6a1f`, gold `#c29a2c`, night `#0b0f14`, lines `#e7e2d8`/`#ddd6c7`; gold
  button gradient as on the site.
- Type: Fraunces (headings), Instrument Sans (body), IBM Plex Mono (eyebrows/labels),
  **self-hosted** woff2 copied from `public/index2-assets/fonts/` (no Google Fonts call).
- Shared chrome: the site's top bar, header (logo, Offerings, Who We Serve, About Us,
  Financial Calculators, Resources, Log In, Sign Up, mobile drawer) and footer, with links
  pointing to moneyfrog.in. Under the header, a **blog bar**: "Insights." wordmark, topic
  pills, search.
- **Blog home:** featured post + 4 editor's picks · Browse by topic (6 tiles) · Market
  Outlook strip (latest 4) · Latest articles grid + "Load more" · "New to investing? Start
  here" (5 Moneyfrog School reads) · newsletter band · advisor CTA · footer.
- **Category page:** breadcrumb, title, article count, description, sub-topic filter pills,
  sort · pinned "start here" post · list rows (thumb + category + title + excerpt + author ·
  date · read time) · numbered pagination · sidebar: Invest-with-Moneyfrog card (with MF
  risk line), popular in topic, newsletter, other topics.
- **Post page:** reading-progress bar · breadcrumb, category, H1, dek, author avatar,
  date, read time, share (LinkedIn, WhatsApp, X, copy link) · wide hero image · "On this
  page" table of contents from H2s · optional Key takeaways box · body at ~68ch / 18px ·
  tables in a horizontal-scroll box · **disclaimer box on every post** · tags · author box
  · "Talk to an advisor" box (in place of comments) · previous/next in series · "Keep
  reading" (3 related) · newsletter · footer.
- **No-image fallback:** posts without a featured image get a typographic cover (cream or
  night background, the topic or a short phrase in Fraunces italic, gold accent). It's
  generated by the theme, never a stock photo.
- "Book a call" / "Talk to an advisor" link to moneyfrog.in's existing book-a-call flow.

## 7. Topic structure (my call; the editor can rename later)

| New topic (slug) | Gets |
|---|---|
| Market Outlook (`market-outlook`) | Every "Market Outlook …" / market-update post, now pulled out of Financial Planning |
| Mutual Funds (`mutual-funds`) | Mutual Funds category + MF-titled posts; sub-tags SIP, Debt Funds, Equity Funds, Direct vs Regular |
| Tax (`tax`) | Tax + Budget |
| Insurance (`insurance`) | Insurance, Health Insurance, Life insurance |
| Retirement (`retirement`) | Retirement + NPS-titled posts |
| Financial Planning (`financial-planning`) | What's left: goals, life stages, investments, FDs, property & loan, E-Will, robo advisors, how-to, resolutions |
| Guides (`guides`) | All Moneyfrog School pages, converted to **posts** in this topic; Infographics and FAQ posts go here too |

The mapping runs as a script with a review CSV (old category → new topic per post) that a
human signs off before import. Old category URLs 301 to the new topic (`/category/market/`
→ `/category/market-outlook/`, and so on).

## 8. Content migration

- **Source:** the old site's public REST API (`/wp-json/wp/v2/posts|pages|media|categories|users`,
  read-only). Only post/page content, title, slug, dates, author name, categories, tags,
  excerpt, featured image and in-body images are read. Nothing else.
- **Sanitising (mandatory, per item):** strip `<script>`, `<iframe>` (except allow-listed
  YouTube), inline event handlers, hidden elements (`display:none`, zero-size, off-screen),
  and every outbound link whose domain isn't on an allow-list (moneyfrog.in, gov/regulator
  sites, AMC sites, YouTube…). Flag any non-English paragraph and any gambling, pharma or
  loan keyword for human review. One known case is listed in the private notes.
  Produce a **sanitising report** listing every removal per post.
- **Images:** download every referenced image, re-upload into the new media library
  (re-encoded, so no file passes through byte-for-byte), and rewrite in-body URLs. Old
  `/wp-content/uploads/...` URLs keep working at the same paths or redirect.
- **Slugs:** keep every post slug, so `/<slug>/` keeps working. Clean BOM slugs
  (`%ef%bb%bf`) and 301 the old form to the clean one. Moneyfrog School pages move to
  posts but keep working at `/moneyfrog-school/<slug>/` through 301s to the new post URL.
- **Authors:** byline names and short bios carried as content. **No old accounts.** New
  WordPress logins are created only for people who will publish (names TBD by Moneyfrog).
  Posts by people with no new login are attributed to a non-login author profile with the
  same name.
- **Dates:** original publish dates kept. "Updated" date shown only if edited after migration.
- **Read time:** computed by the theme from word count.

## 9. Security & hardening (must)

- Fresh latest WordPress core, **PHP 8.3**, new database, new DB user (least privilege),
  new salts, table prefix not `wp_`, `DISALLOW_FILE_EDIT` and `DISALLOW_FILE_MODS` (updates
  via deploy/WP-CLI), `WP_DEBUG` off in production, `wp-config.php` outside the docroot or
  with denied web access.
- **Comments off everywhere:** default comment and ping status closed; existing comments
  not imported; comment support removed from post types; comment feeds, the
  `wp-comments-post.php` endpoint, trackbacks/pingbacks and XML-RPC disabled (return 403/404).
- REST API: `/wp/v2/users` and author archives that leak usernames blocked to the public;
  author bylines link to an author page by display slug, not login.
- Login: renamed or limited login URL, rate-limited, **2FA for every account**, no `admin`
  username, strong unique passwords stored per the credentials rule (never in git).
- Plugins: minimal and only actively maintained ones from wordpress.org. Proposed set: a
  2FA plugin and a backup or security plugin if cPanel backups aren't enough. Everything
  else (comments-off, hardening, SEO meta, sitemaps via core, no-image covers, TOC, read
  time) lives in the theme or a small must-use plugin in our repo.
- Headers: HSTS, X-Content-Type-Options, Referrer-Policy, a CSP matching what the site
  needs (self-hosted fonts mean no Google domains).
- HTTPS via cPanel AutoSSL (available), plus Cloudflare.
- Backups: daily database + weekly files, kept off the server; restore tested once before go-live.
- Automatic minor/security core updates on; a monthly update check for plugins and theme.
- Old install: left exactly as it is (owner's rule). Once the domain points at the new
  site, the old one simply stops receiving visitors. What happens to it later is the owner's
  decision, outside this project.

## 10. Hosting & go-live

- **Server: Moneyfrog's GoDaddy cPanel account**, chosen by the user (account details in
  `docs/private/`). cPanel login works (checked 2026-10-25). AutoSSL, SSH and phpMyAdmin are
  enabled, with ample free disk.
- **Blocker to clear first:** this account has **MultiPHP switched off**. The builder must
  confirm PHP 8.3 is available for the new docroot (via SSH `php -v` /
  `ls /opt/cpanel/ea-php*`, or GoDaddy support enabling MultiPHP / PHP 8.3). No build on PHP
  below 8.2.
- **Owner's rule: do not touch the old code. Build totally new; only import from the old
  blog.** No edit, move, delete, restart or fix on the old live blog or on the old copy on
  GoDaddy (files or database). The old site is only ever **read** through its public pages
  and REST API. Known spam stays on the old site until cut-over replaces it.
- New, separate docroot outside any existing site folder, and a new database. The old
  GoDaddy copy is never used as a source.
- Because the old copy shares the cPanel Unix user, the builder runs a **read-only** scan of
  the account for webshells or unknown PHP files and reports what it finds before
  installing. It removes or changes nothing; what to do with any finding is the owner's call.
- At cut-over the `blog.moneyfrog.in` domain is pointed at the new docroot (a cPanel and
  Cloudflare setting). No old file changes.
- **PHP rule:** PHP 8.3 is set **per docroot only**. The CRM's PHP version must not change.
  If GoDaddy can't give PHP 8.2+ to the new docroot without touching the CRM, stop and come
  back to the owner. No workaround on an old PHP.
- **Staging first** on a staging hostname (a subdomain of the hosting account's domain, see `docs/private/`, behind basic
  auth, `noindex`). Full migration rehearsed and checked there. Only then switch
  `blog.moneyfrog.in` to the new docroot.
- Cut-over: point the `blog.moneyfrog.in` addon domain (and Cloudflare origin) at the new
  site, purge Cloudflare, check redirects, submit the sitemap in Search Console.
- Rollback: point the domain back at the old origin (the old site is untouched, so it is
  still there) within minutes.

## 11. Done means

Live on `blog.moneyfrog.in`, served by the new install (confirmed by WordPress/PHP version
and theme in the response, origin IP), and tested there:
- Home, one page per topic, and at least 10 posts across years render with no PHP errors
  at 1440 px and 390 px.
- 100% of old post/page/category URLs from the inventory return 200 or a single 301 to a 200.
- Spam scan of all migrated content comes back clean; the sanitising report has been reviewed.
- Comments endpoints, XML-RPC and `/wp-json/wp/v2/users` are closed (checked from outside).
- The moneyfrog.in header, footer and Learning Academy links all land correctly.
- Backups are running and one restore has been tested.

## 12. Left to the builder

Theme architecture (classic PHP templates vs a block theme), build tooling, migration
script language, exact plugin picks within section 9, the spam allow-list contents,
image sizes, search implementation (core search is fine), "Load more" vs pagination on home.

## 13. Calls I made (to check)

- Topic structure and mapping in section 7, including turning Moneyfrog School pages into posts in "Guides".
- Author bylines kept as content; no old accounts carried over.
- **Newsletter deferred:** v1 ships without collecting emails (no mail setup, no personal
  data stored). The newsletter band is built but switched off until Moneyfrog picks an email
  tool; the "Talk to an advisor" boxes stay.
- **Disclaimer text:** copied word for word from the moneyfrog.in footer (Mutual Fund +
  Insurance disclaimers), the same on every post, plus "Articles on this blog are for
  education only and are not investment advice." No new compliance wording is invented.
- **Tracking:** the same GTM container as moneyfrog.in, allowed in the CSP, so marketing
  reporting continues. Nothing else third-party.
- **Content source:** the live old site's public REST API (read-only), since the GoDaddy
  copy is stale.
- **Backups:** off-server to a dedicated prefix in Moneyfrog's existing AWS S3 (ap-south-1),
  with its own write-only IAM user.
- **Sign-offs:** Saurabh (or whoever he names, e.g. Sahil) approves the topic-mapping CSV and
  the sanitising report before import.
- Self-hosted fonts instead of Google Fonts.
- Staging hostname: a subdomain of the hosting account's domain (named in `docs/private/`).
- Mockups: the builder is this same team, with access to the canvas.

## 14. Open dependency (needed before cut-over, not before building)

- **Cloudflare access for `moneyfrog.in`** (owner to say who holds it) to point
  `blog.moneyfrog.in` at the new site, purge the cache, and roll back if needed. Plus
  **Google Search Console** access for the blog to submit the sitemap and watch for 404s.
  Staging and the full build can go ahead without it.
