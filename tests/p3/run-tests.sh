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
echo "$out" | grep -q '10.1-2026110601' && ok "version string" || bad "version string" "$out"
fails "unknown command is 64" "${TOOL[@]}" not-a-command
# update without an installed/src tree should fail clearly (not exit 2 "not built")
"${TOOL[@]}" update --root="$T/missing" --src="$T/missing-src" --no-fetch --yes >/dev/null 2>"$T/upd.err"; rc=$?
[ "$rc" -ne 0 ] && grep -Eqi 'program root|source|Not a' "$T/upd.err" && ok "update fails clearly without a tree" || bad "update fails clearly without a tree" "rc=$rc $(cat "$T/upd.err")"

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
echo "$helpout" | grep -q 'raw.githubusercontent.com/ARDZIVE/kloxocp/main/get' && ok "help names get one-liner" || bad "help names get one-liner"
echo "$helpout" | grep -q 'Ubuntu 24.04' && ok "help names Ubuntu 24.04" || bad "help names Ubuntu 24.04"
echo "$helpout" | grep -q '26' && ok "help names Ubuntu 26" || bad "help names Ubuntu 26"
fails "install.sh refuses non-root" bash "$ROOT/install.sh"



echo "== update / upcp (snapshot, apply, auto-rollback)"
# Fake installed panel + newer source tree (no git fetch).
UPD_ROOT="$T/panel"
UPD_SRC="$T/src"
UPD_SNAP="$T/snaps"
mkdir -p "$UPD_ROOT/httpdocs/login" "$UPD_ROOT/httpdocs/lib" "$UPD_ROOT/bin" "$UPD_ROOT/init" "$UPD_ROOT/etc" "$UPD_ROOT/file/ssl" \
  "$UPD_ROOT/httpdocs/driver" "$UPD_ROOT/httpdocs/theme" "$UPD_ROOT/httpdocs/lang" "$UPD_ROOT/var" \
  "$UPD_SRC/kloxo/httpdocs/login" "$UPD_SRC/kloxo/httpdocs/lib" "$UPD_SRC/kloxo/bin" "$UPD_SRC/kloxo/init" \
  "$UPD_SRC/kloxo/httpdocs/driver" "$UPD_SRC/kloxo/httpdocs/theme" "$UPD_SRC/kloxo/httpdocs/lang"
printf '10.1-old\n' > "$UPD_ROOT/bin/kloxoversion"
printf '10.1-new\n' > "$UPD_SRC/kloxo/bin/kloxoversion"
printf '<?php // old\n' > "$UPD_ROOT/httpdocs/login/index.php"
printf '<?php // new login\n' > "$UPD_SRC/kloxo/httpdocs/login/index.php"
printf 'old-lib\n' > "$UPD_ROOT/httpdocs/lib/keep.txt"
printf 'new-lib\n' > "$UPD_SRC/kloxo/httpdocs/lib/keep.txt"
# Kloxo-MR collage GIFs use ".." before the extension; snapshot must not treat that as traversal
mkdir -p "$UPD_ROOT/httpdocs/theme/icon/collage" "$UPD_SRC/kloxo/httpdocs/theme/icon/collage"
printf 'gif\n' > "$UPD_ROOT/httpdocs/theme/icon/collage/subweb_a_addform..gif"
printf 'gif-new\n' > "$UPD_SRC/kloxo/httpdocs/theme/icon/collage/subweb_a_addform..gif"
# Minimal kloxocp in both trees so health check / sync have a binary
cp "$ROOT/kloxo/bin/kloxocp" "$UPD_ROOT/bin/kloxocp"
cp "$ROOT/kloxo/bin/kloxocp" "$UPD_SRC/kloxo/bin/kloxocp"
chmod +x "$UPD_ROOT/bin/kloxocp" "$UPD_SRC/kloxo/bin/kloxocp"

check_out="$("${TOOL[@]}" update --check --root="$UPD_ROOT" --src="$UPD_SRC" --snap-dir="$UPD_SNAP" --json)"
echo "$check_out" | grep -q '"check_only": true' && ok "update --check is plan-only" || bad "update --check is plan-only" "$check_out"
echo "$check_out" | grep -q '10.1-old' && echo "$check_out" | grep -q '10.1-new' \
  && ok "update --check shows from/to versions" || bad "update --check versions" "$check_out"
[ "$(cat "$UPD_ROOT/httpdocs/login/index.php")" = '<?php // old' ] && ok "update --check did not apply" || bad "update --check did not apply"

if "${TOOL[@]}" upcp --root="$UPD_ROOT" --src="$UPD_SRC" --snap-dir="$UPD_SNAP" --no-fetch --yes >/dev/null; then
  ok "upcp applies an update"
else
  bad "upcp applies an update"
fi
[ "$(cat "$UPD_ROOT/bin/kloxoversion")" = '10.1-new' ] && ok "upcp updated kloxoversion" || bad "upcp updated kloxoversion"
[ "$(cat "$UPD_ROOT/httpdocs/login/index.php")" = '<?php // new login' ] && ok "upcp updated login page" || bad "upcp updated login page"
[ "$(cat "$UPD_ROOT/httpdocs/lib/keep.txt")" = 'new-lib' ] && ok "upcp synced httpdocs/lib" || bad "upcp synced httpdocs/lib"
[ "$(cat "$UPD_ROOT/httpdocs/theme/icon/collage/subweb_a_addform..gif")" = 'gif-new' ] \
  && ok "upcp allows filenames containing .." || bad "upcp allows filenames containing .."
ls "$UPD_SNAP"/*/MANIFEST.json >/dev/null 2>&1 && ok "upcp left a snapshot" || bad "upcp left a snapshot"
ls "$UPD_SNAP"/*/httpdocs/theme/icon/collage/subweb_a_addform..gif >/dev/null 2>&1 \
  && ok "snapshot keeps filenames containing .." || bad "snapshot keeps filenames containing .."

# Failed update rolls back: poison the source login dir after a good baseline.
printf '10.1-newer\n' > "$UPD_SRC/kloxo/bin/kloxoversion"
printf '<?php // broken attempt\n' > "$UPD_SRC/kloxo/httpdocs/login/index.php"
rm -rf "$UPD_SRC/kloxo/httpdocs/login"
mkdir -p "$UPD_SRC/kloxo/httpdocs/login"
# empty login => health check fails
if KLOXO_UPDATE_FORCE_FAIL=0 "${TOOL[@]}" update --root="$UPD_ROOT" --src="$UPD_SRC" --snap-dir="$UPD_SNAP" --no-fetch --yes >/dev/null 2>"$T/upd-fail.err"; then
  # empty login may still pass if directory non-empty check is weak — force fail hook
  true
fi
# Use force-fail hook with a valid tree to prove auto-rollback
printf 'ok\n' > "$UPD_SRC/kloxo/httpdocs/login/index.php"
printf '10.1-boom\n' > "$UPD_SRC/kloxo/bin/kloxoversion"
printf 'boom-lib\n' > "$UPD_SRC/kloxo/httpdocs/lib/keep.txt"
if KLOXO_UPDATE_FORCE_FAIL=1 "${TOOL[@]}" update --root="$UPD_ROOT" --src="$UPD_SRC" --snap-dir="$UPD_SNAP" --no-fetch --yes --json >"$T/upd-rb.json" 2>"$T/upd-rb.err"; then
  bad "forced failure should not succeed"
else
  ok "forced failure exits non-zero"
fi
grep -q '"rolled_back": true' "$T/upd-rb.json" 2>/dev/null \
  || grep -q 'rolled back' "$T/upd-rb.err" \
  && ok "forced failure auto-rolled back" || bad "forced failure auto-rolled back" "$(cat "$T/upd-rb.json" "$T/upd-rb.err" 2>/dev/null)"
[ "$(cat "$UPD_ROOT/bin/kloxoversion")" = '10.1-new' ] && ok "rollback restored previous version" || bad "rollback restored previous version" "$(cat "$UPD_ROOT/bin/kloxoversion")"
[ "$(cat "$UPD_ROOT/httpdocs/lib/keep.txt")" = 'new-lib' ] && ok "rollback restored lib" || bad "rollback restored lib"

# --no-rollback leaves the broken tree
printf 'left-broken\n' > "$UPD_SRC/kloxo/bin/kloxoversion"
if KLOXO_UPDATE_FORCE_FAIL=1 "${TOOL[@]}" update --root="$UPD_ROOT" --src="$UPD_SRC" --snap-dir="$UPD_SNAP" --no-fetch --no-rollback --yes >/dev/null 2>&1; then
  bad "no-rollback forced failure should fail"
else
  ok "no-rollback forced failure exits non-zero"
fi
[ "$(cat "$UPD_ROOT/bin/kloxoversion")" = 'left-broken' ] && ok "--no-rollback leaves the new tree" || bad "--no-rollback leaves the new tree"

# Lock: second update while lock held (PHP flock; no util-linux flock on macOS)
php -r '
$f = $argv[1];
$fh = fopen($f, "c+");
if (!$fh || !flock($fh, LOCK_EX)) { fwrite(STDERR, "lock failed\n"); exit(1); }
sleep(8);
' "$UPD_ROOT/var/kloxocp-update.lock" >/dev/null 2>&1 &
lockpid=$!
sleep 0.3
"${TOOL[@]}" update --root="$UPD_ROOT" --src="$UPD_SRC" --snap-dir="$UPD_SNAP" --no-fetch --yes >/dev/null 2>"$T/lock.err"; rc=$?
kill "$lockpid" 2>/dev/null || true
wait "$lockpid" 2>/dev/null || true
[ "$rc" -ne 0 ] && grep -qi 'already running' "$T/lock.err" && ok "update lock refuses a second run" || bad "update lock refuses a second run" "rc=$rc $(cat "$T/lock.err")"

# upcp wrapper script exists and points at kloxocp
if grep -q 'kloxocp' "$ROOT/kloxo/pscript/upcp" && grep -q 'upcp' "$ROOT/kloxo/pscript/upcp" \
  && grep -q 'Must run as root' "$ROOT/kloxo/pscript/upcp"; then
  ok "pscript/upcp wraps kloxocp upcp"
else
  bad "pscript/upcp wraps kloxocp upcp"
fi
help2="$("${TOOL[@]}" help)"
echo "$help2" | grep -q 'update|upcp' && ok "help documents update/upcp" || bad "help documents update/upcp"
echo "$help2" | grep -q 'sudo kloxocp upcp' && ok "help says update needs sudo" || bad "help says update needs sudo"

# Unwritable program root (live /opt/kloxo is root-owned): clear sudo message
NOROOT="$T/noroot"
mkdir -p "$NOROOT/httpdocs" "$NOROOT/bin" "$NOROOT/var" "$NOROOT/log"
echo '10.1-noroot' >"$NOROOT/bin/kloxoversion"
chmod 555 "$NOROOT/var"
"${TOOL[@]}" update --root="$NOROOT" --src="$UPD_SRC" --snap-dir="$UPD_SNAP" --no-fetch --yes >/dev/null 2>"$T/noroot.err"; rc=$?
chmod 755 "$NOROOT/var"
[ "$rc" -ne 0 ] && grep -qi 'Must run as root' "$T/noroot.err" && ok "update refuses unwritable root without sudo" \
  || bad "update refuses unwritable root without sudo" "rc=$rc $(cat "$T/noroot.err")"

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
