#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * LLL-wave security unit checks (php-cli only; no live panel).
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
	str_contains($html, 'security LLL1')
	&& str_contains($html, '$headDesc')
	&& str_contains($html, '$headId')
	&& !str_contains($html, '<?= get_description($obj) ?> Info for <?= $obj->getId() ?>'),
	'LLL1 print_info_block escape'
);

expect(
	str_contains($html, 'security LLL2')
	&& str_contains($html, '$legParent')
	&& str_contains($html, '$parentEsc')
	&& str_contains($html, 'Escaped syncserver (security LLL2)')
	&& !str_contains($html, '<?= $login->getKeywordUc(\'no_matches_found\') ?>'),
	'LLL2 list chrome / print_machine escape'
);

expect(
	str_contains($html, 'security LLL3')
	&& str_contains($html, '$pwBoxEsc')
	&& str_contains($html, '$ownCsrf')
	&& str_contains($html, '$permCsrf'),
	'LLL3 password modal + CSRF escape'
);

expect(
	str_contains($html, 'security LLL4')
	&& str_contains($html, '$helpKwEsc')
	&& str_contains($html, '$switchEsc')
	&& str_contains($html, '$showKwEsc')
	&& str_contains($html, '$filternameEsc')
	&& str_contains($html, '$showallKw'),
	'LLL4 help/switch/show/search toolbar escape'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lib, 'KKK5 / LLL5')
	&& str_contains($lxlib, 'KKK5 / LLL5'),
	'LLL5 schema residual markers'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
expect(
	str_contains($lxlib, 'security LLL6')
	&& str_contains($extSec, 'security LLL6')
	&& str_contains($toast, 'security LLL6')
	&& str_contains($lxlib, 'gated unserialize'),
	'LLL6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
