#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CCC-wave security unit checks (php-cli only; no live panel).
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
	str_contains($html, 'security CCC1')
	&& substr_count($html, 'security CCC1') >= 2
	&& !str_contains($html, "<a href='\$1' target='_blank' style='text-decoration:underline'>"),
	'CCC1 nomodify/warning https auto-link escape'
);

$frame = (string) file_get_contents($root . '/kloxo/httpdocs/theme/frame_top.php');
expect(
	str_contains($frame, 'security CCC2')
	&& str_contains($frame, 'basename')
	&& !str_contains($frame, "title='<?= \$desc ?>'")
	&& !str_contains($frame, "top.mainframe.location='\$url'"),
	'CCC2 frame_top print_one_link escape'
);

expect(
	str_contains($html, 'security CCC3')
	&& str_contains($html, 'json_encode(\'/display.php?\' . $__upload_redir_qs')
	&& !str_contains($html, "echo \$__upload_redir_qs;"),
	'CCC3 uploadRedirectUrl json_encode'
);

expect(
	str_contains($html, 'security CCC4')
	&& str_contains($html, '$ckeName')
	&& str_contains($html, '$ckeValue')
	&& !str_contains($html, 'name="<?=$variable->name;?>"')
	&& !str_contains($html, "CKEDITOR.replace( '<?=\$variable->name;?>'"),
	'CCC4 CKEditor name/value/config escape'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lib, 'security LL3 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5 / CCC5')
	&& str_contains($lib, 'security MM1 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5 / CCC5')
	&& str_contains($lxlib, 'security TT1 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5 / CCC5'),
	'CCC5 schema rawQuery residual markers'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
expect(
	str_contains($lxlib, 'security CCC6')
	&& str_contains($extSec, 'security CCC6')
	&& str_contains($toast, 'security CCC6')
	&& str_contains($lxlib, 'gated unserialize'),
	'CCC6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
