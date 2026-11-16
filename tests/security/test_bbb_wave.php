#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * BBB-wave security unit checks (php-cli only; no live panel).
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
	str_contains($html, 'security BBB1')
	&& str_contains($html, 'preg_replace_callback')
	&& !str_contains($html, "<img title='{\$variable}\$1' src='\$1'")
	&& !str_contains($html, "<a title='{\$variable}\$2' class='insidelist' target='_blank' href='//\$1'>")
	&& !str_contains($html, "href='\$url'> \$2 </a>"),
	'BBB1 _lx* markup escape'
);

expect(
	str_contains($html, 'security BBB2')
	&& substr_count($html, 'security BBB2') >= 2
	&& !str_contains($html, "href='\$fullurl'> \$matches[2] </a>")
	&& !str_contains($html, "href='\$fullurl'><span style='font-weight:bold'>\$matches[2]</span></a>"),
	'BBB2 replace_url / help <url:> escape'
);

expect(
	str_contains($html, 'security BBB3')
	&& str_contains($html, "preg_replace('/[^A-Za-z0-9_]/', '', (string) \$formname)")
	&& str_contains($html, '$labelEsc')
	&& !str_contains($html, "href=\"javascript:document.form_\$formname.submit()\""),
	'BBB3 icon formname/label escape'
);

$tab = (string) file_get_contents($root . '/kloxo/httpdocs/theme/tab_feather.php');
$tabSimp = (string) file_get_contents($root . '/kloxo/httpdocs/theme/tab_simplicity.php');
expect(
	str_contains($tab, 'security BBB4')
	&& str_contains($tabSimp, 'security BBB4')
	&& !str_contains($tab, "\t\$targetstring = \$target;")
	&& !str_contains($tabSimp, "\t\$targetstring = \$target;"),
	'BBB4 tab targetstring allow-list'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lib, 'security LL3 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5')
	&& str_contains($lib, 'security MM1 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5')
	&& str_contains($lxlib, 'security TT1 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5'),
	'BBB5 schema rawQuery residual markers'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
expect(
	str_contains($lxlib, 'security BBB6')
	&& str_contains($extSec, 'security BBB6')
	&& str_contains($toast, 'security BBB6')
	&& str_contains($lxlib, 'gated unserialize'),
	'BBB6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
