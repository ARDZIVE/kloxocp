#!/bin/bash
# P2 PHP 8.3 first-party parse + boot-path checks. php-cli only. Safe locally.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); printf '  ok   %s\n' "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; [ -n "${2:-}" ] && printf '       %s\n' "$2"; }

echo "== first-party php -l (exclude webdisk, formmail, urlrewrite examples)"
lint_fail=0
lint_ok=0
while IFS= read -r f; do
  if php -d display_errors=1 -l "$f" >/dev/null 2>&1; then
    lint_ok=$((lint_ok+1))
  else
    lint_fail=$((lint_fail+1))
    bad "php -l ${f#$ROOT/}" "$(php -d display_errors=1 -l "$f" 2>&1 | tail -2 | tr '\n' ' ')"
  fi
done < <(find "$ROOT/kloxo" \( -name '*.php' -o -name '*.inc' \) \
  ! -path '*/webdisk/*' \
  ! -path '*/file/formmail/*' \
  ! -path '*/lib/urlrewrite/example*' \
  | sort)
if [ "$lint_fail" -eq 0 ]; then
  ok "first-party php -l ($lint_ok files)"
else
  bad "first-party php -l ($lint_fail failed, $lint_ok ok)"
fi

echo "== no __autoload in first-party boot files"
if grep -n 'function __autoload' "$ROOT/kloxo/httpdocs/lib/php/common.inc" "$ROOT/kloxo/httpdocs/lib/extra/PHPMailerAutoload.php" >/dev/null 2>&1; then
  bad "common.inc / PHPMailerAutoload have no __autoload"
else
  ok "common.inc / PHPMailerAutoload have no __autoload"
fi
if grep -q 'spl_autoload_register' "$ROOT/kloxo/httpdocs/lib/php/common.inc"; then
  ok "common.inc registers spl_autoload_register"
else
  bad "common.inc registers spl_autoload_register"
fi
if sed -n '/^function kloxo_autoload(/,/^}/p' "$ROOT/kloxo/httpdocs/lib/php/common.inc" | grep -q "_none"; then
  bad "autoload does not skip *_none drivers"
else
  ok "autoload does not skip *_none drivers"
fi

echo "== boot-path helpers"
if grep -q 'function char_search_beg($haystack, $needle, $insensitive' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php"; then
  ok "char_search_beg accepts the extra csb argument"
else
  bad "char_search_beg accepts the extra csb argument"
fi
if grep -q 'ereg' "$ROOT/kloxo/httpdocs/lib/html/includecore.php"; then
  bad "includecore.php has no ereg*"
else
  ok "includecore.php has no ereg*"
fi
if grep -q 'new mysqli' "$ROOT/kloxo/httpdocs/lib/php/sqlitelib.php"; then
  ok "Sqlite DB layer uses mysqli"
else
  bad "Sqlite DB layer uses mysqli"
fi
if sed -n '/function createQueryStringAdd(/,/function createQueryStringUpdate(/p' "$ROOT/kloxo/httpdocs/lib/php/sqlitelib.php" | grep -q ') values('; then
  ok "createQueryStringAdd closes the column list before values"
else
  bad "createQueryStringAdd closes the column list before values"
fi
if grep -q 'catch (mysqli_sql_exception' "$ROOT/kloxo/httpdocs/lib/php/sqlitelib.php"; then
  ok "database_query catches mysqli_sql_exception"
else
  bad "database_query catches mysqli_sql_exception"
fi

echo "== autoload characterization"
if php "$HERE/test_autoload.php"; then
  ok "test_autoload.php"
else
  bad "test_autoload.php"
fi

echo "== includecore characterization"
if php "$HERE/test_includecore.php"; then
  ok "test_includecore.php"
else
  bad "test_includecore.php"
fi

echo "== panel boot (include.php)"
if php "$HERE/test_boot.php"; then
  ok "test_boot.php"
else
  bad "test_boot.php"
fi
if php "$HERE/test_empty_list.php"; then
  ok "test_empty_list.php"
else
  bad "test_empty_list.php"
fi
if sed -n '/final protected function initListIfUndef(/,/^	final function getUrlIdentity(/p' "$ROOT/kloxo/httpdocs/lib/php/lxclass.php" | grep -q 'is_array($this->$list)'; then
  ok "initListIfUndef does not count() a null child list"
else
  bad "initListIfUndef does not count() a null child list"
fi
if [ -f "$ROOT/kloxo/httpdocs/login/index.php" ]; then
  ok "login/index.php shipped"
else
  bad "login/index.php shipped"
fi
if grep -q 'function kloxo_php_binary' "$ROOT/kloxo/httpdocs/lib/sgbl.php"; then
  ok "sgbl uses kloxo_php_binary"
else
  bad "sgbl uses kloxo_php_binary"
fi
if grep -q '/usr/bin/lxphp.exe"' "$ROOT/kloxo/httpdocs/lib/sgbl.php"; then
  bad "sgbl no longer hard-codes lxphp.exe"
else
  ok "sgbl no longer hard-codes lxphp.exe"
fi
if sed -n '/^function toArray(/,/^}/p' "$ROOT/kloxo/httpdocs/lib/html/lib.php" | grep -q 'is_object'; then
  ok "toArray does not call get_class on null"
else
  bad "toArray does not call get_class on null"
fi
if sed -n '/^function lget_class(/,/^}/p' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" | grep -q 'is_object'; then
  ok "lget_class does not call get_class on null"
else
  bad "lget_class does not call get_class on null"
fi
if sed -n '/^function lxguard_main(/,/^function lxguard_save_hitlist(/p' "$ROOT/kloxo/httpdocs/lib/html/lib.php" | grep -q 'toArray(lfile_get_unserialize'; then
  bad "lxguard_main does not toArray a missing hitlist"
else
  ok "lxguard_main does not toArray a missing hitlist"
fi
if sed -n '/^function getLatestVersion(/,/^function getInstalledVersion(/p' "$ROOT/kloxo/httpdocs/lib/html/lib.php" | grep -qE 'exec\(|yum check-update|yum list'; then
  bad "getLatestVersion does not call yum"
else
  ok "getLatestVersion does not call yum"
fi
if sed -n '/^function getInstalledVersion(/,/^function getDownloadServer(/p' "$ROOT/kloxo/httpdocs/lib/html/lib.php" | grep -qE 'exec\(|yum list'; then
  bad "getInstalledVersion does not call yum"
else
  ok "getInstalledVersion does not call yum"
fi
if grep -q 'is_callable(array($class, $func))' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php"; then
  ok "exec_class_method rejects missing methods"
else
  bad "exec_class_method rejects missing methods"
fi
if grep -q 'function initThisObject' "$ROOT/kloxo/httpdocs/driver/pserver/lxupdatelib.php"; then
  ok "Lxupdate defines initThisObject"
else
  bad "Lxupdate defines initThisObject"
fi
if grep -q 'initialValuesBasic()' "$ROOT/kloxo/httpdocs/driver/pserver/phpinilib.php" && grep -q 'function initPhpIni' "$ROOT/kloxo/httpdocs/driver/pserver/phpinilib.php"; then
  ok "phpini forms apply initialValuesBasic"
else
  bad "phpini forms apply initialValuesBasic"
fi
if grep -q 'function ensureAdminResourcePriv' "$ROOT/kloxo/httpdocs/lib/client/lxclient.php" && grep -q "easyinstaller" "$ROOT/kloxo/httpdocs/lib/php/lxclass.php"; then
  ok "admin resource plan defaults unlimited; easyinstaller off"
else
  bad "admin resource plan defaults unlimited; easyinstaller off"
fi
if grep -q 'function kloxo_is_stream' "$ROOT/kloxo/httpdocs/lib/html/lib.php" && grep -q 'kloxo_is_stream($fp)' "$ROOT/kloxo/httpdocs/lib/html/lib.php"; then
  ok "log scanners guard feof on a failed fopen"
else
  bad "log scanners guard feof on a failed fopen"
fi
if grep -q 'function kcount' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php"; then
  ok "kcount is safe for a null list"
else
  bad "kcount is safe for a null list"
fi
if grep -q 'isset($this->used) && is_object($this->used)' "$ROOT/kloxo/httpdocs/lib/php/lxclass.php"; then
  ok "getUSlashP does not read used on null"
else
  bad "getUSlashP does not read used on null"
fi
if grep -F -q 'if (!is_array($p))' "$ROOT/kloxo/httpdocs/theme/htmllib.php" && grep -F -q '$k = kcount($p);' "$ROOT/kloxo/httpdocs/theme/htmllib.php"; then
  ok "getFullUrl does not count a missing frm_o_o"
else
  bad "getFullUrl does not count a missing frm_o_o"
fi
if grep -F -q '!is_object($this->driver[$master][$syncserver])' "$ROOT/kloxo/httpdocs/lib/php/gbllib.php"; then
  ok "getSyncClass does not read a missing driver row"
else
  bad "getSyncClass does not read a missing driver row"
fi
if grep -q 'is_object($parent)' "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php" && grep -q "array('property' => array())" "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php"; then
  ok "home show does not call getParentO on admin"
else
  bad "home show does not call getParentO on admin"
fi
if ! awk '/function password_contact_check/,/^function /' "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php" | grep -qE '^\s*exit;'; then
  ok "first-login password form does not hide the dashboard"
else
  bad "first-login password form does not hide the dashboard"
fi
if grep -q 'checkButton('\''webhosting_flag'\'')' "$ROOT/kloxo/httpdocs/lib/client/clientlib.php" \
  && grep -q 'checkButton('\''webhosting_flag'\'')' "$ROOT/kloxo/httpdocs/lib/client/clientcorelib.php"; then
  ok "home button list does not call priv on null"
else
  bad "home button list does not call priv on null"
fi
if grep -q 'function kloxo_strftime' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" && grep -q 'function kloxo_html' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php"; then
  ok "kloxo_strftime and kloxo_html exist for PHP 8.5"
else
  bad "kloxo_strftime and kloxo_html exist for PHP 8.5"
fi
if grep -q 'function kloxo_hostname' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" && ! grep -nE 'trim\(`hostname`\)|trim\(`arch`\)' "$ROOT/kloxo/httpdocs/lib/html/lib.php" "$ROOT/kloxo/httpdocs/lib/html/linuxlib.php"; then
  ok "hostname and arch do not use deprecated backticks"
else
  bad "hostname and arch do not use deprecated backticks"
fi
if grep -q 'function kloxo_curl_close' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" \
  && ! grep -nE '^\s*curl_close\(' "$ROOT/kloxo/httpdocs/lib/html/lib.php" "$ROOT/kloxo/httpdocs/lib/php/tldextract.php" "$ROOT/kloxo/httpdocs/lib/parsemail/PostNewMail.php"; then
  ok "first-party curl_close is PHP 8.5-safe"
else
  bad "first-party curl_close is PHP 8.5-safe"
fi
if ! grep -nE '=\s*`\$cmd`|=\s*`yum |=\s*`up2date' "$ROOT/kloxo/httpdocs/driver/pserver/package__yumlib.php" "$ROOT/kloxo/httpdocs/driver/pserver/package__up2datelib.php"; then
  ok "package drivers do not use deprecated backticks"
else
  bad "package drivers do not use deprecated backticks"
fi
if grep -F -q 'php_admin_flag[register_argc_argv] = off' "$ROOT/kloxo/init/kloxo-php-fpm.conf"; then
  ok "panel FPM does not register argc/argv from the query string"
else
  bad "panel FPM does not register argc/argv from the query string"
fi
if ! grep -nE 'ereg_replace|eregi_replace' "$ROOT/kloxo/httpdocs/lib/php/include.inc" "$ROOT/kloxo/httpdocs/lib/html/includecore.php"; then
  ok "boot include files do not call ereg*"
else
  bad "boot include files do not call ereg*"
fi
if ! grep -nE '^\s*dl\(' "$ROOT/kloxo/httpdocs/lib/php/coreffilelib.php" "$ROOT/kloxo/httpdocs/driver/pserver/ddatabaselib.php"; then
  ok "first-party file/db helpers do not call dl()"
else
  bad "first-party file/db helpers do not call dl()"
fi
if ! grep -nE '^\s*(return |\$[a-zA-Z_].*= *)htmlspecialchars\(' "$ROOT/kloxo/httpdocs/lib/client/dskfavorite.php" "$ROOT/kloxo/httpdocs/lib/html/ajaxcore.php"; then
  ok "favorites and ajax escape with kloxo_html"
else
  bad "favorites and ajax escape with kloxo_html"
fi
if ! grep -nE '\(boolean\)|\(integer\)' "$ROOT/kloxo/httpdocs/lib/extra/class.phpmailer.php" "$ROOT/kloxo/httpdocs/lib/extra/class.smtp.php" "$ROOT/kloxo/httpdocs/lib/extra/class.pop3.php" "$ROOT/kloxo/httpdocs/lib/extra/class.phpmaileroauth.php"; then
  ok "PHPMailer does not use deprecated boolean/integer casts"
else
  bad "PHPMailer does not use deprecated boolean/integer casts"
fi
if [ "$(grep -c "PHP_VERSION_ID < 80000 && function_exists('openssl_pkey_free')" "$ROOT/kloxo/httpdocs/lib/extra/class.phpmailer.php")" -ge 3 ]; then
  ok "PHPMailer openssl_pkey_free is PHP 8-safe"
else
  bad "PHPMailer openssl_pkey_free is PHP 8-safe"
fi
if grep -q 'function_exists('\''get_magic_quotes_runtime'\'')' "$ROOT/kloxo/httpdocs/lib/extra/class.phpmailer.php"; then
  ok "PHPMailer does not call removed get_magic_quotes_runtime"
else
  bad "PHPMailer does not call removed get_magic_quotes_runtime"
fi
if grep -q 'function lfile_put_contents($file, $data, $flag = 0)' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php"; then
  ok "lfile_put_contents default flags is 0"
else
  bad "lfile_put_contents default flags is 0"
fi
if grep -q 'function kloxo_php_error_log_path' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" && grep -q '__path_program_root/log/php.err' "$ROOT/kloxo/httpdocs/lib/sgbl.php"; then
  ok "PHP error file is under log/ not httpdocs"
else
  bad "PHP error file is under log/ not httpdocs"
fi
if ! grep -q 'lfile_put_contents($sgbl->__var_error_file' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php"; then
  ok "init does not wipe httpdocs/.php.err on every request"
else
  bad "init does not wipe httpdocs/.php.err on every request"
fi
if grep -F -q 'php_admin_flag[display_errors] = off' "$ROOT/kloxo/init/kloxo-php-fpm.conf"; then
  ok "panel FPM does not display errors in the browser"
else
  bad "panel FPM does not display errors in the browser"
fi
if ! grep -qE '[^_]strftime\(' "$ROOT/kloxo/httpdocs/lib/html/traffichistorylib.php" "$ROOT/kloxo/bin/gettraffic.php" "$ROOT/kloxo/httpdocs/lib/html/lib.php"; then
  ok "first-party traffic/lib no longer call strftime()"
else
  bad "first-party traffic/lib no longer call strftime()"
fi
if sed -n '/^function db_get_value(/,/^function db_get_count(/p' "$ROOT/kloxo/httpdocs/lib/html/lib.php" | grep -q 'array_key_exists'; then
  ok "db_get_value does not index a missing row"
else
  bad "db_get_value does not index a missing row"
fi
if grep -q 'return array();' "$ROOT/kloxo/httpdocs/lib/php/sqlitelib.php" && ! grep -q 'return 0;' "$ROOT/kloxo/httpdocs/lib/php/sqlitelib.php"; then
  ok "rl_query returns an array when there are no rows"
else
  bad "rl_query returns an array when there are no rows"
fi
if grep -q "skin_name = 'simplicity'" "$ROOT/kloxo/httpdocs/lib/php/lxclass.php" && grep -q 'simplicity/default' "$ROOT/kloxo/httpdocs/lib/client/lxclient.php"; then
  ok "admin appearance falls back to simplicity/default"
else
  bad "admin appearance falls back to simplicity/default"
fi
if grep -q "/var/log/mail.log" "$ROOT/kloxo/httpdocs/lib/domain/clientmail.php"; then
  ok "clientmail reads Ubuntu mail.log"
else
  bad "clientmail reads Ubuntu mail.log"
fi
if grep -qE 'exec\(|yum update|yum clean' "$ROOT/kloxo/bin/update.php"; then
  bad "legacy update.php does not call yum"
else
  ok "legacy update.php does not call yum"
fi

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
