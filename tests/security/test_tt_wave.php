#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * TT-wave security unit checks (php-cli only; no live panel).
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
$lxclient = (string) file_get_contents($root . '/kloxo/httpdocs/lib/client/lxclient.php');
$ip = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/ippoollib.php');
$lxguard = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/lxguardlib.php');
$web = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/weblib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lib, 'db_set_default refused unsafe extra (security TT1)')
	&& str_contains($lxclient, 'Quoted parent_clname (security TT1)')
	&& str_contains($ip, 'security LL3 / TT1')
	&& str_contains($lxguard, 'security LL3 / TT1')
	&& str_contains($web, 'Quoted syncserver (security TT1)')
	&& str_contains($lxlib, 'Identifier allow-list (security TT1)'),
	'TT1 rawQuery interpolations hardened'
);

$svc = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/servicelib.php');
expect(
	str_contains($svc, 'security TT2')
	&& str_contains($svc, 'kloxo_sql_eq')
	&& !str_contains($svc, 'addslashes($server)')
	&& !str_contains($svc, 'addslashes($parentCl)'),
	'TT2 servicelib kloxo_sql_eq'
);

expect(
	str_contains($lxclient, 'security TT3')
	&& str_contains($lxclient, "getRows('nname', \$this->nname)")
	&& !str_contains($lxclient, "where nname = '\$this->nname'"),
	'TT3 lxclient nname getRows'
);

$lxa = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/lxa.js');
expect(
	str_contains($lxa, 'security TT4')
	&& str_contains($lxa, 'el.textContent')
	&& !str_contains($lxa, 'el.innerHTML'),
	'TT4 lxa gutter textContent'
);

$html = (string) file_get_contents($root . '/kloxo/httpdocs/theme/htmllib.php');
expect(
	str_contains($html, 'security TT5')
	&& str_contains($html, "preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/'")
	&& str_contains($html, 'self::jsString'),
	'TT5 treename JS-ident allow-list'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
expect(
	str_contains($lxlib, 'security TT6')
	&& str_contains($extSec, 'security TT6')
	&& str_contains($toast, 'security TT6')
	&& str_contains($lxlib, 'gated unserialize'),
	'TT6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
