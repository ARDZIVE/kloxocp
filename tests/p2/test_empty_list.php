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

if (function_exists('kloxo_hostname') && kloxo_hostname() !== '') {
	ok('kloxo_hostname returns a host name');
} else {
	bad('kloxo_hostname returns a host name');
}

if (function_exists('kloxo_machine') && kloxo_machine() !== '') {
	ok('kloxo_machine returns a machine type');
} else {
	bad('kloxo_machine returns a machine type');
}

$errT = sys_get_temp_dir() . '/kloxo-lfile-put-' . getmypid() . '.txt';
@unlink($errT);
$wrote = lfile_put_contents($errT, 'x');
$okWrite = ($wrote === true || $wrote === 1) && is_file($errT) && file_get_contents($errT) === 'x';
@unlink($errT);
if ($okWrite) {
	ok('lfile_put_contents writes without a flags argument');
} else {
	bad('lfile_put_contents writes without a flags argument');
}

$blocked = $doc . '/.php.err';
@unlink($blocked);
lfile_put_contents($blocked, 'should-not-land-here');
if (!is_file($blocked)) {
	ok('lfile_put_contents does not write httpdocs/.php.err');
} else {
	@unlink($blocked);
	bad('lfile_put_contents does not write httpdocs/.php.err');
}

if (function_exists('get_namelist_from_objectlist') && get_namelist_from_objectlist(null) === array()) {
	ok('get_namelist_from_objectlist accepts null');
} else {
	bad('get_namelist_from_objectlist accepts null');
}

$quota = new Client(null, 'localhost', 'admin');
$quota->used = null;
$quota->priv = null;
$slash = $quota->getUSlashP('pserver_num');
if ($slash === '0/Unlimited') {
	ok('getUSlashP works when used and priv are missing');
} else {
	bad('getUSlashP works when used and priv are missing', $slash);
}

$child = array('__title_keep' => 'keep');
$quota->getAlistFromChild(null, $child);
if (isset($child['__title_keep']) && $child['__title_keep'] === 'keep') {
	ok('getAlistFromChild accepts a missing child');
} else {
	bad('getAlistFromChild accepts a missing child');
}

$g = new Gbllib();
$g->driver = array('localhost' => array());
$prevLogin = isset($login) ? $login : null;
$login = $quota;
try {
	$sync = $g->getSyncClass('localhost', 'localhost', 'web');
	if ($sync === null) {
		ok('getSyncClass returns null when the driver row is missing');
	} else {
		bad('getSyncClass returns null when the driver row is missing', var_export($sync, true));
	}
} catch (Throwable $e) {
	bad('getSyncClass returns null when the driver row is missing', $e->getMessage());
}
$login = $prevLogin;

$html = new HtmlLib();
try {
	$url = $html->getFullUrl('a=show');
	if (is_string($url) && str_contains($url, 'frm_action=show')) {
		ok('getFullUrl works when frm_o_o is missing');
	} else {
		bad('getFullUrl works when frm_o_o is missing', var_export($url, true));
	}
} catch (Throwable $e) {
	bad('getFullUrl works when frm_o_o is missing', $e->getMessage());
}

$home = new Client(null, 'localhost', 'admin');
$home->cttype = 'admin';
$home->priv = null;
$home->used = null;
if ($home->checkButton('webhosting_flag') === true) {
	ok('checkButton is true when priv is missing');
} else {
	bad('checkButton is true when priv is missing');
}

try {
	$rlist = $home->createShowRlist(null);
	ok('createShowRlist does not fatal when priv is missing');
} catch (Throwable $e) {
	bad('createShowRlist does not fatal when priv is missing', $e->getMessage());
}

$login = $home;
$langf = $doc . '/lang/en-us/langfunctionlib.php';
if (is_file($langf)) {
	include_once $langf;
}
try {
	$path = '';
	$post = array('frm_action' => 'list', 'frm_o_cname' => 'pserver');
	$class = $name = $identity = null;
	$descr = $html->getActionDescr($path, $post, $class, $name, $identity);
	if (is_array($descr) && isset($descr[2]) && (string)$descr[2] !== '') {
		ok('getActionDescr labels a pserver list button');
	} else {
		bad('getActionDescr labels a pserver list button', var_export($descr, true));
	}
} catch (Throwable $e) {
	bad('getActionDescr labels a pserver list button', $e->getMessage());
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
