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

echo "== localhost File Manager"
if grep -A 50 'function remote_exec' "$ROOT/kloxo/httpdocs/lib/html/remotelib.php" | grep -q 'posix_geteuid' \
  && grep -A 80 'function remote_exec' "$ROOT/kloxo/httpdocs/lib/html/remotelib.php" | grep -q 'return do_local_action($cmd)' \
  && grep -A 80 'function remote_exec' "$ROOT/kloxo/httpdocs/lib/html/remotelib.php" | grep -q 'localhost backend'; then
  ok "localhost File Manager uses kloxo-backend as root, in-process fallback"
else
  bad "localhost File Manager uses kloxo-backend as root, in-process fallback"
fi
# After C1 every localhost envelope is authenticated, so the old $local restart
# path never ran and a newer panel stamp hit slave_upgrading (Fail2Ban/UFW).
if grep -A 80 'function check_for_remote' "$ROOT/kloxo/httpdocs/lib/html/remotelib.php" | grep -q 'localhostCall' \
  && grep -q 'function kloxo_remote_is_localhost' "$ROOT/kloxo/httpdocs/lib/html/remotelib.php" \
  && grep -A 80 'function check_for_remote' "$ROOT/kloxo/httpdocs/lib/html/remotelib.php" | grep -q 'skip that gate'; then
  ok "localhost backend skips slave_upgrading version gate"
else
  bad "localhost backend skips slave_upgrading version gate"
fi
if grep -q 'function kloxo_refresh_sgbl_version' "$ROOT/kloxo/httpdocs/lib/sgbl.php"; then
  ok "kloxo_refresh_sgbl_version re-reads bin/kloxoversion"
else
  bad "kloxo_refresh_sgbl_version re-reads bin/kloxoversion"
fi
# Normal pages must not wait 86400s on a wedged kloxo-backend.
if grep -q 'function kloxo_backend_io_timeouts' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" \
  && grep -q 'kloxo_backend_io_timeouts' "$ROOT/kloxo/httpdocs/lib/html/remotelib.php" \
  && ! grep -q '30000000000' "$ROOT/kloxo/httpdocs/lib/html/remotelib.php" \
  && grep -q 'timed_out' "$ROOT/kloxo/httpdocs/lib/html/remotelib.php"; then
  ok "backend RPC uses short connect/read timeouts (not 3e10/86400 always)"
else
  bad "backend RPC uses short connect/read timeouts (not 3e10/86400 always)"
fi
if grep -q "rl_exec_get(null, 'localhost', array(\"lxbackup\", \"execbackupphp\")" \
  "$ROOT/kloxo/httpdocs/lib/html/lxbackuplib.php" \
  && grep -q 'backendVersionSkewHint' "$ROOT/kloxo/httpdocs/lib/html/lxbackuplib.php"; then
  ok "Backup Now uses localhost RPC and explains slave_upgrading"
else
  bad "Backup Now uses localhost RPC and explains slave_upgrading"
fi
if grep -A 20 'function os_isSelfSystemOrLxlabsUser' "$ROOT/kloxo/httpdocs/lib/html/linuxlib.php" | grep -q 'return false'; then
  ok "os_isSelfSystemOrLxlabsUser returns false for other users"
else
  bad "os_isSelfSystemOrLxlabsUser returns false for other users"
fi
if grep -A 20 'function slave_get_driver' "$ROOT/kloxo/httpdocs/lib/html/lib.php" | grep -q 'is_object($rmt)'; then
  ok "slave_get_driver does not index a missing slavedb/driver file"
else
  bad "slave_get_driver does not index a missing slavedb/driver file"
fi
if grep -A 20 'function lxfile_dstat' "$ROOT/kloxo/httpdocs/lib/html/commonfslib.php" | grep -q 'is_array($stat)' \
  && grep -A 20 'function lxfile_dstat' "$ROOT/kloxo/httpdocs/lib/html/commonfslib.php" | grep -q 'is_array($list)' \
  && grep -A 12 'function lxfile_stat' "$ROOT/kloxo/httpdocs/lib/html/commonfslib.php" | grep -q 'is_array($list)'; then
  ok "File Manager listing skips unreadable entries instead of TypeError"
else
  bad "File Manager listing skips unreadable entries instead of TypeError"
fi

echo "== auth gate"
if php "$HERE/test_auth_gate.php"; then
  ok "test_auth_gate.php"
else
  bad "test_auth_gate.php"
fi

echo
echo "$PASS passed, $FAIL failed (wrapper); see test_auth_gate.php lines above"
[ "$FAIL" -eq 0 ]
