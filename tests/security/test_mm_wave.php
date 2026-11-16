#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * MM-wave security unit checks (php-cli only; no live panel).
 */

$root = realpath(dirname(__DIR__, 2));
if ($root === false) {
	fwrite(STDERR, "cannot resolve repo root\n");
	exit(2);
}

$failed = 0;
$passed = 0;

function expect(bool $ok, string $msg): void
{
	global $failed, $passed;
	if ($ok) {
		echo "OK  {$msg}\n";
		$passed++;
	} else {
		echo "FAIL {$msg}\n";
		$failed++;
	}
}

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
expect(
	str_contains($lib, 'security MM1')
	&& str_contains($lib, 'function changeValues')
	&& str_contains($lib, 'kloxo_sql_ident($tbl_name)')
	&& !str_contains($lib, "nname = '{\$r['dbname']}' where dbname = '{\$r['dbname']}'")
	&& !str_contains($lib, '"insert into lxt_" . $tbl_name . " values" . "(" . $t . ")"'),
	'MM1 schema/migrate rawQuery'
);

$extSec = (string) @file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
expect(
	str_contains($extSec, 'security MM2')
	&& is_dir($root . '/kloxo/httpdocs/theme/extjs')
	&& is_file($root . '/kloxo/httpdocs/theme/extjs/js/ext-all.js'),
	'MM2 ExtJS retained with security notice'
);

$smtp = (string) file_get_contents($root . '/kloxo/httpdocs/lib/extra/class.smtp.php');
expect(
	str_contains($smtp, 'security MM3')
	&& str_contains($smtp, 'security JJ5')
	&& !str_contains($smtp, 'HMAC implementation')
	&& !str_contains($smtp, 'pack(\'H*\', md5')
	&& strlen($smtp) < 1200,
	'MM3 SMTP stub'
);

$def = (string) file_get_contents($root . '/kloxo/httpdocs/lib/define.php');
$drv = (string) file_get_contents($root . '/kloxo/httpdocs/lib/driver_define.php');
$cli = (string) file_get_contents($root . '/kloxo/httpdocs/lib/client/clientlib.php');
$base = (string) file_get_contents($root . '/kloxo/httpdocs/lib/client/clientbaselib.php');
expect(
	str_contains($def, 'security MM4')
	&& !preg_match('/^\s*\$gl_class_array\["easyinstaller"\]/m', $def)
	&& str_contains($drv, 'security MM4')
	&& !preg_match('/^\s*\$gl_class_array\[\'easyinstaller__linux\'\]/m', $drv)
	&& str_contains($cli, 'security MM4')
	&& str_contains($cli, 'easyinstaller_removed')
	&& str_contains($base, 'security MM4')
	&& !str_contains($base, 'createDefaultApplication($this->domain_name'),
	'MM4 EasyInstaller unregistered'
);

expect(
	str_contains($lib, 'security MM5')
	&& str_contains($lib, 'function kloxo_sql_assign_clause')
	&& str_contains($lib, 'db_set_value refused unsafe'),
	'MM5 db_set_value allow-list'
);

$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lxlib, 'security MM6')
	&& str_contains($lxlib, 'crypt() verify-only'),
	'MM6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
