#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * MMM-wave security unit checks (php-cli only; no live panel).
 * Covers leftover list filtername/pagination/search surfaces repaired with LLL.
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
	str_contains($html, 'security LLL4 / MMM1')
	&& str_contains($html, '$filternameEsc')
	&& !str_contains($html, 'name="frm_hpfilter[<?= $filtername ?>][pagenum]"')
	&& !str_contains($html, 'name="frm_hpfilter[<?= $filtername ?>][pagesize]"')
	&& !str_contains($html, 'name="frm_hpfilter[<?= $filtername ?>][searchstring]"'),
	'MMM1 filtername HTML attrs escaped'
);

expect(
	str_contains($html, '$valueEsc')
	&& str_contains($html, '$searchCsrf')
	&& str_contains($html, '$urlEsc')
	&& str_contains($html, 'action="<?= $urlEsc ?>"'),
	'MMM2 search toolbar url/csrf/value escape'
);

expect(
	str_contains($html, 'Cannot access static variable <?= $rcEsc ?>::<?= $descEsc ?>')
	&& str_contains($html, 'Cannot access \'<?= $classEsc ?>\' class'),
	'MMM3 class error messages escaped'
);

expect(
	str_contains($html, '$permCsrf')
	&& str_contains($html, '$ownCsrf')
	&& str_contains($html, '$btnCsrf')
	&& str_contains($html, '$fancyCsrf')
	&& str_contains($html, '$searchCsrf')
	&& !str_contains($html, "value='<?= getCSRFToken(); ?>'"),
	'MMM4 CSRF tokens escaped in attrs'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lib, 'LLL5 / MMM5')
	&& str_contains($lxlib, 'LLL5 / MMM5'),
	'MMM5 schema residual markers'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
expect(
	str_contains($lxlib, 'security MMM6')
	&& str_contains($extSec, 'security MMM6')
	&& str_contains($toast, 'security MMM6')
	&& str_contains($lxlib, 'gated unserialize'),
	'MMM6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
