<?php
/**
 * Panel boot smoke: include.php must load without a Throwable.
 * Does not connect to MySQL and does not start Hiawatha.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function ok(string $d): void { global $pass; $pass++; printf("  ok   %s\n", $d); }
function bad(string $d, string $x = ''): void { global $fail; $fail++; printf("  FAIL %s\n", $d); if ($x !== '') { printf("       %s\n", $x); } }

$root = dirname(__DIR__, 2) . '/kloxo';
$doc = $root . '/httpdocs';

putenv('KLOXO_ROOT=' . $root);
$_SERVER['DOCUMENT_ROOT'] = $doc;
chdir($doc);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', '0');

try {
	include_once $doc . '/lib/html/include.php';
} catch (Throwable $e) {
	bad('include.php loads', $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
	echo "$pass passed, $fail failed\n";
	exit(1);
}

ok('include.php loads');

global $sgbl;
if (isset($sgbl) && $sgbl instanceof Sgbl) {
	ok('Sgbl constructed');
} else {
	bad('Sgbl constructed');
}

if (isset($sgbl) && realpath((string)$sgbl->__path_program_root) === realpath($root)) {
	ok('program root is the tree (not a missing /opt/kloxo)');
} else {
	bad('program root is the tree (not a missing /opt/kloxo)', isset($sgbl) ? (string)$sgbl->__path_program_root : '');
}

if (isset($sgbl) && strpos((string)$sgbl->__path_php_path, 'php') !== false) {
	ok('php path names a php binary');
} else {
	bad('php path names a php binary', isset($sgbl) ? (string)$sgbl->__path_php_path : '');
}

if (isset($sgbl) && strpos((string)$sgbl->__ver_full, '7.0.0') === 0) {
	ok('kloxoversion loaded');
} else {
	bad('kloxoversion loaded', isset($sgbl) ? (string)$sgbl->__ver_full : '');
}

if (isset($sgbl->__var_language['en-us'])) {
	ok('en-us language catalog listed');
} else {
	bad('en-us language catalog listed');
}

if (is_file($doc . '/login/index.php') && is_file($doc . '/login/inc.php')) {
	ok('login page files are present');
} else {
	bad('login page files are present');
}

$inst = function_exists('getInstalledVersion') ? (string)getInstalledVersion() : '';
$lat = function_exists('getLatestVersion') ? (string)getLatestVersion() : '';
if ($inst !== '' && $inst === $lat && strpos($inst, '7.0.0') === 0) {
	ok('update versions are local kloxoversion (no yum)');
} else {
	bad('update versions are local kloxoversion (no yum)', "installed=$inst latest=$lat");
}

$cb = array('Lxupdate', 'initThisObject');
if (is_callable($cb)) {
	ok('Lxupdate has initThisObject');
} else {
	bad('Lxupdate has initThisObject');
}

if (class_exists('dns__none') && is_callable(array('dns__none', 'installMe'))) {
	ok('dns__none loads for switchprogram');
} else {
	bad('dns__none loads for switchprogram');
}
if (class_exists('web__none') && class_exists('web__nginx')) {
	ok('web none/nginx drivers load');
} else {
	bad('web none/nginx drivers load');
}

if (function_exists('kloxo_is_stream') && kloxo_is_stream(false) === false) {
	ok('kloxo_is_stream rejects false');
} else {
	bad('kloxo_is_stream rejects false');
}

$cm = class_exists('clientmail') ? clientmail::readtotallog() : null;
if (is_array($cm)) {
	ok('clientmail log scan returns an array');
} else {
	bad('clientmail log scan returns an array');
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
