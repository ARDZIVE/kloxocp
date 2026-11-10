#!/bin/bash
# Source invariants for P1 tree shrink (nginx-only sites, BIND/Knot DNS, panel Hiawatha).
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
$smtp = isset($driver["smtp"]) ? $driver["smtp"] : null;
echo "web=" . implode(",", (array)$web) . "\n";
echo "webcache=" . implode(",", (array)$cache) . "\n";
echo "dns=" . implode(",", (array)$dns) . "\n";
echo "davuser=" . $dav . "\n";
echo "mmail=" . implode(",", (array)$mail) . "\n";
echo "smtp=" . implode(",", (array)$smtp) . "\n";
' "$ROOT/kloxo/file/driver/linux.inc")"
echo "$php_out" | grep -qx 'web=none,nginx,apache' && ok "web is none,nginx,apache" || bad "web is none,nginx,apache" "$php_out"
echo "$php_out" | grep -qx 'webcache=none,nginx' && ok "webcache is none or nginx (no varnish/squid/ats)" || bad "webcache is none or nginx (no varnish/squid/ats)" "$php_out"
echo "$php_out" | grep -qx 'dns=none,bind,knot' && ok "dns is none,bind,knot" || bad "dns is none,bind,knot" "$php_out"
echo "$php_out" | grep -qx 'davuser=none' && ok "davuser is none" || bad "davuser is none" "$php_out"
echo "$php_out" | grep -qx 'mmail=' && ok "no qmail mmail driver list" || bad "no qmail mmail driver list" "$php_out"
echo "$php_out" | grep -qx 'smtp=none,exim,postfix' && ok "smtp is none,exim,postfix" || bad "smtp is none,exim,postfix" "$php_out"

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
  "web__lighttpd","web__hiawatha","web__openlitespeed","web__monkey",
  "web__lighttpdproxy","web__nginxproxy","web__hiawathaproxy","web__openlitespeedproxy","web__monkeyproxy",
  "serverweb__lighttpd","serverweb__hiawatha","serverweb__openlitespeed","serverweb__monkey",
  "serverweb__lighttpdproxy","serverweb__nginxproxy","serverweb__hiawathaproxy","serverweb__openlitespeedproxy","serverweb__monkeyproxy",
  "webcache__varnish","webcache__trafficserver","webcache__squid",
  "dns__djbdns","dns__nsd","dns__mydns","dns__yadifa","dns__maradns",
  "davuser__lighttpd","davuser__apache",
  "dirprotect__lighttpd",
);
$present = 0;
foreach ($forbid as $c) {
  if (isset($gl_class_array[$c])) { fwrite(STDERR, "still registered $c\n"); $present = 1; }
}
$need = array("web__nginx","web__apache","web__none","serverweb__nginx","serverweb__apache","serverweb__none","webcache__none","webcache__nginx",
  "dns__bind","dns__knot","dns__none","davuser__none","dirprotect__nginx","dirprotect__apache","dirprotect__none",
  "webhandler__nginx","webmimetype__nginx","webhandler__apache","webmimetype__apache","smtp__exim","smtp__postfix","smtp__none");
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
gone="lighttpd varnish squid trafficserver hiawatha djbdns nsd mydns yadifa maradns qmail"
for d in $gone; do
  if [ -e "$ROOT/kloxo/file/$d" ]; then
    bad "file/$d removed"
  else
    ok "file/$d removed"
  fi
done
for d in nginx apache bind mail; do
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
if [[ ! -e "$ROOT/kloxo/install" ]] && [[ ! -f "$ROOT/kloxo/file/driver/redhat.inc" ]]   && [[ ! -f "$ROOT/kloxo/httpdocs/driver/pserver/package__yumlib.php" ]]   && [[ ! -f "$ROOT/kloxo/httpdocs/driver/pserver/service__redhatlib.php" ]]   && [[ ! -f "$ROOT/kloxo/httpdocs/driver/pserver/component__rpmlib.php" ]]   && [[ ! -f "$ROOT/kloxo/httpdocs/driver/pserver/ipaddress__redhatlib.php" ]]; then
  ok "CentOS legacy installer and Red Hat/yum/rpm drivers are removed"
else
  bad "CentOS legacy installer and Red Hat/yum/rpm drivers are removed"
fi
if grep -q "pg_package.*=.*'aptget'" "$ROOT/kloxo/bin/install/init-panel-db"   && grep -q "\$driver\['package'\] = \"aptget\"" "$ROOT/kloxo/file/driver/debian.inc"; then
  ok "Ubuntu package driver is aptget"
else
  bad "Ubuntu package driver is aptget"
fi

if ! grep -q 'Qmail-toaster' "$ROOT/kloxo/httpdocs/lib/sgbl.php" \
  && grep -q 'Exim Mail Server' "$ROOT/kloxo/httpdocs/lib/sgbl.php" \
  && grep -q 'Exim Mail Server' "$ROOT/kloxo/file/sql/db-structure-update.sql" \
  && ! grep -q 'Qmail-toaster' "$ROOT/kloxo/file/sql/db-structure-update.sql"; then
  ok "Qmail-toaster service label is Exim"
else
  bad "Qmail-toaster service label is Exim"
fi
if ( cd "$ROOT/kloxo/file/driver" && php -r '
$driver = array();
include "debian.inc";
exit((isset($driver["smtp"]) && $driver["smtp"] === array("none", "exim", "postfix")) ? 0 : 1);
' ); then
  ok "debian.inc smtp is none,exim,postfix"
else
  bad "debian.inc smtp is none,exim,postfix"
fi
if grep -q "'pg_smtp' => 'exim'" "$ROOT/kloxo/bin/install/init-panel-db"; then
  ok "init-panel-db default smtp is exim"
else
  bad "init-panel-db default smtp is exim"
fi
if grep -q "\$a\['smtp'\] = \"exim\"" "$ROOT/kloxo/httpdocs/lib/html/linuxlib.php"; then
  ok "slave default smtp is exim"
else
  bad "slave default smtp is exim"
fi

echo "== php -l on new stubs"
for f in davuser__nonelib.php mimehandler__nginxlib.php; do
  if php -l "$ROOT/kloxo/httpdocs/driver/web/$f" >/dev/null 2>&1; then
    ok "php -l $f"
  else
    bad "php -l $f"
  fi
done
if php -l "$ROOT/kloxo/httpdocs/driver/mmail/smtp__eximlib.php" >/dev/null 2>&1; then
  ok "php -l smtp__eximlib.php"
else
  bad "php -l smtp__eximlib.php"
fi
if php -l "$ROOT/kloxo/httpdocs/driver/mmail/smtp__postfixlib.php" >/dev/null 2>&1; then
  ok "php -l smtp__postfixlib.php"
else
  bad "php -l smtp__postfixlib.php"
fi
if php -l "$ROOT/kloxo/httpdocs/driver/web/web__apachelib.php" >/dev/null 2>&1; then
  ok "php -l web__apachelib.php"
else
  bad "php -l web__apachelib.php"
fi
if php -l "$ROOT/kloxo/file/driver/linux.inc" >/dev/null 2>&1; then
  ok "php -l linux.inc"
else
  bad "php -l linux.inc"
fi

echo "== Knot DNS ready, BIND default"
if grep -q "'pg_dns' => 'bind'" "$ROOT/kloxo/bin/install/init-panel-db"; then
  ok "init-panel-db default dns is bind"
else
  bad "init-panel-db default dns is bind"
fi
if grep -q "\$a\['dns'\] = \"bind\"" "$ROOT/kloxo/httpdocs/lib/html/linuxlib.php"; then
  ok "slave default dns is bind"
else
  bad "slave default dns is bind"
fi
if php -l "$ROOT/kloxo/httpdocs/lib/php/kloxo_dns.php" >/dev/null 2>&1; then
  ok "php -l kloxo_dns.php"
else
  bad "php -l kloxo_dns.php"
fi
if php -l "$ROOT/kloxo/httpdocs/lib/php/kloxo_knot.php" >/dev/null 2>&1 && php -l "$ROOT/kloxo/bin/install/install-knot" >/dev/null 2>&1; then
  ok "php -l kloxo_knot.php, install-knot"
else
  bad "php -l kloxo_knot.php, install-knot"
fi
if php -r 'include $argv[1]; exit(kloxo_dns_unit("knot") === "knot" && kloxo_dns_unit("bind") !== "" ? 0 : 1);' \
  "$ROOT/kloxo/httpdocs/lib/php/kloxo_dns.php"; then
  ok "kloxo_dns_unit bind/knot"
else
  bad "kloxo_dns_unit bind/knot"
fi
if [ ! -e "$ROOT/kloxo/file/pdns" ] && [ ! -e "$ROOT/kloxo/bin/install/install-pdns" ] \
  && [ ! -e "$ROOT/kloxo/httpdocs/driver/dns/dns__pdnslib.php" ] \
  && ! grep -rqE 'pdns|PowerDNS' "$ROOT/kloxo/httpdocs/lib" "$ROOT/kloxo/httpdocs/driver" "$ROOT/install.sh" --include='*.php' --include='*.inc' --include='*.sh'; then
  ok "no PowerDNS code left (migration in init-panel-db only)"
else
  bad "no PowerDNS code left (migration in init-panel-db only)"
fi
if grep -q 'function kloxo_dns_switch' "$ROOT/kloxo/httpdocs/lib/php/kloxo_dns.php" \
  && grep -q 'kloxo_dns_switch' "$ROOT/kloxo/httpdocs/driver/dns/dns__lib.php"; then
  ok "Switch Program DNS uses systemctl bind9 vs knot"
else
  bad "Switch Program DNS uses systemctl bind9 vs knot"
fi

echo "== Apache ready, Nginx default"
if grep -q "'pg_web' => 'nginx'" "$ROOT/kloxo/bin/install/init-panel-db"; then
  ok "init-panel-db default web is nginx"
else
  bad "init-panel-db default web is nginx"
fi
if grep -q "\$a\['web'\] = \"nginx\"" "$ROOT/kloxo/httpdocs/lib/html/linuxlib.php"; then
  ok "slave default web is nginx"
else
  bad "slave default web is nginx"
fi
if php -l "$ROOT/kloxo/httpdocs/lib/php/kloxo_web.php" >/dev/null 2>&1; then
  ok "php -l kloxo_web.php"
else
  bad "php -l kloxo_web.php"
fi
if php -l "$ROOT/kloxo/bin/install/install-apache" >/dev/null 2>&1; then
  ok "php -l install-apache"
else
  bad "php -l install-apache"
fi
if php -r 'include $argv[1]; exit(kloxo_web_unit("nginx") === "nginx" && kloxo_web_unit("apache") !== "" ? 0 : 1);' \
  "$ROOT/kloxo/httpdocs/lib/php/kloxo_web.php"; then
  ok "kloxo_web_unit nginx/apache"
else
  bad "kloxo_web_unit nginx/apache"
fi
if grep -q 'proxy_fcgi' "$ROOT/kloxo/file/apache/tpl/domains.conf.tpl" \
  && grep -q 'proxy:unix:' "$ROOT/kloxo/file/apache/tpl/domains.conf.tpl" \
  && ! grep -qi 'mod_php' "$ROOT/kloxo/file/apache/tpl/"*.tpl; then
  ok "apache templates use PHP-FPM proxy_fcgi, not mod_php"
else
  bad "apache templates use PHP-FPM proxy_fcgi, not mod_php"
fi
if grep -q 'function kloxo_web_switch' "$ROOT/kloxo/httpdocs/lib/php/kloxo_web.php" \
  && grep -q 'kloxo_web_switch' "$ROOT/kloxo/httpdocs/driver/web/web__lib.php"; then
  ok "Switch Program web uses systemctl nginx vs apache2"
else
  bad "Switch Program web uses systemctl nginx vs apache2"
fi
if [ -f "$ROOT/kloxo/file/apache/tpl/domains.conf.tpl" ] && [ -f "$ROOT/kloxo/file/apache/tpl/defaults.conf.tpl" ]; then
  ok "apache site templates present"
else
  bad "apache site templates present"
fi

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
