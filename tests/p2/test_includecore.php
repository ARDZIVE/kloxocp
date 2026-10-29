<?php
/**
 * Characterization of includecore.php DOCUMENT_ROOT derivation.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function ok(string $d): void { global $pass; $pass++; printf("  ok   %s\n", $d); }
function bad(string $d, string $x = ''): void { global $fail; $fail++; printf("  FAIL %s\n", $d); if ($x !== '') { printf("       %s\n", $x); } }

function dprint($m, $dbg = 0)
{
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/index.php';
unset($_SERVER['DOCUMENT_ROOT']);

require dirname(__DIR__, 2) . '/kloxo/httpdocs/lib/html/includecore.php';

if (isset($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] === '/var/www/html') {
	ok('DOCUMENT_ROOT from SCRIPT_FILENAME minus SCRIPT_NAME');
} else {
	bad('DOCUMENT_ROOT from SCRIPT_FILENAME minus SCRIPT_NAME', (string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
}

if (function_exists('getreal') && getreal('lib/php/common.inc') === '/var/www/html/lib/php/common.inc') {
	ok('getreal prepends DOCUMENT_ROOT');
} else {
	bad('getreal prepends DOCUMENT_ROOT');
}

if (function_exists('getOs') && getOs() === 'Linux') {
	ok('getOs is Linux');
} else {
	bad('getOs is Linux');
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
