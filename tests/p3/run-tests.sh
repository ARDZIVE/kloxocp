#!/bin/bash
# P3 kloxocp CLI: status, doctor, snapshot/rollback. php-cli only. Safe locally.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
TOOL=(php "$ROOT/kloxo/bin/kloxocp")
T="$(mktemp -d "${TMPDIR:-/tmp}/kloxocp.XXXXXX")"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; [ -n "${2:-}" ] && printf '       %s\n' "$2"; }
check(){ local d="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$d"; else bad "$d" "command failed: $*"; fi; }
fails(){ local d="$1"; shift; if "$@" >/dev/null 2>&1; then bad "$d" "expected failure: $*"; else ok "$d"; fi; }
cleanup() { rm -rf "$T"; }
trap cleanup EXIT

echo "== lint and help"
check "php -l kloxocp" php -l "$ROOT/kloxo/bin/kloxocp"
check "help exits 0" "${TOOL[@]}" help
check "version exits 0" "${TOOL[@]}" version
out="$("${TOOL[@]}" version)"
echo "$out" | grep -q '0.1.0-dev' && ok "version string" || bad "version string" "$out"
fails "unknown command is 64" "${TOOL[@]}" not-a-command
"${TOOL[@]}" update >/dev/null 2>"$T/upd.err"; rc=$?
[ "$rc" -eq 2 ] && ok "update exits 2 (not implemented)" || bad "update exits 2 (not implemented)" "rc=$rc"

echo "== status / doctor against this tree"
check "status --root tree" "${TOOL[@]}" status --root="$ROOT/kloxo"
json="$("${TOOL[@]}" status --root="$ROOT/kloxo" --json)"
echo "$json" | grep -q '"kloxoversion"' && ok "status --json has kloxoversion" || bad "status --json has kloxoversion"
check "doctor --root tree" "${TOOL[@]}" doctor --root="$ROOT/kloxo"
djson="$("${TOOL[@]}" doctor --root="$ROOT/kloxo" --json)"
echo "$djson" | grep -q '"ok"' && ok "doctor --json has ok" || bad "doctor --json has ok"
echo "$djson" | grep -q '"login_page"' && ok "doctor checks login_page" || bad "doctor checks login_page"

echo "== component list"
clist="$("${TOOL[@]}" component list)"
echo "$clist" | grep -q nginx && ok "component list includes nginx" || bad "component list includes nginx"
"${TOOL[@]}" component upgrade nginx >/dev/null 2>&1; rc=$?
[ "$rc" -eq 2 ] && ok "component upgrade exits 2" || bad "component upgrade exits 2" "rc=$rc"

echo "== snapshot / rollback in a fake root"
FAKE="$T/root"
SNAP="$T/snaps"
mkdir -p "$FAKE/httpdocs/login" "$FAKE/bin" "$FAKE/init" "$FAKE/file/ssl" "$FAKE/etc"
echo '7.0.0.c-test' > "$FAKE/bin/kloxoversion"
echo 'wrapper' > "$FAKE/init/php-cli.sh"
echo 'login' > "$FAKE/httpdocs/login/index.php"
echo 'secret' > "$FAKE/file/ssl/default.pem"
chmod 600 "$FAKE/file/ssl/default.pem"

if "${TOOL[@]}" snapshot --root="$FAKE" --snap-dir="$SNAP" --json >"$T/snap.json"; then
  ok "snapshot into empty snap-dir"
else
  bad "snapshot into empty snap-dir"
fi
echo 'mutated' > "$FAKE/httpdocs/login/index.php"
if "${TOOL[@]}" rollback --root="$FAKE" --snap-dir="$SNAP" >/dev/null; then
  ok "rollback latest snapshot"
else
  bad "rollback latest snapshot"
fi
got="$(cat "$FAKE/httpdocs/login/index.php")"
[ "$got" = "login" ] && ok "rollback restored login page" || bad "rollback restored login page" "got [$got]"

fails "snap-dir with .. rejected" "${TOOL[@]}" snapshot --root="$FAKE" --snap-dir="$SNAP/../evil"
fails "root with .. rejected" "${TOOL[@]}" status --root="$ROOT/kloxo/../kloxo"

echo "== install.sh Ubuntu bootstrap"
check "install.sh is executable" test -x "$ROOT/install.sh"
check "install.sh --help" bash "$ROOT/install.sh" --help
check "install.sh --dry-run" bash "$ROOT/install.sh" --dry-run
helpout="$(bash "$ROOT/install.sh" --help)"
echo "$helpout" | grep -q 'github.com/ARDZIVE/kloxocp.git' && ok "help names git URL" || bad "help names git URL"
echo "$helpout" | grep -q 'Ubuntu 24.04' && ok "help names Ubuntu 24.04" || bad "help names Ubuntu 24.04"
echo "$helpout" | grep -q '26' && ok "help names Ubuntu 26" || bad "help names Ubuntu 26"
fails "install.sh refuses non-root" bash "$ROOT/install.sh"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
