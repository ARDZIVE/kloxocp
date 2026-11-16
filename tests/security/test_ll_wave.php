#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * LL-wave security unit checks (php-cli only; no live panel).
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

$ei = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/easyinstaller__linuxlib.php');
$eilib = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/easyinstallerlib.php');
$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$up = (string) file_get_contents($root . '/kloxo/bin/easyinstaller-update.php');
expect(
	str_contains($ei, 'security LL1')
	&& str_contains($ei, 'easyinstaller_removed')
	&& !str_contains($ei, 'download_file')
	&& str_contains($eilib, 'security LL1')
	&& !str_contains($eilib, 'md5(time())')
	&& str_contains($lib, 'security LL1')
	&& str_contains($lib, 'appvault_dbfilter refused')
	&& !str_contains($lib, 'md5($cont[\'adminpass\'])')
	&& str_contains($up, 'security LL1'),
	'LL1 EasyInstaller refused'
);

$fm = (string) file_get_contents($root . '/kloxo/file/formmail/formmail.php');
expect(
	str_contains($fm, 'security LL2')
	&& str_contains($fm, 'security HH6')
	&& str_contains($fm, 'security JJ5')
	&& !str_contains($fm, 'popen($cmd')
	&& strlen($fm) < 800,
	'LL2 formmail stub'
);

$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
$ps = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/pservercorelib.php');
$ip = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/ippoollib.php');
expect(
	str_contains($lxlib, 'function kloxo_sql_quote')
	&& str_contains($lxlib, 'security LL3')
	&& str_contains($ps, 'security LL3')
	&& str_contains($ip, 'security LL3')
	&& str_contains($lib, 'security LL3')
	&& !str_contains($ps, "delete from ipaddress where syncserver = '\$this->nname'")
	&& !str_contains($ip, "values ('\$l', '\$date')"),
	'LL3 rawQuery hardening'
);

$fpm = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/kloxo_phpfpm.php');
expect(
	str_contains($fpm, 'security LL4')
	&& str_contains($fpm, 'bashQuote')
	&& str_contains($fpm, '/usr/bin/php\\d+'),
	'LL4 cronWrapper bashQuote'
);

$ex0 = (string) file_get_contents($root . '/kloxo/httpdocs/lib/urlrewrite/example0.php');
$ex3 = (string) file_get_contents($root . '/kloxo/httpdocs/lib/urlrewrite/example3.php');
expect(
	str_contains($ex0, 'security LL5')
	&& str_contains($ex3, 'security LL5')
	&& !str_contains($ex0, 'require_once($root')
	&& !str_contains($ex3, 'DOCUMENT_ROOT'),
	'LL5 urlrewrite examples stubbed'
);

expect(
	str_contains($lxlib, 'security LL6')
	&& !str_contains($lxlib, 'password_hash($plain, PASSWORD_DEFAULT)')
	&& str_contains($lxlib, 'PASSWORD_ARGON2ID'),
	'LL6 Argon2id only'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
