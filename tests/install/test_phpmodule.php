<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!class_exists('lxDriverClass', false)) {
	class lxDriverClass
	{
	}
}
if (!class_exists('lxclass', false)) {
	class lxclass
	{
		public $nname;
		public $syncserver;
		public $__masterserver;
		public $__readserver;
		function __construct($m, $r, $key)
		{
			$this->nname = $key;
			$this->syncserver = $r;
			$this->__masterserver = $m;
			$this->__readserver = $r;
		}
		function getClass()
		{
			return 'phpmodule';
		}
	}
}
if (!function_exists('log_log')) {
	function log_log($a, $b = null): void
	{
	}
}

require $root . '/kloxo/httpdocs/driver/pserver/phpmodule__linuxlib.php';

$fail = 0;
$ok = static function (string $d): void {
	printf("  ok   %s\n", $d);
};
$bad = static function (string $d, string $x = '') use (&$fail): void {
	$fail++;
	printf("  FAIL %s\n", $d);
	if ($x !== '') {
		printf("       %s\n", $x);
	}
};

$list = PhpModule__linux::getListDetail('localhost');
if (is_array($list)) {
	$ok('getListDetail returns a list');
} else {
	$bad('getListDetail returns a list');
}

$on = PhpModule__linux::legacyIniRow('/opt/php53m/etc/php.d/imap.ini', 'localhost', 'multiple-php');
$off = PhpModule__linux::legacyIniRow('/opt/php53m/etc/php.d/imagick.nonini', 'localhost', 'multiple-php');
$branch = PhpModule__linux::legacyIniRow('/etc/php.d/simplexml.ini', 'localhost', 'php-branch', 'php');
if (
	is_array($on) && $on['status'] === 'on' && $on['modulename'] === 'imap' && $on['target'] === 'php53m'
	&& isset($on['syncserver']) && $on['syncserver'] === 'localhost'
	&& is_array($off) && $off['status'] === 'off' && $off['modulename'] === 'imagick'
	&& is_array($branch) && $branch['status'] === 'on' && $branch['target'] === 'php'
	&& $branch['nname'] === 'php-simplexml___localhost'
) {
	$ok('legacyIniRow status and names');
} else {
	$bad('legacyIniRow status and names');
}

$nop = PhpModule__linux::applyAction('enable', '/etc/php/8.3/mods-available/x.ini', 'on');
if (is_array($nop) && !empty($nop['ok'])) {
	$ok('applyAction enable is a no-op when already on');
} else {
	$bad('applyAction enable is a no-op when already on', var_export($nop, true));
}
$badPath = PhpModule__linux::applyAction('disable', '/tmp/evil.ini', 'on');
if (is_array($badPath) && empty($badPath['ok']) && isset($badPath['error'])) {
	$ok('applyAction refuses unsafe paths');
} else {
	$bad('applyAction refuses unsafe paths', var_export($badPath, true));
}

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/phpmodulelib.php');
if (str_contains($lib, 'function initThisList')
	&& str_contains($lib, 'function ensureLinuxDriver')
	&& str_contains($lib, "driverapp = 'linux'")
	&& str_contains($lib, 'PhpModule__linux::getListDetail')
	&& str_contains($lib, 'scan /etc/php/*/mods-available in-process')
	&& str_contains($lib, "array(\$dclass, 'applyAction')")
	&& str_contains($lib, 'no panel-user fallback')
	&& str_contains($lib, 'return null;')
) {
	$ok('initThisList loads linux driver and scans mods-available locally; enable via backend');
} else {
	$bad('initThisList loads linux driver and scans mods-available locally; enable via backend');
}

if (!function_exists('isLocalhost')) {
	function isLocalhost($s): bool
	{
		return $s === null || $s === '' || $s === 'localhost' || $s === '127.0.0.1';
	}
}
$parent = new lxclass(null, 'localhost', 'localhost');
$parent->syncserver = 'localhost';
$parent->__masterserver = null;
$parent->__readserver = 'localhost';
if (!PhpModule::ensureLinuxDriver()) {
	$bad('ensureLinuxDriver loads PhpModule__linux');
} else {
	$ok('ensureLinuxDriver loads PhpModule__linux');
}
$listed = PhpModule::initThisList($parent, 'phpmodule');
if (is_array($listed)) {
	$ok('initThisList returns an array for localhost (count=' . count($listed) . ')');
} else {
	$bad('initThisList returns an array for localhost');
}

// Synthetic Ubuntu mods-available rows (no live /etc/php required).
require_once $root . '/kloxo/httpdocs/lib/php/kloxo_phpver.php';
$parsed = KloxoPhpver::parseModListing(
	'php8.3',
	'/etc/php/8.3/mods-available',
	array('opcache.ini', 'mysqli.ini', 'calendar.ini'),
	array('10-opcache.ini', '20-mysqli.ini'),
	'localhost'
);
if (count($parsed) === 3
	&& $parsed[0]['modulename'] === 'opcache' && $parsed[0]['status'] === 'on'
	&& $parsed[1]['modulename'] === 'mysqli' && $parsed[1]['status'] === 'on'
	&& $parsed[2]['modulename'] === 'calendar' && $parsed[2]['status'] === 'off'
	&& $parsed[0]['nname'] === 'php8.3-opcache___localhost'
	&& $parsed[0]['fullfile'] === '/etc/php/8.3/mods-available/opcache.ini') {
	$ok('parseModListing builds Ubuntu module rows with on/off');
} else {
	$bad('parseModListing builds Ubuntu module rows with on/off', var_export($parsed, true));
}

echo $fail === 0 ? "phpmodule tests ok\n" : "phpmodule tests failed\n";
exit($fail === 0 ? 0 : 1);
