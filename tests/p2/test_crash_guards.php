<?php
/**
 * Once-for-all panel crash / OOM guards (php-cli; no live host).
 *
 * Timeline that crashed Ubuntu boxes after Domain Home:
 *   BEFORE waves: bare priv->isOn threw → blank page.
 *   DURING try/catch waves: empty showalist → white right column.
 *   AFTER tile fixes: tiles paint, but FPM still had memory_limit=-1,
 *   request_terminate_timeout=12000s, default_socket_timeout=600, zlib on,
 *   and leftframe auto-reload — hung workers OOMed the host (even systemd).
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
$fpm = (string) file_get_contents($root . '/kloxo/init/kloxo-php-fpm.conf');
$core = (string) file_get_contents($root . '/kloxo/httpdocs/theme/coredisplaylib.php');
$html = (string) file_get_contents($root . '/kloxo/httpdocs/theme/htmllib.php');
$busy = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-busy.js');
$sqlite = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/sqlitelib.php');
$kloxocp = (string) file_get_contents($root . '/kloxo/bin/kloxocp');
$lxserver = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lxserverlib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
$lxclass = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxclass.php');
$linuxFs = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/linuxfslib.php');

// --- FPM hard caps ---
if (str_contains($fpm, 'memory_limit] = 256M')
	&& !str_contains($fpm, 'memory_limit] = -1')
	&& str_contains($fpm, 'request_terminate_timeout = 600s')
	&& !str_contains($fpm, 'request_terminate_timeout = 12000s')
	&& str_contains($fpm, 'default_socket_timeout] = 15')
	&& !str_contains($fpm, 'default_socket_timeout] = 600')
	&& str_contains($fpm, 'pm.max_children = 4')
	&& str_contains($fpm, 'max_execution_time] = 90')
	&& str_contains($fpm, 'zlib.output_compression] = off')) {
	ok('FPM: 256M / 600s terminate / 15s socket / 4 children / zlib off');
} else {
	bad('FPM: 256M / 600s terminate / 15s socket / 4 children / zlib off');
}

// --- Show path must not stack workers ---
if (str_contains($core, 'NEVER auto-reload left/top frames')
	&& !str_contains($core, 'leftframe.location.reload')
	&& !str_contains($core, 'topframe.location.reload')
	&& str_contains($core, 'set_time_limit(45)')
	&& str_contains($core, '__var_no_sync = true')
	&& str_contains($core, 'sessionStorage.removeItem("kloxo-busy")')) {
	ok('Domain Home: no frame reload storm; time/sync capped; busy cleared');
} else {
	bad('Domain Home: no frame reload storm; time/sync capped; busy cleared');
}

// --- Tile path must finish without ajax hang ---
if (str_contains($html, '__kloxo_ajax_after_tiles = false')
	&& str_contains($core, 'Stream the left column before tiles')
	&& str_contains($core, 'kloxo-tile-fallback')) {
	ok('tiles before ajax; left column flushed; fallback present');
} else {
	bad('tiles before ajax; left column flushed; fallback present');
}

// --- Busy cannot stick forever ---
if (preg_match('/var MAX_WAIT = \d+/', $busy) === 1
	&& str_contains($busy, 'clearBusyNow()')
	&& str_contains($busy, "ev.key !== 'Escape'")
	&& str_contains($busy, "readyState === 'loading'")) {
	ok('busy: MAX_WAIT settles, Esc dismisses, clears while still loading');
} else {
	bad('busy: MAX_WAIT settles, Esc dismisses, clears while still loading');
}

// --- SQLite lib must not defeat FPM memory caps ---
if (!preg_match("/ini_set\(\s*'memory_limit'\s*,\s*'-1'\s*\)/", $sqlite)
	&& str_contains($sqlite, "PHP_SAPI === 'cli'")) {
	ok('sqlitelib does not set memory_limit=-1 for panel SAPI');
} else {
	bad('sqlitelib does not set memory_limit=-1 for panel SAPI');
}

// --- Dropped-job kick must stay gated (Add Domain OOM) ---
if (str_contains($lxlib, 'function kloxo_dropped_client_jobs_busy')
	&& str_contains($lxlib, 'function kloxo_kick_dropped_client_jobs')
	&& str_contains($lxserver, 'kloxo_kick_dropped_client_jobs')
	&& str_contains($lxserver, 'never run sync inline')) {
	ok('dropped-job kick remains pending/busy gated (no accept-loop fork bomb)');
} else {
	bad('dropped-job kick remains pending/busy gated (no accept-loop fork bomb)');
}

// --- upcp rewrites live FPM if an old pool remains ---
if (str_contains($kloxocp, 'ensureFpmOomGuards')
	&& str_contains($kloxocp, 'fpm_oom_guards')) {
	ok('kloxocp upcp ensures FPM OOM guards on live conf');
} else {
	bad('kloxocp upcp ensures FPM OOM guards on live conf');
}

// --- Post-tile was() must not rl_exec on Domain Home ---
$syncFn = '';
if (preg_match('/function syncToSystemCommon\(\)\s*\{.*?\n\tfunction /s', $lxclass, $mSync) === 1) {
	$syncFn = $mSync[0];
}
if ($syncFn !== ''
	&& str_contains($syncFn, '__var_no_sync')
	&& str_contains($core, 'kloxo_close_client_connection')
	&& str_contains($lxlib, 'function kloxo_close_client_connection')) {
	ok('show/list: __var_no_sync blocks syncToSystem; client connection closes before was()');
} else {
	bad('show/list: __var_no_sync blocks syncToSystem; client connection closes before was()');
}

// --- FPM must not pcntl_fork background jobs ---
$bgFn = '';
if (preg_match('/function lxshell_background\(.*?^function /sm', $linuxFs, $mBg) === 1) {
	$bgFn = $mBg[0];
}
if ($bgFn !== ''
	&& str_contains($bgFn, "fpm-fcgi")
	&& str_contains($bgFn, 'lxshell_background refused under')) {
	ok('lxshell_background refuses fork under panel FPM');
} else {
	bad('lxshell_background refuses fork under panel FPM');
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
