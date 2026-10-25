#!/usr/bin/env bash
# Proves the remote lint block of infra/deploy.sh (a) passes on valid PHP, (b) fails on a
# syntax error, (c) fails when it lints 0 files, and (d) uses no process substitution.
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
BLOCK="$(sed -n '/^# LINT-BEGIN/,/^# LINT-END/p' infra/deploy.sh)"
[ -n "$BLOCK" ] || { echo "FAIL no lint block"; exit 1; }
FAIL=0
ok() { echo "PASS $1"; }
bad() { echo "FAIL $1"; FAIL=1; }

grep -q '<(' <<<"$BLOCK" && bad "no_process_substitution" || ok "no_process_substitution"

run() { ( cd "$1" && bash -c "set -euo pipefail; cleanup() { :; }; $BLOCK" ) >"$2" 2>&1; }
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
mk() { mkdir -p "$1/wp-content/mu-plugins" "$1/wp-content/themes/moneyfrog" "$1/wp-content/plugins/two-factor"; }

mk "$T/good"; printf '<?php\necho 1;\n' > "$T/good/wp-content/mu-plugins/a.php"
printf '<?php\necho 2;\n' > "$T/good/wp-content/themes/moneyfrog/with space.php"
run "$T/good" "$T/good.log" && grep -q 'linted 2 files' "$T/good.log" && ok "lint_passes_and_counts" || bad "lint_passes_and_counts"

mk "$T/bad"; printf '<?php\necho 1;\n' > "$T/bad/wp-content/mu-plugins/a.php"
printf '<?php\nif (\n' > "$T/bad/wp-content/themes/moneyfrog/b.php"
run "$T/bad" "$T/bad.log"; rc=$?
[ "$rc" -ne 0 ] && grep -q 'syntax error' "$T/bad.log" && ok "fails_on_syntax_error" || bad "fails_on_syntax_error"

mk "$T/empty"
run "$T/empty" "$T/empty.log"; rc=$?
[ "$rc" -ne 0 ] && grep -q 'linted 0 files' "$T/empty.log" && ok "fails_on_zero_files" || bad "fails_on_zero_files"
# a file name containing a newline is linted (and counted) correctly
case "$(uname -s)" in
  MINGW*|MSYS*|CYGWIN*) echo "SKIP newline_in_filename (Windows has no newline file names; run in the php container)" ;;
  *)
mk "$T/nl"; printf '<?php
echo 1;
' > "$T/nl/wp-content/mu-plugins/a.php"
printf '<?php
echo 3;
' > "$T/nl/wp-content/themes/moneyfrog/line1
line2.php"
run "$T/nl" "$T/nl.log" && grep -q 'linted 2 files' "$T/nl.log" && ok "newline_in_filename" || bad "newline_in_filename"
  ;;
esac

# a missing target directory makes find fail: refuse
mk "$T/nodir"; printf '<?php
echo 1;
' > "$T/nodir/wp-content/mu-plugins/a.php"; rmdir "$T/nodir/wp-content/plugins/two-factor"
run "$T/nodir" "$T/nodir.log"; rc=$?
[ "$rc" -ne 0 ] && ok "fails_on_missing_directory" || bad "fails_on_missing_directory"

# the temp list never outlives the block
before="$(ls "${TMPDIR:-/tmp}" | wc -l)"; run "$T/good" "$T/good2.log"; run "$T/bad" "$T/bad2.log"; after="$(ls "${TMPDIR:-/tmp}" | wc -l)"
[ "$before" = "$after" ] && ok "no_temp_file_left" || bad "no_temp_file_left"
exit "$FAIL"
