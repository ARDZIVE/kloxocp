#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * X-wave security unit checks (php-cli only; no live panel).
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

$tmp = sys_get_temp_dir() . '/kloxo-xwave-' . bin2hex(random_bytes(4));
@mkdir($tmp . '/etc/conf', 0750, true);
$keyHex = bin2hex(random_bytes(32));
file_put_contents($tmp . '/etc/conf/realpass.key', $keyHex . "\n");
chmod($tmp . '/etc/conf/realpass.key', 0640);

$sgbl = new class($tmp) {
	public string $__path_program_root;
	public function __construct(string $r)
	{
		$this->__path_program_root = $r;
	}
};
$GLOBALS['sgbl'] = $sgbl;

// Load only the seal helpers from lxlib (avoid full panel bootstrap).
$lx = file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
if ($lx === false) {
	fwrite(STDERR, "cannot read lxlib.php\n");
	exit(2);
}
if (!preg_match(
	'/function kloxo_realpass_key_path\(\).*?function kloxo_password_hash\(/s',
	$lx,
	$m
)) {
	fwrite(STDERR, "cannot extract realpass helpers\n");
	exit(2);
}
$block = $m[0];
$block = preg_replace('/function kloxo_password_hash\($/', '', $block);
eval($block);

require_once $root . '/kloxo/httpdocs/lib/html/remote_auth_gate.php';

// X1 seal/open
$plain = 's3cret-pass!';
$sealed = kloxo_realpass_seal($plain);
expect(str_starts_with($sealed, '__lxen2:'), 'X1 seal uses __lxen2:');
expect(kloxo_realpass_open($sealed) === $plain, 'X1 open round-trip');
expect(kloxo_realpass_open('__lxen:' . base64_encode('legacy')) === 'legacy', 'X1 legacy __lxen: still opens');
expect(!str_contains($sealed, $plain), 'X1 ciphertext does not contain plaintext');

// X2 panel class filter
expect(kloxo_unserialize_panel_class_ok('dns_record_a'), 'X2 allows dns_record_a');
expect(kloxo_unserialize_panel_class_ok('priv'), 'X2 allows priv');
expect(kloxo_unserialize_panel_class_ok('Remote'), 'X2 allows Remote');
expect(kloxo_unserialize_panel_class_ok('LxaClass'), 'X2 allows LxaClass (domain/mmail sync)');
$GLOBALS['gl_class_array'] = array('mmail' => 'lib/domain/mmail.php');
expect(kloxo_unserialize_panel_class_ok('mmail__exim'), 'X2 allows Class__driver when base in gl');
expect(!kloxo_unserialize_panel_class_ok('orphan__driver'), 'X2 rejects Class__driver without base in gl');
expect(!kloxo_unserialize_panel_class_ok('Phar'), 'X2 rejects Phar');
expect(!kloxo_unserialize_panel_class_ok('Evil\\Gadget'), 'X2 rejects namespaced');
expect(!kloxo_unserialize_panel_class_ok('evilgadget'), 'Z6 rejects unknown lowercase');
$u = kloxo_unserialize_deny_gadgets(serialize((object) ['a' => 1]));
expect(is_object($u), 'X2 unserializes stdClass');
expect(kloxo_unserialize_deny_gadgets('O:4:"Phar":0:{}') === false, 'X2 rejects Phar blob');

// X3 createDbPass algorithm (mirror clientbaselib; source also checked)
$key = kloxo_realpass_key_bytes();
$a = substr(hash_hmac('sha256', 'hello', $key), 0, 16);
$b = substr(hash_hmac('sha256', 'hello', $key), 0, 16);
$c = substr(hash_hmac('sha256', 'other', $key), 0, 16);
expect(strlen($a) === 16 && ctype_xdigit($a), 'X3 HMAC length/hex');
expect($a === $b, 'X3 HMAC deterministic');
expect($a !== $c, 'X3 HMAC differs per password');
expect($a !== substr(md5('hello'), 0, 10), 'X3 not md5 prefix');
$src = file_get_contents($root . '/kloxo/httpdocs/lib/client/clientbaselib.php');
expect(is_string($src) && str_contains($src, 'hash_hmac') && !preg_match(
	'/function createDbPass\(\$pass\)\s*\{[^}]*md5\(/s',
	$src
), 'X3 createDbPass source uses HMAC not md5');

// Cleanup
@unlink($tmp . '/etc/conf/realpass.key');
@rmdir($tmp . '/etc/conf');
@rmdir($tmp);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
