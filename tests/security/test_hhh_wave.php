#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * HHH-wave security unit checks (php-cli only; no live panel).
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
	str_contains($html, 'security HHH1')
	&& str_contains($html, '$nameEsc')
	&& str_contains($html, '$valEsc')
	&& !str_contains($html, 'name="<?= $variable->name ?>" value="<?= $m_value ?>"'),
	'HHH1 textbox name/value/type escape'
);

expect(
	str_contains($html, 'security HHH2')
	&& str_contains($html, '$titleEsc')
	&& !str_contains($html, 'background="<?= $imgheadbg ?>"><b><span style="color:#fff"><?= $title ?></span></b>'),
	'HHH2 form_header title/imgs escape'
);

$core = (string) file_get_contents($root . '/kloxo/httpdocs/theme/coredisplaylib.php');
expect(
	str_contains($core, 'security HHH3')
	&& str_contains($core, "preg_replace('/[^0-9a-fA-F]/', '', (string) \$login->getSkinColor())"),
	'HHH3 chrome getSkinColor hex sanitize'
);

expect(
	str_contains($html, 'security HHH4')
	&& substr_count($html, 'security HHH4') >= 3
	&& !str_contains($html, '<option value="<?= $k ?>"><?= $option ?></option>')
	&& !str_contains($html, '<option value="<?= $vv ?>"><?= $vv ?></option>'),
	'HHH4 select option value/label escape'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lib, 'security LL3 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5 / CCC5 / DDD5 / EEE5 / FFF5 / GGG5 / HHH5')
	&& str_contains($lib, 'security MM1 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5 / CCC5 / DDD5 / EEE5 / FFF5 / GGG5 / HHH5')
	&& str_contains($lxlib, 'security TT1 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5 / CCC5 / DDD5 / EEE5 / FFF5 / GGG5 / HHH5'),
	'HHH5 schema rawQuery residual markers'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
expect(
	str_contains($lxlib, 'security HHH6')
	&& str_contains($extSec, 'security HHH6')
	&& str_contains($toast, 'security HHH6')
	&& str_contains($lxlib, 'gated unserialize'),
	'HHH6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
