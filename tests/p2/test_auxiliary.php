<?php
/**
 * Auxiliary login: .aux class, Argon2id hash, delete/pserver flags, disable-admin.
 * Fake objects only. Live panel login UNVERIFIED.
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
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

include_once $doc . '/lib/html/include.php';

if (getClassFromName('staff.aux') === 'auxiliary'
	&& getClassFromName('admin') === 'client'
	&& getClassFromName('user@example.com') === 'mailaccount'
	&& getClassFromName('user.auxmail') === 'client'
	&& getClassFromName('box.vps') === 'vps') {
	ok('getClassFromName maps .aux suffix (not a mid-name .aux) to auxiliary');
} else {
	bad('getClassFromName maps .aux suffix (not a mid-name .aux) to auxiliary',
		getClassFromName('staff.aux') . '/' . getClassFromName('user.auxmail'));
}

$login = new Client(null, null, 'admin');
$login->cttype = 'admin';
$GLOBALS['login'] = $login;

$adminParent = new Client(null, null, 'admin');
$adminParent->cttype = 'admin';
$got = auxiliary::normalizedLoginName($adminParent, 'Staff');
if ($got === 'staff.aux') {
	ok('admin auxiliary nname is name.aux');
} else {
	bad('admin auxiliary nname is name.aux', $got);
}

$cust = new Client(null, null, 'bob');
$cust->cttype = 'customer';
$got = auxiliary::normalizedLoginName($cust, 'staff.aux');
if ($got === 'bob_staff.aux') {
	ok('customer auxiliary nname is client_name.aux');
} else {
	bad('customer auxiliary nname is client_name.aux', $got);
}

try {
	auxiliary::normalizedLoginName($adminParent, '../etc');
	bad('rejects a path-like auxiliary name');
} catch (lxException $e) {
	ok('rejects a path-like auxiliary name');
} catch (Throwable $e) {
	bad('rejects a path-like auxiliary name', $e->getMessage());
}

$param = auxiliary::add($adminParent, 'auxiliary', array(
	'nname' => 'desk',
	'password' => 'aux-pass-ok',
	'delete_flag' => 'on',
));
$hash = isset($param['password']) ? (string) $param['password'] : '';
$info = function_exists('password_get_info') ? password_get_info($hash) : null;
$algo = is_array($info) && isset($info['algo']) ? $info['algo'] : 0;
if ($param['nname'] === 'desk.aux'
	&& $param['delete_flag'] === 'on'
	&& $param['pserver_flag'] === 'off'
	&& $param['cpstatus'] === 'on'
	&& $algo
	&& check_password('aux-pass-ok', $hash)
	&& !check_password('wrong', $hash)) {
	ok('add hashes with kloxo_password_hash and defaults pserver_flag off');
} else {
	bad('add hashes with kloxo_password_hash and defaults pserver_flag off',
		var_export(array($param['nname'] ?? '', $algo, $param['pserver_flag'] ?? ''), true));
}

try {
	auxiliary::add($adminParent, 'auxiliary', array('nname' => 'x', 'password' => ''));
	bad('add refuses an empty password');
} catch (lxException $e) {
	ok('add refuses an empty password');
} catch (Throwable $e) {
	bad('add refuses an empty password', $e->getMessage());
}

$auxOff = new auxiliary(null, null, 'desk.aux');
$auxOff->delete_flag = 'off';
$auxOff->pserver_flag = 'off';
$login->__auxiliary_object = $auxOff;
$GLOBALS['login'] = $login;

if ($login->isAuxiliary() && $login->getAuxiliaryId() === 'desk.aux'
	&& $login->canSeePserver() === false
	&& $login->canAuxiliaryDelete() === false
	&& kloxo_auxiliary_may_delete($login) === false) {
	ok('auxiliary without flags cannot see servers or delete');
} else {
	bad('auxiliary without flags cannot see servers or delete');
}

$blist = array(
	array('a=delete&c=client'),
	array('a=update&sa=clientsendmessage'),
);
$filtered = kloxo_filter_auxiliary_delete_blist($blist);
$urls = array();
foreach ($filtered as $b) {
	$urls[] = is_array($b) ? $b[0] : $b;
}
if ($urls === array('a=update&sa=clientsendmessage')) {
	ok('delete buttons are stripped from list actions without delete_flag');
} else {
	bad('delete buttons are stripped from list actions without delete_flag', var_export($urls, true));
}

try {
	kloxo_assert_auxiliary_may_delete();
	bad('assert throws without delete_flag');
} catch (lxException $e) {
	ok('assert throws without delete_flag');
} catch (Throwable $e) {
	bad('assert throws without delete_flag', $e->getMessage());
}

$auxOn = new auxiliary(null, null, 'desk.aux');
$auxOn->delete_flag = 'on';
$auxOn->pserver_flag = 'on';
$login->__auxiliary_object = $auxOn;
$GLOBALS['login'] = $login;
if ($login->canSeePserver() === true && $login->canAuxiliaryDelete() === true) {
	ok('auxiliary with flags can see servers and delete');
} else {
	bad('auxiliary with flags can see servers and delete');
}

unset($login->__auxiliary_object);
$GLOBALS['login'] = $login;
if ($login->canSeePserver() === true && $login->canAuxiliaryDelete() === true
	&& kloxo_auxiliary_may_delete($login) === true) {
	ok('plain admin keeps server and delete rights');
} else {
	bad('plain admin keeps server and delete rights');
}

$srcAux = (string) file_get_contents($doc . '/lib/client/auxiliarylib.php');
$srcLx = (string) file_get_contents($doc . '/lib/php/lxlib.php');
$srcMenu = (string) file_get_contents($doc . '/theme/skin/simplicity/default/menu.php');
$srcDel = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
$srcPserv = (string) file_get_contents($doc . '/driver/pserver/pservercorelib.php');
$srcIdx = (string) file_get_contents($doc . '/lib/php/index.php');
$srcCore = (string) file_get_contents($doc . '/lib/client/clientcorelib.php');

if (str_contains($srcIdx, 'getClassFromName') && str_contains($srcLx, "cse(\$name, \".aux\")")) {
	ok('login resolves .aux through getClassFromName suffix match');
} else {
	bad('login resolves .aux through getClassFromName suffix match');
}

if (str_contains($srcDel, 'kloxo_assert_auxiliary_may_delete')
	&& str_contains($srcDel, 'kloxo_filter_auxiliary_delete_blist')) {
	ok('list delete UI and action go through delete_flag');
} else {
	bad('list delete UI and action go through delete_flag');
}

if (str_contains($srcMenu, 'canSeePserver')
	&& str_contains($srcPserv, 'auxiliary_does_not_have_server_privilege')) {
	ok('Server menu and pserver list require pserver_flag');
} else {
	bad('Server menu and pserver list require pserver_flag');
}

if (str_contains($srcCore, 'kcount($list)')
	&& str_contains($srcCore, 'should_create_auxiliary_id_before_disabling_admin')) {
	ok('Disable Admin Login still requires at least one auxiliary');
} else {
	bad('Disable Admin Login still requires at least one auxiliary');
}

if (str_contains($srcLx, 'isAuxiliary()') && str_contains($srcLx, "cpstatus")) {
	ok('disabled auxiliary cpstatus is checked even when acting as admin');
} else {
	bad('disabled auxiliary cpstatus is checked even when acting as admin');
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
