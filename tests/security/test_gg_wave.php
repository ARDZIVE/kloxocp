#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * GG-wave security unit checks (php-cli only; no live panel).
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

$ff = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/ffile__linuxlib.php');
expect(
	str_contains($ff, 'security GG1')
	&& !str_contains($ff, 'exec("chmod -R {$perm}')
	&& !str_contains($ff, 'exec("chown -R {$user}'),
	'GG1 ffile chmod/chown'
);

$ssh = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/sshconfig__linuxlib.php');
expect(
	str_contains($ssh, 'security GG2') && str_contains($ssh, 'lxshell_input')
	&& !str_contains($ssh, 'exec("echo -e'),
	'GG2 sshconfig passwd'
);

$ps = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/pserver__linuxlib.php');
$lx = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxclass.php');
expect(
	str_contains($ps, 'security GG3') && str_contains($ps, 'proc_open')
	&& !str_contains($ps, 'shell_exec("$command')
	&& str_contains($lx, 'security GG3') && str_contains($lx, '$ccenterArgv'),
	'GG3 execCommand / ccenter'
);

$pi = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/phpini__synclib.php');
expect(
	str_contains($pi, 'security GG4')
	&& !str_contains($pi, 'exec("cat {$phpfpm_target_global_pre}')
	&& !str_contains($pi, 'exec("mkdir -p {$path}/php-fpm.d")'),
	'GG4 phpini__synclib'
);

$ssl = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/sslcertlib.php');
expect(
	str_contains($ssl, 'security GG5')
	&& !str_contains($ssl, 'exec("cd {$shpath}; sh')
	&& !str_contains($ssl, 'exec("ln -sf {$spath}/{$dom}'),
	'GG5 sslcertlib'
);

$ftp = (string) file_get_contents($root . '/kloxo/httpdocs/driver/ftp/ftpuser__pureftplib.php');
$fixf = (string) file_get_contents($root . '/kloxo/bin/fix/fixftpuser.php');
$scav = (string) file_get_contents($root . '/kloxo/bin/scavenge.php');
$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$dns = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/dnsslavelib.php');
expect(
	str_contains($ftp, 'security GG6') && !str_contains($ftp, 'exec($cmd)')
	&& str_contains($fixf, 'security GG6')
	&& str_contains($scav, 'security GG6') && !str_contains($scav, 'passthru("$sgbl->__path_php_path')
	&& str_contains($lib, 'security GG6') && !str_contains($lib, 'exec($fixstr)')
	&& !str_contains($lib, 'mustafa.lia.armando')
	&& str_contains($dns, 'security GG6'),
	'GG6 leftovers'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
