# Backups and restore drill

Contains **no secrets, bucket names, hosts, paths or emails**: placeholders only. Real values live in `docs/private/` and the account credentials ledger (both outside git).

What runs: `infra/backup.sh <env>` on the server, from cron, using `infra/s3put.py` (Python 3 standard library only) to upload to S3.

| Object | When | Key |
|---|---|---|
| Database dump (gzip, `--single-transaction`) | every run | `blog-backups/<env>/db/YYYY/MM/<env>-db-<UTC timestamp>.sql.gz` |
| `wp-content/uploads` (tar.gz) | Sundays (IST) or `--weekly` | `blog-backups/<env>/uploads/<env>-uploads-<UTC timestamp>.tar.gz` |
| SHA-256 sidecar | with every object | same key + `.sha256` (`sha256sum -c` format) |

`<env>` is `staging` or `live`. All objects are uploaded with `x-amz-server-side-encryption: AES256`. A DB dump holds user password hashes and email addresses: treat every object as confidential.

## 1. S3 setup (owner or controller, in AWS)

Use the **existing bucket**; do not create a public one. Block Public Access stays on.

1. **Prefix:** `blog-backups/` (then `staging/` and `live/` beneath it).
2. **Default encryption:** SSE-S3 (AES256) on the bucket. The uploader also sends the header on every PUT.
3. **One write-only IAM user per environment**, `<BLOG_BACKUP_WRITER_STAGING>` and `<BLOG_BACKUP_WRITER_LIVE>` (programmatic access only, no console), so a leaked staging key can never write into LIVE history. Each gets one inline policy, nothing else attached. Staging:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "BlogBackupWriteOnlyStaging",
      "Effect": "Allow",
      "Action": "s3:PutObject",
      "Resource": "arn:aws:s3:::<BUCKET>/blog-backups/staging/*"
    }
  ]
}
```

   LIVE (same shape, its own user):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "BlogBackupWriteOnlyLive",
      "Effect": "Allow",
      "Action": "s3:PutObject",
      "Resource": "arn:aws:s3:::<BUCKET>/blog-backups/live/*"
    }
  ]
}
```

   A writer cannot list, read or delete: a stolen server key cannot read or destroy history. (It can still PUT over an existing key; keys carry a timestamp so a run never overwrites an earlier one. Bucket Versioning, step 5, covers the rare overwrite.) Each key goes in that environment's server `infra/.env.backup` only (`chmod 600`; the script refuses to run otherwise), and in the credentials ledger.
4. **Separate read-only identity** `<BLOG_BACKUP_READER>` for restores, used from an operator machine, never stored on the web server:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    { "Effect": "Allow", "Action": "s3:ListBucket", "Resource": "arn:aws:s3:::<BUCKET>",
      "Condition": { "StringLike": { "s3:prefix": ["blog-backups/*"] } } },
    { "Effect": "Allow", "Action": "s3:GetObject", "Resource": "arn:aws:s3:::<BUCKET>/blog-backups/*" }
  ]
}
```

   Prefer an IAM role the operator assumes for the drill (short-lived credentials) over a long-lived user.
5. **Versioning** on the bucket (or at least a rule below that expires noncurrent versions).
6. **Lifecycle rules (this is the retention; the script never deletes).** Create these rules on the bucket, each scoped by prefix:

| Rule | Prefix filter | Action |
|---|---|---|
| `blog-db-30d` | `blog-backups/staging/db/` and `blog-backups/live/db/` (one rule per prefix) | Expire current versions after **30 days** |
| `blog-uploads-8w` | `blog-backups/staging/uploads/` and `blog-backups/live/uploads/` | Expire current versions after **56 days** (8 weekly archives) |
| `blog-noncurrent` | `blog-backups/` | Permanently delete noncurrent versions after 7 days |
| `blog-delete-markers` | `blog-backups/` | Remove expired object delete markers (on a versioned bucket, expired objects otherwise leave markers behind forever) |
| `blog-abort-mpu` | `blog-backups/` | Abort incomplete multipart uploads after 1 day |

   The `.sha256` sidecars sit under the same prefixes, so they expire with their objects. The daily DB dump is one object per day, so 30 days of expiry is "keep 30 daily". Optional: also transition to Glacier Instant Retrieval after 7 days if cost matters.
7. **Verify the policy once** from the server (the controller does this, no secrets printed): run `infra/backup.sh <env>`; then, with the reader identity, list the prefix and see the new objects. Confirm the writer key is denied a `GetObject`/`ListBucket`/`DeleteObject` (it must be).

## 2. Cron (on the server)

`infra/backup.sh` already takes its own `flock` (so overlapping runs are skipped), but wrap it anyway so a stuck run cannot stack. stdout and stderr are **appended to the log, never discarded**, so even a failure before the script starts (bad path, missing interpreter) leaves a trace. Create the log directory once: `mkdir -p $HOME/logs`.

```cron
# 02:30 IST daily = 21:00 UTC (previous calendar day). Adjust to the server's cron timezone, see below.
0 21 * * * /usr/bin/flock -n $HOME/.mf-backup-cron.lock <REPO_PATH>/infra/backup.sh live >>$HOME/logs/mf-backup.log 2>&1
15 21 * * * /usr/bin/flock -n $HOME/.mf-backup-cron-staging.lock <REPO_PATH>/infra/backup.sh staging >>$HOME/logs/mf-backup.log 2>&1
```

* **Timezone caveat.** 02:30 IST is **21:00 UTC the day before**. cron runs in the *server's* timezone (cPanel shared hosts are often UTC, but some are not). Check with `date; date -u` on the server and set the hour accordingly: UTC server `0 21`, IST server `30 2`. Do not assume.
* The "uploads on Sundays" test is computed in IST from UTC inside the script, so it does not depend on the server timezone. A 21:00 UTC Saturday run is Sunday 02:30 IST and includes uploads.
* cPanel cron lines run with a minimal `PATH`; the script sets its own and honours `REMOTE_PATH_PREFIX` (the PHP 8.3 directory) from `.env.backup`. Use absolute paths to `flock` and the script. Edit via cPanel "Cron Jobs" or `crontab -e`.
* Server prerequisites: `bash`, `mysqldump` (used by WP-CLI), `gzip`, `tar`, `flock`, `sha256sum`, `python3`, and WP-CLI. `curl` is not needed. A working `mail`/`sendmail` is needed only for failure emails.
* On failure the script emails `ALERT_EMAIL` (environment, stage, exit code, time, and exactly which of database/uploads succeeded; no secrets) and exits non-zero. This includes **config failures**: a missing, unreadable or group/world-readable `.env.backup` is logged and alerted, and the run is refused. If the env file is missing, the alert goes to `$ALERT_EMAIL` from the environment, else to the cPanel contact address in `~/.contactemail`. If the weekly uploads archive would not fit on disk (free space under 1.2x the uploads size) it is skipped with a `PARTIAL` alert; the database backup still counts. It logs to `~/logs/mf-backup.log` (rotated to `.1` at 1 MB). Also set up a **missing-backup** check: the failure email cannot fire if cron itself stops, so look at the newest object's date in S3 weekly (or add an external monitor).
* First run by hand, then look at the log: `infra/backup.sh --dry-run live` prints the plan; `infra/backup.sh --weekly staging` forces an uploads archive.

## 3. Restore drill (staging; run after the first backup and then quarterly)

Goal: prove a backup restores into a working site, without touching staging's real database or docroot. Everything uses a **scratch DB and scratch directory**, dropped at the end.

**3.1 Download (operator machine, read-only credential).** Use the AWS console (S3, bucket, `blog-backups/staging/`, select the newest `db/` object, its `.sha256`, and the newest `uploads/` pair, Download), or a local AWS CLI with the reader identity:

```sh
aws s3 ls s3://<BUCKET>/blog-backups/staging/db/ --recursive | tail -3
aws s3 cp s3://<BUCKET>/<db-object-key> .
aws s3 cp s3://<BUCKET>/<db-object-key>.sha256 .
aws s3 cp s3://<BUCKET>/<uploads-object-key> .          # newest weekly
aws s3 cp s3://<BUCKET>/<uploads-object-key>.sha256 .
```

**3.2 Verify integrity.** In the folder holding the files:

```sh
sha256sum -c staging-db-<ts>.sql.gz.sha256
sha256sum -c staging-uploads-<ts>.tar.gz.sha256
gzip -t staging-db-<ts>.sql.gz && gzip -dc staging-db-<ts>.sql.gz | tail -n 3   # expect "-- Dump completed"
tar tzf staging-uploads-<ts>.tar.gz | head
```

Both checksums must print `OK`. Stop if either does not. Copy the files to the staging server (scp), into a private directory, and delete them there at the end.

**3.3 Restore into scratch (staging server).** Use the `$WP` convention from `infra/provision.md` (PHP 8.3). The `guard` function below runs before every destructive step: it refuses unless the scratch docroot's `wp-config.php` names the scratch DB, so a copied or wrong config can never import into, or drop, the real staging database.

```sh
SCRATCH=<ACCOUNT_HOME>/restore-drill            # new, empty, mode 700
SCRATCH_DOCROOT="$SCRATCH/docroot"
SCRATCH_DB=<SCRATCH_DB>                         # created in cPanel (MySQL Databases) with <SCRATCH_DB_USER>
guard() { [ "$($WP --path="$SCRATCH_DOCROOT" config get DB_NAME)" = "$SCRATCH_DB" ] || { echo "refusing: not the scratch DB"; return 1; }; }

mkdir -m 700 "$SCRATCH" && cd "$SCRATCH"
# 1. scratch docroot = a copy of the staging core + code, then the restored uploads
mkdir "$SCRATCH_DOCROOT" && rsync -a --exclude=wp-content/uploads --exclude=wp-config.php <STAGING_ROOT>/ "$SCRATCH_DOCROOT/"
tar xzf <path>/staging-uploads-<ts>.tar.gz -C "$SCRATCH_DOCROOT/wp-content"
# 2. scratch wp-config.php pointing at the scratch DB. The password is read from a mode-600
#    file (as in provision.md section 5), never typed into the command line:
cp <STAGING_ROOT>/../wp-config.php "$SCRATCH_DOCROOT/wp-config.php"
set +o history
( umask 077; cat > "$SCRATCH/dbpass.tmp" )      # paste the SCRATCH DB password, Enter, Ctrl-D
$WP --path="$SCRATCH_DOCROOT" config set DB_NAME "$SCRATCH_DB"
$WP --path="$SCRATCH_DOCROOT" config set DB_USER <SCRATCH_DB_USER>
$WP --path="$SCRATCH_DOCROOT" config set DB_PASSWORD "$(tr -d '\n' < "$SCRATCH/dbpass.tmp")" >/dev/null
shred -u "$SCRATCH/dbpass.tmp"
set -o history
guard || exit 1                                  # DB_NAME must now be the scratch DB
```

   (`config set` puts the value briefly in `ps` on a shared host; the password is a throw-away one for a scratch user that is deleted in 3.5. If that is not acceptable, edit `DB_PASSWORD` in `$SCRATCH_DOCROOT/wp-config.php` by hand instead.)

   **No real side effects from the scratch site.** The copied config and data are a clone of staging, so neutralise them before the site ever loads:

```sh
# a) no WP-Cron (it would publish scheduled posts, send mail and call external services)
$WP --path="$SCRATCH_DOCROOT" config set DISABLE_WP_CRON true --raw
# b) no outbound mail: a mu-plugin in the SCRATCH docroot only
mkdir -p "$SCRATCH_DOCROOT/wp-content/mu-plugins"
printf '%s\n' '<?php' "add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX ); // restore drill: never send mail" \
  > "$SCRATCH_DOCROOT/wp-content/mu-plugins/zz-scratch-no-mail.php"
# c) WP_HOME / WP_SITEURL: if staging's wp-config defines them, they override the database
#    and keep the scratch site pointing at (and redirecting to) staging. Remove, then override:
$WP --path="$SCRATCH_DOCROOT" config delete WP_HOME    2>/dev/null || true
$WP --path="$SCRATCH_DOCROOT" config delete WP_SITEURL 2>/dev/null || true
$WP --path="$SCRATCH_DOCROOT" config set WP_HOME    'https://<SCRATCH_HOST>'
$WP --path="$SCRATCH_DOCROOT" config set WP_SITEURL 'https://<SCRATCH_HOST>'
```

   `rsync` of the staging tree copies `wp-content/mu-plugins` too (the blog's own code), which is intended. Then load the dump behind the guard:

```sh
guard || exit 1
gzip -dc <path>/staging-db-<ts>.sql.gz | $WP --path="$SCRATCH_DOCROOT" db import -
$WP --path="$SCRATCH_DOCROOT" search-replace 'https://<STAGING_HOST>' 'https://<SCRATCH_HOST>' --skip-columns=guid --all-tables
$WP --path="$SCRATCH_DOCROOT" cache flush
```

   Serve `$SCRATCH_DOCROOT` on a scratch hostname or subdomain (a cPanel subdomain pointed at it; add HTTP auth or a `noindex` header), or use `php -S` through an SSH tunnel on the staging server. `DISABLE_WP_CRON` and the mail filter cover WordPress's own cron and mail only: if the database holds a third-party credential or webhook, treat it as live.

**3.4 Verify.**

```sh
$WP --path="$SCRATCH_DOCROOT" mf audit                       # hardening audit: must be clean
$WP --path="$SCRATCH_DOCROOT" post list --post_type=post --post_status=publish --format=count
EXPECT_SHA=<deployed sha> SMOKE_SAMPLE_POST=/<a-post-slug>/ tests/smoke/smoke.sh https://<SCRATCH_HOST>
```

   The smoke suite must PASS against the scratch site, and the post count must match staging. Open a post with images to confirm uploads came back. Record date, backup timestamp, durations and the result in `docs/private/`.

**3.5 Tear down (always, even on failure), behind the same guard.** Run it in the same shell session (so `guard` and the variables exist):

```sh
if guard; then
  $WP --path="$SCRATCH_DOCROOT" db drop --yes      # guard just proved DB_NAME is the scratch DB
else
  echo "NOT dropping any database: fix wp-config.php by hand, then delete the scratch DB in cPanel"
fi
rm -rf "$SCRATCH"                                  # the scratch directory only
# remove the scratch subdomain, the scratch DB user, and the downloaded backup files
```

## 4. LIVE restore and rollback

Only for real data loss or corruption. Decide with the owner first. If only a few posts were damaged, restore those from a scratch copy (3.3) instead of overwriting everything.

1. **Freeze and snapshot first, and verify the snapshot.** Announce a maintenance window. Take a fresh safety copy of what exists now, whatever state it is in. Do **not** go on until every check passes: the import in step 4 is destructive and this copy is the rollback.
   ```sh
   set -o pipefail
   PRE=<ACCOUNT_HOME>/pre-restore-<ts>.sql.gz
   $WP --path=<LIVE_ROOT> db export - --single-transaction | gzip > "$PRE" || { echo "snapshot export failed"; exit 1; }
   gzip -t "$PRE"                                         || { echo "snapshot is not a valid gzip"; exit 1; }
   [ "$(wc -c < "$PRE")" -gt 1024 ]                       || { echo "snapshot is suspiciously small"; exit 1; }
   gzip -dc "$PRE" | tail -n 5 | grep -q 'Dump completed' || { echo "snapshot is incomplete (no completion marker)"; exit 1; }
   rc=0; tar czf <ACCOUNT_HOME>/pre-restore-uploads-<ts>.tar.gz -C <LIVE_ROOT>/wp-content uploads || rc=$?
   [ "$rc" -le 1 ]                                        || { echo "uploads snapshot failed (tar exit $rc)"; exit 1; }   # 1 = file changed while reading, acceptable
   gzip -t <ACCOUNT_HOME>/pre-restore-uploads-<ts>.tar.gz || { echo "uploads snapshot is not a valid gzip"; exit 1; }
   sha256sum <ACCOUNT_HOME>/pre-restore-* > <ACCOUNT_HOME>/pre-restore-<ts>.sha256
   echo "SNAPSHOT VERIFIED"
   ```
   These are the same checks `backup.sh` applies to every dump. Keep the files until the restore is accepted.
2. **Choose and verify the backup.** Download from the `live/` prefix, run 3.2, and ideally run the 3.3/3.4 scratch drill with it first (when time allows).
3. **Maintenance mode:** `$WP --path=<LIVE_ROOT> maintenance-mode activate` (or the host's equivalent).
4. **Restore the database** (only after step 1 printed `SNAPSHOT VERIFIED`):
   ```sh
   gzip -dc live-db-<ts>.sql.gz | $WP --path=<LIVE_ROOT> db import -
   $WP --path=<LIVE_ROOT> cache flush && $WP --path=<LIVE_ROOT> rewrite flush
   ```
   The dump already holds the LIVE URLs, so no search-replace is needed. `db import` replaces the tables the dump defines (it drops each before create).
5. **Restore uploads only if they are missing or damaged:** extract the archive into a temp directory first and `rsync -a` the missing files into `wp-content/uploads/` (do not `--delete`).
6. **Code is not in the backup:** the theme and mu-plugins come from git. If the code is wrong too, redeploy the pushed SHA with `infra/deploy.sh live <sha>`.
7. **Verify on LIVE (read-only):** `maintenance-mode deactivate`, then `$WP --path=<LIVE_ROOT> mf audit` and `tests/smoke/smoke.sh https://<LIVE_HOST>` (with `EXPECT_SHA`), plus the URL-inventory check (`tests/smoke/inventory.sh`). Spot-check a few posts and an image.
8. **Rollback if the restore is bad:** re-import the `pre-restore-<ts>.sql.gz` from step 1 the same way (step 4), restore uploads from `pre-restore-uploads-<ts>.tar.gz` if they were changed, flush caches, rerun step 7. Then investigate with the scratch procedure instead of LIVE.
9. After acceptance, delete the `pre-restore-*` files and the downloaded backups from the server, and write the incident note to `docs/private/`.
