#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * EEE-wave security unit checks (php-cli only; no live panel).
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
	str_contains($core, 'security EEE1')
	&& substr_count($core, 'security EEE1') >= 2
	&& !str_contains($core, 'url(<?=$col?>)')
	&& str_contains($core, '$modeEsc')
	&& str_contains($core, '$switchEsc'),
	'EEE1 mode-switch / desktop skin escape'
);

$html = (string) file_get_contents($root . '/kloxo/httpdocs/theme/htmllib.php');
expect(
	str_contains($html, 'security EEE2')
	&& substr_count($html, 'security EEE2') >= 2
	&& !str_contains($html, "multiSelectPopulate('<?= \$form ?>', '<?= trim(\$variablename) ?>'")
	&& !str_contains($html, "multiSelectPopulate('<?= \$form ?>', '<?= trim(\$variable->name) ?>'"),
	'EEE2 multiselect onClick allow-list'
);

expect(
	str_contains($html, 'security EEE3')
	&& str_contains($html, '$altEsc')
	&& str_contains($html, '$descEsc')
	&& !str_contains($html, "title='<?= \$alt ?>'"),
	'EEE3 tablehead sort link escape'
);

expect(
	str_contains($html, 'security EEE4')
	&& str_contains($html, '$fsForm')
	&& str_contains($html, '$fsUrl')
	&& !str_contains($html, "selectFolder(<?= trim(\$form) ?>.<?= trim(\$variable->name) ?>"),
	'EEE4 selectFolder picker escape'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lib, 'security LL3 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5 / CCC5 / DDD5 / EEE5')
	&& str_contains($lib, 'security MM1 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5 / CCC5 / DDD5 / EEE5')
	&& str_contains($lxlib, 'security TT1 / VV5 / WW5 / XX5 / YY5 / ZZ5 / AAA5 / BBB5 / CCC5 / DDD5 / EEE5'),
	'EEE5 schema rawQuery residual markers'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
expect(
	str_contains($lxlib, 'security EEE6')
	&& str_contains($extSec, 'security EEE6')
	&& str_contains($toast, 'security EEE6')
	&& str_contains($lxlib, 'gated unserialize'),
	'EEE6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
