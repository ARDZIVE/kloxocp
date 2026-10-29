#!/bin/bash
# Source invariants for P0.4 / P0.5 / C2 / C5. php-cli only. Safe locally.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; [ -n "${2:-}" ] && printf '       %s\n' "$2"; }
check(){ local d="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$d"; else bad "$d" "command failed: $*"; fi; }
fails(){ local d="$1"; shift; if "$@" >/dev/null 2>&1; then bad "$d" "expected failure: $*"; else ok "$d"; fi; }

echo "== web-console (H3)"
if [ ! -f "$ROOT/kloxo/httpdocs/web-console/web-console.php" ]; then
  ok "web-console.php deleted"
else
  bad "web-console.php deleted"
fi
check "web-console stub php -l" php -l "$ROOT/kloxo/httpdocs/web-console/index.php"
if grep -q 'unserialize' "$ROOT/kloxo/httpdocs/web-console/index.php"; then
  bad "web-console stub does not unserialize"
else
  ok "web-console stub does not unserialize"
fi
if grep -q 'web-console/index.php' "$ROOT/kloxo/httpdocs/driver/pserver/sshclientlib.php"; then
  bad "sshclient no longer iframes web-console"
else
  ok "sshclient no longer iframes web-console"
fi

echo "== sudoers (H6)"
if grep -q 'NOPASSWD' "$ROOT/kloxo/pscript/set-kloxo-php"; then
  bad "set-kloxo-php does not write NOPASSWD sudoers"
else
  ok "set-kloxo-php does not write NOPASSWD sudoers"
fi

echo "== easyinstaller (C5)"
fails "easyinstaller-update.php exits nonzero" php "$ROOT/kloxo/bin/easyinstaller-update.php"
fails "easyinstaller-upload.php exits nonzero" php "$ROOT/kloxo/bin/easyinstaller-upload.php"
fails "pscript/easyinstaller-update exits nonzero" sh "$ROOT/kloxo/pscript/easyinstaller-update"
if grep -q 'wget' "$ROOT/kloxo/bin/easyinstaller-update.php"; then
  bad "easyinstaller-update.php has no wget"
else
  ok "easyinstaller-update.php has no wget"
fi
if grep -q 'easyinstaller_removed' "$ROOT/kloxo/httpdocs/driver/web/easyinstaller__linuxlib.php"; then
  ok "linux driver refuses to install apps"
else
  bad "linux driver refuses to install apps"
fi
if grep -q 'refused plain HTTP' "$ROOT/kloxo/httpdocs/lib/html/lib.php"; then
  ok "download_file refuses plain HTTP"
else
  bad "download_file refuses plain HTTP"
fi
if [ -f "$ROOT/kloxo/etc/flag/disableeasyinstaller.flg" ]; then
  ok "disableeasyinstaller.flg is shipped"
else
  bad "disableeasyinstaller.flg is shipped"
fi

echo "== backend daemon (C2)"
if grep -q 'INADDR_LOOPBACK' "$ROOT/kloxo/cexe/kloxo.c"; then
  ok "kloxo.c binds loopback"
else
  bad "kloxo.c binds loopback"
fi
if grep -q 'INADDR_ANY' "$ROOT/kloxo/cexe/kloxo.c"; then
  bad "kloxo.c no longer binds INADDR_ANY"
else
  ok "kloxo.c no longer binds INADDR_ANY"
fi
if grep -q 'MAX_BACKEND_MSG' "$ROOT/kloxo/cexe/kloxo.c"; then
  ok "kloxo.c has a message size limit"
else
  bad "kloxo.c has a message size limit"
fi
if grep -q 'TLS_server_method' "$ROOT/kloxo/cexe/kloxo.c"; then
  ok "kloxo.c uses TLS_server_method"
else
  bad "kloxo.c uses TLS_server_method"
fi
if grep -q 'SSLv23_method' "$ROOT/kloxo/cexe/kloxo.c"; then
  bad "kloxo.c does not use SSLv23_method"
else
  ok "kloxo.c does not use SSLv23_method"
fi
if grep -q 'ssl://127.0.0.1' "$ROOT/kloxo/httpdocs/lib/html/remotelib.php"; then
  ok "PHP SSL listener binds 127.0.0.1"
else
  bad "PHP SSL listener binds 127.0.0.1"
fi
if grep -q 'ssl://0.0.0.0' "$ROOT/kloxo/httpdocs/lib/html/remotelib.php"; then
  bad "PHP SSL listener no longer binds 0.0.0.0"
else
  ok "PHP SSL listener no longer binds 0.0.0.0"
fi
if grep -q 'tls_material_missing' "$ROOT/kloxo/httpdocs/driver/pserver/ipaddresslib.php"; then
  ok "copyCertificate fails closed without keys"
else
  bad "copyCertificate fails closed without keys"
fi

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
