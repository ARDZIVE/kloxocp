<?php
/**
 * First-login session restore must not assign __session_id on a null $login.
 * php-cli only. Does not start Hiawatha.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function ok(string $d): void { global $pass; $pass++; printf("  ok   %s\n", $d); }
function bad(string $d, string $x = ''): void { global $fail; $fail++; printf("  FAIL %s\n", $d); if ($x !== '') { printf("       %s\n", $x); } }

$root = dirname(__DIR__, 2) . '/kloxo';
$doc = $root . '/httpdocs';
$lib = (string) file_get_contents($doc . '/lib/php/lxlib.php');

if (str_contains($lib, 'function kloxo_restore_cookie_login')
	&& str_contains($lib, 'kloxo_restore_cookie_login($classname, $clientname, $session_id)')
	&& str_contains($lib, 'if (!is_object($login))')
) {
	ok('initProgramlib restores cookies only through kloxo_restore_cookie_login');
} else {
	bad('initProgramlib restores cookies only through kloxo_restore_cookie_login');
}

if (str_contains($lib, 'function kloxo_login_class_allowed')
	&& str_contains($lib, "'client', 'mailaccount', 'auxiliary'")) {
	ok('login class names are allow-listed');
} else {
	bad('login class names are allow-listed');
}

$idx = (string) file_get_contents($doc . '/lib/php/index.php');
if (str_contains($idx, 'is_object($login)')
	&& str_contains($idx, '/login/?frm_emessage=login_error')) {
	ok('login POST redirects when get_login does not set $login');
} else {
	bad('login POST redirects when get_login does not set $login');
}

putenv('KLOXO_ROOT=' . $root);
$_SERVER['DOCUMENT_ROOT'] = $doc;
chdir($doc);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

try {
	include_once $doc . '/lib/html/include.php';
} catch (Throwable $e) {
	bad('include.php loads', $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
	echo "$pass passed, $fail failed\n";
	exit(1);
}

if (!function_exists('kloxo_restore_cookie_login') || !function_exists('get_login')) {
	bad('login helpers are loaded');
	echo "$pass passed, $fail failed\n";
	exit(1);
}
ok('login helpers are loaded');

if (kloxo_login_class_allowed('client') && kloxo_login_class_allowed('Client')
	&& !kloxo_login_class_allowed('sqlite') && !kloxo_login_class_allowed('')) {
	ok('allow-list accepts client and rejects sqlite');
} else {
	bad('allow-list accepts client and rejects sqlite');
}

$threw = false;
$ret = null;
try {
	$ret = get_login('', '');
} catch (Throwable $e) {
	$threw = true;
	bad('get_login empty class returns 0', $e->getMessage());
}
if (!$threw && $ret === 0) {
	ok('get_login empty class returns 0');
} elseif (!$threw) {
	bad('get_login empty class returns 0', 'got ' . var_export($ret, true));
}

global $login;
$login = 'sentinel';
$out = null;
$threw = false;
try {
	$out = kloxo_restore_cookie_login('notaclass', 'admin', 'sess1');
} catch (Throwable $e) {
	$threw = true;
	bad('unknown cookie class does not fatal', $e->getMessage());
}
if (!$threw && $out === null) {
	ok('unknown cookie class does not fatal');
} elseif (!$threw) {
	bad('unknown cookie class does not fatal', 'got ' . var_export($out, true));
}

$login = 'sentinel';
$out = null;
$threw = false;
try {
	$out = kloxo_restore_cookie_login('auxiliary', 'nobody.aux', 'sess2');
} catch (Throwable $e) {
	$threw = true;
	bad('auxiliary cookie without a parent does not assign __session_id on null',
		$e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}
if ($threw) {
	// already recorded
} elseif ($out !== null) {
	bad('auxiliary cookie without a parent does not assign __session_id on null',
		'got a session object');
} elseif (is_object($login) && isset($login->__session_id) && $login->__session_id === 'sess2'
	&& !isset($login->__auxiliary_object)) {
	bad('auxiliary cookie without a parent does not assign __session_id on null',
		'assigned session id on a non-auxiliary login');
} else {
	ok('auxiliary cookie without a parent does not assign __session_id on null');
}

$login = null;
$out = null;
$threw = false;
try {
	$out = kloxo_restore_cookie_login('client', 'admin', 'sess3');
} catch (Throwable $e) {
	$threw = true;
	bad('client cookie restore does not fatal when get_login fails',
		$e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}
if (!$threw) {
	ok('client cookie restore does not fatal when get_login fails');
}

$incSrc = (string) file_get_contents($doc . '/login/inc.php');
$pageSrc = (string) file_get_contents($root . '/file/pages/login_inc.php');
if (str_contains($incSrc, 'login welcome text')
	&& str_contains($incSrc, 'defaultLoginMessage')
	&& str_contains($incSrc, 'catch (Throwable')
	&& str_contains($pageSrc, 'login welcome text')
	&& str_contains($pageSrc, 'defaultLoginMessage')) {
	ok('login/inc soft-fails welcome DB read so the form still prints');
} else {
	bad('login/inc soft-fails welcome DB read so the form still prints');
}

$missing = sys_get_temp_dir() . '/kloxo-login-nodb-' . getmypid();
@mkdir($missing, 0700, true);
$prevVar = getenv('KLOXO_VAR_LIB');
putenv('KLOXO_VAR_LIB=' . $missing);
$_ENV['KLOXO_VAR_LIB'] = $missing;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/login/';
$_SERVER['PHP_SELF'] = '/login/index.php';
$prevCwd = getcwd();
chdir($doc . '/login');
ob_start();
$renderFatal = '';
try {
	include $doc . '/login/index.php';
} catch (Throwable $e) {
	$renderFatal = $e->getMessage();
}
$html = (string) ob_get_clean();
chdir(is_string($prevCwd) ? $prevCwd : $doc);
putenv('KLOXO_VAR_LIB');
unset($_ENV['KLOXO_VAR_LIB']);
if ($prevVar !== false && $prevVar !== '') {
	putenv('KLOXO_VAR_LIB=' . $prevVar);
	$_ENV['KLOXO_VAR_LIB'] = $prevVar;
}
@rmdir($missing);
if ($renderFatal === ''
	&& str_contains($html, 'name="frm_clientname"')
	&& str_contains($html, 'name="frm_password"')
	&& str_contains($html, 'Login')) {
	ok('login page shows the form when panel.sqlite is missing');
} else {
	bad('login page shows the form when panel.sqlite is missing',
		$renderFatal !== '' ? $renderFatal : ('len=' . strlen($html)
			. ' form=' . (str_contains($html, 'frm_clientname') ? 'yes' : 'no')));
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
