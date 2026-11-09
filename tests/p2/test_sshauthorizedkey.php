<?php
/**
 * SSH Authorized Keys: ~/.ssh/authorized_keys parse/add/delete. Fake fs only.
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

$ed = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIDeadBeefDeadBeefDeadBeefDeadBee alice@laptop';
$rsa = 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQC7examplekeydatahereforlengthenough bob@host';
$opts = 'no-agent-forwarding,command="ls" ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAICCCCCCCCCCCCCCCCCCCCCCCCCCCCCC deploy@ci';

$p = sshauthorizedkey__sync::parseLine($ed);
if (is_array($p) && $p['type'] === 'ssh-ed25519' && $p['hostname'] === 'alice@laptop') {
	ok('parseLine reads type, key and comment');
} else {
	bad('parseLine reads type, key and comment', var_export($p, true));
}

$po = sshauthorizedkey__sync::parseLine($opts);
if (is_array($po) && $po['type'] === 'ssh-ed25519' && $po['hostname'] === 'deploy@ci') {
	ok('parseLine keeps OpenSSH options and still finds the key');
} else {
	bad('parseLine keeps OpenSSH options and still finds the key', var_export($po, true));
}

if (sshauthorizedkey__sync::parseLine('not a key') === null
	&& sshauthorizedkey__sync::parseLine('# comment') === null
	&& sshauthorizedkey__sync::parseLine('') === null) {
	ok('parseLine rejects garbage, comments and empty lines');
} else {
	bad('parseLine rejects garbage, comments and empty lines');
}

$tmp = sys_get_temp_dir() . '/kloxo-sshak-' . getmypid();
@mkdir($tmp, 0700, true);
sshauthorizedkey__sync::$fsPrefix = $tmp;

if (sshauthorizedkey__sync::authorizedKeysPath('root') === $tmp . '/root/.ssh/authorized_keys'
	&& sshauthorizedkey__sync::authorizedKeysPath('alice') === $tmp . '/home/alice/.ssh/authorized_keys') {
	ok('authorized_keys path is ~/.ssh/authorized_keys for root and clients');
} else {
	bad('authorized_keys path is ~/.ssh/authorized_keys for root and clients',
		sshauthorizedkey__sync::authorizedKeysPath('root'));
}

$badUsers = array('../etc', 'alice/../bob', 'alice/foo', "alice\0root", 'alice;root', '/root');
$rejected = 0;
foreach ($badUsers as $u) {
	try {
		sshauthorizedkey__sync::homeDir($u);
	} catch (Throwable $e) {
		if ($e->getMessage() === 'invalid_ssh_username' || str_contains($e->getMessage(), 'SSH user')) {
			$rejected++;
		}
	}
}
if ($rejected === count($badUsers)) {
	ok('homeDir rejects usernames with /, .., NUL or other path characters');
} else {
	bad('homeDir rejects usernames with /, .., NUL or other path characters',
		'rejected ' . (string) $rejected . ' of ' . (string) count($badUsers));
}

if (sshauthorizedkey__sync::getAuthorizedKey('root') === array()) {
	ok('missing authorized_keys is an empty list');
} else {
	bad('missing authorized_keys is an empty list');
}

$drv = new sshauthorizedkey__sync(null, null, 'k');
$drv->main = new stdClass();
$drv->main->username = 'root';
$drv->main->full_key = $ed;
$drv->main->syncserver = 'localhost';
$drv->main->nname = 'localhost___' . $p['nname'];
$drv->dbactionAdd();
$drv->main->full_key = $rsa;
$drv->dbactionAdd();
$list = sshauthorizedkey__sync::getAuthorizedKey('root');
$cfg = $tmp . '/root/.ssh/config';
$body = (string) file_get_contents($tmp . '/root/.ssh/authorized_keys');
if (count($list) === 2 && str_contains($body, 'alice@laptop') && str_contains($body, 'bob@host')
	&& !is_file($cfg)) {
	ok('Add appends keys to authorized_keys and does not write ssh config');
} else {
	bad('Add appends keys to authorized_keys and does not write ssh config',
		$body . ' count=' . (string) count($list));
}

$drv->main->nname = 'localhost___' . $p['nname'];
$drv->dbactionDelete();
$list2 = sshauthorizedkey__sync::getAuthorizedKey('root');
$left = array_values($list2);
if (count($left) === 1 && isset($left[0]['hostname']) && $left[0]['hostname'] === 'bob@host') {
	ok('Delete removes only the matching authorized_keys line');
} else {
	bad('Delete removes only the matching authorized_keys line', var_export($left, true));
}

$login = new class {
	public function getThrow($k)
	{
		return $k;
	}
};
$GLOBALS['login'] = $login;
$threw = false;
try {
	sshauthorizedkey::add(new stdClass(), 'sshauthorizedkey', array('full_key' => 'nope'));
} catch (lxException $e) {
	$threw = ($e->getMessage() === 'invalid_ssh_authorized_key' || str_contains($e->getMessage(), 'valid SSH'));
}
if ($threw) {
	ok('add refuses a line that is not a public key');
} else {
	bad('add refuses a line that is not a public key');
}

$form = sshauthorizedkey::addform(new stdClass(), 'sshauthorizedkey');
if (is_array($form) && isset($form['variable']['full_key']) && $form['variable']['full_key'][1] === '') {
	ok('add form is an empty Full Key paste box');
} else {
	bad('add form is an empty Full Key paste box', var_export($form, true));
}

$ps = new pserver(null, 'localhost', 'localhost');
$ps->nname = 'localhost';
$ps->username = 'root';
if (sshauthorizedkey::unixUser($ps) === 'root') {
	ok('localhost SSH keys are root authorized_keys');
} else {
	bad('localhost SSH keys are root authorized_keys', sshauthorizedkey::unixUser($ps));
}

$src = (string) file_get_contents($doc . '/driver/pserver/sshauthorizedkeylib.php');
$sync = (string) file_get_contents($doc . '/driver/pserver/sshauthorizedkey__synclib.php');
if (str_contains($src, 'rl_exec_get')
	&& str_contains($sync, '/.ssh/authorized_keys')
	&& !str_contains($sync, '.authorized_keys')
	&& !str_contains($sync, 'ssh-keygen')
	&& !str_contains($sync, '/root/.ssh/config')) {
	ok('list goes through kloxo-backend; file is authorized_keys; no keygen or ssh config');
} else {
	bad('list goes through kloxo-backend; file is authorized_keys; no keygen or ssh config');
}

foreach (glob($tmp . '/root/.ssh/*') ?: array() as $f) {
	@unlink($f);
}
@rmdir($tmp . '/root/.ssh');
@rmdir($tmp . '/root');
@rmdir($tmp);

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
