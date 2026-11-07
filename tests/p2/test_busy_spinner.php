<?php
/**
 * Busy modal: Loading.io "ball" spinner while a Services icon action runs.
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
$svg = (string) @file_get_contents($doc . '/theme/loadingio/fvz7y1.svg');
$html = (string) file_get_contents($doc . '/theme/htmllib.php');
$notice = (string) file_get_contents($root . '/NOTICE');

if (str_contains($svg, '<d:slug>fvz7y1</d:slug>') && substr_count($svg, '<circle') === 8) {
	ok('spinner SVG is the Loading.io fvz7y1 ball ring (8 circles)');
} else {
	bad('spinner SVG is the Loading.io fvz7y1 ball ring');
}
if (str_contains($notice, 'fvz7y1') && str_contains($notice, 'Loading.io BY License')) {
	ok('NOTICE credits Loading.io');
} else {
	bad('NOTICE credits Loading.io');
}
if (str_contains($html, '"kloxo-busy.js"')) {
	ok('kloxo-busy.js is included on every panel page');
} else {
	bad('kloxo-busy.js is included on every panel page');
}
if (str_contains($js, '/theme/loadingio/fvz7y1.svg') && str_contains($js, 'KloxoBusy')
	&& str_contains($js, "'pageshow'") && str_contains($js, 'setTimeout(api.hide')) {
	ok('JS shows the spinner, hides on pageshow and has a time-out');
} else {
	bad('JS shows the spinner, hides on pageshow and has a time-out');
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
