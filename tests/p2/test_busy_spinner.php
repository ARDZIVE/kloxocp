<?php
/**
 * Busy modal: animated GIF spinner while a Services icon action runs.
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
$js = (string) @file_get_contents($doc . '/theme/js/kloxo-busy.js');
$gif = (string) @file_get_contents($doc . '/theme/img/kloxo-spinner.gif');
$html = (string) file_get_contents($doc . '/theme/htmllib.php');
$notice = (string) file_get_contents($root . '/NOTICE');

$frames = substr_count($gif, "\x21\xF9\x04");   // graphic control extension per frame
if (str_starts_with($gif, 'GIF89a') && $frames >= 8 && str_contains($gif, 'NETSCAPE2.0')) {
	ok('spinner is an animated, looping GIF (' . $frames . ' frames)');
} else {
	bad('spinner is an animated, looping GIF', 'frames=' . $frames);
}
if (!is_dir($doc . '/theme/loadingio') && !str_contains($notice, 'Loading.io')) {
	ok('no Loading.io asset or attribution is left');
} else {
	bad('no Loading.io asset or attribution is left');
}
if (str_contains($html, 'kloxo-busy.js')) {
	ok('kloxo-busy.js is included on every panel page');
} else {
	bad('kloxo-busy.js is included on every panel page');
}
$core = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
$lxlib = (string) file_get_contents($doc . '/lib/php/lxlib.php');
$libHtml = (string) file_get_contents($doc . '/lib/html/lib.php');
$linuxFs = (string) file_get_contents($doc . '/lib/html/linuxfslib.php');
$serverLib = (string) file_get_contents($doc . '/lib/html/lxserverlib.php');
$wd = (string) file_get_contents($doc . '/lib/php/kloxo_watchdog.php');
$runner = (string) @file_get_contents($root . '/kloxo/bin/common/run-dropped-client-jobs.php');
$finishFn = '';
if (preg_match('/function kloxo_finish_client_add\(.*?^function /sm', $lxlib, $mFin) === 1) {
	$finishFn = $mFin[0];
}
$emitFn = '';
if (preg_match('/function kloxo_emit_local_redirect\(.*?^function /sm', $lxlib, $mEm) === 1) {
	$emitFn = $mEm[0];
}
$stubFn = '';
if (preg_match('/function kloxo_redirect_stub_html\(.*?^function /sm', $lxlib, $mSt) === 1) {
	$stubFn = $mSt[0];
}
$initFn = '';
if (preg_match('/function do_display_init\(.*?^function /sm', $core, $mInit) === 1) {
	$initFn = $mInit[0];
}
if (str_contains($core, 'kloxo_finish_client_add')
	&& str_contains($core, 'kloxo_hold_background_spawn(true)')
	&& str_contains($core, 'function kloxo_flush_deferred_chrome_for_form')
	&& str_contains($core, 'kloxo_flush_deferred_chrome_for_form()')
	&& $initFn !== ''
	&& str_contains($initFn, "\$act === 'continue'")
	&& str_contains($initFn, '__kloxo_defer_chrome')
	&& $finishFn !== ''
	&& str_contains($finishFn, 'kloxo_ensure_client_unix')
	&& str_contains($finishFn, 'kloxo_queue_client_system_sync')
	&& str_contains($finishFn, 'kloxo_release_held_background_to_queue')
	&& !str_contains($finishFn, 'kloxo_flush_held_background_jobs')
	&& !str_contains($finishFn, "callInBackground('kloxo_client_system_sync'")
	&& $emitFn !== ''
	&& str_contains($emitFn, 'http_response_code(200)')
	&& !str_contains($emitFn, 'http_response_code(303)')
	&& !str_contains($emitFn, "header('Location:")
	&& $stubFn !== ''
	&& str_contains($stubFn, 'sessionStorage.removeItem("kloxo-busy")')
	&& str_contains($stubFn, 'mainframe')
	&& str_contains($lxlib, 'function kloxo_run_dropped_client_jobs')
	&& str_contains($lxlib, 'client-sync.request')
	&& str_contains($serverLib, 'kloxo_kick_dropped_client_jobs')
	&& str_contains($wd, 'runClientAddJobs')
	&& str_contains($runner, 'kloxo_run_dropped_client_jobs')
	&& str_contains($libHtml, 'function kloxo_hold_background_spawn')
	&& preg_match('/function lxshell_background\(.*?^function /sm', $linuxFs, $bgFn) === 1
	&& str_contains($bgFn[0], 'setsid')
	&& !str_contains($bgFn[0], 'ionice -c3')) {
	ok('Add Client defers continue chrome, returns 200 stub, drop-queues sync');
} else {
	bad('Add Client defers continue chrome, returns 200 stub, drop-queues sync');
}

// Add Domain / Subdomain: same fast path — SQLite write + 200 stub, then
// domain-sync.request for nginx/DNS/mail (never hold Please wait... on was()).
$domFin = '';
if (preg_match('/function kloxo_finish_domain_add\(.*?^function /sm', $lxlib, $mDom) === 1) {
	$domFin = $mDom[0];
}
if ($domFin !== ''
	&& str_contains($domFin, 'kloxo_skip_was_now')
	&& str_contains($domFin, 'kloxo_queue_domain_system_sync')
	&& str_contains($domFin, 'kloxo_emit_local_redirect')
	&& str_contains($domFin, 'kloxo_kick_dropped_client_jobs')
	&& str_contains($domFin, '$dom->was()')
	&& str_contains($domFin, 'domain_l')
	&& !str_contains($domFin, 'kloxo_flush_held_background_jobs')
	&& str_contains($lxlib, 'function kloxo_queue_domain_system_sync')
	&& str_contains($lxlib, 'domain-sync.request')
	&& str_contains($lxlib, 'function kloxo_domain_system_sync')
	&& str_contains($core, 'kloxo_finish_domain_add')
	&& str_contains($core, "\$class === 'domain' || \$class === 'subdomain'")
	&& str_contains($wd, 'domain-sync.request')
	&& str_contains($js, 'function hasToastQuery')
	&& str_contains($js, 'frm_smessage=')
	&& str_contains($js, 'hasToastQuery()')
	&& str_contains($html, 'kloxo-busy.js')
	&& str_contains($html, "'?v=9'")
	&& str_contains($js, 'MAX_AGE = 8')
	&& str_contains($js, 'function clearBusyNow')
	&& str_contains($js, 'DELAY * 4')) {
	ok('Add Domain returns 200 stub, writes domain child, drop-queues sync; toast clears busy');
} else {
	bad('Add Domain returns 200 stub, writes domain child, drop-queues sync; toast clears busy');
}

// Domain add must not treat every deferred was() failure as Backup success
// ("Domain was added successfully" with an empty Domains list).
$lxFrm = '';
if (preg_match('/try \{\s*\$login->was\(\);.*?^[\t ]*if \(\$login->isAuxiliary/sm', $core, $mWas) === 1) {
	$lxFrm = $mWas[0];
}
if ($lxFrm !== ''
	&& str_contains($lxFrm, 'kloxo_is_long_backend_update')
	&& str_contains($lxFrm, 'post-sync local write')
	&& str_contains($lxFrm, 'throw $wasEx')
	&& !str_contains($lxFrm, "empty(\$gbl->__kloxo_defer_chrome) || empty(\$gbl->__this_redirect)")) {
	ok('was() failures after Add Domain are not swallowed as success');
} else {
	bad('was() failures after Add Domain are not swallowed as success');
}
if (str_contains($js, '/theme/img/kloxo-spinner.gif') && str_contains($js, 'KloxoBusy')
	&& str_contains($js, "'pageshow'")) {
	ok('JS shows the GIF and hides on pageshow');
} else {
	bad('JS shows the GIF and hides on pageshow');
}

$login = (string) file_get_contents($doc . '/login/index.php');
if (str_contains($login, '/theme/js/kloxo-busy.js')) {
	ok('the login page includes kloxo-busy.js');
} else {
	bad('the login page includes kloxo-busy.js');
}
// Click/submit must beginNavigation (clear settled) or the spinner never shows
// again after the first page's DOMContentLoaded.
if (str_contains($js, "addEventListener('beforeunload'") && str_contains($js, 'sessionStorage')
	&& str_contains($js, "'DOMContentLoaded'") && str_contains($js, 'arrivedFully')
	&& str_contains($js, 'doc.documentElement')
	&& str_contains($js, 'function beginNavigation')
	&& str_contains($js, 'settled = false')) {
	ok('JS shows the modal on click/submit and resets settled so later navigations work');
} else {
	bad('JS shows the modal on click/submit and resets settled so later navigations work');
}
// Stuck Please wait... after Add Domain: refresh must not re-arm when settled;
// pageshow clears a finished page; print_redirect must not use HTTP 303 Location.
$beforeFn = '';
if (preg_match("/addEventListener\\('beforeunload',\\s*function\\s*\\([^)]*\\)\\s*\\{.*?\\}\\);/s", $js, $mBu) === 1) {
	$beforeFn = $mBu[0];
}
if ($beforeFn !== '' && str_contains($beforeFn, 'if (settled)') && str_contains($beforeFn, 'flagClear()')
	&& str_contains($js, 'kloxo-busy-suppress') && str_contains($js, 'function isSuppressed')
	&& str_contains($beforeFn, 'isSuppressed()')) {
	ok('beforeunload does not re-arm busy after the page has settled');
} else {
	bad('beforeunload does not re-arm busy after the page has settled');
}
$coreDisp = (string) @file_get_contents($doc . '/theme/coredisplaylib.php');
if (str_contains($coreDisp, 'kloxo-busy-suppress')
	&& str_contains($coreDisp, '__refresh_lpanel')
	&& str_contains($coreDisp, 'KloxoBusy.hide')) {
	ok('lpanel frame refresh suppresses busy so Domain Home is not hyper-busy');
} else {
	bad('lpanel frame refresh suppresses busy so Domain Home is not hyper-busy');
}
if (str_contains($js, "addEventListener('pageshow'")
	&& str_contains($js, "doc.readyState === 'complete'")
	&& str_contains($js, '!isImmediateRefresh()')) {
	ok('pageshow clears stuck spinner on a finished page');
} else {
	bad('pageshow clears stuck spinner on a finished page');
}
$redirFn = '';
if (preg_match('/function print_redirect\(\$redirect_url.*?\n\tfunction /s', $html, $mPr) === 1) {
	$redirFn = $mPr[0];
}
if ($redirFn !== ''
	&& str_contains($redirFn, 'kloxo_emit_local_redirect')
	&& !str_contains($redirFn, "http_response_code(303)")
	&& !str_contains($redirFn, "header('Location:")) {
	ok('print_redirect uses 200 stub (no 303 Location) so busy clears');
} else {
	bad('print_redirect uses 200 stub (no 303 Location) so busy clears');
}
if (str_contains($js, 'data-kloxo-nobusy') && str_contains($js, "'download'") && str_contains($js, 'mailto:')
	&& str_contains($js, 'hasChosenFile') && !str_contains($js, "enctype === 'multipart/form-data'") && str_contains($js, 'frm_subaction=download')) {
	ok('JS skips downloads, mailto, targets and forms with a chosen file, but not every multipart form (the panel renders all forms as multipart)');
} else {
	bad('JS skips downloads, mailto, targets and forms with a chosen file, but not every multipart form');
}
// Quick pages: DELAY show timer; MAX_WAIT failsafe so a hung POST cannot leave
// the modal forever (password change / add client used to stick). No CSS fade.
// settled/navToken stop a late timer from resurrecting the modal after DCL.
$code = (string)preg_replace('#/\*.*?\*/#s', '', $js);
$setTimeouts = substr_count($code, 'setTimeout(');
if (str_contains($code, 'function showSoon(') && str_contains($code, 'armMaxWait')
	&& preg_match('/var DELAY = \d+;/', $code) === 1
	&& preg_match('/var MAX_WAIT = \d+/', $code) === 1
	&& str_contains($code, 'settled') && str_contains($code, 'navToken')
	&& str_contains($code, 'function isImmediateRefresh')
	&& str_contains($code, 'function followRedirectStub')
	&& str_contains($code, 'location.replace')
	&& !str_contains($code, 'animation')
	&& $setTimeouts >= 2 && $setTimeouts <= 3) {
	ok('modal appears after DELAY ms; MAX_WAIT failsafe; redirect stubs navigate');
} else {
	bad('modal appears after DELAY ms; MAX_WAIT failsafe; redirect stubs navigate', 'setTimeouts=' . $setTimeouts);
}

// The hrefs the Services list really renders must match the JS patterns.
if (preg_match('~var serviceAct = /(.+)/;~', $js, $m1) && preg_match('~var serviceObj = /(.+)/;~', $js, $m2)) {
	$act = '~' . str_replace('~', '\~', $m1[1]) . '~';
	$obj = '~' . str_replace('~', '\~', $m2[1]) . '~';
	$all = true;
	foreach (array('start', 'stop', 'restart', 'toggle_state', 'toggle_boot_state') as $sa) {
		$href = '/display.php?frm_action=update&frm_subaction=' . $sa
			. '&frm_o_o[0][class]=pserver&frm_o_o[0][nname]=localhost&frm_o_o[1][class]=service&frm_o_o[1][nname]=nginx___localhost';
		if (!preg_match($act, $href) || !preg_match($obj, $href)) {
			$all = false;
		}
	}
	$other = '/display.php?frm_action=update&frm_subaction=start&frm_o_o[0][class]=pserver&frm_o_o[0][nname]=localhost&frm_o_o[1][class]=web&frm_o_o[1][nname]=x';
	if ($all && !preg_match($obj, $other)) {
		ok('URL patterns match the five Services icon links and nothing else');
	} else {
		bad('URL patterns match the five Services icon links and nothing else');
	}
} else {
	bad('URL patterns found in JS');
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
