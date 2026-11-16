#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * AAA-wave security unit checks (php-cli only; no live panel).
 */

$root = realpath(dirname(__DIR__, 2));
if ($root === false) {
	fwrite(STDERR, "cannot resolve repo root\n");
	exit(2);
}

$failed = 0;
$passed = 0;

function expect(bool $ok, string $msg): void
{
	global $failed, $passed;
	if ($ok) {
		echo "OK  {$msg}\n";
		$passed++;
	} else {
		echo "FAIL {$msg}\n";
		$failed++;
	}
}

$html = (string) file_get_contents($root . '/kloxo/httpdocs/theme/htmllib.php');
expect(
	str_contains($html, 'security AAA1')
	&& substr_count($html, 'security AAA1') >= 3
	&& !str_contains($html, "<span title='\$alt'><img src='\$image'")
	&& !str_contains($html, "<span title='\$alt'><img src='\$_t_image'")
	&& !str_contains($html, "<span title='\$serverdiscr'>"),
	'AAA1 list-column icon title/src escape'
);

$tab = (string) file_get_contents($root . '/kloxo/httpdocs/theme/tab_feather.php');
expect(
	str_contains($html, 'security AAA2')
	&& str_contains($tab, 'security AAA2')
	&& str_contains($tab, '$urlEsc')
	&& !str_contains($tab, "<span title='\$help'>")
	&& !str_contains($html, "<span title='\$help'> <a href=\"\$url\">"),
	'AAA2 tab button title/href escape'
);

expect(
	str_contains($html, 'security AAA3')
	&& str_contains($html, 'class=([A-Za-z0-9_-]+)')
	&& str_contains($html, '$typeEsc')
	&& str_contains($html, '$valueEsc'),
	'AAA3 print_input escaped attrs'
);

expect(
	str_contains($html, 'security AAA4')
	&& substr_count($html, 'security AAA4') >= 2
	&& !str_contains($html, "<img src='\$image' width=14 height=14>\$descr[2]")
	&& !str_contains($html, "<img width=14 height=14 src='\$img'>"),
	'AAA4 toolbar/imgstr escape'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lib, 'security LL3 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5')
	&& str_contains($lib, 'security MM1 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5')
	&& str_contains($lxlib, 'security TT1 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5'),
	'AAA5 schema rawQuery residual markers'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
expect(
	str_contains($lxlib, 'security AAA6')
	&& str_contains($extSec, 'security AAA6')
	&& str_contains($toast, 'security AAA6')
	&& str_contains($lxlib, 'gated unserialize'),
	'AAA6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
