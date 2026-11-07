<?php
/**
 * Mail Account > Set Auto Responder with no autoresponders threw an lxException
 * that do_updateform's generic catch hid as "The requested form could not be loaded."
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

global $login;
$login = new Client(null, 'localhost', 'admin');
$login->nname = 'admin';
$m = new Mailaccount(null, 'localhost', 'info@mesrob.com');
$m->nname = 'info@mesrob.com';
$m->__listtype_autoresponder = 'fullist';
$m->autoresponder_l = array();
try {
	$m->updateform('autores', array());
	bad('autores form with no autoresponders throws a readable lxException');
} catch (lxException $e) {
	ok('autores form with no autoresponders throws a readable lxException');
} catch (Throwable $e) {
	bad('autores form with no autoresponders throws a readable lxException', get_class($e));
}

$a = new Autoresponder(null, 'localhost', 'x___info@mesrob.com');
$a->autores_name = 'x';
$m->autoresponder_l = array('x___info@mesrob.com' => $a);
try {
	$f = $m->updateform('autores', array());
	if (isset($f['autores_name'][1]['x___info@mesrob.com'])) {
		ok('autores form lists the existing autoresponder');
	} else {
		bad('autores form lists the existing autoresponder');
	}
} catch (Throwable $e) {
	bad('autores form lists the existing autoresponder', $e->getMessage());
}

$src = (string)file_get_contents($doc . '/theme/coredisplaylib.php');
if (preg_match('/catch \(lxException \$e\) \{[^}]*do_updateform[^}]*\$e->getMessage\(\)/s', $src)) {
	ok('show page prints an lxException message from do_updateform');
} else {
	bad('show page prints an lxException message from do_updateform');
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
