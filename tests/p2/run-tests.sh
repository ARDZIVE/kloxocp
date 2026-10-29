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

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
