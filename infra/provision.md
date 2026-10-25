# Provisioning the Moneyfrog blog (staging, then LIVE)

A runbook for a shared Linux host (CloudLinux, Apache, per-docroot PHP handler, SSH with a password). It contains **no secrets, hosts, usernames or paths**: use the placeholders below and keep the real values in `docs/private/` and the account credentials ledger (both outside git). Never paste a credential into a commit, a ticket or a chat.

| Placeholder | Meaning |
|---|---|
| `<STAGING_HOST>` / `<LIVE_HOST>` | public hostname of the staging / live blog |
| `<ACCOUNT_HOME>` | the hosting account's home directory |
| `<STAGING_ROOT>` / `<LIVE_ROOT>` | docroot of staging / live (becomes `STAGING_ROOT` / `LIVE_ROOT` in `infra/.env.deploy`) |
| `<DB_NAME>` `<DB_USER>` | new database and its least-privilege user |
| `<ADMIN_LOGIN>` | administrator login chosen by the owner (**never `admin`**) |

Rules that apply throughout: the old blog and the existing sites on this account are never touched, the account's PHP version for other sites never changes, and nothing here is run until the owner has approved the account.

## 0. Prerequisites (local)

1. `infra/.env.deploy` exists locally, copied from `infra/.env.deploy.example` (git-ignored).
2. The commit to deploy is pushed (`git ls-remote origin` shows it) and the working tree is clean.
3. `migration/output/` holds `bundle.json` and `media/` (git-ignored; they travel by scp/rsync only).

## 1. Subdomain and docroot (staging)

1. In the hosting control panel create the subdomain `<STAGING_HOST>`.
2. Give it its **own docroot outside every existing site**, e.g. `<ACCOUNT_HOME>/blog-staging/public` (not inside another site's docroot). Record it as `<STAGING_ROOT>`.
3. Confirm DNS resolves to the account and the empty docroot answers over HTTP.

## 2. PHP 8.3 for this docroot only

The panel's PHP selector is unavailable, so the handler is chosen per docroot in `<STAGING_ROOT>/.htaccess` (first lines):

```apache
<IfModule mime_module>
  AddHandler application/x-httpd-ea-php83 .php
</IfModule>
```

Verify (no `phpinfo` page is ever created):

```sh
ssh <USER>@<HOST> 'cd <STAGING_ROOT> && wp eval "echo PHP_VERSION;"'   # after step 5; must print 8.3.x
```

If the server's CLI `php` is older, set `REMOTE_PATH_PREFIX` in `.env.deploy` to the directory holding the PHP 8.3 CLI (on cPanel hosts typically `/opt/cpanel/ea-php83/root/usr/bin`), so `composer` and `wp` use it.

## 3. Database

1. Create a new database `<DB_NAME>` and a new user `<DB_USER>` with a long random secret.
2. Grant only what WordPress needs on that one database: `SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES`. No global privileges.
3. Record host, name, user and the secret in the credentials ledger (outside git).

## 4. WordPress core

```sh
cd <STAGING_ROOT>
wp core download --locale=en_US          # latest stable
```

## 5. wp-config.php (one level above the docroot)

```sh
cd <STAGING_ROOT>
wp config create --dbname=<DB_NAME> --dbuser=<DB_USER> --prompt=dbpass \
    --dbprefix=mfb_ --skip-check --extra-php <<'PHP'
define('DISALLOW_FILE_EDIT', true);
define('DISALLOW_FILE_MODS', true);
define('WP_AUTO_UPDATE_CORE', 'minor');
define('WP_DEBUG', false);
define('FORCE_SSL_ADMIN', true);
PHP
mv wp-config.php ../wp-config.php         # WordPress finds it one directory above the docroot
```

`wp config create` generates fresh salts. Check afterwards: `wp config get table_prefix` prints `mfb_`; `wp config get DISALLOW_FILE_MODS` prints `1`. Because `DISALLOW_FILE_MODS` is on, the Two Factor plugin and core updates are installed by deploy/CLI only, never from wp-admin.

## 6. Install and base settings

```sh
wp core install --url=https://<STAGING_HOST> --title="Moneyfrog Insights" \
    --admin_user=<ADMIN_LOGIN> --admin_email=<OWNER_EMAIL> --prompt=admin_password --skip-email
wp option update timezone_string Asia/Kolkata
wp rewrite structure '/%postname%/' --hard
wp option update default_comment_status closed
wp option update default_ping_status closed
```

Use a strong generated secret for the administrator and store it in the ledger. `<ADMIN_LOGIN>` must not be `admin` (the audit fails otherwise).

## 7. Staging gate: hidden from search, behind basic auth

1. `wp option update blog_public 0` (adds `noindex`).
2. Create the credentials file **outside** the docroot: `htpasswd -c <ACCOUNT_HOME>/.htpasswd-blog-staging <STAGING_USER>` (secret into the ledger).
3. Append to `<STAGING_ROOT>/.htaccess`:

```apache
AuthType Basic
AuthName "Staging"
AuthUserFile <ACCOUNT_HOME>/.htpasswd-blog-staging
Require valid-user
```

4. Issue the TLS certificate with the panel's AutoSSL for `<STAGING_HOST>` and confirm `https://<STAGING_HOST>/` answers 401 without credentials.

Smoke and inventory runs supply the credentials through `SMOKE_AUTH=<STAGING_USER>:<secret>` (environment only; never printed, never committed).

## 8. First deploy

From the repo, on the pushed commit:

```sh
infra/deploy.sh --dry-run staging <sha>      # read the plan
infra/deploy.sh staging <sha>                # prints: DEPLOYED <sha> staging
```

This ships `wp-content/mu-plugins`, `wp-content/themes/moneyfrog` and `composer install --no-dev` (which installs the Two Factor plugin), syncs only those three directories, writes `wp-content/.mf-release`, and flushes cache and rewrites. Then on the server:

```sh
wp theme activate moneyfrog
wp plugin activate two-factor
wp eval 'echo PHP_VERSION;'                  # 8.3.x
wp core version                              # latest
```

Verify the release independently: `curl -sI -u "$SMOKE_AUTH" https://<STAGING_HOST>/ | grep -i x-mf-release` shows the first 12 characters of `<sha>`.

## 9. Editor accounts and two-factor

1. For each editor the owner names: `wp user create <login> <email> --role=editor --send-email` (or `--user_pass` from a generated secret).
2. Each editor signs in once and sets up Two Factor under Profile before publishing anything.
3. Run the audit; it must exit 0 before the site is considered ready:

```sh
wp mf audit
```

It prints JSON (`php`, `wp`, `timezone`, `two_factor_missing`, `file_edit_disabled`, `file_mods_disabled`, `admin_user_exists`, `xmlrpc_off`, `comments_open_count`, `blog_public`) and fails when any editor lacks Two Factor (the import service user `mf-import` is exempt, its login is blocked), either `DISALLOW_FILE_*` is not true, a user called `admin` exists, any post has comments open, or the timezone is not `Asia/Kolkata`. `blog_public` is reported only (0 on staging, 1 on LIVE).

## 10. Content: media, bundle, import, warm cache

```sh
# from the repo root; host, user and paths come from your private notes
rsync -a migration/output/media/ <USER>@<HOST>:<ACCOUNT_HOME>/mf-import/media/
scp migration/output/bundle.json <USER>@<HOST>:<ACCOUNT_HOME>/mf-import/bundle.json
```

The import copies the images into the uploads folder (keeping their original upload paths); the staging area is outside the docroot. Then on the server:

```sh
wp mf import <ACCOUNT_HOME>/mf-import/bundle.json --media-dir=<ACCOUNT_HOME>/mf-import/media --dry-run   # review the report
wp mf import <ACCOUNT_HOME>/mf-import/bundle.json --media-dir=<ACCOUNT_HOME>/mf-import/media
wp mf import <ACCOUNT_HOME>/mf-import/bundle.json --media-dir=<ACCOUNT_HOME>/mf-import/media             # second run: created=0
wp mf warm                                                                                              # caches each post's card image
```

The bundle and media are never committed. Delete `<ACCOUNT_HOME>/mf-import/` from the server when the import is accepted.

## 11. Smoke and inventory

```sh
export SMOKE_AUTH='<STAGING_USER>:<secret>'          # staging only
export SMOKE_SAMPLE_POST=/<a-published-post-slug>/   # enables the single-post checks
export SMOKE_BOM_PATH='/<old-slug>%ef%bb%bf/'        # optional; must 301 to SMOKE_SAMPLE_POST
export EXPECT_SHA=<40-hex deployed sha>              # checks X-MF-Release
tests/smoke/smoke.sh https://<STAGING_HOST>
tests/smoke/inventory.sh https://<STAGING_HOST> migration/output/inventory.txt
```

Checks that need WP-CLI (PHP version, `file_edit_disabled`, `two_factor_enforced`, import checks) show `SKIP` remotely; their remote equivalents are `wp mf audit` and the `wp eval` calls above. Expected: no `FAIL`, inventory every URL 200 or one 301 then 200.

## 12. Rollback

Each deploy keeps the previous releases under `<STAGING_ROOT>/../releases/` (newest 5 per target, named `<target>-<sha12>-<timestamp>`). To go back:

1. Re-deploy the previous pushed commit: `infra/deploy.sh staging <previous-sha>` (preferred, it updates `.mf-release` and flushes caches), or
2. By hand on the server: `rsync -a --delete <releases>/<name>/wp-content/mu-plugins/ <ROOT>/wp-content/mu-plugins/` (and likewise `themes/moneyfrog/` and `plugins/two-factor/`), write the old full SHA into `<ROOT>/wp-content/.mf-release`, then `wp cache flush && wp rewrite flush`.

Content rolls back separately: take `wp db export` before every import into a private location, and re-import it if needed. Uploads are never removed by a deploy.

## LIVE

Repeat sections 1 to 6 and 8 to 10 for `<LIVE_HOST>`/`<LIVE_ROOT>` with these differences:

- No basic auth, no `.htpasswd`; `wp option update blog_public 1`.
- Deploy with `infra/deploy.sh live <sha>`; the sha must be on `origin/main`, ideally the exact commit already verified on staging.
- Smoke and inventory run **without** `SMOKE_AUTH`, with `EXPECT_SHA` set.
- Before the switch take `wp db export` and keep the old blog untouched and reachable for rollback.
- **Owner step, Cloudflare:** the Cloudflare record for `<LIVE_HOST>` is pointed at the new origin (or the origin override changed) only after staging has passed. Rollback is pointing it back at the previous origin. Cloudflare is changed by the owner, not by scripts.
- After the switch re-run the smoke and inventory suites against the public URL, confirm `X-MF-Release` matches the deployed sha, and run `wp mf audit` again (`blog_public` reported as 1).
