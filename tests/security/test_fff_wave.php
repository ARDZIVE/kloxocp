#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * FFF-wave security unit checks (php-cli only; no live panel).
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
	str_contains($html, 'security FFF1')
	&& substr_count($html, 'security FFF1') >= 2
	&& str_contains($html, '$clickAddEsc')
	&& !str_contains($html, "\$cdesc .= \" for \$parent->nname\";"),
	'FFF1 list add/search skin/unique_name escape'
);

expect(
	str_contains($html, 'security FFF2')
	&& str_contains($html, "preg_replace('/[^A-Za-z0-9_]/', '', (string) \$unique_name) ?: 'list'"),
	'FFF2 pagination unique_name allow-list'
);

expect(
	str_contains($html, 'security FFF3')
	&& str_contains($html, '$nameJs')
	&& str_contains($html, 'new Menu(<?= json_encode($nameJs')
	&& !str_contains($html, "new Menu('<?= \$name ?>',130)"),
	'FFF3 print_menulist new Menu escape'
);

expect(
	str_contains($html, 'security FFF4')
	&& str_contains($html, 'htmlspecialchars((string) $obj->nname')
	&& !str_contains($html, 'value="<?= $obj->nname ?>"'),
	'FFF4 checkbox nname escape'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lib, 'security LL3 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5 / CCC5 / DDD5 / EEE5 / FFF5')
	&& str_contains($lib, 'security MM1 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5 / CCC5 / DDD5 / EEE5 / FFF5')
	&& str_contains($lxlib, 'security TT1 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5 / CCC5 / DDD5 / EEE5 / FFF5'),
	'FFF5 schema rawQuery residual markers'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
expect(
	str_contains($lxlib, 'security FFF6')
	&& str_contains($extSec, 'security FFF6')
	&& str_contains($toast, 'security FFF6')
	&& str_contains($lxlib, 'gated unserialize'),
	'FFF6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
