#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * DD-wave security unit checks (php-cli only; no live panel).
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

$csync = (string) file_get_contents($root . '/kloxo/bin/common/csyncdb.sh');
expect(
	str_contains($csync, 'defaults-extra-file') && !str_contains($csync, '-p`cat'),
	'DD1 csyncdb defaults-extra-file'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
expect(
	str_contains($lib, 'security DD2') && str_contains($lib, 'KLOXO_ADMIN_PASSWORD')
	&& !str_contains($lib, '--admin-password={$adminpass}'),
	'DD2 createMultipLeVps env password'
);

$lfs = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/linuxfslib.php');
expect(
	str_contains($lfs, 'kloxo_archive_extract_argv') && str_contains($lfs, 'security DD3')
	&& !str_contains($lfs, 'nice -n +10 ionice -c3 $fcmd'),
	'DD3 archive argv'
);

require_once $root . '/kloxo/httpdocs/lib/html/linuxfslib.php';
// os_getZipType needs lxshell_output — skip live extract; check helper shape via source.
expect(str_contains($lfs, "array('unzip', '-oq'"), 'DD3 unzip argv shape');

$web = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/weblib.php');
$web2 = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/web__lib.php');
$ssl = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/sslcertlib.php');
expect(
	str_contains($web, 'security DD4') && str_contains($web2, 'security DD4')
	&& str_contains($ssl, 'security DD4')
	&& !str_contains($web, 'exec("sh /script/fixphp --domain='),
	'DD4 web/ssl argv'
);

$lxsrv = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lxserverlib.php');
$linux = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/linuxlib.php');
expect(
	str_contains($lxsrv, 'security DD5') && !str_contains($lxsrv, 'exec("service $cmd')
	&& str_contains($linux, 'security DD5'),
	'DD5 systemctl argv'
);

$cleanup = (string) file_get_contents($root . '/kloxo/bin/common/cleanup.php');
$fixlog = (string) file_get_contents($root . '/kloxo/bin/common/fixlogdir.php');
$fixtraf = (string) file_get_contents($root . '/kloxo/bin/common/fixtraffic.php');
$gen = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/generallib.php');
expect(
	str_contains($cleanup, 'security DD6') && !str_contains($cleanup, 'system("lxphp')
	&& str_contains($fixlog, 'security DD6') && str_contains($fixtraf, 'security DD6')
	&& str_contains($lib, 'security DD6') && str_contains($gen, 'security DD6')
	&& !str_contains($gen, "exec(\"echo '\$sslport'"),
	'DD6 misc system leftovers'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
