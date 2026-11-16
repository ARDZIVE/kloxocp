#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * GGG-wave security unit checks (php-cli only; no live panel).
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

$core = (string) file_get_contents($root . '/kloxo/httpdocs/theme/coredisplaylib.php');
expect(
	str_contains($core, 'security GGG1')
	&& substr_count($core, 'security GGG1') >= 2
	&& str_contains($core, '$urlEsc')
	&& !str_contains($core, "href='<?=\$url?>'")
	&& !str_contains($core, 'href="<?=$url?>"'),
	'GGG1 breadcrumb / HyperVM back escape'
);

$html = (string) file_get_contents($root . '/kloxo/httpdocs/theme/htmllib.php');
expect(
	str_contains($html, 'security GGG2')
	&& str_contains($html, '$targetAttr')
	&& str_contains($html, '$pathEsc')
	&& !str_contains($html, 'name="form_<?= $form_name ?>" <?= $target ?> method="post" action="<?= $path ?>"'),
	'GGG2 icon form name/path/target escape'
);

expect(
	str_contains($html, 'security GGG3')
	&& !str_contains($html, 'new FCKeditor($variable->name)')
	&& str_contains($html, '$taName')
	&& str_contains($html, '$taValue'),
	'GGG3 FCKeditor removed / textarea escaped'
);

expect(
	str_contains($html, 'security GGG4')
	&& str_contains($html, '$fileNameEsc')
	&& !str_contains($html, 'name="<?= $variable->name ?>" id="<?= $variable->name ?>"'),
	'GGG4 file upload name/id escape'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lib, 'security LL3 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5 / CCC5 / DDD5 / EEE5 / FFF5 / GGG5')
	&& str_contains($lib, 'security MM1 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5 / CCC5 / DDD5 / EEE5 / FFF5 / GGG5')
	&& str_contains($lxlib, 'security TT1 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5 / CCC5 / DDD5 / EEE5 / FFF5 / GGG5'),
	'GGG5 schema rawQuery residual markers'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
expect(
	str_contains($lxlib, 'security GGG6')
	&& str_contains($extSec, 'security GGG6')
	&& str_contains($toast, 'security GGG6')
	&& str_contains($lxlib, 'gated unserialize'),
	'GGG6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
