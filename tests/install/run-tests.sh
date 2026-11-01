#!/bin/bash
# Ubuntu panel schema + Hiawatha installer invariants. php-cli only. Safe locally.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
INITDB=(php "$ROOT/kloxo/bin/install/init-panel-db")
HIA="$ROOT/kloxo/bin/install/install-hiawatha"
T="$(mktemp -d "${TMPDIR:-/tmp}/kloxo-install.XXXXXX")"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; [ -n "${2:-}" ] && printf '       %s\n' "$2"; }
check(){ local d="$1"; shift; if "$@" >/dev/null 2>&1; then ok "$d"; else bad "$d" "command failed: $*"; fi; }
fails(){ local d="$1"; shift; if "$@" >/dev/null 2>&1; then bad "$d" "expected failure: $*"; else ok "$d"; fi; }
cleanup() { rm -rf "$T"; }
trap cleanup EXIT

echo "== lint and help"
check "php -l init-panel-db" php -l "$ROOT/kloxo/bin/install/init-panel-db"
check "php -l create.php" php -l "$ROOT/kloxo/bin/install/create.php"
check "php -l initlib.php" php -l "$ROOT/kloxo/httpdocs/lib/html/initlib.php"
check "init-panel-db --help" "${INITDB[@]}" --help
check "init-panel-db --version" "${INITDB[@]}" --version
check "install-hiawatha --help" bash "$HIA" --help
check "install-hiawatha --dry-run" bash "$HIA" --dry-run --root="$ROOT/kloxo"
fails "init-panel-db root with .. rejected" "${INITDB[@]}" --root="$ROOT/kloxo/../kloxo" --print-schema-stats
fails "init-panel-db short admin password" "${INITDB[@]}" --root="$ROOT/kloxo" --admin-password=short

echo "== schema stats (no MariaDB)"
stats="$("${INITDB[@]}" --print-schema-stats --root="$ROOT/kloxo")"
echo "$stats" | grep -q '"has_client": true' && ok "schema has client" || bad "schema has client" "$stats"
echo "$stats" | grep -q '"has_general": true' && ok "schema has general" || bad "schema has general"
echo "$stats" | grep -q '"has_web": true' && ok "schema has web" || bad "schema has web"
echo "$stats" | grep -q '"has_domain": true' && ok "schema has domain" || bad "schema has domain"
echo "$stats" | grep -q '"has_pserver": true' && ok "schema has pserver" || bad "schema has pserver"
echo "$stats" | grep -q '"missing_required": \[\]' && ok "no missing required tables" || bad "no missing required tables" "$stats"

echo "== source invariants (the live-server failures)"
if grep -E 'mysql .*< .*&' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php"; then
  bad "create_database no longer backgrounds mysql"
else
  ok "create_database no longer backgrounds mysql"
fi
if sed -n '/^function create_database(/,/^function update_database(/p' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" | grep -q 'slave_get_db_pass('; then
  bad "create_database no longer uses slave_get_db_pass"
else
  ok "create_database no longer uses slave_get_db_pass"
fi
if grep -n 'function create_mysql_db' -A 60 "$ROOT/kloxo/httpdocs/lib/html/initlib.php" | grep -qi 'identified by'; then
  # CREATE USER ... IDENTIFIED BY ? is required; GRANT ... IDENTIFIED BY is not.
  if grep -n 'function create_mysql_db' -A 60 "$ROOT/kloxo/httpdocs/lib/html/initlib.php" | grep -qi 'grant all.*identified by'; then
    bad "create_mysql_db no GRANT IDENTIFIED BY"
  else
    ok "create_mysql_db no GRANT IDENTIFIED BY"
  fi
else
  ok "create_mysql_db no GRANT IDENTIFIED BY"
fi
if grep -E '^[[:space:]]*MinTLSversion[[:space:]]*=' "$ROOT/kloxo/init/hiawatha.conf.base"; then
  bad "hiawatha.conf.base has no MinTLSversion (removed in Hiawatha 11)"
else
  ok "hiawatha.conf.base has no MinTLSversion (removed in Hiawatha 11)"
fi
if grep -q '^CGIhandler' "$ROOT/kloxo/init/hiawatha.conf.base"; then
  bad "hiawatha.conf.base has no CGIhandler (php-cgi missing on Ubuntu)"
else
  ok "hiawatha.conf.base has no CGIhandler (php-cgi missing on Ubuntu)"
fi
if grep -q 'changeport.php' "$ROOT/kloxo/init/kloxo-web.service"; then
  bad "kloxo-web.service does not call changeport.php (needs a live DB)"
else
  ok "kloxo-web.service does not call changeport.php (needs a live DB)"
fi
if grep -q 'HIAWATHA_VERSION="12.4"' "$HIA"; then
  ok "install-hiawatha pins 12.4"
else
  bad "install-hiawatha pins 12.4"
fi
if grep -q 'c80f87b5f9f04520b57af96ac52905198f96de38c3b7d988abc0f758cdd46d2e' "$HIA"; then
  ok "install-hiawatha pins sha256"
else
  bad "install-hiawatha pins sha256"
fi
if grep -q "web'] = \"nginx\"" "$ROOT/kloxo/httpdocs/lib/html/linuxlib.php"; then
  ok "default slave driver web=nginx"
else
  bad "default slave driver web=nginx"
fi
if grep -q '/usr/lib/systemd/system' "$ROOT/kloxo/httpdocs/lib/html/linuxlib.php"; then
  bad "os_create_program_service uses /etc/systemd/system"
else
  ok "os_create_program_service uses /etc/systemd/system"
fi
if grep -q 'init-panel-db' "$ROOT/install.sh"; then
  ok "install.sh calls init-panel-db"
else
  bad "install.sh calls init-panel-db"
fi
ONELINER="sudo bash -c 'export DEBIAN_FRONTEND=noninteractive; apt-get update && apt-get install -y git ca-certificates && { test -f /usr/local/src/kloxo-cp/install.sh || git clone --depth 1 --branch main https://github.com/ARDZIVE/kloxocp.git /usr/local/src/kloxo-cp; } && bash /usr/local/src/kloxo-cp/install.sh'"
if grep -Fqx "$ONELINER" "$ROOT/README.md" && grep -Fq "$ONELINER" "$ROOT/install.sh"; then
  ok "README and install.sh share the same one-liner"
else
  bad "README and install.sh share the same one-liner"
fi
if echo "$ONELINER" | grep -q 'test -f /usr/local/src/kloxo-cp/install.sh || git clone'; then
  ok "one-liner re-runs when the clone already exists"
else
  bad "one-liner re-runs when the clone already exists"
fi
if grep -q 'print_access_box' "$ROOT/install.sh" && grep -q 'Username' "$ROOT/install.sh" && grep -q 'first login' "$ROOT/install.sh"; then
  ok "installer prints a login access box"
else
  bad "installer prints a login access box"
fi
if grep -q 'install-hiawatha' "$ROOT/install.sh"; then
  ok "install.sh calls install-hiawatha"
else
  bad "install.sh calls install-hiawatha"
fi
hia_line="$(grep -n 'install-hiawatha' "$ROOT/install.sh" | head -n1 | cut -d: -f1)"
db_line="$(grep -n 'init-panel-db' "$ROOT/install.sh" | grep -v 'chmod' | tail -n1 | cut -d: -f1)"
if [[ -n "$hia_line" && -n "$db_line" && "$hia_line" -lt "$db_line" ]]; then
  ok "install.sh builds Hiawatha before the panel schema"
else
  bad "install.sh builds Hiawatha before the panel schema"
fi
if grep -q 'USE_STATIC_MBEDTLS_LIBRARY=on' "$ROOT/kloxo/bin/install/install-hiawatha"; then
  ok "Hiawatha links bundled mbed TLS statically"
else
  bad "Hiawatha links bundled mbed TLS statically"
fi
if grep -q 'WORK_DIR=' "$ROOT/kloxo/bin/install/install-hiawatha" && grep -q '/usr/local/var/lib/hiawatha/upload' "$ROOT/kloxo/bin/install/install-hiawatha"; then
  ok "Hiawatha upload work directory is created"
else
  bad "Hiawatha upload work directory is created"
fi
if grep -q 'Type=simple' "$ROOT/kloxo/init/kloxo-web.service" && grep -q 'kloxo-hiawatha -d' "$ROOT/kloxo/init/kloxo-web.service"; then
  ok "kloxo-web runs Hiawatha in the foreground"
else
  bad "kloxo-web runs Hiawatha in the foreground"
fi
PMA="$ROOT/kloxo/bin/install/install-phpmyadmin"
check "install-phpmyadmin --help" bash "$PMA" --help
check "install-phpmyadmin --dry-run" bash "$PMA" --dry-run --root="$ROOT/kloxo"
fails "install-phpmyadmin root with .. rejected" bash "$PMA" --root="$ROOT/kloxo/../kloxo"
if grep -q 'install-phpmyadmin' "$ROOT/install.sh"; then
  ok "install.sh calls install-phpmyadmin"
else
  bad "install.sh calls install-phpmyadmin"
fi
if [[ -f "$ROOT/kloxo/httpdocs/thirdparty/phpMyAdmin/index.php" ]]; then
  bad "phpMyAdmin is not vendored in the tree"
else
  ok "phpMyAdmin is not vendored in the tree"
fi
if grep -q 'KLOXO_BLOWFISH_SECRET' "$ROOT/kloxo/file/phpmyadmin/config.inc.php"; then
  ok "phpMyAdmin config template has no committed blowfish secret"
else
  bad "phpMyAdmin config template has no committed blowfish secret"
fi
if grep -q 'pma_password' "$ROOT/kloxo/httpdocs/lib/client/clientlib.php"; then
  bad "phpMyAdmin link does not put the DB password in the URL"
else
  ok "phpMyAdmin link does not put the DB password in the URL"
fi
if sed -n '/^function setInitialPhpMyAdmin(/,/^}/p' "$ROOT/kloxo/httpdocs/lib/html/lib.php" | grep -q 'lxfile_cp.*phpMyAdmin/config'; then
  bad "setInitialPhpMyAdmin does not create an empty phpMyAdmin dir"
else
  ok "setInitialPhpMyAdmin does not create an empty phpMyAdmin dir"
fi
dry="$(bash "$HIA" --dry-run --root="$ROOT/kloxo")"
echo "$dry" | grep -q '12.4' && ok "dry-run names 12.4" || bad "dry-run names 12.4" "$dry"
echo "$dry" | grep -q 'kloxo-hiawatha' && ok "dry-run names kloxo-hiawatha" || bad "dry-run names kloxo-hiawatha"

echo "== Ubuntu 24.04 and 26.x"
eval "$(sed -n '/^kloxo_ubuntu_ok()/,/^}/p' "$ROOT/install.sh")"
if kloxo_ubuntu_ok ubuntu 24.04; then ok "installer accepts 24.04"; else bad "installer accepts 24.04"; fi
if kloxo_ubuntu_ok ubuntu 26.04; then ok "installer accepts 26.04"; else bad "installer accepts 26.04"; fi
if kloxo_ubuntu_ok ubuntu 26.10; then ok "installer accepts 26.10"; else bad "installer accepts 26.10"; fi
if kloxo_ubuntu_ok ubuntu 22.04; then bad "installer rejects 22.04"; else ok "installer rejects 22.04"; fi
if kloxo_ubuntu_ok ubuntu 25.04; then bad "installer rejects 25.04"; else ok "installer rejects 25.04"; fi
if kloxo_ubuntu_ok debian 26.04; then bad "installer rejects debian"; else ok "installer rejects debian"; fi
helpout="$(bash "$ROOT/install.sh" --help)"
echo "$helpout" | grep -q '26' && ok "install.sh help names 26" || bad "install.sh help names 26"
if grep -q 'php-fpm8.5' "$ROOT/kloxo/init/kloxo-php-fpm.sh" && grep -q 'php-fpm8.3' "$ROOT/kloxo/init/kloxo-php-fpm.sh"; then
  ok "php-fpm wrapper tries 8.5 then 8.3"
else
  bad "php-fpm wrapper tries 8.5 then 8.3"
fi
if grep -q 'php-fpm8.3' "$ROOT/kloxo/init/kloxo-php.service"; then
  bad "kloxo-php.service uses kloxo-php-fpm.sh (not php-fpm8.3)"
else
  ok "kloxo-php.service uses kloxo-php-fpm.sh (not php-fpm8.3)"
fi
if grep -qE 'magic_quotes_gpc|allow_url_include|mysql\.allow_persistent|enable_dl' "$ROOT/kloxo/init/kloxo-php-fpm.conf"; then
  bad "kloxo-php-fpm.conf has no removed PHP 8 INI flags"
else
  ok "kloxo-php-fpm.conf has no removed PHP 8 INI flags"
fi
if grep -q 'Type=simple' "$ROOT/kloxo/init/kloxo-php.service"; then
  ok "kloxo-php.service is Type=simple"
else
  bad "kloxo-php.service is Type=simple"
fi
if grep -q 'kloxo-php-fpm.conf" -t' "$ROOT/install.sh" && grep -q '"$ROOT/session"' "$ROOT/install.sh"; then
  ok "install.sh tests the FPM pool and creates session dir"
else
  bad "install.sh tests the FPM pool and creates session dir"
fi
if grep -q 'listen = 127.0.0.1:39000' "$ROOT/kloxo/init/kloxo-php-fpm.conf" \
  && grep -q 'ConnectTo = 127.0.0.1:39000' "$ROOT/kloxo/init/hiawatha.conf.base" \
  && grep -q 'systemctl restart kloxo-web' "$ROOT/install.sh"; then
  ok "Hiawatha FastCGI uses 127.0.0.1:39000 and restarts after FPM"
else
  bad "Hiawatha FastCGI uses 127.0.0.1:39000 and restarts after FPM"
fi
if grep -q '/usr/bin/php8.5' "$ROOT/kloxo/init/php-cli.sh" && grep -q '/usr/bin/php8.3' "$ROOT/kloxo/httpdocs/lib/sgbl.php"; then
  ok "php-cli and sgbl look for php8.5 and php8.3"
else
  bad "php-cli and sgbl look for php8.5 and php8.3"
fi
if grep -q "str_starts_with(\$version, '26.')" "$ROOT/kloxo/bin/kloxocp"; then
  ok "kloxocp doctor accepts Ubuntu 26.x"
else
  bad "kloxocp doctor accepts Ubuntu 26.x"
fi
if grep -q '/run/mariadb/mysqld.sock' "$ROOT/kloxo/bin/install/init-panel-db"; then
  ok "init-panel-db tries MariaDB 11 socket paths"
else
  bad "init-panel-db tries MariaDB 11 socket paths"
fi
if grep -n 'function connectRoot' -A 40 "$ROOT/kloxo/bin/install/init-panel-db" | grep -q 'is_file(\$sock)'; then
  bad "connectRoot does not use is_file on sockets"
else
  ok "connectRoot does not use is_file on sockets"
fi
if grep -q 'function socketExists' "$ROOT/kloxo/bin/install/init-panel-db" && grep -q 'file_exists' "$ROOT/kloxo/bin/install/init-panel-db"; then
  ok "socketExists uses file_exists"
else
  bad "socketExists uses file_exists"
fi
if grep -q 'cliBootstrap' "$ROOT/kloxo/bin/install/init-panel-db" && grep -q 'connectApp' "$ROOT/kloxo/bin/install/init-panel-db"; then
  ok "schema uses mariadb CLI unix_socket then kloxo user"
else
  bad "schema uses mariadb CLI unix_socket then kloxo user"
fi
if grep -q 'PDO as' "$ROOT/kloxo/bin/install/init-panel-db"; then
  bad "init-panel-db does not print PDO-as-root warnings"
else
  ok "init-panel-db does not print PDO-as-root warnings"
fi
if grep -q 'wait_mariadb' "$ROOT/install.sh"; then
  ok "install.sh waits for MariaDB"
else
  bad "install.sh waits for MariaDB"
fi
helpdb="$("${INITDB[@]}" --help)"
echo "$helpdb" | grep -q 'admin/admin' && ok "init-panel-db help names admin/admin" || bad "init-panel-db help names admin/admin"
echo "$helpout" | grep -q 'admin/admin' && ok "install.sh help names admin/admin" || bad "install.sh help names admin/admin"
if grep -q 'admin_env="${KLOXO_ADMIN_PASSWORD:-admin}"' "$ROOT/install.sh"; then
  ok "install.sh defaults UI password to admin"
else
  bad "install.sh defaults UI password to admin"
fi
if grep -q 'must change after first login' "$ROOT/kloxo/bin/install/create.php"; then
  ok "create.php defaults UI password to admin"
else
  bad "create.php defaults UI password to admin"
fi
if grep -q "check_raw_password('client', 'admin', 'admin')" "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php"; then
  ok "password_contact_check forces change while password is admin"
else
  bad "password_contact_check forces change while password is admin"
fi
if grep -q 'cannot_use_default_password' "$ROOT/kloxo/httpdocs/lib/client/lxclient.php"; then
  ok "updatePassword rejects reuse of admin"
else
  bad "updatePassword rejects reuse of admin"
fi
if grep -q "syncserver = IF" "$ROOT/kloxo/bin/install/init-panel-db" && grep -q "'localhost')" "$ROOT/kloxo/bin/install/init-panel-db"; then
  ok "init-panel-db seeds admin syncserver=localhost"
else
  bad "init-panel-db seeds admin syncserver=localhost"
fi
if grep -q 'function seedPserver' "$ROOT/kloxo/bin/install/init-panel-db" && grep -q 'function seedPhpini' "$ROOT/kloxo/bin/install/init-panel-db" && grep -q 'php-fpm_event' "$ROOT/kloxo/bin/install/init-panel-db"; then
  ok "init-panel-db seeds pserver phpini serverweb"
else
  bad "init-panel-db seeds pserver phpini serverweb"
fi
if grep -q 'function seedAdminPriv' "$ROOT/kloxo/bin/install/init-panel-db" && grep -q "WHERE nname = 'admin'" "$ROOT/kloxo/bin/install/init-panel-db"; then
  ok "init-panel-db seeds admin unlimited priv"
else
  bad "init-panel-db seeds admin unlimited priv"
fi
if grep -q 'max_execution_time_flag' "$ROOT/kloxo/bin/install/init-panel-db" && grep -q 'function phpiniFlagDefaults' "$ROOT/kloxo/bin/install/init-panel-db"; then
  ok "init-panel-db seeds full phpini defaults"
else
  bad "init-panel-db seeds full phpini defaults"
fi
if grep -q "nname = 'localhost'" "$ROOT/kloxo/bin/install/init-panel-db" && grep -q "coma_psrole_a" "$ROOT/kloxo/bin/install/init-panel-db" && grep -q "osversion" "$ROOT/kloxo/bin/install/init-panel-db"; then
  ok "init-panel-db seeds localhost pserver display fields"
else
  bad "init-panel-db seeds localhost pserver display fields"
fi
if grep -q 'function ensureLocalhostServer' "$ROOT/kloxo/httpdocs/driver/pserver/pservercorelib.php"; then
  ok "pserver list ensures localhost"
else
  bad "pserver list ensures localhost"
fi
if grep -q 'isClient() && $obj->isAdmin()' "$ROOT/kloxo/httpdocs/theme/htmllib.php"; then
  ok "list skip is admin-client only"
else
  bad "list skip is admin-client only"
fi
if grep -n 'db_get_value("serverweb"' -A0 "$ROOT/kloxo/httpdocs/driver/pserver/phpini__synclib.php" | grep -q 'php-type'; then
  bad "phpini sync uses php_type column (not php-type)"
else
  ok "phpini sync uses php_type column (not php-type)"
fi
if grep -q 'KloxoIpaddr::currentIpv4' "$ROOT/kloxo/httpdocs/driver/pserver/ipaddress__redhatlib.php" && grep -q 'KloxoIpaddr::refuseNetworkWrite' "$ROOT/kloxo/httpdocs/driver/pserver/ipaddress__redhatlib.php"; then
  ok "redhat ip driver reads ip(8) and refuses Ubuntu writes"
else
  bad "redhat ip driver reads ip(8) and refuses Ubuntu writes"
fi
if grep -q 'KloxoIpaddr::currentIpv4' "$ROOT/kloxo/httpdocs/driver/pserver/ipaddress__debianlib.php"; then
  ok "debian ip driver falls back to ip(8)"
else
  bad "debian ip driver falls back to ip(8)"
fi
if grep -q "syncserver ?: 'localhost'" "$ROOT/kloxo/httpdocs/lib/client/clientbaselib.php"; then
  ok "phpini alert uses localhost when syncserver is empty"
else
  bad "phpini alert uses localhost when syncserver is empty"
fi
check "php -l kloxo_ipaddr.php" php -l "$ROOT/kloxo/httpdocs/lib/php/kloxo_ipaddr.php"
check "KloxoIpaddr parse fixture" php "$HERE/test_ipaddr.php"
if grep -q 'KloxoPhpver::installedIds' "$ROOT/kloxo/httpdocs/lib/html/lib.php" && grep -q 'KloxoPhpver::installedIds' "$ROOT/kloxo/httpdocs/driver/web/serverweblib.php"; then
  ok "PHP lists use distro php-fpm discovery"
else
  bad "PHP lists use distro php-fpm discovery"
fi
check "php -l kloxo_phpver.php" php -l "$ROOT/kloxo/httpdocs/lib/php/kloxo_phpver.php"
check "KloxoPhpver ids" php "$HERE/test_phpver.php"
if grep -q 'KloxoPhpver::distroModuleRows' "$ROOT/kloxo/httpdocs/driver/pserver/phpmodule__linuxlib.php"; then
  ok "PHP Module Status scans Ubuntu mods-available"
else
  bad "PHP Module Status scans Ubuntu mods-available"
fi
check "php -l phpmodule__linuxlib.php" php -l "$ROOT/kloxo/httpdocs/driver/pserver/phpmodule__linuxlib.php"
check "phpmodule list rows" php "$HERE/test_phpmodule.php"
if grep -q 'KloxoTraceroute::run' "$ROOT/kloxo/httpdocs/lib/html/traceroute.php" && ! grep -q 'throw new lxException' "$ROOT/kloxo/httpdocs/lib/html/traceroute.php"; then
  ok "traceroute list does not throw traceroute_failed"
else
  bad "traceroute list does not throw traceroute_failed"
fi
if grep -q 'setDefaultValue("realhostname"' "$ROOT/kloxo/httpdocs/driver/pserver/pservercorelib.php" && grep -q 'realhostname = ' "$ROOT/kloxo/httpdocs/driver/pserver/pservercorelib.php"; then
  ok "server information prefills description and FQDN"
else
  bad "server information prefills description and FQDN"
fi
check "php -l kloxo_traceroute.php" php -l "$ROOT/kloxo/httpdocs/lib/php/kloxo_traceroute.php"
check "traceroute host validation" php "$HERE/test_traceroute.php"
if grep -q 'apt-get install -y hiawatha' "$ROOT/install.sh"; then
  bad "install.sh does not apt-get hiawatha (source build only)"
else
  ok "install.sh does not apt-get hiawatha (source build only)"
fi
if grep -q 'function innodbSafe' "$ROOT/kloxo/bin/install/init-panel-db" && grep -q 'ROW_FORMAT=DYNAMIC' "$ROOT/kloxo/bin/install/init-panel-db"; then
  ok "init-panel-db rewrites wide tables for MariaDB 11"
else
  bad "init-panel-db rewrites wide tables for MariaDB 11"
fi
check "init-panel-db --self-test" "${INITDB[@]}" --self-test

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
