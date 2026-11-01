<?php
/**
 * Fresh admin has no allowedip/blockedip rows. PHP 8 count(null) threw
 * after login in Lxclass::initListIfUndef (lxclass.php:1330).
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function ok(string $d): void { global $pass; $pass++; printf("  ok   %s\n", $d); }
function bad(string $d, string $x = ''): void
{
	global $fail;
	$fail++;
	printf("  FAIL %s\n", $d);
	if ($x !== '') {
		printf("       %s\n", $x);
	}
}

$root = dirname(__DIR__, 2) . '/kloxo';
$doc = $root . '/httpdocs';
putenv('KLOXO_ROOT=' . $root);
$_SERVER['DOCUMENT_ROOT'] = $doc;
chdir($doc);
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', '0');

include_once $doc . '/lib/html/include.php';

$client = new Client(null, 'localhost', 'admin');
$method = new ReflectionMethod(Lxclass::class, 'setListFromArray');
$method->setAccessible(true);
$method->invoke($client, 'localhost', 'localhost', 'allowedip', null);

if (is_array($client->allowedip_l) && count($client->allowedip_l) === 0) {
	ok('empty allowedip result is an array');
} else {
	bad('empty allowedip result is an array', gettype($client->allowedip_l ?? null));
}

$method->invoke($client, 'localhost', 'localhost', 'blockedip', array());
if (is_array($client->blockedip_l) && count($client->blockedip_l) === 0) {
	ok('empty blockedip result is an array');
} else {
	bad('empty blockedip result is an array');
}

$client->__listtype_allowedip = 'fullist';
$client->allowedip_l = array();
if ($client->isAllowed() === true) {
	ok('isAllowed is true when allowedip is empty');
} else {
	bad('isAllowed is true when allowedip is empty');
}

$client->__listtype_blockedip = 'fullist';
$client->blockedip_l = array();
if ($client->isBlocked() === false) {
	ok('isBlocked is false when blockedip is empty');
} else {
	bad('isBlocked is false when blockedip is empty');
}

if (function_exists('kcount') && kcount(null) === 0 && kcount(array('a')) === 1) {
	ok('kcount accepts null');
} else {
	bad('kcount accepts null');
}

if (function_exists('kloxo_html') && kloxo_html(null) === '' && str_contains(kloxo_html('<x>'), '&lt;')) {
	ok('kloxo_html accepts null');
} else {
	bad('kloxo_html accepts null');
}

if (function_exists('kloxo_strftime') && kloxo_strftime('%m', 0) === '01' && kloxo_strftime('%Y', 0) === '1970') {
	ok('kloxo_strftime works without strftime()');
} else {
	bad('kloxo_strftime works without strftime()', function_exists('kloxo_strftime') ? kloxo_strftime('%m', 0) : 'missing');
}

if (function_exists('get_namelist_from_objectlist') && get_namelist_from_objectlist(null) === array()) {
	ok('get_namelist_from_objectlist accepts null');
} else {
	bad('get_namelist_from_objectlist accepts null');
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
