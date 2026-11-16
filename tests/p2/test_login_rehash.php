<?php
/**
 * First login must not wipe cttype/cpstatus when upgrading the password hash.
 * Reproduces the blank "login has been Disabled" page after fresh install.
 * php-cli + temp panel.sqlite only.
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

$repo = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/kloxo-rehash-' . getmypid();
@mkdir($tmp . '/varlib', 0700, true);
@mkdir($tmp . '/root/etc', 0700, true);
@mkdir($tmp . '/root/log', 0700, true);
symlink($repo . '/kloxo/file', $tmp . '/root/file');
symlink($repo . '/kloxo/bin', $tmp . '/root/bin');
symlink($repo . '/kloxo/httpdocs', $tmp . '/root/httpdocs');

$cmd = array(
	PHP_BINARY,
	$repo . '/kloxo/bin/install/init-panel-db',
	'--root=' . $tmp . '/root',
	'--sqlite-only',
	'--data-dir=' . $tmp . '/varlib',
);
$desc = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w'));
$p = proc_open($cmd, $desc, $pipes, null, array('KLOXO_VAR_LIB' => $tmp . '/varlib'));
if (!is_resource($p)) {
	bad('init-panel-db starts');
	echo "0 passed, 1 failed\n";
	exit(1);
}
fclose($pipes[0]);
$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$rc = proc_close($p);
$db = $tmp . '/varlib/panel.sqlite';
if ($rc !== 0 || !is_file($db)) {
	bad('init-panel-db seeds panel.sqlite', trim($out));
	echo "$pass passed, $fail failed\n";
	exit(1);
}
ok('init-panel-db seeds panel.sqlite');

$pdo = new PDO('sqlite:' . $db);
$row = $pdo->query("SELECT cttype, cpstatus, status, syncserver, password FROM client WHERE nname='admin'")
	->fetch(PDO::FETCH_ASSOC);
if (($row['cttype'] ?? '') === 'admin' && ($row['cpstatus'] ?? '') === 'on'
	&& ($row['status'] ?? '') === 'on' && ($row['syncserver'] ?? '') === 'localhost') {
	ok('fresh admin row has cttype/cpstatus/status/syncserver');
} else {
	bad('fresh admin row has cttype/cpstatus/status/syncserver', var_export($row, true));
}

putenv('KLOXO_ROOT=' . $tmp . '/root');
putenv('KLOXO_VAR_LIB=' . $tmp . '/varlib');
$_SERVER['DOCUMENT_ROOT'] = $tmp . '/root/httpdocs';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/display.php';
$_SERVER['SERVER_NAME'] = 'localhost';
chdir($tmp . '/root/httpdocs');
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
include_once $tmp . '/root/httpdocs/lib/html/include.php';

$sqlSrc = (string) file_get_contents($repo . '/kloxo/httpdocs/lib/php/sqlitelib.php');
if (str_contains($sqlSrc, 'Only SET columns the caller provided')
	&& str_contains($sqlSrc, 'array_key_exists($key, $array)')) {
	ok('createQueryStringUpdate skips columns not in the update array');
} else {
	bad('createQueryStringUpdate skips columns not in the update array');
}

// Force a legacy MD5-crypt hash so the login path must rehash (Argon2id seed
// would skip setRow and miss the wipe regression).
$legacySalt = '$1$' . substr(bin2hex(random_bytes(4)), 0, 8) . '$';
$legacy = crypt('admin', $legacySalt);
$pdo->prepare('UPDATE client SET password = ? WHERE nname = ?')->execute(array($legacy, 'admin'));

$before = $pdo->query("SELECT cttype, cpstatus, status, syncserver FROM client WHERE nname='admin'")
	->fetch(PDO::FETCH_ASSOC);
$okLogin = check_raw_password('client', 'admin', 'admin');
$after = $pdo->query("SELECT cttype, cpstatus, status, syncserver, substr(password,1,10) AS p FROM client WHERE nname='admin'")
	->fetch(PDO::FETCH_ASSOC);

if ($okLogin) {
	ok('check_raw_password accepts admin/admin');
} else {
	bad('check_raw_password accepts admin/admin');
}

if (isset($after['p']) && str_starts_with((string) $after['p'], '$argon2id')) {
	ok('legacy hash is upgraded to Argon2id on login');
} else {
	bad('legacy hash is upgraded to Argon2id on login', var_export($after['p'] ?? null, true));
}

if (($after['cttype'] ?? '') === 'admin' && ($after['cpstatus'] ?? '') === 'on'
	&& ($after['status'] ?? '') === 'on' && ($after['syncserver'] ?? '') === 'localhost') {
	ok('password rehash keeps cttype/cpstatus/status/syncserver');
} else {
	bad('password rehash keeps cttype/cpstatus/status/syncserver',
		'before=' . var_export($before, true) . ' after=' . var_export($after, true));
}

$ret = get_login('client', 'admin');
global $login;
if ($ret && is_object($login) && $login->isAdmin() && $login->isOn('cpstatus')) {
	ok('get_login after rehash yields admin with cpstatus on');
} else {
	bad('get_login after rehash yields admin with cpstatus on',
		'cttype=' . var_export($login->cttype ?? null, true)
		. ' cpstatus=' . var_export($login->cpstatus ?? null, true));
}

$lx = (string) file_get_contents($repo . '/kloxo/httpdocs/lib/php/lxlib.php');
if (str_contains($lx, "nname === 'admin'")
	&& str_contains($lx, 'div_display_die')) {
	ok('disabled check exempts nname=admin and prints a visible body');
} else {
	bad('disabled check exempts nname=admin and prints a visible body');
}

// Cleanup
@unlink($db);
passthru('rm -rf ' . escapeshellarg($tmp));

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
