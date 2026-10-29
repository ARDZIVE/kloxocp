#!/bin/bash
# Source invariants for P1 tree shrink (nginx-only sites, BIND/PowerDNS, panel Hiawatha).
# php-cli only. Safe locally. Does not start the panel.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; [ -n "${2:-}" ] && printf '       %s\n' "$2"; }

echo "== selectable drivers (linux.inc)"
php_out="$(php -r '
$driver = array();
include $argv[1];
$web = isset($driver["web"]) ? $driver["web"] : null;
$cache = isset($driver["webcache"]) ? $driver["webcache"] : null;
$dns = isset($driver["dns"]) ? $driver["dns"] : null;
$dav = isset($driver["davuser"]) ? $driver["davuser"] : null;
$mail = isset($driver["mmail"]) ? $driver["mmail"] : null;
echo "web=" . implode(",", (array)$web) . "\n";
echo "webcache=" . implode(",", (array)$cache) . "\n";
echo "dns=" . implode(",", (array)$dns) . "\n";
echo "davuser=" . $dav . "\n";
echo "mmail=" . implode(",", (array)$mail) . "\n";
' "$ROOT/kloxo/file/driver/linux.inc")"
echo "$php_out" | grep -qx 'web=none,nginx' && ok "web is none,nginx" || bad "web is none,nginx" "$php_out"
echo "$php_out" | grep -qx 'webcache=none' && ok "webcache is none" || bad "webcache is none" "$php_out"
echo "$php_out" | grep -qx 'dns=none,bind,pdns' && ok "dns is none,bind,pdns" || bad "dns is none,bind,pdns" "$php_out"
echo "$php_out" | grep -qx 'davuser=none' && ok "davuser is none" || bad "davuser is none" "$php_out"
echo "$php_out" | grep -qx 'mmail=qmail' && ok "mmail still qmail" || bad "mmail still qmail" "$php_out"

echo "== class map files exist and unused stacks unregistered"
map_out="$(
  cd "$ROOT/kloxo/httpdocs" && php -r '
$gl_class_array = array();
include "lib/driver_define.php";
$missing = 0;
foreach ($gl_class_array as $c => $f) {
  if (!is_file($f)) { fwrite(STDERR, "missing $c -> $f\n"); $missing = 1; }
}
$forbid = array(
  "web__apache","web__lighttpd","web__hiawatha","web__openlitespeed","web__monkey",
  "web__lighttpdproxy","web__nginxproxy","web__hiawathaproxy","web__openlitespeedproxy","web__monkeyproxy",
  "serverweb__apache","serverweb__lighttpd","serverweb__hiawatha","serverweb__openlitespeed","serverweb__monkey",
  "serverweb__lighttpdproxy","serverweb__nginxproxy","serverweb__hiawathaproxy","serverweb__openlitespeedproxy","serverweb__monkeyproxy",
  "webcache__varnish","webcache__trafficserver","webcache__squid",
  "dns__djbdns","dns__nsd","dns__mydns","dns__yadifa","dns__maradns",
  "davuser__lighttpd","davuser__apache",
  "dirprotect__apache","dirprotect__lighttpd",
);
$present = 0;
foreach ($forbid as $c) {
  if (isset($gl_class_array[$c])) { fwrite(STDERR, "still registered $c\n"); $present = 1; }
}
$need = array("web__nginx","web__none","serverweb__nginx","serverweb__none","webcache__none",
  "dns__bind","dns__pdns","dns__none","davuser__none","dirprotect__nginx","dirprotect__none",
  "webhandler__nginx","webmimetype__nginx");
$needmiss = 0;
foreach ($need as $c) {
  if (!isset($gl_class_array[$c])) { fwrite(STDERR, "not registered $c\n"); $needmiss = 1; }
}
exit(($missing || $present || $needmiss) ? 1 : 0);
'
)"
map_rc=$?
if [ "$map_rc" -eq 0 ]; then
  ok "class map: kept drivers present, removed stacks unregistered, files exist"
else
  bad "class map: kept drivers present, removed stacks unregistered, files exist" "$map_out"
fi

echo "== template trees"
gone="apache lighttpd varnish squid trafficserver hiawatha djbdns nsd mydns yadifa maradns"
for d in $gone; do
  if [ -e "$ROOT/kloxo/file/$d" ]; then
    bad "file/$d removed"
  else
    ok "file/$d removed"
  fi
done
for d in nginx bind pdns qmail mail; do
  if [ -d "$ROOT/kloxo/file/$d" ]; then
    ok "file/$d kept"
  else
    bad "file/$d kept"
  fi
done
if [ -f "$ROOT/kloxo/file/nginx/tpl/domains.conf.tpl" ]; then
  ok "nginx site template present"
else
  bad "nginx site template present"
fi
if [ -f "$ROOT/kloxo/init/hiawatha.conf.base" ]; then
  ok "panel Hiawatha config kept in init/"
else
  bad "panel Hiawatha config kept in init/"
fi

echo "== defaults are nginx"
if grep -q "'web' => 'nginx'" "$ROOT/kloxo/httpdocs/lib/html/lib.php"; then
  ok "lib.php default web driver nginx"
else
  bad "lib.php default web driver nginx"
fi
if grep -q "'web' => 'apache'" "$ROOT/kloxo/httpdocs/lib/html/lib.php"; then
  bad "lib.php no longer defaults web to apache"
else
  ok "lib.php no longer defaults web to apache"
fi
if grep -q 's:3:"web";s:5:"nginx"' "$ROOT/kloxo/install/step2.inc"; then
  ok "legacy installer serialised default web=nginx"
else
  bad "legacy installer serialised default web=nginx"
fi
if grep -q 's:3:"web";s:6:"apache"' "$ROOT/kloxo/install/step2.inc" "$ROOT/kloxo/install/installer.php"; then
  bad "legacy installer no longer serialises web=apache"
else
  ok "legacy installer no longer serialises web=apache"
fi

echo "== php -l on new stubs"
for f in davuser__nonelib.php mimehandler__nginxlib.php; do
  if php -l "$ROOT/kloxo/httpdocs/driver/web/$f" >/dev/null 2>&1; then
    ok "php -l $f"
  else
    bad "php -l $f"
  fi
done
if php -l "$ROOT/kloxo/file/driver/linux.inc" >/dev/null 2>&1; then
  ok "php -l linux.inc"
else
  bad "php -l linux.inc"
fi

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
