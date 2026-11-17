<?php
/**
 * Add Domain fast path: SQLite write + redirect stub, then domain-sync.request.
 * (php-cli source invariants; no live panel / nginx.)
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
$core = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
$wd = (string) file_get_contents($doc . '/lib/php/kloxo_watchdog.php');
$js = (string) file_get_contents($doc . '/theme/js/kloxo-busy.js');
$html = (string) file_get_contents($doc . '/theme/htmllib.php');
$runner = (string) @file_get_contents($root . '/kloxo/bin/common/run-dropped-client-jobs.php');
$serverLib = (string) file_get_contents($doc . '/lib/html/lxserverlib.php');

$acAdd = '';
if (preg_match('/function __ac_desc_add\(.*?^function /sm', $core, $m) === 1) {
	$acAdd = $m[0];
}
if ($acAdd !== ''
	&& str_contains($acAdd, "\$class === 'client' || \$class === 'domain' || \$class === 'subdomain'")
	&& str_contains($acAdd, 'kloxo_hold_background_spawn(true)')
	&& str_contains($acAdd, 'kloxo_skip_was_now')
	&& str_contains($acAdd, 'kloxo_finish_domain_add($nname, $object)')) {
	ok('__ac_desc_add holds spawn/skip-was and finishes domain with parent');
} else {
	bad('__ac_desc_add holds spawn/skip-was and finishes domain with parent');
}

$fin = '';
if (preg_match('/function kloxo_finish_domain_add\(.*?^function /sm', $lxlib, $m) === 1) {
	$fin = $m[0];
}
// Client::$__desc_domain_l is "RqdtB" — parent->was() skips domain children.
// Must write the domain object itself (domain_l / subdomain_l), like Add Client.
$clientLib = (string) file_get_contents($doc . '/lib/client/clientlib.php');
$lxclass = (string) file_get_contents($doc . '/lib/php/lxclass.php');
$writeChild = '';
if (preg_match('/function writeAChildList\(.*?^[\t ]*(?:final )?function /sm', $lxclass, $mWc) === 1) {
	$writeChild = $mWc[0];
}
if ($fin !== ''
	&& str_contains($fin, 'kloxo_skip_was_now')
	&& str_contains($fin, 'kloxo_queue_domain_system_sync')
	&& str_contains($fin, 'kloxo_emit_local_redirect')
	&& str_contains($fin, 'kloxo_close_client_connection')
	&& str_contains($fin, 'kloxo_kick_dropped_client_jobs')
	&& str_contains($fin, 'exit(0)')
	&& str_contains($fin, "domain_l")
	&& str_contains($fin, 'subdomain_l')
	&& str_contains($fin, '$dom->was()')
	&& str_contains($fin, 'readonly')
	&& !str_contains($fin, 'kloxo_flush_held_background_jobs')
	&& !str_contains($fin, "callInBackground('kloxo_domain_system_sync'")
	&& preg_match('/\$__desc_domain_l\s*=\s*array\s*\(\s*"R/', $clientLib) === 1
	&& $writeChild !== ''
	&& str_contains($writeChild, 'csa($desc[0], "r")')) {
	ok('kloxo_finish_domain_add writes the domain child (client domain_l is readonly)');
} else {
	bad('kloxo_finish_domain_add writes the domain child (client domain_l is readonly)');
}

$queue = '';
if (preg_match('/function kloxo_queue_domain_system_sync\(.*?^function /sm', $lxlib, $m) === 1) {
	$queue = $m[0];
}
if ($queue !== ''
	&& str_contains($queue, 'domain-sync.request')
	&& str_contains($queue, 'FILE_APPEND | LOCK_EX')
	&& str_contains($queue, '/^[A-Za-z0-9._-]{1,253}$/')) {
	ok('kloxo_queue_domain_system_sync appends a validated nname drop file');
} else {
	bad('kloxo_queue_domain_system_sync appends a validated nname drop file');
}

$sync = '';
if (preg_match('/function kloxo_domain_system_sync\(.*?^function /sm', $lxlib, $m) === 1) {
	$sync = $m[0];
}
if ($sync !== ''
	&& str_contains($sync, "dbaction = 'syncadd'")
	&& str_contains($sync, "'web'")
	&& str_contains($sync, "'dns'")
	&& str_contains($sync, "'mmail'")
	&& str_contains($sync, '->was()')) {
	ok('kloxo_domain_system_sync reloads domain and syncadds web/dns/mmail');
} else {
	bad('kloxo_domain_system_sync reloads domain and syncadds web/dns/mmail');
}

$drain = '';
if (preg_match('/function kloxo_run_dropped_client_jobs\(.*?^function /sm', $lxlib, $m) === 1) {
	$drain = $m[0];
}
if ($drain !== ''
	&& str_contains($drain, 'domain-sync.request')
	&& str_contains($drain, 'kloxo_domain_system_sync')
	&& str_contains($drain, 'kloxo_drain_sync_request_file')
	&& str_contains($serverLib, 'kloxo_run_dropped_client_jobs')
	&& str_contains($runner, 'kloxo_run_dropped_client_jobs')
	&& str_contains($wd, 'domain-sync.request')
	&& str_contains($wd, 'runClientAddJobs')) {
	ok('backend/watchdog drain domain-sync.request via run-dropped-client-jobs');
} else {
	bad('backend/watchdog drain domain-sync.request via run-dropped-client-jobs');
}

if (str_contains($js, 'function hasToastQuery')
	&& str_contains($js, 'frm_smessage=')
	&& str_contains($js, 'frm_emessage=')
	&& preg_match('/if \(hasToastQuery\(\)\) \{\s*settled = true;/s', $js) === 1
	&& str_contains($js, 'arrived && !hasToastQuery()')
	&& str_contains($html, '?v=8')) {
	ok('busy.js clears spinner when the destination URL carries a toast');
} else {
	bad('busy.js clears spinner when the destination URL carries a toast');
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
