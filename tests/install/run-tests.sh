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
fails "init-panel-db refuses missing admin password" "${INITDB[@]}" --root="$ROOT/kloxo"
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
if grep -q 'install-hiawatha' "$ROOT/install.sh"; then
  ok "install.sh calls install-hiawatha"
else
  bad "install.sh calls install-hiawatha"
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

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
