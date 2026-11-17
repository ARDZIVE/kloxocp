<?php
/**
 * Dropped-job kick must not fork when idle or when a drain is already running
 * (backend do_server_stuff runs every accept + 30s — that was the OOM fork bomb).
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function ok(string $d): void { global $pass; $pass++; printf("  ok   %s\n", $d); }
function bad(string $d, string $x = ''): void
{
	global $fail;
	$fail++;
	printf("  FAIL %s\n", $d);
	if ($x !== '') {
		printf("       %s\n", $x);
	}
}

$root = dirname(__DIR__, 2);
$doc = $root . '/kloxo/httpdocs';
$lxlib = (string) file_get_contents($doc . '/lib/php/lxlib.php');
$server = (string) file_get_contents($doc . '/lib/html/lxserverlib.php');
$runner = (string) file_get_contents($root . '/kloxo/bin/common/run-dropped-client-jobs.php');
$wd = (string) file_get_contents($doc . '/lib/php/kloxo_watchdog.php');
$remote = (string) file_get_contents($doc . '/lib/html/remotelib.php');

$kick = '';
if (preg_match('/function kloxo_kick_dropped_client_jobs\(.*?^function /sm', $lxlib, $m) === 1) {
	$kick = $m[0];
}
if ($kick !== ''
	&& str_contains($kick, 'kloxo_dropped_client_jobs_pending')
	&& str_contains($kick, 'kloxo_dropped_client_jobs_busy')
	&& str_contains($kick, 'dropped-client-jobs.spawn')
	&& str_contains($lxlib, 'function kloxo_dropped_client_jobs_pending')
	&& str_contains($lxlib, 'function kloxo_dropped_client_jobs_busy')
	&& str_contains($lxlib, 'dropped-client-jobs.pid')
	&& str_contains($lxlib, 'dropped-client-jobs.spawn')
	&& str_contains($lxlib, 'domain-sync.request')) {
	ok('kick requires pending work and skips when a drain is busy');
} else {
	bad('kick requires pending work and skips when a drain is busy');
}

if (str_contains($runner, 'LOCK_EX | LOCK_NB')
	&& str_contains($runner, 'dropped-client-jobs.pid')
	&& str_contains($runner, 'dropped-client-jobs.lock')
	&& str_contains($runner, 'dropped-client-jobs.spawn')
	&& str_contains($runner, 'background.queue')
	&& preg_match('/\$pending = false;.*?exit\(0\);/s', $runner) === 1) {
	ok('runner exits before include when idle and takes a single-flight lock');
} else {
	bad('runner exits before include when idle and takes a single-flight lock');
}

if (str_contains($server, 'kloxo_kick_dropped_client_jobs')
	&& str_contains($server, 'fork-bombed')
	&& str_contains($remote, 'do_server_stuff()')
	&& str_contains($remote, 'stream_select')) {
	ok('backend still kicks (gated) from do_server_stuff; select loop documented');
} else {
	bad('backend still kicks (gated) from do_server_stuff; select loop documented');
}

if (str_contains($wd, 'kloxo_dropped_client_jobs_busy')
	|| str_contains($wd, 'dropped-client-jobs.pid')) {
	ok('watchdog skips spawning a second drain while busy');
} else {
	bad('watchdog skips spawning a second drain while busy');
}

// Behavioural: pending/busy helpers with a temp tree
$_SERVER['PHP_SELF'] = '/display.php';
chdir($doc);
include_once $doc . '/lib/html/include.php';

$tmp = sys_get_temp_dir() . '/kloxo-dropped-jobs-' . getmypid();
@mkdir($tmp . '/log', 0700, true);
@mkdir($tmp . '/pid', 0700, true);

if (!kloxo_dropped_client_jobs_pending($tmp)) {
	ok('pending is false on an empty tree');
} else {
	bad('pending is false on an empty tree');
}
@file_put_contents($tmp . '/log/domain-sync.request', "example.com\n");
if (kloxo_dropped_client_jobs_pending($tmp)) {
	ok('pending is true when domain-sync.request exists');
} else {
	bad('pending is true when domain-sync.request exists');
}
if (!kloxo_dropped_client_jobs_busy($tmp)) {
	ok('busy is false without a live pid/lock');
} else {
	bad('busy is false without a live pid/lock');
}
@file_put_contents($tmp . '/pid/dropped-client-jobs.pid', (string) getmypid());
if (kloxo_dropped_client_jobs_busy($tmp)) {
	ok('busy is true when pid file names this live process');
} else {
	bad('busy is true when pid file names this live process');
}
@unlink($tmp . '/pid/dropped-client-jobs.pid');
@file_put_contents($tmp . '/pid/dropped-client-jobs.spawn', (string) time());
if (kloxo_dropped_client_jobs_busy($tmp)) {
	ok('busy is true during the kick spawn cooldown');
} else {
	bad('busy is true during the kick spawn cooldown');
}
@unlink($tmp . '/pid/dropped-client-jobs.spawn');
@unlink($tmp . '/log/domain-sync.request');
@rmdir($tmp . '/log');
@rmdir($tmp . '/pid');
@rmdir($tmp);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
