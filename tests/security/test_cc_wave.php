#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CC-wave security unit checks (php-cli only; no live panel).
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
	str_contains($lib, 'security CC1')
	&& str_contains($lib, 'defaults-extra-file')
	&& !str_contains($lib, 'kloxo.pass`'),
	'CC1 createDatabaseInterfaceTemplate'
);

$sec = (string) file_get_contents($root . '/kloxo/bin/common/setupsecondary.php');
expect(
	str_contains($sec, 'security CC2')
	&& str_contains($sec, 'defaults-extra-file')
	&& !str_contains($sec, "-p' . \$pass")
	&& !str_contains($sec, "-p\" . \$pass"),
	'CC2 setupsecondary'
);

$phpexec = (string) file_get_contents($root . '/kloxo/bin/phpexec.php');
$lx = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($phpexec, 'security CC3')
	&& str_contains($phpexec, 'proc_open')
	&& !str_contains($phpexec, "cmd .= \"'{"),
	'CC3 phpexec argv'
);
expect(
	str_contains($lx, 'security CC3')
	&& str_contains($lx, 'array_merge')
	&& !str_contains($lx, 'phpexec.php $uid $gid $cmd'),
	'CC3 new_process_cmd'
);

expect(
	str_contains($lib, 'security CC4')
	&& str_contains($lib, 'function kloxo_shexec_argv')
	&& !str_contains($lib, 'shell_exec("{$caller}'),
	'CC4 shexec'
);

expect(
	str_contains($lib, 'security CC5')
	&& str_contains($lib, "'-exec', 'chown'")
	&& !str_contains($lib, 'exec("find {$cdir}/{$ks}'),
	'CC5 ownership argv find/chown'
);

$rc = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/kloxo_roundcube.php');
expect(
	str_contains($rc, 'security CC6')
	&& str_contains($rc, 'IDENTIFIED BY ?')
	&& !str_contains($rc, "IDENTIFIED BY '{\$pass}'"),
	'CC6 roundcube prepared'
);

require_once $root . '/kloxo/httpdocs/lib/php/kloxo_roundcube.php';
$seen = [];
KloxoRoundcube::$sqlExec = static function (string $sql, array $params) use (&$seen): void {
	$seen[] = [$sql, $params];
};
KloxoRoundcube::ensureDatabase('abc123def456abc123def456');
$hasPrepared = false;
$passOnArgv = false;
foreach ($seen as [$sql, $params]) {
	if (str_contains($sql, 'IDENTIFIED BY ?') && ($params[0] ?? '') === 'abc123def456abc123def456') {
		$hasPrepared = true;
	}
	if (str_contains($sql, 'abc123def456abc123def456')) {
		$passOnArgv = true;
	}
}
expect($hasPrepared && !$passOnArgv && count($seen) >= 6, 'CC6 ensureDatabase uses bound params');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
