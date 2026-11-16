#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * AA-wave security unit checks (php-cli only; no live panel).
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

$me = (string) file_get_contents($root . '/kloxo/bin/common/mebackup.php');
expect(str_contains($me, 'defaults-extra-file') && !str_contains($me, '-p{$pass}'), 'AA1 mebackup');

$linux = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/linuxlib.php');
expect(str_contains($linux, 'security AA2') && !str_contains($linux, 'system("pkill'), 'AA2 pkill');

$cfs = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/commonfslib.php');
expect(str_contains($cfs, 'security AA3') && !str_contains($cfs, 'eval($sgbl->arg_getting_string)'), 'AA3/AA4 commonfslib');

$lx = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(!str_contains($lx, 'eval($sgbl->arg_getting_string)'), 'AA4 lxlib');

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
expect(str_contains($lib, 'security AA5') && str_contains($lib, 'random_int'), 'AA5 random_string_lcase');
expect(str_contains($lib, 'security AA7') && !str_contains($lib, "eval('?>'"), 'AA7 no eval');

$logo = (string) file_get_contents($root . '/kloxo/bin/fix/fix-userlogo.php');
$ssl = (string) file_get_contents($root . '/kloxo/bin/fix/fixssl.php');
expect(str_contains($logo, 'security AA6') && !str_contains($logo, "system(\"'cp'"), 'AA6 userlogo');
expect(str_contains($ssl, 'security AA6') && !str_contains($ssl, "exec(\"'cp'"), 'AA6 fixssl');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
