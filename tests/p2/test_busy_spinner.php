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
	&& str_contains($stubFn, 'sessionStorage.setItem(k,JSON.stringify(f))')
	&& !str_contains($stubFn, 'sessionStorage.removeItem("kloxo-busy")')
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
	&& (str_contains($html, "'?v=15'") || str_contains($html, '"?v=15"'))
	&& str_contains($js, 'MAX_AGE = 3 * 60 * 1000')
	&& str_contains($js, 'MAX_WAIT = 3 * 60 * 1000')
	&& str_contains($js, 'DELAY = 300')
	&& str_contains($js, 'WAIT_TEXTS')
	&& str_contains($js, 'Still working...')
	&& str_contains($js, 'function clearBusyNow')
	&& str_contains($js, 'function startRotate')
	&& str_contains($js, 'function isHeavy')
	&& str_contains($js, 'function armNav')
	&& str_contains($js, 'switchprogram')
	&& str_contains($js, "t === 'mainframe'")
	&& str_contains($js, 'Services always take noticeable time')) {
	ok('Add Domain returns 200 stub, writes domain child, drop-queues sync; toast clears busy');
} else {
	bad('Add Domain returns 200 stub, writes domain child, drop-queues sync; toast clears busy');
}

// Domain add must not treat every deferred was() failure as Backup success
// ("Domain was added successfully" with an empty Domains list).
$lxFrm = '';
if (preg_match('/function lx_frm_inc\(.*?^function /sm', $core, $mLx) === 1) {
	$lxFrm = $mLx[0];
}
if ($lxFrm !== ''
	&& str_contains($lxFrm, 'kloxo_is_long_backend_update')
	&& str_contains($lxFrm, 'post-sync local write')
	&& str_contains($lxFrm, 'throw $wasEx')
	&& str_contains($lxFrm, '$login->was()')
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
// Click/submit must beginNavigation (clear settled + clear suppress) or the
// spinner never shows again after DCL / Domain Home leftover suppress.
$beginFn = '';
if (preg_match('/function beginNavigation\(\s*text,\s*shown\s*\)\s*\{.*?^\tfunction /sm', $js, $mBegin) === 1) {
	$beginFn = $mBegin[0];
}
if (str_contains($js, "addEventListener('beforeunload'") && str_contains($js, 'sessionStorage')
	&& str_contains($js, "'DOMContentLoaded'") && str_contains($js, 'arrivedFully')
	&& str_contains($js, 'doc.documentElement')
	&& str_contains($js, 'function beginNavigation')
	&& str_contains($js, 'function clearSuppress')
	&& str_contains($js, 'settled = false')
	&& $beginFn !== ''
	&& str_contains($beginFn, 'clearSuppress()')
	&& !str_contains($beginFn, 'isSuppressed()')
	&& str_contains($js, "overlay.style.display = 'flex'")) {
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
$initShow = '';
if (preg_match('/function do_display_init\(\)\s*\{.*?^function /sm', $coreDisp, $mInitShow) === 1) {
	$initShow = $mInitShow[0];
}
// Tile homes clear busy after tiles; raw-print pages (Fail2Ban, SSH) clear after
// showRawPrint/forms/lists so the spinner stays for heavy content. No 8s suppress.
// __refresh_lpanel still sets a short suppress for automatic frame reloads.
$homeSuppress8s = preg_match(
	'/kloxoBusyCleared.*?kloxo-busy-suppress.*?8000/s',
	$coreDisp
) === 1;
if (str_contains($coreDisp, 'kloxo-busy-suppress')
	&& str_contains($coreDisp, '__refresh_lpanel')
	&& str_contains($coreDisp, 'KloxoBusy.hide')
	&& str_contains($coreDisp, 'NEVER auto-reload left/top frames')
	&& !str_contains($coreDisp, 'leftframe.location.reload')
	&& !str_contains($coreDisp, 'top.topframe&&top.topframe.location')
	&& str_contains($coreDisp, 'sessionStorage.removeItem("kloxo-busy")')
	&& str_contains($coreDisp, '$kloxoBusyCleared')
	&& str_contains($coreDisp, 'empty($kloxoBusyCleared)')
	&& str_contains($coreDisp, 'raw-print')
	&& !$homeSuppress8s
	&& $initShow !== ''
	&& str_contains($initShow, 'set_time_limit(45)')
	&& str_contains($initShow, "__var_no_sync = true")) {
	ok('no frame auto-reload after Domain Home; show caps time and skips sync');
} else {
	bad('no frame auto-reload after Domain Home; show caps time and skips sync');
}
if (str_contains($js, "addEventListener('pageshow'")
	&& str_contains($js, 'ev.persisted')
	&& str_contains($js, '!isImmediateRefresh()')
	&& str_contains($js, 'flagTouch')) {
	ok('pageshow only clears bfcache restore; in-flight busy is kept');
} else {
	bad('pageshow only clears bfcache restore; in-flight busy is kept');
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
// Quick pages: DELAY show timer; long MAX_WAIT so Switch Program / apt do not
// drop the spinner early; WAIT_TEXTS rotate; Esc dismisses. No CSS fade.
$code = (string)preg_replace('#/\*.*?\*/#s', '', $js);
$setTimeouts = substr_count($code, 'setTimeout(');
$armFn = '';
if (preg_match('/function armMaxWait\(\)\s*\{.*?^\tfunction /sm', $js, $mArm) === 1) {
	$armFn = $mArm[0];
}
if (str_contains($code, 'function showSoon(') && str_contains($code, 'armMaxWait')
	&& preg_match('/var DELAY = 300;/', $code) === 1
	&& preg_match('/var MAX_WAIT = 3 \* 60 \* 1000;/', $code) === 1
	&& preg_match('/var MAX_AGE = 3 \* 60 \* 1000;/', $code) === 1
	&& str_contains($code, 'WAIT_TEXTS')
	&& str_contains($code, 'Still working...')
	&& str_contains($code, 'function startRotate')
	&& str_contains($code, 'function isHeavy')
	&& str_contains($code, 'function armNav')
	&& str_contains($code, 'switchprogram')
	&& str_contains($code, 'settled') && str_contains($code, 'navToken')
	&& str_contains($code, 'function isImmediateRefresh')
	&& str_contains($code, 'function followRedirectStub')
	&& str_contains($code, 'location.replace')
	&& !str_contains($code, 'animation')
	&& $armFn !== '' && str_contains($armFn, 'clearBusyNow()')
	&& str_contains($js, "ev.key !== 'Escape'")
	&& $setTimeouts >= 3 && $setTimeouts <= 6) {
	ok('modal appears after 300ms; heavy/Services immediate; long MAX_WAIT + rotating text');
} else {
	bad('modal appears after 300ms; heavy/Services immediate; long MAX_WAIT + rotating text', 'setTimeouts=' . $setTimeouts);
}

// The hrefs the Services list really renders must match the JS patterns.
if (preg_match('~var serviceAct = /(.+)/[gimuy]*;~', $js, $m1)
	&& preg_match('~var serviceObj = /(.+)/[gimuy]*;~', $js, $m2)
	&& str_contains($js, "t === 'mainframe'")
	&& str_contains($js, 'present(text)')) {
	$act = '~' . str_replace('~', '\~', $m1[1]) . '~i';
	$obj = '~' . str_replace('~', '\~', $m2[1]) . '~i';
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

// Heavy pages/actions must arm the spinner immediately (isHeavy).
if (preg_match('~var heavyRe = /(.+)/[gimuy]*;~', $js, $mH)) {
	$heavy = '~' . str_replace('~', '\~', $mH[1]) . '~i';
	$heavyOk = true;
	foreach (array(
		'/display.php?frm_action=update&frm_subaction=switchprogram',
		'/display.php?frm_action=update&frm_subaction=backup',
		'/display.php?frm_action=show&frm_o_o[1][class]=fail2ban',
		'/display.php?frm_action=list&frm_o_cname=service',
		'frm_subaction=restore_confirm_confirm',
	) as $u) {
		if (!preg_match($heavy, $u)) {
			$heavyOk = false;
		}
	}
	if ($heavyOk && !preg_match($heavy, '/display.php?frm_action=show&frm_o_o[0][class]=client')) {
		ok('heavy URL patterns cover Switch Program / Backup / Fail2Ban / Services');
	} else {
		bad('heavy URL patterns cover Switch Program / Backup / Fail2Ban / Services');
	}
} else {
	bad('heavy URL patterns cover Switch Program / Backup / Fail2Ban / Services', 'heavyRe missing');
}

// Mutating GET links (Re Read IPaddress, list icons) must POST with CSRF.
if (preg_match('~var mutateAct = /(.+)/[gimuy]*;~', $js, $mMut)
	&& str_contains($js, 'mutateAct.test(href)')
	&& str_contains($html, 'kloxoCsrfToken')
	&& str_contains($html, "\$post['frm_action']")
	&& str_contains($html, "\$act === 'update' || \$act === 'add' || \$act === 'delete'")
	&& str_contains($html, 'name="frm_token"')) {
	$mut = '~' . str_replace('~', '\~', $mMut[1]) . '~i';
	$mutOk = preg_match($mut, '/display.php?frm_action=update&frm_subaction=readipaddress')
		&& preg_match($mut, '/display.php?frm_action=delete&frm_o_cname=ipaddress')
		&& !preg_match($mut, '/display.php?frm_action=updateform&frm_subaction=password')
		&& !preg_match($mut, '/display.php?frm_action=addform&frm_o_cname=domain')
		&& !preg_match($mut, '/display.php?frm_action=list&frm_o_cname=ipaddress')
		&& !preg_match($mut, '/display.php?frm_action=show');
	if ($mutOk) {
		ok('mutateAct POSTs update/add/delete GET links; updateform/addform/list stay GET');
	} else {
		bad('mutateAct POSTs update/add/delete GET links; updateform/addform/list stay GET');
	}
} else {
	bad('mutateAct POSTs update/add/delete GET links; updateform/addform/list stay GET', 'pattern or CSRF wiring missing');
}

$menu = (string) file_get_contents($doc . '/theme/skin/simplicity/default/menu.php');
if (str_contains($menu, 'function kloxo_menu_post_link')
	&& str_contains($menu, "frm_subaction' => 'readipaddress'")
	&& !preg_match('/href="\/display\.php\?frm_action=update&frm_subaction=readipaddress/', $menu)) {
	ok('Re Read IPaddress menu uses POST + frm_token, not a GET href');
} else {
	bad('Re Read IPaddress menu uses POST + frm_token, not a GET href');
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
