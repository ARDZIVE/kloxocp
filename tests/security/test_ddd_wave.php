#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * DDD-wave security unit checks (php-cli only; no live panel).
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
	str_contains($html, 'security DDD1')
	&& str_contains($html, '$cssSafe($style)')
	&& str_contains($html, '$esc($this->convert_message($mess))'),
	'DDD1 show_error_message style/mess sanitize'
);

$frame = (string) file_get_contents($root . '/kloxo/httpdocs/theme/frame_top.php');
expect(
	str_contains($frame, 'security DDD2')
	&& substr_count($frame, 'security DDD2') >= 2
	&& !str_contains($frame, "javascript:window.open('\$url')")
	&& !str_contains($frame, "javascript:window.open('\$helpurl/')"),
	'DDD2 frame_top ticket/help/skin escape'
);

expect(
	str_contains($html, 'security DDD3')
	&& str_contains($html, '$menuJs')
	&& !str_contains($html, "window.location='<?= \$url ?>';")
	&& !str_contains($html, "frame1+\"'<?= \$url ?>'\""),
	'DDD3 print_pmenu addMenuItem escape'
);

expect(
	str_contains($html, 'security DDD4')
	&& !str_contains($html, "onClick=\"top.mainframe.window.location='\$url'\"")
	&& !str_contains($html, "onClick=\"top.window.open('\$url')\"")
	&& !str_contains($html, "onClick=\"\$formname\""),
	'DDD4 icon onClick location/open escape'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lib, 'security LL3 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5 / CCC5 / DDD5')
	&& str_contains($lib, 'security MM1 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5 / CCC5 / DDD5')
	&& str_contains($lxlib, 'security TT1 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5 / CCC5 / DDD5'),
	'DDD5 schema rawQuery residual markers'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
expect(
	str_contains($lxlib, 'security DDD6')
	&& str_contains($extSec, 'security DDD6')
	&& str_contains($toast, 'security DDD6')
	&& str_contains($lxlib, 'gated unserialize'),
	'DDD6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
