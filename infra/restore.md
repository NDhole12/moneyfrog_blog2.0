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
3. **Write-only IAM user** `<BLOG_BACKUP_WRITER>` (programmatic access only, no console). Inline policy, nothing else attached:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "BlogBackupWriteOnly",
      "Effect": "Allow",
      "Action": "s3:PutObject",
      "Resource": "arn:aws:s3:::<BUCKET>/blog-backups/*"
    }
  ]
}
```

   The user cannot list, read, overwrite-and-then-read or delete: a stolen server key cannot read or destroy history. (It can still PUT over an existing key; keys carry a timestamp so a run never overwrites an earlier one. Bucket Versioning, step 5, covers the rare overwrite.) Put the access key in the server's `infra/.env.backup` only (`chmod 600`), and in the credentials ledger.
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
| `blog-abort-mpu` | `blog-backups/` | Abort incomplete multipart uploads after 1 day |

   The `.sha256` sidecars sit under the same prefixes, so they expire with their objects. The daily DB dump is one object per day, so 30 days of expiry is "keep 30 daily". Optional: also transition to Glacier Instant Retrieval after 7 days if cost matters.
7. **Verify the policy once** from the server (the controller does this, no secrets printed): run `infra/backup.sh <env>`; then, with the reader identity, list the prefix and see the new objects. Confirm the writer key is denied a `GetObject`/`ListBucket`/`DeleteObject` (it must be).

## 2. Cron (on the server)

`infra/backup.sh` already takes its own `flock` (so overlapping runs are skipped), but wrap it anyway so cron mail stays quiet and a stuck run cannot stack:

```cron
# 02:30 IST daily = 21:00 UTC (previous calendar day). Adjust to the server's cron timezone, see below.
0 21 * * * /usr/bin/flock -n $HOME/.mf-backup-cron.lock <REPO_PATH>/infra/backup.sh live >/dev/null 2>&1
15 21 * * * /usr/bin/flock -n $HOME/.mf-backup-cron-staging.lock <REPO_PATH>/infra/backup.sh staging >/dev/null 2>&1
```

* **Timezone caveat.** 02:30 IST is **21:00 UTC the day before**. cron runs in the *server's* timezone (cPanel shared hosts are often UTC, but some are not). Check with `date; date -u` on the server and set the hour accordingly: UTC server `0 21`, IST server `30 2`. Do not assume.
* The "uploads on Sundays" test is computed in IST from UTC inside the script, so it does not depend on the server timezone. A 21:00 UTC Saturday run is Sunday 02:30 IST and includes uploads.
* cPanel cron lines run with a minimal `PATH`; the script sets its own and honours `REMOTE_PATH_PREFIX` (the PHP 8.3 directory) from `.env.backup`. Use absolute paths to `flock` and the script. Edit via cPanel "Cron Jobs" or `crontab -e`.
* Server prerequisites: `bash`, `mysqldump` (used by WP-CLI), `gzip`, `tar`, `flock`, `sha256sum`, `python3`, and WP-CLI. `curl` is not needed. A working `mail`/`sendmail` is needed only for failure emails.
* On failure the script emails `ALERT_EMAIL` (environment, stage, exit code, time; no secrets) and exits non-zero. It logs to `~/logs/mf-backup.log` (rotated to `.1` at 1 MB). Also set up a **missing-backup** check: the failure email cannot fire if cron itself stops, so look at the newest object's date in S3 weekly (or add an external monitor).
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

**3.3 Restore into scratch (staging server).** Use the `$WP` convention from `infra/provision.md` (PHP 8.3).

```sh
SCRATCH=<ACCOUNT_HOME>/restore-drill            # new, empty, mode 700
mkdir -m 700 "$SCRATCH" && cd "$SCRATCH"
# a scratch database and user created in cPanel (MySQL Databases): <SCRATCH_DB> / <SCRATCH_DB_USER>
# 1. scratch docroot = a copy of the staging core + code, then the restored uploads
mkdir docroot && rsync -a --exclude=wp-content/uploads --exclude=wp-config.php <STAGING_ROOT>/ docroot/
tar xzf <path>/staging-uploads-<ts>.tar.gz -C docroot/wp-content
# 2. scratch wp-config.php pointing at the scratch DB (never at the real one)
cp <STAGING_ROOT>/../wp-config.php docroot/wp-config.php
$WP --path=docroot config set DB_NAME <SCRATCH_DB>
$WP --path=docroot config set DB_USER <SCRATCH_DB_USER>
$WP --path=docroot config set DB_PASSWORD '<SCRATCH_DB_PASSWORD>' --prompt   # or edit the file by hand
# 3. load the dump
gzip -dc <path>/staging-db-<ts>.sql.gz | $WP --path=docroot db import -
# 4. rewrite URLs to the scratch address
$WP --path=docroot search-replace 'https://<STAGING_HOST>' 'https://<SCRATCH_HOST>' --skip-columns=guid --all-tables
$WP --path=docroot cache flush
```

   Serve `docroot` on a scratch hostname or subdomain (cPanel subdomain pointed at it, not indexed: add HTTP auth or a `noindex` header), or use `php -S` through an SSH tunnel on the staging server.

**3.4 Verify.**

```sh
$WP --path=docroot mf audit                                  # hardening audit: must be clean
$WP --path=docroot post list --post_type=post --post_status=publish --format=count
EXPECT_SHA=<deployed sha> SMOKE_SAMPLE_POST=/<a-post-slug>/ tests/smoke/smoke.sh https://<SCRATCH_HOST>
```

   The smoke suite must PASS against the scratch site, and the post count must match staging. Open a post with images to confirm uploads came back. Record date, backup timestamp, durations and the result in `docs/private/`.

**3.5 Tear down (always, even on failure).**

```sh
$WP --path=docroot db drop --yes          # the scratch DB only; check wp-config shows <SCRATCH_DB> first
rm -rf "$SCRATCH"
# remove the scratch subdomain, the scratch DB user, and the downloaded backup files
```

## 4. LIVE restore and rollback

Only for real data loss or corruption. Decide with the owner first. If only a few posts were damaged, restore those from a scratch copy (3.3) instead of overwriting everything.

1. **Freeze and snapshot first.** Announce a maintenance window. Take a fresh safety copy of what exists now, whatever state it is in:
   ```sh
   $WP --path=<LIVE_ROOT> db export - --single-transaction | gzip > <ACCOUNT_HOME>/pre-restore-<ts>.sql.gz
   tar czf <ACCOUNT_HOME>/pre-restore-uploads-<ts>.tar.gz -C <LIVE_ROOT>/wp-content uploads
   sha256sum <ACCOUNT_HOME>/pre-restore-* > <ACCOUNT_HOME>/pre-restore-<ts>.sha256
   ```
   Keep these until the restore is accepted. They are the rollback.
2. **Choose and verify the backup.** Download from the `live/` prefix, run 3.2, and ideally run the 3.3/3.4 scratch drill with it first (when time allows).
3. **Maintenance mode:** `$WP --path=<LIVE_ROOT> maintenance-mode activate` (or the host's equivalent).
4. **Restore the database:**
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
