#!/usr/bin/env bash
# Idempotent QA fixtures for the local Docker stack: topics, one byline, ~26 test posts.
# Usage: tests/fixtures/seed.sh [base-url]   (default http://localhost:8080)
# Refuses to run against anything but localhost; safe to run repeatedly.
set -euo pipefail
BASE="${1:-http://localhost:8080}"
BASE="${BASE%/}"
if ! [[ "$BASE" =~ ^http://(localhost|127\.0\.0\.1)(:[0-9]+)?$ ]]; then
  echo "seed.sh: refusing to seed non-local site '$BASE'" >&2
  exit 2
fi
cd "$(dirname "$0")/../.."
# wp eval takes code without the opening tag; seed.php also re-checks the home URL.
docker compose run --rm -T wpcli wp eval "$(tail -n +2 tests/fixtures/seed.php)"
