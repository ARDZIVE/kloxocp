#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * ZZ-wave security unit checks (php-cli only; no live panel).
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

$frame = (string) file_get_contents($root . '/kloxo/httpdocs/theme/frame_left.php');
expect(
	str_contains($frame, 'security ZZ1')
	&& str_contains($frame, "createSubMenu(xxpDescr, <?= \$labelJs ?>")
	&& !str_contains($frame, 'state_v_')
	&& !str_contains($frame, 'rdesc .= "<tr'),
	'ZZ1 Usage per-item plain-text createSubMenu'
);

$html = (string) file_get_contents($root . '/kloxo/httpdocs/theme/htmllib.php');
expect(
	str_contains($html, 'security ZZ2')
	&& str_contains($html, "href=\"javascript:document.' . \$formId . '.submit()\"")
	&& str_contains($html, "src=\"' . \$iconEsc . '/' . \$nameEsc . '_page.gif\"")
	&& !str_contains($html, 'href=javascript:document.form{$name}_page_$place.submit()')
	&& !str_contains($html, '<img src=$iconpath/{$name}_page.gif'),
	'ZZ2 pagination quoted/escaped href+src'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
expect(
	str_contains($lib, 'security ZZ3')
	&& str_contains($lib, "array('/usr/sbin/vzmigrate')")
	&& !str_contains($lib, '"vzmigrate $ssh_string -r yes $newserver $vpsid"'),
	'ZZ3 exec_vzmigrate argv-only'
);

$core = (string) file_get_contents($root . '/kloxo/httpdocs/theme/coredisplaylib.php');
expect(
	str_contains($core, 'security ZZ4')
	&& substr_count($core, 'security ZZ4') >= 2
	&& !str_contains($core, '<span title=\'$k is {$ob->$k}\'><img src=\'$img\'')
	&& !str_contains($core, '<span title=\'Account is Demo\'><img src=\'$_timg\'></span>'),
	'ZZ4 coredisplaylib status icon escape'
);

$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lib, 'security LL3 / VV5 / WW5 / XX5 / YY5 / ZZ5')
	&& str_contains($lib, 'security MM1 / VV5 / WW5 / XX5 / YY5 / ZZ5')
	&& str_contains($lxlib, 'security TT1 / VV5 / WW5 / XX5 / YY5 / ZZ5'),
	'ZZ5 schema rawQuery residual markers'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
expect(
	str_contains($lxlib, 'security ZZ6')
	&& str_contains($extSec, 'security ZZ6')
	&& str_contains($toast, 'security ZZ6')
	&& str_contains($lxlib, 'gated unserialize'),
	'ZZ6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
