#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * FF-wave security unit checks (php-cli only; no live panel).
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
	str_contains($lib, 'security FF1')
	&& !str_contains($lib, 'exec("sh /script/setdriver --server=localhost --class={$key}')
	&& !str_contains($lib, 'exec("sh /script/enable-service pure-ftpd')
	&& !str_contains($lib, "exec(\"'rm' -f {\$path}/redirect-to-*.php\")"),
	'FF1 lib.php setdriver/rm/enable-service'
);

$sw = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/serverweb__lib.php');
expect(
	str_contains($sw, 'security FF2')
	&& !str_contains($sw, 'exec("sh /script/enable-php-fpm; sh /script/add-start-queue')
	&& !str_contains($sw, "exec(\"'rm' -rf {\$ehcdpath}/swtune.conf\")"),
	'FF2 serverweb__lib'
);

$mt = (string) file_get_contents($root . '/kloxo/httpdocs/driver/mmail/mailtrafficlib.php');
expect(
	str_contains($mt, 'security FF3') && str_contains($mt, 'proc_open')
	&& !str_contains($mt, 'system("perl'),
	'FF3 mailtrafficlib'
);

$web = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/web__lib.php');
expect(
	str_contains($web, 'security FF4')
	&& !str_contains($web, "exec(\"'rm' -rf /etc/php-fpm.d/*.conf\")")
	&& str_contains($web, "glob('/etc/php-fpm.d/*.conf')"),
	'FF4 web__lib php-fpm.d'
);

$lx = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
$app = (string) file_get_contents($root . '/kloxo/httpdocs/lib/client/appearancelib.php');
expect(
	str_contains($lx, 'security FF5') && !str_contains($lx, 'eval(debug_print')
	&& str_contains($app, 'security FF5')
	&& !str_contains($app, 'exec("sh /script/fix-userlogo")'),
	'FF5 get_debug_print / appearancelib'
);

$scav = (string) file_get_contents($root . '/kloxo/bin/scavenge.php');
$iwdb = (string) file_get_contents($root . '/kloxo/bin/misc/installWebmailDb.php');
$fcb = (string) file_get_contents($root . '/kloxo/bin/fix/fix-cron-backup.php');
$lprog = (string) file_get_contents($root . '/kloxo/httpdocs/lib/linuxproglib.php');
expect(
	str_contains($scav, 'security FF6')
	&& str_contains($iwdb, 'security FF6') && !str_contains($iwdb, 'system("sh /script/setup-roundcube")')
	&& str_contains($fcb, 'security FF6') && !str_contains($fcb, "exec(\"'rm' -f /etc/cron.d/backup*\")")
	&& str_contains($lprog, 'security FF6') && !str_contains($lprog, 'system("echo > /etc/hosts.deny")'),
	'FF6 scavenge/installWebmail/fix-cron/hosts.deny'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
