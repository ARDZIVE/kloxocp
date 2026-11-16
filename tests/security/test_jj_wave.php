#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * JJ-wave security unit checks (php-cli only; no live panel).
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

$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
$common = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/commonfslib.php');
$lfs = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/linuxfslib.php');
expect(
	str_contains($lxlib, 'security JJ1')
	&& str_contains($lxlib, 'function kloxo_shell_argv')
	&& str_contains($common, 'security JJ1')
	&& !str_contains($common, 'getShellCommand(')
	&& str_contains($lfs, 'security JJ1')
	&& !str_contains($lfs, 'getShellCommand(')
	&& str_contains($lfs, 'refuse legacy shell strings'),
	'JJ1 argv shell path'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$linux = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/linuxlib.php');
$lxserv = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lxserverlib.php');
$remote = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/remotelib.php');
$ssh = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/sshconfig__linuxlib.php');
expect(
	str_contains($lib, 'security JJ2')
	&& str_contains($lib, 'function kloxo_exec_closed_argv')
	&& str_contains($lib, 'function kloxo_closed_exec_parse')
	&& str_contains($linux, 'security JJ2')
	&& !str_contains($linux, 'exec_with_all_closed("service {$serv}')
	&& str_contains($lxserv, 'security JJ2')
	&& str_contains($remote, 'security JJ2')
	&& str_contains($ssh, "array('systemctl', 'restart', 'ssh')")
	&& !str_contains($lib, 'exec_with_all_closed("fetchmail')
	&& !str_contains($lib, 'exec_with_all_closed("service '),
	'JJ2 closed-exec argv'
);

$demo = (string) file_get_contents($root . '/kloxo/bin/install/demoinit.php');
$checkpw = (string) file_get_contents($root . '/kloxo/bin/common/misc/checkpassword.php');
$dbu = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/ddatabaseuserlib.php');
$db = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/ddatabaselib.php');
expect(
	str_contains($demo, 'security JJ3')
	&& !str_contains($demo, "crypt('admin'")
	&& str_contains($checkpw, 'security JJ3')
	&& !preg_match('/^\s*\$c\s*=\s*crypt\(/m', $checkpw)
	&& str_contains($dbu, 'security JJ3')
	&& !str_contains($dbu, 'md5($this->dbpassword)')
	&& str_contains($db, 'security JJ3')
	&& !str_contains($db, 'md5((string) $this->dbpassword)')
	&& str_contains($lib, 'bin2hex(random_bytes(16))'),
	'JJ3 password hashing leftovers'
);

$urlrw = (string) file_get_contents($root . '/kloxo/httpdocs/lib/urlrewrite/hn_urlrewrite.class.php');
$rdns = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/reversedns__bindlib.php');
$html = (string) file_get_contents($root . '/kloxo/httpdocs/theme/htmllib.php');
$login = (string) file_get_contents($root . '/kloxo/httpdocs/login/index.php');
expect(
	str_contains($urlrw, 'security JJ4') && !str_contains($urlrw, 'mt_rand(')
	&& str_contains($rdns, 'security JJ4') && !str_contains($rdns, 'rand(0, 99)')
	&& str_contains($html, 'security JJ4')
	&& str_contains($lib, 'security JJ4') && !str_contains($lib, 'rand(0, 100)')
	&& str_contains($login, 'security JJ4') && !str_contains($login, 'rand(0, ($count'),
	'JJ4 weak RNG leftovers'
);

$lxclass = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxclass.php');
$smtp = (string) file_get_contents($root . '/kloxo/httpdocs/lib/extra/class.smtp.php');
$formmail = (string) file_get_contents($root . '/kloxo/file/formmail/formmail.php');
expect(
	str_contains($lxclass, 'security JJ5')
	&& !str_contains($lxclass, 'eval("return {$string}')
	&& str_contains($smtp, 'security JJ5')
	&& str_contains($formmail, 'security JJ5')
	&& str_contains($lib, 'security JJ5'),
	'JJ5 dead eval + third-party md5 markers'
);

expect(
	str_contains($lib, 'security JJ6')
	&& !str_contains($lib, 'extract($__vars, EXTR_SKIP)')
	&& str_contains($lib, '$$__k = $__v'),
	'JJ6 getParseInlinePhp no extract'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
