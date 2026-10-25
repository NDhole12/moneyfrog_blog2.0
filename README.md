# moneyfrog_blog — new blog.moneyfrog.in

A brand-new WordPress blog (latest WordPress, PHP 8.3, hardened, comments off), built to
match the moneyfrog.in redesign. Deployed on the **GoDaddy cPanel server**. Separate from
the API, the website and the ERP repos.

Rule: the old blog is never touched. Content and images are only imported from it, read-only.

- Design: [docs/superpowers/specs/2026-10-25-blog-revamp-design.md](docs/superpowers/specs/2026-10-25-blog-revamp-design.md)
- Mockups: "Moneyfrog Blog Redesign" design canvas (3 artboards; link shared separately, private until shared)

Status: design approved 2026-10-25; build starting. Server details live in `docs/private/`
(git-ignored).

## Local development

```bash
cp .env.example .env            # fill in the placeholder passwords
composer install && (cd migration && npm install)
docker compose up -d            # http://localhost:8080
docker compose run --rm wpcli wp core install --url=http://localhost:8080 \
  --title="Moneyfrog Insights" --admin_user=mf_codespark_qa \
  --admin_email=qa@code-spark.com --skip-email
docker compose run --rm wpcli wp rewrite structure '/%postname%/' --hard
```

Tests: `composer test` (PHPUnit), `npm test` in `migration/` (vitest),
`tests/smoke/smoke.sh http://localhost:8080` (live checks).
