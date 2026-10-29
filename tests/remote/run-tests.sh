#!/bin/bash
# Regression tests for audit C1 (unauthenticated unserialize). Safe locally.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; [ -n "${2:-}" ] && printf '       %s\n' "$2"; }
check(){ local d="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$d"; else bad "$d" "command failed: $*"; fi; }

echo "== lint"
check "php -l remote_auth_gate.php" php -l "$ROOT/kloxo/httpdocs/lib/html/remote_auth_gate.php"
check "php -l remotelib.php"        php -l "$ROOT/kloxo/httpdocs/lib/html/remotelib.php"
check "php -l test_auth_gate.php"   php -l "$HERE/test_auth_gate.php"

echo "== auth gate"
if php "$HERE/test_auth_gate.php"; then
  ok "test_auth_gate.php"
else
  bad "test_auth_gate.php"
fi

echo
echo "$PASS passed, $FAIL failed (wrapper); see test_auth_gate.php lines above"
[ "$FAIL" -eq 0 ]
