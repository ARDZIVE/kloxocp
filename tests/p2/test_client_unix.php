<?php
/**
 * Client Unix ensure: Add Client / PHP-FPM must not race useradd.
 * php-cli only — no live useradd.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$doc = $root . '/kloxo/httpdocs';
$fails = 0;

function ok(string $m): void
{
	echo "OK  {$m}\n";
}

function bad(string $m): void
{
	global $fails;
	$fails++;
	echo "BAD {$m}\n";
}

$unix = (string) file_get_contents($doc . '/lib/php/kloxo_client_unix.php');
$lxlib = (string) file_get_contents($doc . '/lib/php/lxlib.php');
$fpm = (string) file_get_contents($doc . '/lib/php/kloxo_phpfpm.php');
$inc = (string) file_get_contents($doc . '/lib/html/include.php');

if (str_contains($unix, 'final class KloxoClientUnix')
	&& str_contains($unix, 'public static function ensure')
	&& str_contains($unix, 'public static function ensureUnixName')
	&& str_contains($unix, 'createUser')
	&& str_contains($unix, 'declare(strict_types=1)')) {
	ok('KloxoClientUnix ensure / ensureUnixName present');
} else {
	bad('KloxoClientUnix ensure / ensureUnixName present');
}

if (str_contains($lxlib, 'function kloxo_ensure_client_unix')
	&& str_contains($lxlib, "array('KloxoClientUnix', 'ensure')")
	&& str_contains($lxlib, 'rl_exec_get')) {
	ok('panel helper sends ensure through kloxo-backend');
} else {
	bad('panel helper sends ensure through kloxo-backend');
}

$finishFn = '';
if (preg_match('/function kloxo_finish_client_add\(.*?^function /sm', $lxlib, $mFin) === 1) {
	$finishFn = $mFin[0];
}
if ($finishFn !== ''
	&& str_contains($finishFn, 'kloxo_ensure_client_unix')
	&& str_contains($finishFn, 'kloxo_queue_client_system_sync')
	&& strpos($finishFn, 'kloxo_ensure_client_unix') < strpos($finishFn, 'kloxo_emit_local_redirect')) {
	ok('Add Client ensures Unix user before redirect');
} else {
	bad('Add Client ensures Unix user before redirect');
}

if (str_contains($fpm, 'KloxoClientUnix::ensureUnixName')
	&& str_contains($fpm, "unix user '")
	&& preg_match('/function applyDomain\(.*?return \[\'ok\' => true/s', $fpm, $mApp) === 1
	&& str_contains($mApp[0], 'ensureUnixName')
	&& strpos($mApp[0], 'ensureUnixName') < strpos($mApp[0], 'switchDomain')) {
	ok('applyDomain ensures pool user before php-fpm -t');
} else {
	bad('applyDomain ensures pool user before php-fpm -t');
}

if (str_contains($inc, 'kloxo_client_unix.php')) {
	ok('include.php loads KloxoClientUnix for backend RPC');
} else {
	bad('include.php loads KloxoClientUnix for backend RPC');
}

$out = [];
$code = 0;
exec('php -l ' . escapeshellarg($doc . '/lib/php/kloxo_client_unix.php') . ' 2>&1', $out, $code);
if ($code === 0) {
	ok('kloxo_client_unix.php php -l');
} else {
	bad('kloxo_client_unix.php php -l: ' . implode(' ', $out));
}

require_once $doc . '/lib/php/kloxo_client_unix.php';
$euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : 1;
$got = KloxoClientUnix::ensure('__no_such_client_xyz__');
if ($euid !== 0 && is_array($got) && empty($got['ok']) && ($got['error'] ?? '') === 'not root') {
	ok('ensure refuses when not root');
} elseif ($euid === 0 && is_array($got) && empty($got['ok'])) {
	ok('ensure refuses when not root');
} else {
	bad('ensure refuses when not root');
}

$gotU = KloxoClientUnix::ensureUnixName('');
if (is_array($gotU) && empty($gotU['ok'])) {
	ok('ensureUnixName rejects empty name');
} else {
	bad('ensureUnixName rejects empty name');
}

$gotBad = KloxoClientUnix::ensureUnixName('../evil');
if (is_array($gotBad) && empty($gotBad['ok'])) {
	ok('ensureUnixName rejects path-like names');
} else {
	bad('ensureUnixName rejects path-like names');
}

if (str_contains($unix, 'public static function purgeLeftover')
	&& str_contains($unix, 'public static function purgePaths')
	&& str_contains($unix, 'removeHomeAndUser')) {
	ok('KloxoClientUnix purgeLeftover / purgePaths present');
} else {
	bad('KloxoClientUnix purgeLeftover / purgePaths present');
}

if (str_contains($lxlib, 'function kloxo_purge_client_leftover')
	&& str_contains($lxlib, 'function kloxo_queue_client_home_purge')
	&& str_contains($lxlib, 'function kloxo_client_home_purge')
	&& str_contains($lxlib, "array('KloxoClientUnix', 'purgeLeftover')")
	&& str_contains($lxlib, 'client-home-purge.request')) {
	ok('panel helpers purge leftover home via backend / drop-queue');
} else {
	bad('panel helpers purge leftover home via backend / drop-queue');
}

$base = (string) file_get_contents($doc . '/lib/client/clientbaselib.php');
$sync = (string) file_get_contents($doc . '/lib/client/client__synclib.php');
if (str_contains($base, 'kloxo_purge_client_leftover')
	&& str_contains($base, 'dir_exists_under_home_dir')
	&& str_contains($sync, 'KloxoClientUnix::purgePaths')) {
	ok('Add Client reclaims orphan /home; delete uses purgePaths');
} else {
	bad('Add Client reclaims orphan /home; delete uses purgePaths');
}

$gotPurge = KloxoClientUnix::purgeLeftover('../evil');
if (is_array($gotPurge) && empty($gotPurge['ok'])) {
	ok('purgeLeftover rejects path-like names');
} else {
	bad('purgeLeftover rejects path-like names');
}

$gotPurge2 = KloxoClientUnix::purgeLeftover('mesrob');
if ($euid !== 0 && is_array($gotPurge2) && empty($gotPurge2['ok']) && ($gotPurge2['error'] ?? '') === 'not root') {
	ok('purgeLeftover refuses when not root');
} elseif ($euid === 0 && is_array($gotPurge2)) {
	ok('purgeLeftover refuses when not root');
} else {
	bad('purgeLeftover refuses when not root');
}

$bg = (string) file_get_contents($root . '/kloxo/bin/common/background.php');
if (str_contains($bg, 'kloxo_client_home_purge')) {
	ok('background.php allow-lists kloxo_client_home_purge');
} else {
	bad('background.php allow-lists kloxo_client_home_purge');
}

exit($fails > 0 ? 1 : 0);
