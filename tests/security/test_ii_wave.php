#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * II-wave security unit checks (php-cli only; no live panel).
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
	str_contains($lib, 'security II1')
	&& !str_contains($lib, 'exec("setquota -u root')
	&& !str_contains($lib, 'exec("service pure-ftpd stop")')
	&& !str_contains($lib, 'exec("pkill -f sisinfoc")'),
	'II1 lib.php fixed shells'
);

expect(
	str_contains($lib, 'security II2')
	&& !str_contains($lib, "exec(\"php -v|grep")
	&& !str_contains($lib, 'exec(\'dpkg-query -W')
	&& !str_contains($lib, 'exec("quotaon -pa|grep')
	&& str_contains($lib, 'EasyinstallerPHP refused'),
	'II2 lib.php pipes/vars'
);

expect(
	str_contains($lib, 'security II3')
	&& !str_contains($lib, 'exec("cp -f ../file/logrotate/etc/logrotate.d/*')
	&& !str_contains($lib, "exec(\"sed -i 's:-/var/log/:/var/log/:g' /etc/*syslog.conf\")"),
	'II3 logrotate/syslog'
);

$ps = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/pserver__linuxlib.php');
$rel = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/releasenotelib.php');
expect(
	str_contains($ps, 'security II4') && !str_contains($ps, 'exec("sh /script/virtual-info -t"')
	&& str_contains($rel, 'security II4')
	&& !str_contains($rel, 'exec("sh /script/version --vertype=full"'),
	'II4 virtual-info / version'
);

$lfs = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/linuxfslib.php');
expect(
	str_contains($lfs, 'security II5') && str_contains($lfs, 'pcntl_fork')
	&& !str_contains($lfs, 'exec($full)'),
	'II5 background fork'
);

$fixSsl = (string) file_get_contents($root . '/kloxo/bin/fix/fixssl.php');
$phpini = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/phpini__synclib.php');
$dns = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/dnsslavelib.php');
expect(
	str_contains($fixSsl, 'security II6') && !str_contains($fixSsl, 'exec("openssl rsa')
	&& str_contains($phpini, 'security II6')
	&& str_contains($dns, 'security II6')
	&& str_contains($lib, 'security II6'),
	'II6 dead leftovers'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
