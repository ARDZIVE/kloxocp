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

exit($fails > 0 ? 1 : 0);
