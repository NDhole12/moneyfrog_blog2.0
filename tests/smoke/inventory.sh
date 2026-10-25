#!/usr/bin/env bash
# Old-URL inventory check. Usage: tests/smoke/inventory.sh <base-url> <inventory.txt>
#
# Every line of the inventory is an old blog URL. The https://blog.moneyfrog.in prefix is
# replaced by <base-url>. A URL passes when the final status is 200 after at most ONE 301.
# Uses plain curl GETs (no WP-CLI), so it works against local, staging and LIVE alike.
# Prints "FAIL <url> <status chain>" per bad URL and a total; exits non-zero on any FAIL.
set -u
# Optional SMOKE_AUTH=user:pass enables HTTP basic auth (staging). Basic auth goes to curl through a config on stdin (never argv, so it is not visible in `ps`),
# and only for URLs on the base host: a redirect to another host is fetched without credentials.
curl() {
  local last="${!#}"
  if [ -n "${SMOKE_AUTH:-}" ] && { [ "$last" = "$BASE" ] || [[ "$last" == "$BASE"/* ]] || [[ "$last" == "$BASE"\?* ]]; }; then
    local esc="${SMOKE_AUTH//\\/\\\\}"
    esc="${esc//\"/\\\"}"
    printf 'user = "%s"\n' "$esc" | command curl -K - "$@"
  else
    command curl "$@"
  fi
}
BASE="${1:-}"
LIST="${2:-}"
if [ -z "$BASE" ] || [ -z "$LIST" ] || [ ! -f "$LIST" ]; then
  echo "usage: $0 <base-url> <inventory.txt>" >&2
  exit 2
fi
BASE="${BASE%/}"
OLD_PREFIX="https://blog.moneyfrog.in"
TOTAL=0
FAILS=0

while IFS= read -r line || [ -n "$line" ]; do
  line="${line%$'\r'}"
  [ -z "$line" ] && continue
  url="${BASE}${line#"$OLD_PREFIX"}"
  TOTAL=$((TOTAL + 1))
  chain=""
  current="$url"
  hops=0
  final=""
  while :; do
    out="$(curl -s -o /dev/null -w '%{http_code} %{redirect_url}' --max-time 30 "$current")"
    code="${out%% *}"
    next="${out#* }"
    chain="${chain:+$chain }$code"
    if [ "$code" = 301 ] || [ "$code" = 302 ] || [ "$code" = 307 ] || [ "$code" = 308 ]; then
      hops=$((hops + 1))
      current="$next"
      [ -z "$current" ] && { final="$code"; break; }
      [ "$hops" -gt 2 ] && { final="loop"; break; }
      continue
    fi
    final="$code"
    break
  done
  # PASS: 200 directly, or exactly one 301 then 200.
  if [ "$chain" = "200" ] || [ "$chain" = "301 200" ]; then
    :
  else
    FAILS=$((FAILS + 1))
    echo "FAIL $url $chain"
  fi
done <"$LIST"

echo "Inventory: $((TOTAL - FAILS)) passed, $FAILS failed, $TOTAL total"
[ "$FAILS" -eq 0 ]
