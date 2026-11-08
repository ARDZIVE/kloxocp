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
if (str_contains($html, '"kloxo-busy.js"')) {
	ok('kloxo-busy.js is included on every panel page');
} else {
	bad('kloxo-busy.js is included on every panel page');
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
// Every navigation shows the modal and the new page shows it again from its <head> until it has all its HTML.
if (str_contains($js, "addEventListener('beforeunload'") && str_contains($js, 'sessionStorage')
	&& str_contains($js, "'DOMContentLoaded'") && str_contains($js, 'arrivedFully')
	&& str_contains($js, 'doc.documentElement')) {
	ok('JS shows the modal on beforeunload, keeps it across the page change and hides it at DOMContentLoaded');
} else {
	bad('JS shows the modal on beforeunload, keeps it across the page change and hides it at DOMContentLoaded');
}
if (str_contains($js, 'data-kloxo-nobusy') && str_contains($js, "'download'") && str_contains($js, 'mailto:')
	&& str_contains($js, 'hasChosenFile') && !str_contains($js, "enctype === 'multipart/form-data'") && str_contains($js, 'frm_subaction=download')) {
	ok('JS skips downloads, mailto, targets and forms with a chosen file, but not every multipart form (the panel renders all forms as multipart)');
} else {
	bad('JS skips downloads, mailto, targets and forms with a chosen file, but not every multipart form');
}
// Quick pages never show it: one show-delay timer (showSoon), no CSS animation (it does
// not run in an unpainted new document) and no timer that hides the modal.
$code = (string)preg_replace('#/\*.*?\*/#s', '', $js);
if (substr_count($code, 'setTimeout(') === 1 && str_contains($code, 'function showSoon(')
	&& preg_match('/var DELAY = \d+;/', $code) === 1 && !str_contains($code, 'animation')
	&& !preg_match('/setTimeout\([^)]*hide/', $code)) {
	ok('modal appears after DELAY ms through one show timer; no CSS animation, no hide time-out');
} else {
	bad('modal appears after DELAY ms through one show timer; no CSS animation, no hide time-out');
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
