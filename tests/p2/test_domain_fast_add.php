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
$drainFile = '';
if (preg_match('/function kloxo_drain_sync_request_file\(.*?^function /sm', $lxlib, $m) === 1) {
	$drainFile = $m[0];
}
$bgPhp = (string) file_get_contents($root . '/kloxo/bin/common/background.php');
$coreDisp = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
$htmlLib = (string) file_get_contents($doc . '/theme/htmllib.php');
if ($drain !== ''
	&& str_contains($drain, 'domain-sync.request')
	&& str_contains($drain, 'kloxo_domain_system_sync')
	&& str_contains($drain, 'kloxo_drain_sync_request_file')
	&& $drainFile !== ''
	&& str_contains($drainFile, 'posix_geteuid')
	&& str_contains($drainFile, "PHP_SAPI === 'cli'")
	&& str_contains($serverLib, 'kloxo_kick_dropped_client_jobs')
	&& !str_contains($serverLib, 'kloxo_run_dropped_client_jobs()')
	&& str_contains($runner, 'kloxo_run_dropped_client_jobs')
	&& str_contains($runner, 'LOCK_EX | LOCK_NB')
	&& str_contains($lxlib, 'kloxo_dropped_client_jobs_pending')
	&& str_contains($lxlib, 'kloxo_dropped_client_jobs_busy')
	&& str_contains($wd, 'domain-sync.request')
	&& str_contains($wd, 'runClientAddJobs')
	&& str_contains($bgPhp, 'kloxo_domain_system_sync')
	&& str_contains($bgPhp, 'kloxo_client_system_sync')) {
	ok('backend kicks gated runner; drain allow-lists sync; single-flight lock');
} else {
	bad('backend kicks gated runner; drain allow-lists sync; single-flight lock');
}

if (str_contains($js, 'function hasToastQuery')
	&& str_contains($js, 'frm_smessage=')
	&& str_contains($js, 'frm_emessage=')
	&& str_contains($js, 'function clearBusyNow')
	&& str_contains($js, 'MAX_AGE = 8')
	&& str_contains($js, 'arrived && !hasToastQuery()')
	&& str_contains($html, '?v=9')) {
	ok('busy.js clears spinner on toast URL and drops stale flags on refresh');
} else {
	bad('busy.js clears spinner on toast URL and drops stale flags on refresh');
}

$domSrc = (string) file_get_contents($doc . '/lib/domain/domainlib.php');
$docrootOk = false;
if (preg_match('/if \(\$var === \'docroot\'\) \{.*?\n\t\treturn parent::display/s', $domSrc, $mDoc) === 1) {
	// Active docroot branch ends before return parent::display; must not load web.
	$docrootOk = str_contains($mDoc[0], 'web_o')
		&& !preg_match('/^\s*\$web = \$this->getObject\(/m', $mDoc[0]);
}
if ($docrootOk
	&& str_contains($fin, 'web_o')
	&& str_contains($coreDisp, "addEventListener('load'")
	&& str_contains($coreDisp, 'kloxoRefreshLpanel')
	&& str_contains($htmlLib, 'isset($obj->used) && is_object($obj->used)')
	&& str_contains($htmlLib, '@ob_flush()')) {
	ok('Domains list: no getObject(web), deferred lpanel, safe used/priv, row flush');
} else {
	bad('Domains list: no getObject(web), deferred lpanel, safe used/priv, row flush');
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
