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
if grep -q 'function kloxo_admin_db_pass_exception' "$ROOT/kloxo/httpdocs/lib/html/lib.php" \
  && grep -A20 'function getAdminDbPass' "$ROOT/kloxo/httpdocs/lib/html/lib.php" | grep -q 'kloxo_admin_db_pass_exception'; then
  ok "getAdminDbPass throws lxException via kloxo_admin_db_pass_exception"
else
  bad "getAdminDbPass throws lxException via kloxo_admin_db_pass_exception"
fi
if grep -A20 'function getAdminDbPass' "$ROOT/kloxo/httpdocs/lib/html/lib.php" | grep -q 'throw new Exception'; then
  bad "getAdminDbPass does not throw generic Exception"
else
  ok "getAdminDbPass does not throw generic Exception"
fi
if grep -A40 'function connect' "$ROOT/kloxo/httpdocs/lib/php/sqlitelib.php" | grep -q 'KloxoSqlite::open' \
  && grep -A40 'function connect' "$ROOT/kloxo/httpdocs/lib/php/sqlitelib.php" | grep -q 'kloxo_admin_db_pass_exception'; then
  ok "Sqlite::connect opens the SQLite panel file (MariaDB branch still wraps getAdminDbPass)"
else
  bad "Sqlite::connect opens the SQLite panel file (MariaDB branch still wraps getAdminDbPass)"
fi
if grep -A50 'function do_remote_exec' "$ROOT/kloxo/httpdocs/lib/html/remotelib.php" | grep -q 'kloxo_admin_db_pass_exception'; then
  ok "do_remote_exec wraps getAdminDbPass"
else
  bad "do_remote_exec wraps getAdminDbPass"
fi
if grep -A80 'function kloxo_import_sql_file' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" | grep -q 'KloxoSqlite::applyScript' \
  && grep -A80 'function kloxo_import_sql_file' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" | grep -q 'getAdminDbPass'; then
  ok "kloxo_import_sql_file uses SQLite for the panel DB and still fails closed on a missing MariaDB password"
else
  bad "kloxo_import_sql_file uses SQLite for the panel DB and still fails closed on a missing MariaDB password"
fi
if grep -A12 'function changeSuperAdminPass' "$ROOT/kloxo/httpdocs/lib/client/superclientlib.php" | grep -q 'could_not_change_superadmin_pass'; then
  ok "changeSuperAdminPass wraps getAdminDbPass"
else
  bad "changeSuperAdminPass wraps getAdminDbPass"
fi
if awk '/function __construct/,/function getlMessage/' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" | grep -q 'parent::__construct'; then
  ok "lxException populates Exception::getMessage"
else
  bad "lxException populates Exception::getMessage"
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
if php "$HERE/test_login_session.php"; then
  ok "test_login_session.php"
else
  bad "test_login_session.php"
fi
if php "$HERE/test_empty_list.php"; then
  ok "test_empty_list.php"
else
  bad "test_empty_list.php"
fi
if php "$HERE/test_quota_math.php"; then
  ok "test_quota_math.php"
else
  bad "test_quota_math.php"
fi
if php "$HERE/test_disable_policy.php"; then
  ok "test_disable_policy.php"
else
  bad "test_disable_policy.php"
fi
if php "$HERE/test_autoresponder_form.php"; then
  ok "test_autoresponder_form.php"
else
  bad "test_autoresponder_form.php"
fi
if php "$HERE/test_mail_tile_icons.php"; then
  ok "test_mail_tile_icons.php"
else
  bad "test_mail_tile_icons.php"
fi
if php "$HERE/test_menu_title.php"; then
  ok "test_menu_title.php"
else
  bad "test_menu_title.php"
fi
if php "$HERE/test_sshauthorizedkey.php"; then
  ok "test_sshauthorizedkey.php"
else
  bad "test_sshauthorizedkey.php"
fi
if php "$HERE/test_server_pages.php"; then
  ok "test_server_pages.php"
else
  bad "test_server_pages.php"
fi
if php "$HERE/test_ufw.php"; then
  ok "test_ufw.php"
else
  bad "test_ufw.php"
fi
if php "$HERE/test_apt.php"; then
  ok "test_apt.php"
else
  bad "test_apt.php"
fi
if php "$HERE/test_recovery.php"; then
  ok "test_recovery.php"
else
  bad "test_recovery.php"
fi
if php "$HERE/test_watchdog.php"; then
  ok "test_watchdog.php"
else
  bad "test_watchdog.php"
fi
if php "$HERE/test_fail2ban.php"; then
  ok "test_fail2ban.php"
else
  bad "test_fail2ban.php"
fi
if php "$HERE/test_mailpolicy.php"; then
  ok "test_mailpolicy.php"
else
  bad "test_mailpolicy.php"
fi
if php "$HERE/test_switchprogram.php"; then
  ok "test_switchprogram.php"
else
  bad "test_switchprogram.php"
fi
if php "$HERE/test_webcache.php"; then
  ok "test_webcache.php"
else
  bad "test_webcache.php"
fi
if php "$HERE/test_phpfpm.php"; then
  ok "test_phpfpm.php"
else
  bad "test_phpfpm.php"
fi
if php "$HERE/test_knot.php"; then
  ok "test_knot.php"
else
  bad "test_knot.php"
fi
if php "$HERE/test_mariadb.php"; then
  ok "test_mariadb.php"
else
  bad "test_mariadb.php"
fi
if php "$HERE/test_backup.php"; then
  ok "test_backup.php"
else
  bad "test_backup.php"
fi
if php "$HERE/test_knot_driver.php"; then
  ok "test_knot_driver.php"
else
  bad "test_knot_driver.php"
fi
if php "$HERE/test_busy_spinner.php"; then
  ok "test_busy_spinner.php"
else
  bad "test_busy_spinner.php"
fi
if php "$HERE/test_toast.php"; then
  ok "test_toast.php"
else
  bad "test_toast.php"
fi
if php "$HERE/test_auxiliary.php"; then
  ok "test_auxiliary.php"
else
  bad "test_auxiliary.php"
fi
if php "$HERE/test_service_toggle.php"; then
  ok "test_service_toggle.php"
else
  bad "test_service_toggle.php"
fi

echo "== DNS none disables BIND through its real unit (bind9 is an alias of named)"
if php "$HERE/test_dns_alias.php"; then
  ok "test_dns_alias.php"
else
  bad "test_dns_alias.php"
fi

echo "== panel SQLite (Hiawatha stays up with MariaDB down)"
if php "$HERE/test_sqlite_panel.php"; then
  ok "test_sqlite_panel.php"
else
  bad "test_sqlite_panel.php"
fi

echo "== storage layer (WAL factory, migrations, regen, stats, VACUUM INTO)"
if php "$HERE/test_storage.php"; then
  ok "test_storage.php"
else
  bad "test_storage.php"
fi

echo "== removed MTA leftover (SysV script) on the Services list"
if php "$HERE/test_mta_leftover.php"; then
  ok "test_mta_leftover.php"
else
  bad "test_mta_leftover.php"
fi
if php "$HERE/test_multiserver.php"; then
  ok "test_multiserver.php"
else
  bad "test_multiserver.php"
fi
if grep -q 'function AddListForm' "$ROOT/kloxo/httpdocs/driver/pserver/pservercorelib.php" \
  && grep -q 'nnameIsValid' "$ROOT/kloxo/httpdocs/driver/pserver/pservercorelib.php" \
  && grep -q 'failedlist = array()' "$ROOT/kloxo/httpdocs/driver/pserver/pservercorelib.php" \
  && ! grep -F -q 'a=addform&c=$class' "$ROOT/kloxo/httpdocs/driver/pserver/pservercorelib.php"; then
  ok "Servers list can add a remote host"
else
  bad "Servers list can add a remote host"
fi
if sed -n '/final protected function initListIfUndef(/,/^	final function getUrlIdentity(/p' "$ROOT/kloxo/httpdocs/lib/php/lxclass.php" | grep -q 'is_array($this->$list)'; then
  ok "initListIfUndef does not count() a null child list"
else
  bad "initListIfUndef does not count() a null child list"
fi
if grep -q 'return is_array($v) ? $v : array();' "$ROOT/kloxo/httpdocs/lib/php/lxclass.php" \
  && grep -F -q '$count = kcount($res);' "$ROOT/kloxo/httpdocs/lib/php/lxclass.php"; then
  ok "getList/getVirtualList do not count() a null _a list"
else
  bad "getList/getVirtualList do not count() a null _a list"
fi
if [ -f "$ROOT/kloxo/httpdocs/login/index.php" ]; then
  ok "login/index.php shipped"
else
  bad "login/index.php shipped"
fi
if grep -q 'function kloxo_php_binary' "$ROOT/kloxo/httpdocs/lib/sgbl.php" \
  && grep -q 'function kloxo_write_panel_php_pin' "$ROOT/kloxo/httpdocs/lib/sgbl.php" \
  && grep -q 'pdo_sqlite' "$ROOT/kloxo/httpdocs/lib/sgbl.php"; then
  ok "sgbl uses kloxo_php_binary with pdo_sqlite pin"
else
  bad "sgbl uses kloxo_php_binary with pdo_sqlite pin"
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
if grep -q 'initialValuesBasic()' "$ROOT/kloxo/httpdocs/driver/pserver/phpinilib.php" && grep -q 'function initPhpIni' "$ROOT/kloxo/httpdocs/driver/pserver/phpinilib.php" \
  && grep -q "a=updateform&sa=extraedit" "$ROOT/kloxo/httpdocs/driver/pserver/phpinilib.php" \
  && grep -q 'phpiniCanReadPanelDb' "$ROOT/kloxo/httpdocs/driver/pserver/phpinilib.php" \
  && grep -q 'function kloxo_url_has_phpini' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" \
  && grep -q 'function phpiniPropertyTabsForParent' "$ROOT/kloxo/httpdocs/driver/pserver/phpinilib.php" \
  && grep -q 'phpini::phpiniPropertyTabs()' "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php" \
  && grep -q 'phpiniPropertyTabsForParent' "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php"; then
  ok "phpini forms apply initialValuesBasic and keep PHP Configure / Advanced tabs"
else
  bad "phpini forms apply initialValuesBasic and keep PHP Configure / Advanced tabs"
fi
if grep -q 'function lxguardPropertyTabs' "$ROOT/kloxo/httpdocs/driver/pserver/lxguardlib.php" \
  && grep -q 'function kloxo_url_has_lxguard' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" \
  && grep -q "return 'LxGuard'" "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" \
  && grep -q 'lxguard::lxguardPropertyTabs()' "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php"; then
  ok "LxGuard page first tab is labelled LxGuard"
else
  bad "LxGuard page first tab is labelled LxGuard"
fi
if grep -q 'function sshconfigPropertyTabs' "$ROOT/kloxo/httpdocs/driver/pserver/sshconfiglib.php" \
  && grep -q 'function kloxo_url_has_sshconfig' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" \
  && grep -q "return 'SSH Configure'" "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" \
  && grep -q 'sshconfig::sshconfigPropertyTabs()' "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php"; then
  ok "SSH Configure page tab is labelled SSH Configure"
else
  bad "SSH Configure page tab is labelled SSH Configure"
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
if sed -n '/function password_contact_check(/,/^function do_display_init(/p' "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php" | grep -q 'kloxo-password-force' \
  && sed -n '/function password_contact_check(/,/^function do_display_init(/p' "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php" | grep -q 'kloxo-modal-required' \
  && sed -n '/function password_contact_check(/,/^function do_display_init(/p' "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php" | grep -q 'do_updateform' \
  && ! sed -n '/function password_contact_check(/,/^function do_display_init(/p' "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php" | grep -q 'show_error_message' \
  && grep -A 16 'function kloxoHideAllPopups' "$ROOT/kloxo/httpdocs/theme/js/lxa.js" | grep -q 'kloxo-modal-required' \
  && grep -A 18 'function kloxoHidePopup' "$ROOT/kloxo/httpdocs/theme/js/lxa.js" | grep -q 'kloxo-password-force' \
  && grep -q '.kloxo-modal-backdrop' "$ROOT/kloxo/httpdocs/theme/css/common.css" \
  && grep -q '.kloxo-modal.kloxo-password-force' "$ROOT/kloxo/httpdocs/theme/css/common.css"; then
  ok "first-login password change is a required modal that keeps the form usable"
else
  bad "first-login password change is a required modal that keeps the form usable"
fi
if grep -q 'checkButton('\''webhosting_flag'\'')' "$ROOT/kloxo/httpdocs/lib/client/clientlib.php" \
  && grep -q 'checkButton('\''webhosting_flag'\'')' "$ROOT/kloxo/httpdocs/lib/client/clientcorelib.php"; then
  ok "home button list does not call priv on null"
else
  bad "home button list does not call priv on null"
fi
if grep -F -q 'overflow: visible' "$ROOT/kloxo/httpdocs/theme/css/common.css" \
  && grep -F -q 'min-height: 240px' "$ROOT/kloxo/httpdocs/theme/css/common.css"; then
  ok "main content column is not clipped by overflow:hidden"
else
  bad "main content column is not clipped by overflow:hidden"
fi
if grep -A 20 'div.section, div#createNew' "$ROOT/kloxo/httpdocs/theme/htmllib.php" | grep -q 'overflow: visible' \
  && grep -A 20 'div.section, div#createNew' "$ROOT/kloxo/httpdocs/theme/htmllib.php" | grep -q 'height: auto'; then
  ok "dashboard sections are not clipped by overflow:hidden"
else
  bad "dashboard sections are not clipped by overflow:hidden"
fi
if grep -A 8 '\.div_container {' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'margin: 3px' \
  && grep -A 8 '\.div_container_icons {' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'width: 90px' \
  && grep -A 8 '\.div_container_icons {' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'height: 90px' \
  && grep -A 12 '\.div_icons_text {' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'font-size: 9pt' \
  && grep -A 12 '\.div_icons_text {' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'height: 50px' \
  && grep -A 6 '\.div_icons_image {' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'height: 40px'; then
  ok "dashboard tiles match Kloxo-MR 90x90 cells, 32px icons, and 9pt labels"
else
  bad "dashboard tiles match Kloxo-MR 90x90 cells, 32px icons, and 9pt labels"
fi
if awk '/class="div_menu_left"/{l=NR} /class="div_menu_right"/{r=NR} END {exit !(l && r && l<r)}' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/menu.php" \
  && grep -q "getKeywordUc('help')" "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/menu.php"; then
  ok "simplicity Help/Logout print on the right of the top menu"
else
  bad "simplicity Help/Logout print on the right of the top menu"
fi
if grep -F -q 'flex-wrap: wrap' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/css/menu.css" \
  && grep -F -q '.div_menu_right' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/css/menu.css"; then
  ok "simplicity top menu keeps Help/Logout visible when items wrap"
else
  bad "simplicity top menu keeps Help/Logout visible when items wrap"
fi
if grep -A 40 'function getTitleOnly' "$ROOT/kloxo/httpdocs/theme/htmllib.php" | grep -q 'catch (Throwable' \
  && grep -A 40 'function getActionDetails' "$ROOT/kloxo/httpdocs/theme/htmllib.php" | grep -q 'catch (Throwable'; then
  ok "menu titles and dashboard buttons survive a missing action description"
else
  bad "menu titles and dashboard buttons survive a missing action description"
fi
if grep -q 'initThisObjectRule($parent, $class, $name = null)' "$ROOT/kloxo/httpdocs/driver/pserver/sshclientlib.php" \
  && grep -q 'initThisObjectRule($parent, $class, $name = null)' "$ROOT/kloxo/httpdocs/driver/pserver/vncviewerlib.php"; then
  ok "sshclient and vncviewer initThisObjectRule match Lxclass"
else
  bad "sshclient and vncviewer initThisObjectRule match Lxclass"
fi
if grep -q 'only_admin_can_access' "$ROOT/kloxo/httpdocs/driver/pserver/sshclientlib.php" \
  && grep -q 'sshclient_o' "$ROOT/kloxo/httpdocs/driver/pserver/pservercorelib.php" \
  && ! grep -q 'o=sshclient' "$ROOT/kloxo/httpdocs/lib/client/clientlib.php" \
  && ! grep -q 'sshclient_o' "$ROOT/kloxo/httpdocs/lib/client/clientbaselib.php"; then
  ok "SSH Terminal is admin-only and is not on client home"
else
  bad "SSH Terminal is admin-only and is not on client home"
fi
if grep -q 'a=show&o=ufwfirewall' "$ROOT/kloxo/httpdocs/driver/pserver/pserverlib.php" \
  && grep -F -q 'frm_o_o[1][class]=ufwfirewall' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/menu.php" \
  && grep -q '"UFW Firewall"' "$ROOT/kloxo/httpdocs/lang/en-us/langkeywordlib.php" \
  && grep -q 'function kloxo_url_has_ufwfirewall' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" \
  && grep -q "'ufwfirewall'" "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" \
  && grep -q 'class KloxoUfw' "$ROOT/kloxo/httpdocs/lib/php/kloxo_ufw.php" \
  && grep -q 'class ufwfirewall' "$ROOT/kloxo/httpdocs/driver/pserver/ufwfirewalllib.php" \
  && grep -q 'ufwfirewall' "$ROOT/kloxo/httpdocs/lib/html/definecore.php"; then
  ok "Security section has a UFW Firewall button"
else
  bad "Security section has a UFW Firewall button"
fi
if grep -q 'function kloxo_implode' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" \
  && grep -q 'function kloxo_list_from' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" \
  && grep -q 'kloxo_implode' "$ROOT/kloxo/httpdocs/lib/php/sqlitelib.php"; then
  ok "client write path does not implode() a string list"
else
  bad "client write path does not implode() a string list"
fi
if grep -q "new ListPriv" "$ROOT/kloxo/httpdocs/lib/html/resourceplanlib.php" \
  && grep -q "getIpaddress(array('localhost'))" "$ROOT/kloxo/httpdocs/lib/html/resourceplanlib.php" \
  && grep -q "\\\$class === 'resourceplan'" "$ROOT/kloxo/httpdocs/lib/html/objectactionlib.php" \
  && grep -q '!isset($this->listpriv)' "$ROOT/kloxo/httpdocs/lib/php/lxclass.php"; then
  ok "resourceplan add does not need a DNS template or parent quota"
else
  bad "resourceplan add does not need a DNS template or parent quota"
fi
if grep -q 'function kloxo_menu_title' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" \
  && ! grep -q '$ghtml->getTitleOnly(' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/menu.php" \
  && grep -q 'kloxo_menu_title(' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/menu.php"; then
  ok "simplicity menu titles go through kloxo_menu_title"
else
  bad "simplicity menu titles go through kloxo_menu_title"
fi
if grep -A 12 'function char_search_beg' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" | grep -q '=== null' \
  && grep -A 20 'function get_real_class_variable' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" | grep -q 'ReflectionClass'; then
  ok "class descriptions do not strpos(null) or eval property names"
else
  bad "class descriptions do not strpos(null) or eval property names"
fi
if awk '/include_once "\{\$simplicity_topbar_right\}"/ {r=NR} /include_once "\{\$simplicity_menu\}"/ {m=NR} END {exit !(r && m && r<m)}' "$ROOT/kloxo/httpdocs/theme/htmllib.php"; then
  ok "simplicity Status/Show-Hide/time print before the left menu"
else
  bad "simplicity Status/Show-Hide/time print before the left menu"
fi
if grep -A 20 '^function check_if_many_server' "$ROOT/kloxo/httpdocs/lib/html/lib.php" | grep -q 'catch (Throwable'; then
  ok "check_if_many_server does not abort the Server menu"
else
  bad "check_if_many_server does not abort the Server menu"
fi
if grep -A 40 '^function get_classvar_description' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" | grep -q 'if (!is_array($ret))'; then
  ok "get_classvar_description does not index a non-array description"
else
  bad "get_classvar_description does not index a non-array description"
fi
if grep -q 'id="div_status"' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/topbar_right.php" \
  && grep -q 'id="div_showhide"' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/topbar_right.php" \
  && grep -q 'id="clock_div"' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/topbar_right.php" \
  && grep -q "getKeywordUc('status')" "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/topbar_right.php" \
  && grep -q "getKeywordUc('showhide')" "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/topbar_right.php" \
  && grep -q "date('H:i:s')" "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/topbar_right.php"; then
  ok "simplicity top-right bar prints Status, Show/Hide, and the time"
else
  bad "simplicity top-right bar prints Status, Show/Hide, and the time"
fi
if grep -A 16 '.div_fixed_right' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'display: flex' \
  && grep -A 16 '.div_fixed_right' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'flex-direction: row' \
  && grep -A 14 '.div_fixed_left' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'position: relative' \
  && grep -A 8 '.div_header_message_inactive' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/css/menu.css" | grep -q 'color: #fff'; then
  ok "simplicity Status/Show-Hide/time stay visible on the top right"
else
  bad "simplicity Status/Show-Hide/time stay visible on the top right"
fi
if grep -A 20 '.div_menu {' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/css/menu.css" | grep -q 'flex: 1 1 auto' \
  && grep -A 12 '.div_menu_right' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/css/menu.css" | grep -q 'order: 3' \
  && grep -A 16 '.div_fixed_right' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'order: 4' \
  && python3 -c '
import re
from pathlib import Path
t = Path("'"$ROOT"'/kloxo/httpdocs/theme/skin/simplicity/default/menu.php").read_text()
ok = bool(re.search(r"id=\"menu_div\"[\s\S]+class=\"div_menu_right\"[\s\S]+?</div>\s*</div>\s*<!-- .END - Simplicity menu", t))
raise SystemExit(0 if ok else 1)
'; then
  ok "green menu fills the middle; Help/Logout stay in the green strip left of Status"
else
  bad "green menu fills the middle; Help/Logout stay in the green strip left of Status"
fi
if grep -A 18 '.div_content {' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'calc(100vh - 80px)' \
  && grep -A 18 '.div_mmm_simplicity {' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'calc(100vh - 42px)' \
  && grep -A 18 '.div_mmm_simplicity {' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'flex-direction: column'; then
  ok "content white background fills to the bottom of the page"
else
  bad "content white background fills to the bottom of the page"
fi
if grep -A 18 '.div_mmm_simplicity {' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'background-color: transparent' \
  && grep -A 18 '.div_fixed_logo_right {' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'position: fixed' \
  && grep -A 18 '.div_fixed_logo_right {' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'right: 10px' \
  && grep -q 'class="div_tab_block"' "$ROOT/kloxo/httpdocs/theme/tab_simplicity.php" \
  && grep -A 16 '.div_tab_block {' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/css/style.css" | grep -q 'display: flex' \
  && grep -A 18 '.verb3 {' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/css/style.css" | grep -q 'border-radius: 4px 4px 0 0'; then
  ok "content tabs sit on the beige header like Kloxo-MR; logo sits on the right of the screen"
else
  bad "content tabs sit on the beige header like Kloxo-MR; logo sits on the right of the screen"
fi
if grep -A 12 'function print_css_source' "$ROOT/kloxo/httpdocs/theme/htmllib.php" | grep -q 'filemtime' \
  && grep -A 12 'function print_jscript_source' "$ROOT/kloxo/httpdocs/theme/htmllib.php" | grep -q 'filemtime' \
  && grep -q '?v=' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/menu.php"; then
  ok "theme CSS and JS links bust the browser cache after an update"
else
  bad "theme CSS and JS links bust the browser cache after an update"
fi
if grep -q '<table class="div_home_page"' "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php" \
  && grep -q 'table-layout:fixed' "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php" \
  && grep -q 'div_home_left' "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php" \
  && grep -q 'div_home_right' "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php" \
  && grep -q 'print_object_action_block' "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php"; then
  ok "home page keeps Find/Information/Resource beside the dashboard tiles"
else
  bad "home page keeps Find/Information/Resource beside the dashboard tiles"
fi
if grep -q 'class="div_fixed_logo_right"' "$ROOT/kloxo/httpdocs/theme/htmllib.php" \
  && grep -q '/login/images/kloxo.png' "$ROOT/kloxo/httpdocs/theme/htmllib.php" \
  && grep -q 'alt="Kloxo CP"' "$ROOT/kloxo/httpdocs/theme/htmllib.php" \
  && awk '/div_fixed_logo_right/{l=NR} /id="mmm"/{m=NR} END {exit !(l && m && l<m)}' "$ROOT/kloxo/httpdocs/theme/htmllib.php" \
  && grep -A 24 '.div_fixed_logo_right {' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'z-index: 20' \
  && grep -A 24 '.div_fixed_logo_right {' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'position: fixed' \
  && grep -A 24 '.div_fixed_logo_right {' "$ROOT/kloxo/httpdocs/theme/css/common.css" | grep -q 'right: 10px' \
  && test -f "$ROOT/kloxo/httpdocs/login/images/kloxo.png" \
  && file "$ROOT/kloxo/httpdocs/login/images/kloxo.png" | grep -q 'PNG image'; then
  ok "simplicity logo sits on the right of the screen"
else
  bad "simplicity logo sits on the right of the screen"
fi
if grep -A 25 'function getLastLogin' "$ROOT/kloxo/httpdocs/lib/client/lxclient.php" | grep -q 'info_currentloginip' \
  && grep -A 25 'function getLastLogin' "$ROOT/kloxo/httpdocs/lib/client/lxclient.php" | grep -q 'getRemoteIp()' \
  && ! grep -A 25 'function getLastLogin' "$ROOT/kloxo/httpdocs/lib/client/lxclient.php" | grep -q 'return;'; then
  ok "information table still shows current login on the first session"
else
  bad "information table still shows current login on the first session"
fi
if grep -q 'is_array($raw)' "$ROOT/kloxo/httpdocs/lib/php/resourcelib.php" \
  && grep -q 'if (!$privobj)' "$ROOT/kloxo/httpdocs/lib/php/resourcelib.php"; then
  ok "information and resource tables do not fatal on a missing priv object"
else
  bad "information and resource tables do not fatal on a missing priv object"
fi
if ! awk '/function do_display_init/,/^function /' "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php" | grep -qE 'ob_start\(|flush\(\)'; then
  ok "display init does not flush then buffer the main column"
else
  bad "display init does not flush then buffer the main column"
fi
if grep -A 20 '^function display_exec' "$ROOT/kloxo/httpdocs/theme/coredisplaylib.php" | grep -q 'catch (Throwable'; then
  ok "display_exec catches PHP 8 Error so the middle column can render"
else
  bad "display_exec catches PHP 8 Error so the middle column can render"
fi
if grep -q 'catch (Throwable $e)' "$ROOT/kloxo/httpdocs/theme/htmllib.php" && grep -q 'print_tab_block_start' "$ROOT/kloxo/httpdocs/theme/htmllib.php"; then
  ok "tab block failure does not abort the page body"
else
  bad "tab block failure does not abort the page body"
fi
if grep -q 'print_navigation(isset($gbl->__navig)' "$ROOT/kloxo/httpdocs/theme/htmllib.php" \
  && grep -A 8 'function print_content_end' "$ROOT/kloxo/httpdocs/theme/htmllib.php" | grep -q '</div>'; then
  ok "content wrapper is closed after the main column"
else
  bad "content wrapper is closed after the main column"
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
if [[ ! -f "$ROOT/kloxo/httpdocs/driver/pserver/package__yumlib.php" ]]   && [[ ! -f "$ROOT/kloxo/httpdocs/driver/pserver/package__up2datelib.php" ]]   && [[ -f "$ROOT/kloxo/httpdocs/driver/pserver/package__aptgetlib.php" ]]; then
  ok "yum/up2date package drivers removed; aptget remains"
else
  bad "yum/up2date package drivers removed; aptget remains"
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
if grep -q "/var/log/exim4/mainlog" "$ROOT/kloxo/httpdocs/lib/domain/clientmail.php" \
  && grep -q "/var/log/mail.log" "$ROOT/kloxo/httpdocs/lib/domain/clientmail.php"; then
  ok "clientmail reads Ubuntu Exim mainlog and mail.log"
else
  bad "clientmail reads Ubuntu Exim mainlog and mail.log"
fi
if grep -qE 'exec\(|yum update|yum clean' "$ROOT/kloxo/bin/update.php"; then
  bad "legacy update.php does not call yum"
else
  ok "legacy update.php does not call yum"
fi
php_fp_hits() {
  local pat="$1"
  find "$ROOT/kloxo" \( -name '*.php' -o -name '*.inc' \) \
    ! -path '*/webdisk/*' \
    ! -path '*/file/formmail/*' \
    ! -path '*/lib/urlrewrite/example*' \
    ! -path '*/file/phpmyadmin/*' \
    ! -path '*/pscript/*' \
    -print0 | xargs -0 grep -nE "$pat" 2>/dev/null || true
}
if [ -z "$(php_fp_hits '\b(ereg|eregi|ereg_replace|eregi_replace)\s*\(')" ]; then
  ok "first-party PHP does not call removed ereg*"
else
  bad "first-party PHP does not call removed ereg*"
fi
if [ -z "$(php_fp_hits '\b(mysql_connect|mysql_query|mysql_select_db|create_function|utf8_encode|utf8_decode)\s*\(')" ]; then
  ok "first-party PHP does not call removed mysql_*/create_function/utf8_*"
else
  bad "first-party PHP does not call removed mysql_*/create_function/utf8_*"
fi
if ! grep -nE '[^[:alnum:]_]strftime\s*\(' "$ROOT/kloxo/httpdocs/lib/php/lxlib.php" >/dev/null; then
  ok "kloxo_strftime does not call deprecated strftime()"
else
  bad "kloxo_strftime does not call deprecated strftime()"
fi
if ! grep -nE '\bescape\s*\(|\bunescape\s*\(|document\.all|\bwith\s*\(' \
  "$ROOT/kloxo/httpdocs/theme/js/lxa.js" \
  "$ROOT/kloxo/httpdocs/theme/js/login.js" \
  "$ROOT/kloxo/httpdocs/theme/js/preop.js" \
  "$ROOT/kloxo/httpdocs/theme/js/helptext.js" \
  "$ROOT/kloxo/httpdocs/theme/js/divpop.js" \
  "$ROOT/kloxo/httpdocs/theme/js/jsvalidator.js" \
  "$ROOT/kloxo/httpdocs/theme/js/dragdrop.js" \
  "$ROOT/kloxo/httpdocs/theme/js/dragdivscroll.js" \
  "$ROOT/kloxo/httpdocs/theme/js/xpmenu/ua.js" \
  "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/js/clock.js" >/dev/null; then
  ok "live theme JS does not call escape/unescape/document.all/with"
else
  bad "live theme JS does not call escape/unescape/document.all/with"
fi
if ! grep -nE '\bescape\s*\(|\bunescape\s*\(|document\.all' \
  "$ROOT/kloxo/httpdocs/theme/js/lxa.js" \
  "$ROOT/kloxo/httpdocs/theme/js/preop.js" \
  "$ROOT/kloxo/httpdocs/theme/js/divpop.js" >/dev/null; then
  ok "simplicity scripts use encodeURIComponent and getElementById"
else
  bad "simplicity scripts use encodeURIComponent and getElementById"
fi
if grep -q 'encodeURIComponent' "$ROOT/kloxo/httpdocs/theme/js/lxa.js"; then
  ok "password form encodes with encodeURIComponent"
else
  bad "password form encodes with encodeURIComponent"
fi
if grep -q 'kloxo-error-box' "$ROOT/kloxo/httpdocs/theme/htmllib.php" \
  && grep -q 'id="showimage"' "$ROOT/kloxo/httpdocs/theme/htmllib.php" \
  && grep -q 'function kloxoHidePopup' "$ROOT/kloxo/httpdocs/theme/js/lxa.js" \
  && grep -q 'function kloxoHideAllPopups' "$ROOT/kloxo/httpdocs/theme/js/lxa.js" \
  && grep -q 'function kloxoPopupDrag' "$ROOT/kloxo/httpdocs/theme/js/divpop.js"; then
  ok "error and generate-password popups use distinct ids and can be closed"
else
  bad "error and generate-password popups use distinct ids and can be closed"
fi
if grep -A 25 'function convert_message' "$ROOT/kloxo/httpdocs/theme/htmllib.php" | grep -q 'is_array($this->frm_o_o)' \
  && grep -A 12 'function toggleVisibilityById' "$ROOT/kloxo/httpdocs/theme/js/lxa.js" | grep -q 'if (!el)'; then
  ok "popup message conversion and Help toggle survive missing objects"
else
  bad "popup message conversion and Help toggle survive missing objects"
fi
if grep -q 'function url_tag_href' "$ROOT/kloxo/httpdocs/theme/htmllib.php" \
  && grep -A 8 'function format_message' "$ROOT/kloxo/httpdocs/theme/htmllib.php" | grep -q 'convert_message' \
  && grep -A 8 "lxguard_not_configured" "$ROOT/kloxo/httpdocs/lang/en-us/messagelib.php" | grep -q 'a=show&o=lxguard'; then
  ok "LxGuard alert [here] is converted to a pserver lxguard URL"
else
  bad "LxGuard alert [here] is converted to a pserver lxguard URL"
fi
if grep -A 40 'static function addform' "$ROOT/kloxo/httpdocs/lib/domain/domainlib.php" | grep -q 'domain_owned_flag' \
  && grep -A 40 'static function addform' "$ROOT/kloxo/httpdocs/lib/domain/domainlib.php" | grep -q 'simple_add_f' \
  && grep -A 40 'static function addform' "$ROOT/kloxo/httpdocs/lib/domain/domainlib.php" | grep -q 'ssl_flag' \
  && grep -A 25 'function getDnsTemplateList' "$ROOT/kloxo/httpdocs/lib/domain/domainbaselib.php" | grep -q 'kloxo_list_from'; then
  ok "admin Domains add form has owner/docroot/SSL/PHP flags and no longer throws without a DNS template"
else
  bad "admin Domains add form has owner/docroot/SSL/PHP flags and no longer throws without a DNS template"
fi
if grep -q '\$peek = (\$skin_name === '\''simplicity'\'')' "$ROOT/kloxo/httpdocs/theme/htmllib.php" \
  && grep -q 'print_inline_alert' "$ROOT/kloxo/httpdocs/theme/htmllib.php" \
  && grep -q 'function isSimplicitySkin' "$ROOT/kloxo/httpdocs/theme/htmllib.php" \
  && grep -q 'kloxoStatusToastPinned' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/topbar_right.php" \
  && grep -q 'kloxo-status-toast-open' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/topbar_right.php" \
  && grep -q 'kloxo-inline-info' "$ROOT/kloxo/httpdocs/theme/css/common.css"; then
  ok "simplicity Status toast peeks without consuming; content shows inline alerts"
else
  bad "simplicity Status toast peeks without consuming; content shows inline alerts"
fi
if grep -F -q 'reset($x)' "$ROOT/kloxo/httpdocs/driver/pserver/sshauthorizedkeylib.php" \
  && grep -A 12 'static function getAuthorizedKey' "$ROOT/kloxo/httpdocs/driver/pserver/sshauthorizedkey__synclib.php" | grep -q 'return array()'; then
  ok "SSH authorized keys addform does not call key() on a missing list"
else
  bad "SSH authorized keys addform does not call key() on a missing list"
fi
if grep -q 'function getServiceDetails' "$ROOT/kloxo/httpdocs/driver/pserver/service__debianlib.php" \
  && grep -q 'component__deb' "$ROOT/kloxo/httpdocs/lib/html/driver_definecore.php" \
  && grep -q 'mailqueue__exim' "$ROOT/kloxo/httpdocs/lib/driver_define.php" \
  && grep -q "function updateStart" "$ROOT/kloxo/httpdocs/driver/pserver/servicelib.php" \
  && grep -q "canGetSingle" "$ROOT/kloxo/httpdocs/driver/pserver/servicelib.php" \
  && grep -q 'Service__Linux::systemctlRun' "$ROOT/kloxo/httpdocs/driver/pserver/service__debianlib.php" \
  && grep -q 'function systemctlRun' "$ROOT/kloxo/httpdocs/driver/pserver/service__linuxlib.php" \
  && grep -q 'function mayControlUnit' "$ROOT/kloxo/httpdocs/driver/pserver/service__linuxlib.php" \
  && grep -q "'--no-block'" "$ROOT/kloxo/httpdocs/driver/pserver/service__linuxlib.php" \
  && grep -q 'cannot_control_critical_service' "$ROOT/kloxo/httpdocs/driver/pserver/servicelib.php" \
  && grep -q "include_once __DIR__" "$ROOT/kloxo/httpdocs/driver/pserver/service__debianlib.php"; then
  ok "Ubuntu services, component info, and Exim mail queue drivers are registered"
else
  bad "Ubuntu services, component info, and Exim mail queue drivers are registered"
fi
if grep -q "function powerCommandArgs" "$ROOT/kloxo/httpdocs/driver/pserver/pserver__linuxlib.php" \
  && grep -q "'/usr/bin/systemctl'" "$ROOT/kloxo/httpdocs/driver/pserver/pserver__linuxlib.php" \
  && grep -q "'--no-block'" "$ROOT/kloxo/httpdocs/driver/pserver/pserver__linuxlib.php" \
  && ! grep -q 'system("reboot")' "$ROOT/kloxo/httpdocs/driver/pserver/pserver__linuxlib.php" \
  && ! grep -q 'system("poweroff")' "$ROOT/kloxo/httpdocs/driver/pserver/pserver__linuxlib.php"; then
  ok "pserver reboot/poweroff uses systemctl --no-block"
else
  bad "pserver reboot/poweroff uses systemctl --no-block"
fi
if grep -q "exim/exim4.conf" "$ROOT/kloxo/bin/mail/kloxo-mail" \
  && test -f "$ROOT/kloxo/file/mail/exim/exim4.conf" \
  && ! test -f "$ROOT/kloxo/file/mail/postfix/main.cf" \
  && grep -q 'exim4-daemon-heavy' "$ROOT/install.sh" \
  && grep -F -q "['name' => 'exim4'" "$ROOT/kloxo/httpdocs/lib/php/kloxo_component.php"; then
  ok "hosted mail templates and installer stay Exim"
else
  bad "hosted mail templates and installer stay Exim"
fi
if grep -F -q "['name' => 'postfix'" "$ROOT/kloxo/httpdocs/lib/php/kloxo_component.php" \
  && grep -q 'smtp__postfix' "$ROOT/kloxo/httpdocs/lib/driver_define.php" \
  && grep -q 'array("none", "exim", "postfix")' "$ROOT/kloxo/file/driver/debian.inc" \
  && grep -q "'pg_smtp' => 'exim'" "$ROOT/kloxo/bin/install/init-panel-db" \
  && grep -q 'disable --now postfix' "$ROOT/install.sh" \
  && ! grep -E 'enable --now postfix' "$ROOT/install.sh"; then
  ok "Switch Program SMTP lists postfix; default remains exim"
else
  bad "Switch Program SMTP lists postfix; default remains exim"
fi
if grep -q 'KloxoComponent' "$ROOT/kloxo/httpdocs/driver/pserver/component__deblib.php" \
  && grep -q 'mariadb-server' "$ROOT/kloxo/httpdocs/lib/php/kloxo_component.php" \
  && ! grep -q 'set\.\*\.lst' "$ROOT/kloxo/httpdocs/driver/pserver/component__deblib.php"; then
  ok "Component Info uses Ubuntu package names not CentOS RPM lists"
else
  bad "Component Info uses Ubuntu package names not CentOS RPM lists"
fi
if grep -q 'function kloxo_driver_class' "$ROOT/kloxo/httpdocs/lib/html/lib.php" \
  && grep -q 'function kloxo_rl_driver' "$ROOT/kloxo/httpdocs/lib/html/lib.php" \
  && grep -F -q 'if (!is_array($result)' "$ROOT/kloxo/httpdocs/lib/php/lxclass.php" \
  && grep -q 'package__aptget' "$ROOT/kloxo/httpdocs/lib/html/driver_definecore.php" \
  && grep -q 'aptget' "$ROOT/kloxo/file/driver/debian.inc" \
  && grep -A 4 "initThisList', \$this, \$class" "$ROOT/kloxo/httpdocs/lib/php/lxclass.php" | grep -q 'catch (Throwable' \
  && grep -q 'initThisObject {$class}' "$ROOT/kloxo/httpdocs/lib/php/lxclass.php"; then
  ok "list/driver PHP 8 guards and Ubuntu apt package driver are present"
else
  bad "list/driver PHP 8 guards and Ubuntu apt package driver are present"
fi
if grep -A 20 'function remote_exec' "$ROOT/kloxo/httpdocs/lib/html/remotelib.php" | grep -q 'return do_local_action($cmd)' \
  && grep -A 8 'function isSync' "$ROOT/kloxo/httpdocs/lib/client/clientlib.php" | grep -q 'cron_mailto' \
  && grep -A 12 'function syncCreateConf' "$ROOT/kloxo/httpdocs/driver/pserver/cron__linuxlib.php" | grep -q 'preg_match' \
  && grep -q 'lxshell_return("/usr/bin/crontab"' "$ROOT/kloxo/httpdocs/driver/pserver/cron__linuxlib.php"; then
  ok "Cron Tasks Mail To does not need the C daemon and crontab is argument-array"
else
  bad "Cron Tasks Mail To does not need the C daemon and crontab is argument-array"
fi
if grep -F -q '"driver/dns/dnstemplatelib.php"' "$ROOT/kloxo/httpdocs/lib/html/definecore.php" \
  && grep -c 'frm_action=list&frm_o_cname=dnstemplate' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/menu.php" | grep -q '[2-9]' \
  && grep -A 12 'function AddListForm' "$ROOT/kloxo/httpdocs/driver/dns/dnstemplatelib.php" | grep -q 'return null' \
  && grep -q 'clientquery;?>frm_o_cname=allowedip' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/menu.php" \
  && ! grep -q 'frm_ocname=' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/menu.php" \
  && ! grep -q 'c=phpmdule' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/menu.php"; then
  ok "DNS Template list is not Backup/Restore and menu hrefs keep their query strings"
else
  bad "DNS Template list is not Backup/Restore and menu hrefs keep their query strings"
fi
if grep -F -q 'frm_o_o[1][class]=serverftp' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/menu.php" \
  && grep -A 8 'function isSync' "$ROOT/kloxo/httpdocs/driver/ftp/serverftplib.php" | grep -q "dbaction === 'update'" \
  && grep -q "function seedServerftp" "$ROOT/kloxo/bin/install/init-panel-db" \
  && grep -q "'serverftp'" "$ROOT/kloxo/httpdocs/lib/php/lxlib.php"; then
  ok "FTP Configure show does not need the C daemon and is not backup ftp_conf"
else
  bad "FTP Configure show does not need the C daemon and is not backup ftp_conf"
fi
if grep -q 'localhostquery;?>frm_o_cname=ftpsession' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/menu.php" \
  && grep -q 'serverquery;?>frm_o_cname=ftpsession' "$ROOT/kloxo/httpdocs/theme/skin/simplicity/default/menu.php" \
  && grep -q "stripos(\$command, 'pure-ftp')" "$ROOT/kloxo/httpdocs/driver/ftp/ftpsession__pureftplib.php" \
  && grep -q '\$ret = array();' "$ROOT/kloxo/httpdocs/driver/ftp/ftpsession__pureftplib.php"; then
  ok "FTP Sessions list is under pserver and getFtpList returns an array"
else
  bad "FTP Sessions list is under pserver and getFtpList returns an array"
fi

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
