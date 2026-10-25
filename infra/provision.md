# Provisioning the Moneyfrog blog (staging, then LIVE)

A runbook for a shared Linux host (CloudLinux, Apache, per-docroot PHP handler, SSH with a password). It contains **no secrets, hosts, usernames or paths**: use the placeholders below and keep the real values in `docs/private/` and the account credentials ledger (both outside git). Never paste a credential into a commit, a ticket or a chat.

| Placeholder | Meaning |
|---|---|
| `<STAGING_HOST>` / `<LIVE_HOST>` | public hostname of the staging / live blog |
| `<SSH_HOST>` `<SSH_PORT>` `<USER>` | SSH endpoint and account |
| `<ACCOUNT_HOME>` | the hosting account's home directory |
| `<STAGING_ROOT>` / `<LIVE_ROOT>` | docroot of staging / live (becomes `STAGING_ROOT` / `LIVE_ROOT` in `infra/.env.deploy`) |
| `<PHP83_BIN>` | the PHP 8.3 CLI binary on the server (cPanel hosts: `/opt/cpanel/ea-php83/root/usr/bin/php`) |
| `<WP_PATH>` `<COMPOSER_PATH>` | the `wp` and `composer` executables on the server (`command -v wp`, `command -v composer`) |
| `<DB_NAME>` `<DB_USER>` | new database and its least-privilege user |
| `<ADMIN_LOGIN>` | administrator login chosen by the owner (**never `admin`**) |

Rules that apply throughout: the old blog and the existing sites on this account are never touched, the account's PHP version for other sites never changes, and nothing here is run until the owner has approved the account.

**PHP 8.3 for every command.** The server's default `php` may be older than 8.3. On the server, every session starts with:

```sh
WP='<PHP83_BIN> <WP_PATH>'
COMPOSER='<PHP83_BIN> <COMPOSER_PATH>'
```

and every `wp` / `composer` command below is written `$WP ...` / `$COMPOSER ...`. For `infra/deploy.sh` set `REMOTE_PATH_PREFIX` in `.env.deploy` to the directory holding the 8.3 `php`. It is **required** whenever the server's default `php` is below 8.3: the deploy's version gate and `php -l` call plain `php` from PATH (as do `wp` and `composer`), so without it the deploy stops at the gate. `WP_BIN` / `COMPOSER_BIN` are optional overrides.

## 0. Prerequisites (local)

1. `infra/.env.deploy` exists locally, copied from `infra/.env.deploy.example` (git-ignored).
2. The commit to deploy is pushed (`git ls-remote origin` shows it) and the working tree is clean. LIVE only accepts a commit that is on `origin/main`; staging also accepts `origin/feat/blog-build` until it merges.
3. `migration/output/` holds `bundle.json` and `media/` (git-ignored; they travel by rsync/scp only).

### Pin the server host key (once, before the first deploy)

`deploy.sh` runs ssh with `StrictHostKeyChecking=yes` by default, so the host key must already be in `known_hosts`. Pin it from a trusted comparison, not blindly:

```sh
ssh-keyscan -p <SSH_PORT> -t ed25519,rsa <SSH_HOST> > hostkeys.tmp
ssh-keygen -lf hostkeys.tmp          # prints the SHA256 fingerprints
```

Compare those fingerprints **out of band** with the ones the hosting provider shows (or read them on the server console: `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`). Only when they match, append the file to `~/.ssh/known_hosts` (`cat hostkeys.tmp >> ~/.ssh/known_hosts`) and delete `hostkeys.tmp`. For a non-default port the entry is keyed `[<SSH_HOST>]:<SSH_PORT>`, which `ssh-keyscan -p` produces. If you ever need first-contact trust for a throwaway test, set `SSH_STRICT_HOST_KEY_CHECKING=accept-new` for that run only; never leave it set for LIVE.

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

Verification happens after step 6 (the web handler) and with the CLI now: `<PHP83_BIN> -v` must print 8.3.x. No `phpinfo` page is ever created.

## 3. Database

1. Create a new database `<DB_NAME>` and a new user `<DB_USER>` with a long random secret.
2. Grant only what WordPress needs on that one database: `SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES`. No global privileges.
3. Record host, name, user and the secret in the credentials ledger (outside git).

## 4. WordPress core

```sh
cd <STAGING_ROOT>
$WP core download --locale=en_US          # latest stable
```

## 5. wp-config.php (one level above the docroot)

Create the file without the database secret appearing in the process list (argv) or shell history. **Preferred:** a mode-600 file that is read on stdin and then shredded:

```sh
cd <STAGING_ROOT>
set +o history                            # bash: nothing below is recorded
( umask 077; cat > ../dbpass.tmp )        # paste the secret from the ledger, press Enter, then Ctrl-D
$WP config create --dbname=<DB_NAME> --dbuser=<DB_USER> --prompt=dbpass \
    --dbprefix=mfb_ --skip-check < ../dbpass.tmp >/dev/null   # WP-CLI echoes the command with the secret, so discard stdout
shred -u ../dbpass.tmp
set -o history
```

(No other command in that step may read stdin.) **Alternative** (the secret is briefly visible in `ps` to other users of the host):

```sh
set +o history
read -rs DB_PASS        # run this line ON ITS OWN, paste the secret, press Enter; then paste the rest
echo
$WP config create --dbname=<DB_NAME> --dbuser=<DB_USER> --dbpass="$DB_PASS" --dbprefix=mfb_ --skip-check
unset DB_PASS
set -o history
```

`wp config create` generates fresh salts. Then set every define with its own command (none of these read stdin; string values are quoted inside the raw value):

```sh
$WP config set DISALLOW_FILE_EDIT     true      --raw --type=constant
$WP config set DISALLOW_FILE_MODS     true      --raw --type=constant
$WP config set WP_AUTO_UPDATE_CORE    "'minor'" --raw --type=constant
$WP config set WP_DEBUG               false     --raw --type=constant
$WP config set FORCE_SSL_ADMIN        true      --raw --type=constant
mv wp-config.php ../wp-config.php         # WordPress finds it one directory above the docroot
```

Verify **every** define, the table prefix, and the database connection:

```sh
for c in DISALLOW_FILE_EDIT DISALLOW_FILE_MODS WP_AUTO_UPDATE_CORE WP_DEBUG FORCE_SSL_ADMIN; do
  printf '%s=' "$c"; $WP config get "$c" --type=constant; echo
done
# expected (WP-CLI prints true as 1, false as an empty line, a string without quotes):
#   DISALLOW_FILE_EDIT=1  DISALLOW_FILE_MODS=1  WP_AUTO_UPDATE_CORE=minor  WP_DEBUG=<empty line>  FORCE_SSL_ADMIN=1
# an empty WP_DEBUG is correct (false); a MISSING constant makes the command fail with an error instead
$WP config get table_prefix               # mfb_
$WP db check                              # must report the database tables OK: proves the connection and the grants
```

Because `DISALLOW_FILE_MODS` is on, the Two Factor plugin and core updates are installed by deploy/CLI only, never from wp-admin.

## 6. Install and base settings

```sh
$WP core install --url=https://<STAGING_HOST> --title="Moneyfrog Insights" \
    --admin_user=<ADMIN_LOGIN> --admin_email=<OWNER_EMAIL> --skip-email --prompt=admin_password
```

`--prompt=admin_password` reads the generated secret from the terminal (nothing else may use stdin in this command). Store it in the ledger. `<ADMIN_LOGIN>` must not be `admin` (the audit fails otherwise). Then:

```sh
$WP option update timezone_string Asia/Kolkata
$WP rewrite structure '/%postname%/' --hard
$WP option update default_comment_status closed
$WP option update default_ping_status closed
$WP eval 'echo PHP_VERSION;'              # must print 8.3.x (the CLI)
curl -sI https://<STAGING_HOST>/wp-login.php | head -1   # the web handler answers (a PHP error page means the .htaccess handler is wrong)
```

## 7. Staging gate: hidden from search, behind basic auth

1. `$WP option update blog_public 0` (adds `noindex`).
2. Create the credentials file **outside** the docroot: `htpasswd -c <ACCOUNT_HOME>/.htpasswd-blog-staging <STAGING_USER>` (secret into the ledger).
3. Append to `<STAGING_ROOT>/.htaccess`:

```apache
AuthType Basic
AuthName "Staging"
AuthUserFile <ACCOUNT_HOME>/.htpasswd-blog-staging
Require valid-user
```

4. Issue the TLS certificate with the panel's AutoSSL for `<STAGING_HOST>` and confirm `https://<STAGING_HOST>/` answers 401 without credentials.

Smoke and inventory runs supply the credentials through `SMOKE_AUTH=<STAGING_USER>:<secret>` (environment only). The scripts hand it to curl on stdin (never in the argument list, so not visible in `ps`), send it only to the staging host and never to another host a redirect points at, and never print it. Set it with history disabled (`set +o history; read -rs SMOKE_AUTH; export SMOKE_AUTH`) rather than typing it on a command line.

## 8. First deploy

From the repo, on the pushed commit:

```sh
infra/deploy.sh --dry-run staging <sha>      # read the plan
infra/deploy.sh staging <sha>                # prints DEPLOYED <sha> staging only when the server confirms it
```

The script checks that the target is a WordPress install first, takes a server-side lock (a second concurrent deploy fails fast), ships `wp-content/mu-plugins`, `wp-content/themes/moneyfrog` and `composer install --no-dev` (which installs the Two Factor plugin), syntax-checks every PHP file, prints the rsync plan including every file it will delete, applies only those three directories, verifies them, flushes cache and rewrites, and only then writes `wp-content/.mf-release`. If any step fails after the sync started, the three directories are re-synced from the previous release (the exact directory recorded in `wp-content/.mf-release-dir` by the last successful deploy, and only if it is complete; otherwise nothing is synced and the exit code is 31), `.mf-release` stays unchanged and the script exits non-zero ("rolled back to <old sha>"). Then on the server:

```sh
$WP theme activate moneyfrog
$WP plugin activate two-factor
$WP eval 'echo PHP_VERSION;'                 # 8.3.x
$WP core version                             # latest
```

Verify the release independently: `curl -sI https://<STAGING_HOST>/ | grep -i x-mf-release` (with the basic-auth credentials) shows the first 12 characters of `<sha>`; the smoke run checks this when `EXPECT_SHA` is set.

## 9. Editor accounts and two-factor

1. For each editor the owner names: `$WP user create <login> <email> --role=editor --send-email` (or `--user_pass` from a generated secret).
2. Each editor signs in once and sets up Two Factor under Profile before publishing anything.
3. Run the audit; it must exit 0 before the site is considered ready:

```sh
$WP mf audit
```

It prints JSON (`php`, `wp`, `timezone`, `two_factor_missing`, `file_edit_disabled`, `file_mods_disabled`, `admin_user_exists`, `xmlrpc_off`, `comments_open_count`, `blog_public`) and fails when any editor lacks Two Factor (the import service user `mf-import` is exempt, its login is blocked), either `DISALLOW_FILE_*` is not true, a user called `admin` exists, any post has comments open, or the timezone is not `Asia/Kolkata`. `blog_public` is reported only (0 on staging, 1 on LIVE).

## 10. Content: media, bundle, import, warm cache

```sh
# from the repo root; host, user and paths come from your private notes
rsync -a migration/output/media/ <USER>@<SSH_HOST>:<ACCOUNT_HOME>/mf-import/media/
scp migration/output/bundle.json <USER>@<SSH_HOST>:<ACCOUNT_HOME>/mf-import/bundle.json
```

The import copies the images into the uploads folder (keeping their original upload paths); the staging area is outside the docroot. Then on the server (from `<STAGING_ROOT>`):

```sh
$WP mf import <ACCOUNT_HOME>/mf-import/bundle.json --media-dir=<ACCOUNT_HOME>/mf-import/media --dry-run   # review the report
$WP mf import <ACCOUNT_HOME>/mf-import/bundle.json --media-dir=<ACCOUNT_HOME>/mf-import/media
$WP mf import <ACCOUNT_HOME>/mf-import/bundle.json --media-dir=<ACCOUNT_HOME>/mf-import/media             # second run: created=0
$WP mf warm                                                                                              # caches each post's card image
```

The bundle and media are never committed. Delete `<ACCOUNT_HOME>/mf-import/` from the server when the import is accepted.

## 11. Smoke and inventory

```sh
export SMOKE_AUTH=...                                # staging only; set without echo, see step 7
export SMOKE_SAMPLE_POST=/<a-published-post-slug>/   # enables the single-post checks
export SMOKE_BOM_PATH='/<old-slug>%ef%bb%bf/'        # optional; must 301 to SMOKE_SAMPLE_POST
export EXPECT_SHA=<40-hex deployed sha>              # checks X-MF-Release
tests/smoke/smoke.sh https://<STAGING_HOST>
tests/smoke/inventory.sh https://<STAGING_HOST> migration/output/inventory.txt
```

Checks that need WP-CLI (PHP version, `file_edit_disabled`, `two_factor_enforced`, import checks) show `SKIP` remotely; their remote equivalents are `$WP mf audit` and the `$WP eval` calls above. The summary prints `SKIPPED <n>`. A remote run without both `EXPECT_SHA` and `SMOKE_SAMPLE_POST` prints a WARNING and exits non-zero unless `SMOKE_ALLOW_SKIPS=1`. Expected: no `FAIL`, inventory every URL 200 or one 301 then 200.

## 12. Rollback

Each deploy keeps the previous releases under `<STAGING_ROOT>/../releases/` (newest 5 per target, named `<target>-<sha12>-<timestamp>`). A failed deploy rolls itself back to the previous release. To go back deliberately:

1. Re-deploy the previous pushed commit: `infra/deploy.sh staging <previous-sha>` (preferred, it updates `.mf-release`, verifies and flushes caches), or
2. By hand on the server: `rsync -a --delete <releases>/<name>/wp-content/mu-plugins/ <ROOT>/wp-content/mu-plugins/` (and likewise `themes/moneyfrog/` and `plugins/two-factor/`), write the old full SHA into `<ROOT>/wp-content/.mf-release`, then `$WP cache flush && $WP rewrite flush`.

Content rolls back separately: take `$WP db export` before every import into a private location, and re-import it if needed. Uploads are never removed by a deploy.

## LIVE

Repeat sections 1 to 6 and 8 to 10 for `<LIVE_HOST>`/`<LIVE_ROOT>` with these differences:

- No basic auth, no `.htpasswd`; `$WP option update blog_public 1`.
- Deploy with `infra/deploy.sh live <sha>`; the script refuses any sha that is not on `origin/main` (no feature-branch exceptions), ideally the exact commit already verified on staging.
- Smoke and inventory run **without** `SMOKE_AUTH`, with `EXPECT_SHA` and `SMOKE_SAMPLE_POST` set.
- Before the switch take `$WP db export` and keep the old blog untouched and reachable for rollback.
- **Owner step, Cloudflare:** the Cloudflare record for `<LIVE_HOST>` is pointed at the new origin (or the origin override changed) only after staging has passed. Rollback is pointing it back at the previous origin. Cloudflare is changed by the owner, not by scripts.
- After the switch re-run the smoke and inventory suites against the public URL, confirm `X-MF-Release` matches the deployed sha, and run `$WP mf audit` again (`blog_public` reported as 1).
