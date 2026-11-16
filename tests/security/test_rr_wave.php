#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * RR-wave security unit checks (php-cli only; no live panel).
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

$sql = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/sqlitelib.php');
expect(
	str_contains($sql, 'security RR1')
	&& (bool) preg_match('/function existInTable[\s\S]{0,200}getRows\s*\(/', $sql)
	&& !str_contains($sql, "getRowsWhere(\"{\$var} = '{\$value}'\")"),
	'RR1 existInTable via getRows'
);

$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
$res = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/resourceplanlib.php');
$inv = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/invoicelib.php');
$pay = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/paymentdetaillib.php');
$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
expect(
	str_contains($lxlib, 'security RR2')
	&& str_contains($lxlib, 'function kloxo_sql_eq')
	&& str_contains($lxlib, 'function kloxo_sql_and_eq')
	&& str_contains($sql, 'function getRows($field, $value, $list = null)')
	&& !str_contains($res, "resourceplan_used = '\$this->nname'")
	&& str_contains($inv, 'kloxo_sql_and_eq')
	&& !str_contains($pay, "transactionid = '\$transactionid'")
	&& !str_contains($lib, "nname = '\$name'"),
	'RR2 helpers + key call sites'
);

// Source-level quote shape (avoid loading full lxlib bootstrap).
expect(
	str_contains($lxlib, "return \$field . \" = '\" . kloxo_sql_quote(\$value) . \"'\"")
	&& str_contains($lxlib, "return '0 = 1'"),
	'RR2 kloxo_sql_eq quote shape'
);

$def = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/definecore.php');
expect(
	str_contains($def, 'security RR3')
	&& !preg_match('/^\s*\$gl_class_array\["mssqldb"\]/m', $def)
	&& !preg_match('/^\s*\$gl_class_array\["mssqldbuser"\]/m', $def),
	'RR3 mssqldb unregistered'
);

$html = (string) file_get_contents($root . '/kloxo/httpdocs/theme/htmllib.php');
expect(
	str_contains($html, 'security RR4')
	&& str_contains($html, 'function jsString')
	&& str_contains($html, 'self::jsString')
	&& !str_contains($html, "changeContent('help',' \$help')")
	&& !str_contains($html, "changeContent('help','\$help')"),
	'RR4 help jsString'
);

$panel = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/xpmenu/PanelBarOrig.js');
expect(
	str_contains($panel, 'security RR5')
	&& (bool) preg_match('/function getLink[\s\S]{0,500}javascript:/', $panel)
	&& str_contains($panel, 'data:'),
	'RR5 getLink URL harden'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
expect(
	str_contains($lxlib, 'security RR6')
	&& str_contains($extSec, 'security RR6')
	&& str_contains($lxlib, 'gated unserialize'),
	'RR6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
