<?php
/**
 * Characterization of kloxo_autoload (common.inc) without booting the panel.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function ok(string $d): void { global $pass; $pass++; printf("  ok   %s\n", $d); }
function bad(string $d, string $x = ''): void { global $fail; $fail++; printf("  FAIL %s\n", $d); if ($x !== '') { printf("       %s\n", $x); } }

function csb($haystack, $needle, $insensitive = 1)
{
	return strpos((string)$haystack, (string)$needle) === 0;
}
function strfrom($string, $needle)
{
	$p = strpos((string)$string, (string)$needle);
	if ($p === false) {
		return $string;
	}
	return substr((string)$string, $p + strlen((string)$needle));
}
function getreal($vpath)
{
	return $vpath;
}
function dprint($m)
{
}

$here = __DIR__;
$gl_class_array = array(
	'sampleclass' => $here . '/fixtures/sampleclass.php',
);

require dirname(__DIR__, 2) . '/kloxo/httpdocs/lib/php/common.inc';

if (function_exists('kloxo_autoload')) {
	ok('kloxo_autoload is defined');
} else {
	bad('kloxo_autoload is defined');
}
if (function_exists('__autoload')) {
	bad('__autoload is not defined');
} else {
	ok('__autoload is not defined');
}

if (class_exists('sampleclass')) {
	ok('map load: sampleclass');
} else {
	bad('map load: sampleclass');
}

$before = get_declared_classes();
class_exists('all_sampleclass');
ok('all_ prefix does not fatal');

class_exists('ghost_none');
ok('_none suffix does not fatal');

class_exists('DefinitelyNotAClass');
ok('missing class does not fatal');

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
