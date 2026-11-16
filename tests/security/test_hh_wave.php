#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * HH-wave security unit checks (php-cli only; no live panel).
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
	str_contains($lfs, 'security HH1')
	&& !str_contains($lfs, 'exec("nice -n +10 ionice -c3 du -s {$path}")')
	&& !str_contains($lfs, "exec('stat -c %s '. escapeshellarg"),
	'HH1 linuxfslib du/stat/background'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
expect(
	str_contains($lib, 'security HH2')
	&& !str_contains($lib, 'exec("up2date-nox --nosig $value")')
	&& !str_contains($lib, 'exec("mkdir -p {$logf}; chown named:named')
	&& !str_contains($lib, "exec(\"echo '## MR -- blank only'"),
	'HH2 lib.php cleanup'
);

$sys = (string) file_get_contents($root . '/kloxo/bin/fix/sysinfo.php');
expect(
	str_contains($sys, 'security HH3') && str_contains($sys, 'kloxo_sysinfo_dpkg')
	&& !str_contains($sys, 'cat /opt/kloxo/etc/list/web.lst|')
	&& !str_contains($sys, 'sed -e \'" . $seddata'),
	'HH3 sysinfo.php'
);

$ip = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/ipaddresslib.php');
$web = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/web__lib.php');
expect(
	str_contains($ip, 'security HH4')
	&& !str_contains($ip, 'exec("cat {$ppath}/file/ssl/default.key')
	&& str_contains($web, 'security HH4')
	&& !str_contains($web, "exec(\"'mv' -f {\$spath} {\$dpath}\")"),
	'HH4 ipaddress / web__lib'
);

$sw = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/serverweb__lib.php');
$psc = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/pservercorelib.php');
$tmp = (string) file_get_contents($root . '/kloxo/bin/common/tmpupdatecleanup.php');
expect(
	str_contains($sw, 'security HH5') && !str_contains($sw, '@exec("systemctl enable php-fpm')
	&& str_contains($psc, 'security HH5')
	&& !str_contains($psc, 'exec("echo \'\' > ../etc/flag/use_apache24.flg")')
	&& str_contains($tmp, 'security HH5')
	&& !str_contains($tmp, '@exec("systemctl disable --now mysql'),
	'HH5 serverweb/pservercore/tmpupdate'
);

$fm = (string) file_get_contents($root . '/kloxo/file/formmail/formmail.php');
$lxi = (string) file_get_contents($root . '/kloxo/bin/install/lxinstall.php');
$lxetc = (string) file_get_contents($root . '/kloxo/bin/common/misc/lxetc.php');
$lin = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/linuxlib.php');
$svc = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/service__linuxlib.php');
$chp = (string) file_get_contents($root . '/kloxo/init/changeport.php');
expect(
	str_contains($fm, 'security HH6') && !str_contains($fm, 'popen($cmd')
	&& str_contains($lxi, 'security HH6') && !str_contains($lxi, 'system($cmd)')
	&& str_contains($lxetc, 'security HH6') && !str_contains($lxetc, 'system("cd ~/.etc/bin')
	&& str_contains($lin, 'security HH6') && !str_contains($lin, "exec(\"ip addr|grep")
	&& str_contains($svc, 'security HH6')
	&& !str_contains($svc, 'systemctl list-unit-files --type=service|awk')
	&& str_contains($chp, 'security HH6')
	&& !str_contains($chp, 'exec("mkdir -p /var/run/acme'),
	'HH6 leftovers'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
