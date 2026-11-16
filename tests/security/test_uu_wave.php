#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * UU-wave security unit checks (php-cli only; no live panel).
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
	str_contains($lib, 'Quoted syncserver (security UU1)')
	&& str_contains($lib, "getRows('syncserver', \$servername")
	&& str_contains($lib, "getRows('parent_clname', 'domain-'")
	&& !str_contains($lib, "syncserver = '{\$servername}'")
	&& !str_contains($lib, "parent_clname = 'domain-{\$v2}'"),
	'UU1 lib.php syncserver/parent getRows'
);

$lxclass = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxclass.php');
expect(
	str_contains($lxclass, 'security UU2')
	&& str_contains($lxclass, 'kloxo_sql_and_eq')
	&& !str_contains($lxclass, "action = '\$this->dbaction'"),
	'UU2 lxclass customaction kloxo_sql_and_eq'
);

$html = (string) file_get_contents($root . '/kloxo/httpdocs/theme/htmllib.php');
$clock = (string) file_get_contents($root . '/kloxo/httpdocs/theme/skin/simplicity/default/js/clock.js');
expect(
	str_contains($html, 'security UU3')
	&& str_contains($html, "progress_percent').textContent = '0%'")
	&& !str_contains($html, "progress_percent').innerHTML")
	&& str_contains($clock, 'security UU3')
	&& str_contains($clock, 'textContent')
	&& !str_contains($clock, 'innerHTML'),
	'UU3 progress/clock textContent'
);

$login = (string) file_get_contents($root . '/kloxo/httpdocs/login/inc.php');
$loginFile = (string) file_get_contents($root . '/kloxo/file/pages/login_inc.php');
expect(
	str_contains($login, 'security UU4')
	&& str_contains($login, 'countdown.textContent')
	&& str_contains($login, 'blockedLabelJs')
	&& !str_contains($login, 'countdown.innerHTML')
	&& str_contains($loginFile, 'security UU4')
	&& str_contains($loginFile, 'countdown.textContent')
	&& !str_contains($loginFile, 'countdown.innerHTML'),
	'UU4 login countdown textContent'
);

$dup = (string) file_get_contents($root . '/kloxo/bin/duplicate.php');
expect(
	str_contains($dup, 'security UU5')
	&& str_contains($dup, 'kloxo_sql_ident')
	&& str_contains($dup, "delRow('nname'")
	&& !str_contains($dup, 'delete from $class'),
	'UU5 duplicate.php ident + delRow'
);

$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
expect(
	str_contains($lxlib, 'security UU6')
	&& str_contains($extSec, 'security UU6')
	&& str_contains($toast, 'security UU6')
	&& str_contains($lxlib, 'gated unserialize'),
	'UU6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
