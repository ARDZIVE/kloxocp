#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * KK-wave security unit checks (php-cli only; no live panel).
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
$common = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/commonfslib.php');
$lxclass = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxclass.php');
expect(
	str_contains($lib, 'security KK1')
	&& str_contains($common, 'security KK1')
	&& str_contains($lxclass, 'security KK1')
	&& !str_contains($lib, 'exec_with_all_closed("sh /script/load-wrapper')
	&& !str_contains($common, 'exec_with_all_closed("sh /script/load-wrapper')
	&& str_contains($lib, "array('sh', '/script/load-wrapper')"),
	'KK1 load-wrapper argv'
);

$webdisk = (string) file_get_contents($root . '/kloxo/httpdocs/webdisk/index.php');
$webdav = (string) file_get_contents($root . '/kloxo/httpdocs/webdisk/webdav.php');
$auth = (string) file_get_contents($root . '/kloxo/httpdocs/webdisk/authenticate.php');
expect(
	str_contains($webdisk, 'security KK2')
	&& str_contains($webdav, 'security KK2')
	&& str_contains($auth, 'security KK2')
	&& !is_dir($root . '/kloxo/httpdocs/webdisk/PEAR')
	&& !is_dir($root . '/kloxo/httpdocs/webdisk/HTTP')
	&& !str_contains($webdav, '$DBPWD'),
	'KK2 webdisk PEAR removed'
);

$phpver = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/kloxo_phpver.php');
expect(
	str_contains($phpver, 'security KK3')
	&& str_contains($phpver, 'function bashQuote')
	&& str_contains($phpver, 'Fail-closed'),
	'KK3 bashQuote helper'
);

$setup = (string) file_get_contents($root . '/kloxo/bin/common/setupsecondary.php');
expect(
	str_contains($setup, 'security KK4')
	&& !str_contains($setup, 'escapeshellarg($slavepass)')
	&& str_contains($setup, "preg_match('/^[A-Za-z0-9]+$/', \$slavepass)"),
	'KK4 setupsecondary no escapeshellarg slavepass'
);

$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lxlib, 'security KK5')
	&& str_contains($lxlib, 'getShellCommand refused')
	&& !preg_match('/function getShellCommand\([^)]*\)\s*\{[^}]*implode/s', $lxlib),
	'KK5 getShellCommand stub'
);

$checkpw = (string) file_get_contents($root . '/kloxo/bin/common/misc/checkpassword.php');
expect(
	str_contains($lxlib, 'security KK6')
	&& str_contains($checkpw, 'security KK6')
	&& str_contains($lib, 'security JJ5'),
	'KK6 crypt verify-only / md5 markers'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
