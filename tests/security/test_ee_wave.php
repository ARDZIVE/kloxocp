#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * EE-wave security unit checks (php-cli only; no live panel).
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

$lfs = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/linuxfslib.php');
expect(
	str_contains($lfs, 'security EE1') && str_contains($lfs, "array(\$expect, '-f', \$t)")
	&& !str_contains($lfs, 'system("expect'),
	'EE1 lxshell_expect'
);

$cs = (string) file_get_contents($root . '/kloxo/httpdocs/lib/client/client__synclib.php');
expect(
	str_contains($cs, 'security EE2') && str_contains($cs, 'kloxo_exec_argv')
	&& !str_contains($cs, "exec(\"'rm' -f /opt/configs/php-fpm"),
	'EE2 client__synclib'
);

$dns = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/dnsslavelib.php');
expect(
	str_contains($dns, 'security EE3') && !str_contains($dns, 'exec("sh /script/fixdns --server='),
	'EE3 dnsslavelib'
);

$ps = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/pserverlib.php');
$pi = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/phpinilib.php');
expect(
	str_contains($ps, 'security EE4') && str_contains($pi, 'security EE4')
	&& !str_contains($ps, 'exec("sh /script/fixweb --server=')
	&& !str_contains($pi, 'exec("sh /script/fixphp --server='),
	'EE4 pserver/phpini'
);

$upd = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/updatelib.php');
$gen = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/generallib.php');
expect(
	str_contains($upd, 'security EE5') && str_contains($gen, 'security EE5')
	&& !str_contains($upd, 'system("cd /opt/kloxo')
	&& !str_contains($gen, 'exec("sh /script/fixphp --server=all'),
	'EE5 update/general'
);

$linux = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/linuxlib.php');
$myc = (string) file_get_contents($root . '/kloxo/bin/fix/mysql-convert.php');
$sys = (string) file_get_contents($root . '/kloxo/bin/fix/sysinfo.php');
expect(
	str_contains($linux, 'security EE6') && !str_contains($linux, 'system("quotacheck')
	&& str_contains($myc, 'security EE6') && !str_contains($myc, '@exec("sed -i')
	&& str_contains($sys, 'security EE6') && !str_contains($sys, "exec(\"'rm' -rf {\$v}\")"),
	'EE6 quotacheck/mysql/sysinfo'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
