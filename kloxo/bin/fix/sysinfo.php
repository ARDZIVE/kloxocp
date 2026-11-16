<?php

// by mustafa@bigraf.com for Kloxo-MR

include_once "lib/html/include.php";
initProgram('admin');

// Helpers — argv / PHP parse only (security HH3).
function kloxo_sysinfo_dpkg(string $pkg): string
{
	if ($pkg === '' || preg_match('/^[A-Za-z0-9._+-]+$/', $pkg) !== 1) {
		return '';
	}
	$desc = array(
		0 => array('file', '/dev/null', 'r'),
		1 => array('pipe', 'w'),
		2 => array('file', '/dev/null', 'w'),
	);
	$proc = @proc_open(
		array('dpkg-query', '-W', '-f=${Package} ${Version}
', $pkg),
		$desc,
		$pipes
	);
	if (!is_resource($proc)) {
		return '';
	}
	$out = trim((string) stream_get_contents($pipes[1]));
	fclose($pipes[1]);
	if (isset($pipes[2]) && is_resource($pipes[2])) {
		fclose($pipes[2]);
	}
	proc_close($proc);

	return $out;
}

function kloxo_sysinfo_web_bases(): array
{
	$file = '/opt/kloxo/etc/list/web.lst';
	if (!is_file($file)) {
		return array();
	}
	$bases = array();
	foreach (explode(',', (string) file_get_contents($file)) as $part) {
		$base = explode('_', trim($part), 2)[0];
		if ($base !== '') {
			$bases[] = $base;
		}
	}

	return array_values(array_unique($bases));
}

function kloxo_sysinfo_run(array $argv): array
{
	$lines = array();
	foreach ($argv as $a) {
		if (!is_string($a) || str_contains($a, chr(0))) {
			return array();
		}
	}
	$desc = array(
		0 => array('file', '/dev/null', 'r'),
		1 => array('pipe', 'w'),
		2 => array('file', '/dev/null', 'w'),
	);
	$proc = @proc_open($argv, $desc, $pipes);
	if (!is_resource($proc)) {
		return array();
	}
	$raw = (string) stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	if (isset($pipes[2]) && is_resource($pipes[2])) {
		fclose($pipes[2]);
	}
	proc_close($proc);
	foreach (preg_split('/
|
|
/', $raw) as $line) {
		if ($line !== '') {
			$lines[] = $line;
		}
	}

	return $lines;
}

$kloxopath="/opt/kloxo";

$kloxover = kloxo_sysinfo_run(array('sh', '/script/version', '--vertype=full'));
$kloxover = isset($kloxover[0]) ? $kloxover[0] : '';

$osrelease = array();
foreach (glob('/etc/*release') ?: array() as $_rel) {
	$osrelease = array_merge($osrelease, preg_split('/
|
|
/', (string) file_get_contents($_rel)) ?: array());
}
$osplateform = kloxo_sysinfo_run(array('uname', '-m'));

$out = null;

$mysqlbranch = getRpmBranchInstalled('mysql');
if ($mysqlbranch) {
	$out = array(); $_q = kloxo_sysinfo_dpkg($mysqlbranch); if ($_q !== '') { $out[] = $_q; }
	$appmysql = trim($out[0]);
} else {
	$appmysql = '--uninstalled--';
}

$out = null;

$phpbranch = getRpmBranchInstalled('php');
if ($phpbranch) {
	$out = array(); $_q = kloxo_sysinfo_dpkg($phpbranch . '-cli'); if ($_q !== '') { $out[] = $_q; }
	$appphp = trim($out[0]);
} else {
	$appphp = '--uninstalled--';
}

$out = null;

$phpmdirs = glob("/opt/php*m", GLOB_MARK);

$phpsbranch = str_replace("\n", "", file_get_contents("{$kloxopath}/init/kloxo_php_active"));
$phpsver = str_replace("\n", "", file_get_contents("/opt/{$phpsbranch}/version"));

if (file_exists("{$kloxopath}/init/kloxo_use_php-cgi")) {
	$phpsver = $phpsver . " (cgi mode)";
} else {
	$phpsver = $phpsver . " (fpm mode)";
}

$appApache = '--uninstalled--';
$apachelist = array();
foreach (kloxo_sysinfo_web_bases() as $_b) {
	if (str_contains($_b, 'httpd') && !str_contains($_b, 'nginx') && !str_contains($_b, 'tengine') && !str_contains($_b, 'lighttpd') && !str_contains($_b, 'hiawatha')) {
		$apachelist[] = $_b;
	}
}

$out = null;
foreach ($apachelist as $k => $v) {
	$apachebranch = getRpmBranchInstalled($v);

	if ($apachebranch) {
		$out = array(); $_q = kloxo_sysinfo_dpkg($apachebranch); if ($_q !== '') { $out[] = $_q; }
		$appApache = trim($out[0]);
	}
}

$appLighttpd = '--uninstalled--';
$lighttpdlist = array();
foreach (kloxo_sysinfo_web_bases() as $_b) {
	if (str_contains($_b, 'lighttpd') && !str_contains($_b, 'nginx') && !str_contains($_b, 'tengine') && !str_contains($_b, 'hiawatha')) {
		$lighttpdlist[] = $_b;
	}
}

$out = null;
foreach ($lighttpdlist as $k => $v) {
	$lighttpdbranch = getRpmBranchInstalled($v);

	if ($lighttpdbranch) {
		$out = array(); $_q = kloxo_sysinfo_dpkg($lighttpdbranch); if ($_q !== '') { $out[] = $_q; }
		$appLighttpd = trim($out[0]);
	}
}

$appNginx = '--uninstalled--';
$nginxlist = array();
foreach (kloxo_sysinfo_web_bases() as $_b) {
	if ((str_contains($_b, 'nginx') || str_contains($_b, 'tengine')) && !str_contains($_b, 'httpd') && !str_contains($_b, 'lighttpd') && !str_contains($_b, 'hiawatha')) {
		$nginxlist[] = $_b;
	}
}

$out = null;
foreach ($nginxlist as $k => $v) {
	$nginxbranch = getRpmBranchInstalled($v);

	if ($nginxbranch) {
		$out = array(); $_q = kloxo_sysinfo_dpkg($nginxbranch); if ($_q !== '') { $out[] = $_q; }
		$appNginx = trim($out[0]);
	}
}

$appHiawatha = '--uninstalled--';
$hiawathalist = array();
foreach (kloxo_sysinfo_web_bases() as $_b) {
	if (str_contains($_b, 'hiawatha') && !str_contains($_b, 'httpd') && !str_contains($_b, 'lighttpd') && !str_contains($_b, 'nginx')) {
		$hiawathalist[] = $_b;
	}
}

$out = null;
foreach ($hiawathalist as $k => $v) {
	$hiawathabranch = getRpmBranchInstalled($v);

	if ($hiawathabranch) {
		$out = array(); $_q = kloxo_sysinfo_dpkg($hiawathabranch); if ($_q !== '') { $out[] = $_q; }
		$appHiawatha = trim($out[0]);
	}
}

$out = null;

$atsbranch = getRpmBranchInstalled('trafficserver');
if ($atsbranch) {
	$out = array(); $_q = kloxo_sysinfo_dpkg($atsbranch); if ($_q !== '') { $out[] = $_q; }
	$appats = trim($out[0]);
} else {
	$appats = '--uninstalled--';
}

$out = null;

$squidbranch = getRpmBranchInstalled('squid');
if ($squidbranch) {
	$out = array(); $_q = kloxo_sysinfo_dpkg($squidbranch); if ($_q !== '') { $out[] = $_q; }
	$appsquid = trim($out[0]);
} else {
	$appsquid = '--uninstalled--';
}

$out = null;

$varnishbranch = getRpmBranchInstalled('varnish');
if ($varnishbranch) {
	$out = array(); $_q = kloxo_sysinfo_dpkg($varnishbranch); if ($_q !== '') { $out[] = $_q; }
	$appvarnish = trim($out[0]);
} else {
	$appvarnish = '--uninstalled--';
}

$out = null;

$bindbranch = getRpmBranchInstalled('bind');
if ($bindbranch) {
	$out = array(); $_q = kloxo_sysinfo_dpkg($bindbranch); if ($_q !== '') { $out[] = $_q; }
	$appbind = trim($out[0]);
} else {
	$appbind = '--uninstalled--';
}

$out = null;

$djbdnsbranch = getRpmBranchInstalled('djbdns');
if ($djbdnsbranch) {
	$out = array(); $_q = kloxo_sysinfo_dpkg($djbdnsbranch); if ($_q !== '') { $out[] = $_q; }
	$appdjbdns = trim($out[0]);
} else {
	$appdjbdns = '--uninstalled--';
}

$out = null;

$nsdbranch = getRpmBranchInstalled('nsd');
if ($nsdbranch) {
	$out = array(); $_q = kloxo_sysinfo_dpkg($nsdbranch); if ($_q !== '') { $out[] = $_q; }
	$appnsd = trim($out[0]);
} else {
	$appnsd = '--uninstalled--';
}

$out = null;

$pdnsbranch = getRpmBranchInstalled('pdns');
if ($pdnsbranch) {
	$out = array(); $_q = kloxo_sysinfo_dpkg($pdnsbranch); if ($_q !== '') { $out[] = $_q; }
	$apppdns = trim($out[0]);
} else {
	$apppdns = '--uninstalled--';
}

$out = null;

$yadifabranch = getRpmBranchInstalled('yadifa');
if ($yadifabranch) {
	$out = array(); $_q = kloxo_sysinfo_dpkg($yadifabranch); if ($_q !== '') { $out[] = $_q; }
	$appyadifa = trim($out[0]);
} else {
	$appyadifa = '--uninstalled--';
}

$out = null;

$dovecotbranch = getRpmBranchInstalled('dovecot');
if ($dovecotbranch) {
	$out = array(); $_q = kloxo_sysinfo_dpkg($dovecotbranch); if ($_q !== '') { $out[] = $_q; }
	$appdovecot = trim($out[0]);
} else {
	$appdovecot = '--uninstalled--';
}

$out = null;

$a = get_namelist_from_objectlist($login->getList('pserver'), 'syncserver');
$b = implode("", $a);

$phptype = db_get_value('serverweb', "pserver-{$b}", 'php_type');

if (!isset($phptype)) { $phptype = 'php-fpm_event (default)'; }

if (getServiceType('php-fpm') === 'systemd') {
$out = array();
	$_svc = '/usr/lib/systemd/system/php-fpm.service';
	if (is_file($_svc)) {
		foreach (preg_split('/\r\n|\n|\r/', (string) file_get_contents($_svc)) ?: array() as $_ln) {
			if (str_starts_with($_ln, 'ExecStart=')) {
				if (preg_match('#^ExecStart=/usr/sbin/(\S+)#', $_ln, $_m)) {
					$out[] = $_m[1];
				}
				break;
			}
		}
	}
} else {
$out = array();
	$_init = '/etc/rc.d/init.d/php-fpm';
	if (is_file($_init)) {
		foreach (preg_split('/\r\n|\n|\r/', (string) file_get_contents($_init)) ?: array() as $_ln) {
			if (str_starts_with($_ln, 'prog=')) {
				if (preg_match('/^prog="?(.*?)"?$/', $_ln, $_m)) {
					$out[] = $_m[1];
				}
				break;
			}
		}
	}
}
if (count($out) > 0) {
	$phpused = $out[0];

	if ($phpused === "php-fpm") {
		$phpused = '--PHP Branch--';
	}
} else {
	$phpused = '--PHP Branch--';
}

$out = null;

$pop3app = slave_get_driver('pop3');

$out = array(); if (isDebInstalled('dovecot-core') || isDebInstalled('dovecot')) { $out[] = 'dovecot'; }

if (count($out) > 0) {
	$pop3app = $out[0];
} else {
	$pop3app = 'none';
}

$out = null;

$smtpapp = slave_get_driver('smtp');

$out = array(); if (isDebInstalled('exim4-daemon-heavy') || isDebInstalled('exim4') || isDebInstalled('postfix')) { $out[] = isDebInstalled('postfix') ? 'postfix' : 'exim4'; }

if (count($out) > 0) {
	$smtpapp = $out[0];
} else {
	$smtpapp = 'none';
}

$out = null;

$spamapp = slave_get_driver('spam');

if ($spamapp === 'spamassassin' || $spamapp === 'bogofilter' || $spamapp === 'spamassassin-toaster') {
	$spamapp = 'rspamd';
}

$out = array(); $_q = kloxo_sysinfo_dpkg($spamapp); if ($_q !== '') { $out[] = $_q; }

if (count($out) > 0) {
	$spamapp = $out[0];
} else {
	$spamapp = '--uninstalled--';
}

$out = null;

if (file_exists("/etc/httpd/conf.d/suphp2.conf")) {
	$secondary_php = 'on';
} else {
	$secondary_php = 'off';
}

$out = null;

$ftpbranch = getRpmBranchInstalled('pure-ftpd');
if ($ftpbranch) {
	$out = array(); $_q = kloxo_sysinfo_dpkg($ftpbranch); if ($_q !== '') { $out[] = $_q; }
	$appftp = trim($out[0]);
} else {
	$appftp = '--uninstalled--';
}

$meminfo = kloxo_sysinfo_run(array('free', '-m'));

$diskinfo = kloxo_sysinfo_run(array('df', '-h', '/'));

$gen = $login->getObject('general')->generalmisc_b;
$webstatsprog = ($gen->webstatisticsprogram) ? $gen->webstatisticsprogram : 'awstats';

$out = null;

// MR -- use grep because possible as kloxo-stats-<statsprog> or just <statsprog>
$out = array(); if (is_string($webstatsprog) && $webstatsprog !== '' && isDebInstalled($webstatsprog)) { $out[] = $webstatsprog; }

if (count($out) > 0) {
	$appstats = $out[0];
} else {
	$appstats = '--uninstalled--';
}

echo "";
echo "\n";
echo "A. Control Panel:" .
	"               \n"; // need more space because overwrite waiting line
echo "   - Kloxo CP: {$kloxover}\n";
echo "   - Web: {$appHiawatha}\n";
echo "   - PHP: {$phpsbranch} - {$phpsver}\n";
//echo "\n";
echo "B. Plateform:\n";
echo "   - OS: {$osrelease[0]} {$osplateform[0]} \n";
echo "   - Hostname: " . gethostname() . "\n";
//echo "\n";
echo "C. Services:\n";
echo "   1. MySQL: {$appmysql}\n";
echo "   2. PHP: \n";
echo "      - Installed:\n";
echo "        - Branch: {$appphp}\n";
if ($phpmdirs) {
	echo "        - Multiple: \n";
	foreach ($phpmdirs as $k => $v) {
		if (file_exists("{$v}/version")) {
			$v1 = str_replace("/", "", str_replace("/opt/", "", $v));
			$v2  = file_get_contents("{$v}/version");
			echo "          * {$v1} - " . str_replace("\n", "", $v2) . "\n";
		} else {
			// MR - remove if not version file not exists (argv; security EE6).
			$v = (string) $v;
			if (str_starts_with($v, '/opt/') && !str_contains($v, '..') && !str_contains($v, "\0")
				&& function_exists('kloxo_exec_argv')) {
				kloxo_exec_argv(array('rm', '-rf', '--', $v));
			}
		}
	}
}
echo "      - Used: {$phpused}\n";

if (file_exists("{$kloxopath}/etc/flag/enablemultiplephp.flg")) {
	echo "      - Multiple: enable\n";
} else {
	echo "      - Multiple: disable\n";
}

echo "   3. Web Used: " . slave_get_driver('web') . "\n";
echo "     - Hiawatha: {$appHiawatha}\n";
echo "     - Lighttpd: {$appLighttpd}\n";
echo "     - Nginx: {$appNginx}\n";
echo "     - Apache: {$appApache}\n";
echo "       - PHP Type: {$phptype}\n";
echo "       - Secondary PHP: {$secondary_php}\n";
echo "   4. WebCache: " .  slave_get_driver('webcache') . "\n";
echo "     - ATS: {$appats}\n";
echo "     - Squid: {$appsquid}\n";
echo "     - Varnish: {$appvarnish}\n";
echo "   5. Dns: " .  slave_get_driver('dns') . "\n";
echo "     - Bind: {$appbind}\n";
echo "     - DJBDns: {$appdjbdns}\n";
echo "     - NSD: {$appnsd}\n";
echo "     - PowerDNS: {$apppdns}\n";
echo "     - Yadifa: {$appyadifa}\n";
echo "   6. Mail: " . slave_get_driver('smtp') . "\n";

if ($appdovecot !== '--uninstalled--') {
	echo "      - with: {$appdovecot}\n";
}

echo "      - pop3/imap4: {$pop3app}\n";
echo "      - smtp: {$smtpapp}\n";
echo "      - spam: {$spamapp}\n";

echo "   7. FTP: pure-ftpd\n";
echo "      - pure-ftpd: {$appftp}\n";

echo "   8. Stats: {$webstatsprog}\n";
echo "      - {$webstatsprog}: {$appstats}\n";

echo "D. Memory:\n";
foreach ($meminfo as $k => $v) {
	echo $v . "\n";
}

echo "E. Disk Space:\n";
foreach ($diskinfo as $k => $v) {
	echo "{$v}\n";
}

echo "\n";
