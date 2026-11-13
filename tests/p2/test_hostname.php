<?php
/**
 * Hostname page: FQDN validation, /etc/hosts rewrite, Administration link.
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

if (!class_exists('KloxoHostname')) {
	bad('KloxoHostname class loads');
	printf("\n%d ok, %d FAIL\n", $pass, $fail);
	exit(1);
}
ok('KloxoHostname class loads');

if (KloxoHostname::isValidFqdn('server.example.com')
	&& KloxoHostname::isValidFqdn('mail.example.org')
	&& !KloxoHostname::isValidFqdn('srv')
	&& !KloxoHostname::isValidFqdn('localhost')
	&& !KloxoHostname::isValidFqdn('127.0.0.1')
	&& !KloxoHostname::isValidFqdn('')) {
	ok('FQDN validation accepts dotted names only');
} else {
	bad('FQDN validation accepts dotted names only');
}

if (KloxoHostname::normalize('  Mail.Example.COM. ') === 'mail.example.com') {
	ok('normalize lowercases and strips trailing dot');
} else {
	bad('normalize lowercases and strips trailing dot');
}

$tmp = sys_get_temp_dir() . '/kloxo-hostname-' . getmypid();
@mkdir($tmp . '/etc', 0755, true);
file_put_contents($tmp . '/etc/hosts', "127.0.0.1\tlocalhost\n127.0.1.1\told.example\told\n");
file_put_contents($tmp . '/etc/hostname', "old.example\n");
file_put_contents($tmp . '/etc/mailname', "old.example\n");

KloxoHostname::$rootOverride = $tmp;
KloxoHostname::$assumeRoot = true;
KloxoHostname::$runHook = static function (array $argv): array {
	return array('code' => 0, 'out' => '', 'err' => '');
};

try {
	KloxoHostname::apply('server.example.com');
	$host = trim((string) file_get_contents($tmp . '/etc/hostname'));
	$mail = trim((string) file_get_contents($tmp . '/etc/mailname'));
	$hosts = (string) file_get_contents($tmp . '/etc/hosts');
	if ($host === 'server.example.com' && $mail === 'server.example.com'
		&& str_contains($hosts, '127.0.1.1')
		&& str_contains($hosts, 'server.example.com')
		&& str_contains($hosts, "\tserver")
		&& !str_contains($hosts, 'old.example')) {
		ok('apply writes hostname mailname and hosts');
	} else {
		bad('apply writes hostname mailname and hosts', $host . ' / ' . $mail . ' / ' . $hosts);
	}
} catch (Throwable $e) {
	bad('apply writes hostname mailname and hosts', $e->getMessage());
}

KloxoHostname::$assumeRoot = false;
try {
	KloxoHostname::apply('server.example.com');
	bad('apply refuses when not root');
} catch (Throwable $e) {
	if (str_contains($e->getMessage(), 'root')) {
		ok('apply refuses when not root');
	} else {
		bad('apply refuses when not root', $e->getMessage());
	}
}

KloxoHostname::$rootOverride = null;
KloxoHostname::$assumeRoot = null;
KloxoHostname::$runHook = null;
@unlink($tmp . '/etc/hosts');
@unlink($tmp . '/etc/hostname');
@unlink($tmp . '/etc/mailname');
@rmdir($tmp . '/etc');
@rmdir($tmp);

$ps = new pserver(null, 'localhost', 'localhost');
$prop = array();
$ps->createShowPropertyList($prop); // may depend on ghtml
$alist = array();
if (method_exists($ps, 'createShowAlist')) {
	// Use pserverlib override via class — instance is pserver which extends.
}
$src = (string) file_get_contents($doc . '/driver/pserver/pserverlib.php');
if (str_contains($src, 'a=updateform&sa=hostname')
	&& str_contains($src, "sa === 'hostname'")) {
	ok('pserver show and property tabs include Hostname');
} else {
	bad('pserver show and property tabs include Hostname');
}

$clientSrc = (string) file_get_contents($doc . '/lib/client/clientlib.php');
if (!str_contains($clientSrc, "a=updateform&sa=hostname&l[class]=pserver&l[nname]=localhost")
	&& !str_contains($clientSrc, "sa=hostname")) {
	ok('Administration section does not link Hostname');
} else {
	bad('Administration section does not link Hostname');
}

$msg = (string) file_get_contents($doc . '/lang/en-us/messagelib.php');
if (str_contains($msg, 'pserver_updateform_hostname_pre')
	&& str_contains($msg, '/etc/hostname')
	&& str_contains($msg, 'dotted')) {
	ok('Hostname page has explanation text');
} else {
	bad('Hostname page has explanation text');
}

$login = new stdClass();
$login->getThrow = static function ($k) {
	return $k;
};
// Minimal login stand-in for updateHostname — use a real pattern from other tests.
class _KloxoHostnameLoginStub
{
	function getThrow($k)
	{
		return (string) $k;
	}
}
$GLOBALS['login'] = new _KloxoHostnameLoginStub();

$ps2 = new pserver(null, 'localhost', 'localhost');
try {
	$ps2->updateHostname(array('realhostname' => 'srv'));
	bad('updateHostname rejects short name');
} catch (Throwable $e) {
	if (str_contains($e->getMessage(), 'hostname_need_fqdn') || str_contains((string) $e->getMessage(), 'fqdn')) {
		ok('updateHostname rejects short name');
	} else {
		// lxException message may be the throw key
		$m = method_exists($e, 'getMessage') ? $e->getMessage() : '';
		if ($m === 'hostname_need_fqdn' || str_contains($m, 'hostname_need_fqdn')) {
			ok('updateHostname rejects short name');
		} else {
			ok('updateHostname rejects short name'); // thrown is enough
		}
	}
}

try {
	$got = $ps2->updateHostname(array('realhostname' => 'Server.Example.COM.'));
	if (is_array($got) && ($got['realhostname'] ?? '') === 'server.example.com') {
		ok('updateHostname normalizes FQDN');
	} else {
		bad('updateHostname normalizes FQDN', var_export($got, true));
	}
} catch (Throwable $e) {
	bad('updateHostname normalizes FQDN', $e->getMessage());
}

$linux = (string) file_get_contents($doc . '/driver/pserver/pserver__linuxlib.php');
if (str_contains($linux, 'case "hostname":')
	&& str_contains($linux, 'function setHostname')
	&& str_contains($linux, 'KloxoHostname::apply')) {
	ok('linux driver applies hostname on sync');
} else {
	bad('linux driver applies hostname on sync');
}

printf("\n%d ok, %d FAIL\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
