<?php
/**
 * Log Manager catalog and always-visible picker invariants.
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

if (!class_exists('Llog')) {
	bad('Llog class loads');
	printf("\n%d ok, %d FAIL\n", $pass, $fail);
	exit($fail > 0 ? 1 : 0);
}
ok('Llog class loads');

$cat = Llog::logCatalog();
if (!is_array($cat) || $cat === array()) {
	bad('logCatalog returns a non-empty array');
} else {
	ok('logCatalog returns a non-empty array');
}

$dead = array(
	'lighttpd/access.log', 'lighttpd/error.log',
	'httpd/access_log', 'httpd/error_log',
	'messages', 'secure', 'cron', 'yum.log',
	'named/', 'djbdns.log', 'nsd.log', 'yadifa/yadifa.log',
	'rkhunter/rkhunter.log', 'httpry/httpry.log',
);
$deadHit = array();
foreach ($dead as $k) {
	if (array_key_exists($k, $cat)) {
		$deadHit[] = $k;
	}
}
if ($deadHit === array()) {
	ok('logCatalog omits removed-stack / CentOS paths');
} else {
	bad('logCatalog omits removed-stack / CentOS paths', implode(',', $deadHit));
}

$need = array(
	'syslog', 'auth.log', 'dpkg.log',
	'exim4/mainlog', 'mail.log',
	'nginx/error.log', 'apache2/error.log',
	'/opt/kloxo/log/hiawatha-system.log',
	'/opt/kloxo/log/php.err',
	'/opt/kloxo/log/watchdog.log',
	'fail2ban.log', 'pureftpd.log', 'mysql/error.log',
);
$missing = array();
foreach ($need as $k) {
	if (!array_key_exists($k, $cat)) {
		$missing[] = $k;
	}
}
if ($missing === array()) {
	ok('logCatalog includes Ubuntu panel / service logs');
} else {
	bad('logCatalog includes Ubuntu panel / service logs', implode(',', $missing));
}

$llog = new Llog(null, 'localhost', 'pserver-localhost');
$llog->__masterserver = null;
$llog->__readserver = 'localhost';
$prop = array();
$llog->createShowPropertyList($prop);
$props = $prop['property'] ?? array();
if (is_array($props) && $props === array('a=show')) {
	ok('Log Manager show has a single Log Manager tab (no File Manager /)');
} else {
	bad('Log Manager show has a single Log Manager tab (no File Manager /)', var_export($props, true));
}

$sc = $llog->createShowSclist();
if (isset($sc['ffile']) && $sc['ffile'] === $cat) {
	ok('createShowSclist uses logCatalog');
} else {
	bad('createShowSclist uses logCatalog');
}

$sw = Llog::logSwitchList();
$swOk = is_array($sw) && $sw !== array();
$idsOk = true;
foreach ($sw as $path => $stub) {
	if (!is_object($stub) || !method_exists($stub, 'getId')) {
		$idsOk = false;
		break;
	}
	if ((string) $stub->getId() !== (string) ($cat[$path] ?? '')) {
		$idsOk = false;
		break;
	}
}
if ($swOk && $idsOk && array_keys($sw) === array_keys($cat)) {
	ok('logSwitchList stubs match catalog keys and labels');
} else {
	bad('logSwitchList stubs match catalog keys and labels');
}

$parent = new stdClass();
$parent->username = 'root';
$ff = new Ffile(null, 'localhost', '/', '/var/log/syslog', 'root');
$ff->__parent_o = $llog;
$ff->readonly = 'on';
$ff->ttype = 'file';
$ff->base = 'syslog';

if (method_exists($ff, 'isLogManagerView') && $ff->isLogManagerView()) {
	ok('ffile under llog isLogManagerView');
} else {
	bad('ffile under llog isLogManagerView');
}

$self = $ff->getSelfList();
if (is_array($self) && array_keys($self) === array_keys($cat)) {
	ok('ffile under llog getSelfList returns the log picker list');
} else {
	bad('ffile under llog getSelfList returns the log picker list');
}

$fprop = array();
$ff->createShowPropertyList($fprop);
$fp = $fprop['property'] ?? array();
$fpOk = is_array($fp)
	&& isset($fp[0]) && str_contains((string) $fp[0], 'goback=1')
	&& in_array('a=show', $fp, true)
	&& in_array('a=update&sa=download', $fp, true)
	&& !in_array('a=updateform&sa=perm', $fp, true);
if ($fpOk) {
	ok('log viewer tabs are Log Manager + show + download (not File Manager)');
} else {
	bad('log viewer tabs are Log Manager + show + download (not File Manager)', var_export($fp, true));
}

$core = file_get_contents($doc . '/theme/coredisplaylib.php');
if (is_string($core) && str_contains($core, 'function kloxo_print_llog_picker')
	&& str_contains($core, "class === 'llog'")
	&& str_contains($core, 'llogjumpselect')) {
	ok('landing page uses auto-submit llog picker');
} else {
	bad('landing page uses auto-submit llog picker');
}

printf("\n%d ok, %d FAIL\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
