<?php
/**
 * Master/slave add-server guards: nname, addform, listen address, password hash.
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

if (!is_callable(array('pservercore', 'nnameIsValid'))) {
	bad('pservercore::nnameIsValid exists', 'method missing');
} else {
	$good = array('localhost', 'slave.example.com', '37.59.173.71', 'a-b.cd');
	$badn = array('', 'host;rm', '../etc', '::1', '-bad', str_repeat('a', 254));
	$okg = true;
	foreach ($good as $n) {
		if (!pservercore::nnameIsValid($n)) {
			$okg = false;
			bad('nname accepts host/IPv4', $n);
			break;
		}
	}
	if ($okg) {
		ok('nname accepts host/IPv4');
	}
	$okb = true;
	foreach ($badn as $n) {
		if (pservercore::nnameIsValid($n)) {
			$okb = false;
			bad('nname rejects junk', $n);
			break;
		}
	}
	if ($okb) {
		ok('nname rejects junk');
	}
}

try {
	$form = pservercore::addform(null, 'pserver');
	if (is_array($form) && isset($form['variable']) && is_array($form['variable'])
		&& array_key_exists('nname', $form['variable'])
		&& array_key_exists('ps_password', $form['variable'])
		&& ($form['action'] ?? '') === 'add') {
		ok('pserver addform has hostname and password');
	} else {
		bad('pserver addform has hostname and password');
	}
} catch (Throwable $e) {
	bad('pserver addform has hostname and password', $e->getMessage());
}

$parent = new stdClass();
$inline = pservercore::AddListForm($parent, 'pserver');
if ($inline === null) {
	ok('AddListForm is null without isAdmin()');
} else {
	bad('AddListForm is null without isAdmin()');
}

if (is_callable('kloxo_ssl_listen_address')) {
	$addr = kloxo_ssl_listen_address();
	if ($addr === '127.0.0.1') {
		ok('master SSL listen address is 127.0.0.1');
	} else {
		bad('master SSL listen address is 127.0.0.1', (string)$addr);
	}
} else {
	bad('master SSL listen address is 127.0.0.1', 'function missing');
}

if (is_callable('kloxo_password_hash') && is_callable('check_password')) {
	$hash = kloxo_password_hash('slave-pass-ok');
	if (is_string($hash) && $hash !== '' && check_password('slave-pass-ok', $hash)
		&& !check_password('wrong', $hash) && !check_password('', $hash)) {
		ok('kloxo_password_hash round-trips through check_password');
	} else {
		bad('kloxo_password_hash round-trips through check_password');
	}
	$crypt = crypt('legacy-pass', '$1$abcd1234$');
	if (is_string($crypt) && check_password('legacy-pass', $crypt) && !check_password('nope', $crypt)) {
		ok('check_password still accepts crypt hashes');
	} else {
		bad('check_password still accepts crypt hashes');
	}
} else {
	bad('kloxo_password_hash round-trips through check_password', 'functions missing');
	bad('check_password still accepts crypt hashes', 'functions missing');
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
