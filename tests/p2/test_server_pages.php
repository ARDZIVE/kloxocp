<?php
/**
 * Server-page PHP 8 guards: SSH keys, mail queue, services, find column.
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

$parent = new stdClass();
$parent->username = 'root';
try {
	$ret = sshauthorizedkey::addform($parent, 'sshauthorizedkey');
	if (is_array($ret) && isset($ret['variable']['full_key']) && $ret['action'] === 'add') {
		ok('sshauthorizedkey addform returns a form without key(null)');
	} else {
		bad('sshauthorizedkey addform returns a form without key(null)');
	}
} catch (Throwable $e) {
	bad('sshauthorizedkey addform returns a form without key(null)', $e->getMessage());
}

$keys = sshauthorizedkey__sync::getAuthorizedKey('nobody-missing', null);
if (is_array($keys)) {
	ok('getAuthorizedKey returns an array when the file is missing');
} else {
	bad('getAuthorizedKey returns an array when the file is missing');
}

if (class_exists('Service__Debian', false) || class_exists('Service__Debian')) {
	ok('Service__Debian class loads');
} else {
	bad('Service__Debian class loads');
}

if (is_callable(array('Service__Debian', 'getServiceDetails'))) {
	$d = Service__Debian::getServiceDetails(array());
	if (is_array($d)) {
		ok('Service__Debian::getServiceDetails accepts an empty list');
	} else {
		bad('Service__Debian::getServiceDetails accepts an empty list');
	}
} else {
	bad('Service__Debian::getServiceDetails accepts an empty list', 'method missing');
}

if (class_exists('Component__deb')) {
	ok('Component__deb class loads');
} else {
	bad('Component__deb class loads');
}

if (!class_exists('KloxoComponent')) {
	include_once $doc . '/lib/php/kloxo_component.php';
}
if (class_exists('KloxoComponent')) {
	$cat = KloxoComponent::catalog();
	$names = array();
	foreach ($cat as $row) {
		if (isset($row['name'])) {
			$names[$row['name']] = true;
		}
	}
	if (isset($names['nginx'], $names['apache2'], $names['bind9'], $names['pdns-server'],
		$names['mariadb-server'], $names['postfix'], $names['dovecot-core'], $names['rspamd'],
		$names['hiawatha'], $names['php8.3-cli'], $names['php5.6-cli'], $names['php7.4-fpm'])) {
		ok('Component catalog lists the Ubuntu stack');
	} else {
		bad('Component catalog lists the Ubuntu stack');
	}
	if (!isset($names['qmail-toaster']) && !isset($names['lighttpd'])
		&& !isset($names['MariaDB-server']) && !isset($names['php56w-cli'])) {
		ok('Component catalog does not use CentOS RPM names');
	} else {
		bad('Component catalog does not use CentOS RPM names');
	}
	$parsed = KloxoComponent::parseQuery(
		"nginx\t1.24.0-2ubuntu7\tinstalled\nbind9\t1:9.18.28-0ubuntu0.24.04.1\tinstalled\napache2\t2.4.58-1ubuntu8\tnot-installed\n"
	);
	if (isset($parsed['nginx']['installed']) && $parsed['nginx']['installed'] === true
		&& $parsed['bind9']['version'] === '1:9.18.28-0ubuntu0.24.04.1'
		&& $parsed['apache2']['installed'] === false) {
		ok('parseQuery maps dpkg installed vs not-installed');
	} else {
		bad('parseQuery maps dpkg installed vs not-installed');
	}
	if (KloxoComponent::parseHiawathaVersion("Hiawatha v12.4\n") === '12.4'
		&& KloxoComponent::packageFromNname('nginx___localhost') === 'nginx') {
		ok('hiawatha version and nname parse');
	} else {
		bad('hiawatha version and nname parse');
	}
	$rows = KloxoComponent::rowsFromStatus('localhost', $parsed, array('version' => '12.4', 'installed' => true));
	$by = array();
	foreach ($rows as $r) {
		$by[$r['componentname']] = $r;
	}
	if (isset($by['nginx'], $by['bind9'], $by['hiawatha'], $by['postfix'])
		&& $by['nginx']['status'] === 'on'
		&& $by['bind9']['status'] === 'on'
		&& $by['hiawatha']['status'] === 'on'
		&& $by['hiawatha']['version'] === 'hiawatha-12.4'
		&& $by['postfix']['status'] === 'off'
		&& str_contains($by['postfix']['version'], 'is not installed')
		&& $by['nginx']['type'] === 'web'
		&& count($rows) === count($cat)) {
		ok('Component rows use green/red status like Kloxo-MR');
	} else {
		bad('Component rows use green/red status like Kloxo-MR');
	}
	$list = Component__deb::getListVersion('localhost', array());
	if (is_array($list) && count($list) === count($cat)) {
		ok('Component__deb::getListVersion returns the full catalog');
	} else {
		bad('Component__deb::getListVersion returns the full catalog');
	}
} else {
	bad('Component catalog lists the Ubuntu stack', 'KloxoComponent missing');
	bad('Component catalog does not use CentOS RPM names');
	bad('parseQuery maps dpkg installed vs not-installed');
	bad('hiawatha version and nname parse');
	bad('Component rows use green/red status like Kloxo-MR');
	bad('Component__deb::getListVersion returns the full catalog');
}

if (class_exists('mailqueue__postfix')) {
	$parsed = mailqueue__postfix::parseTextQueue(
		"ABC123  100 Tue Nov  1 12:00:00  sender@example.com\n                                         dest@example.com\n"
	);
	if (is_array($parsed) && isset($parsed[0]['nname']) && $parsed[0]['nname'] === 'ABC123') {
		ok('mailqueue__postfix parses postqueue -p text');
	} else {
		bad('mailqueue__postfix parses postqueue -p text');
	}
} else {
	bad('mailqueue__postfix parses postqueue -p text', 'class missing');
}

$src = file_get_contents($doc . '/lib/php/lxlib.php');
if (is_string($src) && str_contains($src, 'function kloxo_skip_find_class')
	&& str_contains($src, "'sshconfig', 'lxguard', 'sshclient', 'ffile'")
	&& str_contains($src, "'lxguardhitdisplay', 'rawlxguardhit', 'lxguardwhitelist'")) {
	ok('Find column is skipped on log/driver/php/backup/webserver/ssh/lxguard/file show pages');
} else {
	bad('Find column is skipped on log/driver/php/backup/webserver/ssh/lxguard/file show pages');
}
if (function_exists('kloxo_skip_find_class')
	&& kloxo_skip_find_class('lxguard')
	&& kloxo_skip_find_class('lxguardhitdisplay')
	&& kloxo_skip_find_class('rawlxguardhit')
	&& kloxo_skip_find_class('lxguardwhitelist')
	&& !kloxo_skip_find_class('client')) {
	ok('LxGuard pages skip the Find input');
} else {
	bad('LxGuard pages skip the Find input');
}

if (function_exists('kloxo_driver_class')
	&& kloxo_driver_class('package', 'apt-get') === 'package__aptget'
	&& kloxo_driver_class('phpmodule', '') === null
	&& kloxo_driver_class('service', 'debian') === 'service__debian') {
	ok('kloxo_driver_class rejects empty drivers and strips apt-get hyphens');
} else {
	bad('kloxo_driver_class rejects empty drivers and strips apt-get hyphens');
}

if (function_exists('kloxo_rl_driver')) {
	$empty = kloxo_rl_driver('localhost', 'localhost', 'phpmodule', '', 'getListDetail', array());
	if (is_array($empty)) {
		ok('kloxo_rl_driver returns an array when the driver is missing');
	} else {
		bad('kloxo_rl_driver returns an array when the driver is missing');
	}
} else {
	bad('kloxo_rl_driver returns an array when the driver is missing', 'function missing');
}

if (class_exists('package__aptget')) {
	$pkgs = package__aptget::getPackages();
	if (is_array($pkgs)) {
		ok('package__aptget::getPackages returns an array');
	} else {
		bad('package__aptget::getPackages returns an array');
	}
} else {
	bad('package__aptget::getPackages returns an array', 'class missing');
}

$debsrc = file_get_contents($doc . '/driver/pserver/ipaddress__debianlib.php');
if (is_string($debsrc) && str_contains($debsrc, 'ipaddress::fixstatus')
	&& !str_contains($debsrc, 'ipaddress::listSystemIps')) {
	ok('debian reread IP uses fixstatus not missing listSystemIps');
} else {
	bad('debian reread IP uses fixstatus not missing listSystemIps');
}

if (class_exists('Ipaddress') && method_exists('Ipaddress', 'fixstatus')) {
	$fixed = Ipaddress::fixstatus(array(
		array('devname' => 'ens160', 'ipaddr' => '37.59.173.71', 'status' => 'yes'),
		array('devname' => 'lo', 'ipaddr' => '127.0.0.1', 'status' => 'yes'),
		null,
	));
	if (is_array($fixed) && count($fixed) === 1 && $fixed[0]['ipaddr'] === '37.59.173.71'
		&& $fixed[0]['status'] === 'on') {
		ok('Ipaddress::fixstatus keeps the Ubuntu iface and skips loopback');
	} else {
		bad('Ipaddress::fixstatus keeps the Ubuntu iface and skips loopback');
	}
	if (Ipaddress::fixstatus(null) === array()) {
		ok('Ipaddress::fixstatus returns an array when given null');
	} else {
		bad('Ipaddress::fixstatus returns an array when given null');
	}
} else {
	bad('Ipaddress::fixstatus keeps the Ubuntu iface and skips loopback', 'class missing');
}

if (class_exists('ipaddress__debian') && is_callable(array('ipaddress__debian', 'listSystemIps'))) {
	try {
		$ips = ipaddress__debian::listSystemIps('localhost');
		if (is_array($ips)) {
			ok('ipaddress__debian::listSystemIps returns an array');
		} else {
			bad('ipaddress__debian::listSystemIps returns an array');
		}
	} catch (Throwable $e) {
		bad('ipaddress__debian::listSystemIps returns an array', $e->getMessage());
	}
} else {
	bad('ipaddress__debian::listSystemIps returns an array', 'class missing');
}

if (class_exists('genlist')) {
	$gl = new genlist(null, null, 'admin');
	$gl->dirindexlist_a = null;
	try {
		$idx = $gl->getList('dirindexlist_a');
		$count = null;
		$vlist = $gl->getVirtualList('dirindexlist_a', $count);
		if (is_array($idx) && is_array($vlist) && $count === 0) {
			ok('Directory Index list does not count() a missing dirindexlist_a');
		} else {
			bad('Directory Index list does not count() a missing dirindexlist_a');
		}
	} catch (Throwable $e) {
		bad('Directory Index list does not count() a missing dirindexlist_a', $e->getMessage());
	}
} else {
	bad('Directory Index list does not count() a missing dirindexlist_a', 'class missing');
}

if (class_exists('cron__Linux')) {
	$drv = new cron__Linux(null, null, 'test');
	$job = new Cron(null, null, 'dummy-cron');
	$job->username = 'nobody-missing-kloxo';
	$job->__var_mailto = "not an\nemail";
	$job->__var_cron_list = null;
	$job->ttype = 'complex';
	$job->command = "/bin/true\n/bin/false";
	$job->dbaction = 'clean';
	$drv->main = $job;
	try {
		$drv->syncCreateConf();
		ok('cron__Linux::syncCreateConf accepts a missing cron list');
	} catch (Throwable $e) {
		bad('cron__Linux::syncCreateConf accepts a missing cron list', $e->getMessage());
	}
	$job->username = 'bad user; rm -rf /';
	try {
		$drv->syncCreateConf();
		ok('cron__Linux::syncCreateConf rejects an unsafe username');
	} catch (Throwable $e) {
		bad('cron__Linux::syncCreateConf rejects an unsafe username', $e->getMessage());
	}
} else {
	bad('cron__Linux::syncCreateConf accepts a missing cron list', 'class missing');
}

$cron = new Cron(null, null, 'dummy-cron');
try {
	$cron->createExtraVariables();
	if (is_array($cron->__var_cron_list) && is_array($cron->__var_user_list)) {
		ok('Cron::createExtraVariables does not index a missing parent');
	} else {
		bad('Cron::createExtraVariables does not index a missing parent');
	}
} catch (Throwable $e) {
	bad('Cron::createExtraVariables does not index a missing parent', $e->getMessage());
}

$merged = merge_array_object_not_deleted(null, $cron);
if (is_array($merged)) {
	ok('merge_array_object_not_deleted returns an array when given null');
} else {
	bad('merge_array_object_not_deleted returns an array when given null');
}

if (class_exists('Client')) {
	$cl = new Client(null, null, 'admin');
	$cl->subaction = 'cron_mailto';
	if ($cl->isSync() === false) {
		ok('Mail To update does not sync the client through the C daemon');
	} else {
		bad('Mail To update does not sync the client through the C daemon');
	}
} else {
	bad('Mail To update does not sync the client through the C daemon', 'class missing');
}

$map = file_get_contents($doc . '/lib/html/definecore.php');
if (is_string($map) && str_contains($map, '"driver/dns/dnstemplatelib.php"')
	&& !str_contains($map, 'driver/dns//dnstemplatelib.php')) {
	ok('dnstemplate autoload path has no double slash');
} else {
	bad('dnstemplate autoload path has no double slash');
}

$menu = file_get_contents($doc . '/theme/skin/simplicity/default/menu.php');
if (is_string($menu)
	&& substr_count($menu, 'frm_action=list&frm_o_cname=dnstemplate') >= 2
	&& str_contains($menu, 'frm_o_o[0][class]=lxbackup')
	&& str_contains($menu, 'clientquery;?>frm_o_cname=allowedip')
	&& !str_contains($menu, 'clientid;?>frm_o_cname=allowedip')
	&& str_contains($menu, 'frm_o_o[1][class]=client&frm_o_o[1][nname]={$clientid2}')
	&& !str_contains($menu, 'frm_o_o[1][nname]={$clientid}&"')) {
	ok('DNS Template menu hrefs stay on dnstemplate, not lxbackup');
} else {
	bad('DNS Template menu hrefs stay on dnstemplate, not lxbackup');
}

$swapped = array();
if (is_string($menu) && preg_match_all('/href="\/display\.php\?([^"]+)"/', $menu, $hrefs)) {
	foreach ($hrefs[1] as $href) {
		$href = html_entity_decode($href, ENT_QUOTES);
		$isDns = str_contains($href, 'frm_o_cname=dnstemplate');
		$isBak = str_contains($href, '[class]=lxbackup');
		if ($isDns && $isBak) {
			$swapped[] = $href;
		}
	}
}
if ($swapped === array()) {
	ok('no menu href mixes DNS Template with Backup/Restore');
} else {
	bad('no menu href mixes DNS Template with Backup/Restore', $swapped[0]);
}

if (is_string($menu) && !str_contains($menu, 'frm_ocname=')
	&& !str_contains($menu, 'c=phpmdule')
	&& str_contains($menu, 'frm_subaction=poweroff')
	&& str_contains($menu, 'sa=poweroff')) {
	ok('menu query keys keep frm_o_cname and poweroff keeps its own title');
} else {
	bad('menu query keys keep frm_o_cname and poweroff keeps its own title');
}

if (class_exists('Dnsbase') && is_callable(array('Dnsbase', 'getIpaddressList'))) {
	$parent = new stdClass();
	$parent->__masterserver = null;
	try {
		$ips = Dnsbase::getIpaddressList($parent);
		if (is_array($ips)) {
			ok('Dnsbase::getIpaddressList returns an array');
		} else {
			bad('Dnsbase::getIpaddressList returns an array');
		}
	} catch (Throwable $e) {
		bad('Dnsbase::getIpaddressList returns an array', $e->getMessage());
	}
} else {
	bad('Dnsbase::getIpaddressList returns an array', 'class missing');
}

if (class_exists('Dnstemplate') && is_callable(array('Dnstemplate', 'AddListForm'))) {
	$parent = new Client(null, null, 'admin');
	$parent->__masterserver = null;
	try {
		$ret = Dnstemplate::AddListForm($parent, 'dnstemplate');
		if ($ret === null || is_array($ret)) {
			ok('Dnstemplate list add form does not throw no_ip_address');
		} else {
			bad('Dnstemplate list add form does not throw no_ip_address', gettype($ret));
		}
	} catch (Throwable $e) {
		bad('Dnstemplate list add form does not throw no_ip_address', $e->getMessage());
	}
} else {
	bad('Dnstemplate list add form does not throw no_ip_address', 'class missing');
}

$ghtml = class_exists('HtmlLib') ? new HtmlLib() : null;
if (is_object($ghtml) && method_exists($ghtml, 'getFullUrl')) {
	$ghtml->frm_o_o = array();
	$dns = $ghtml->getFullUrl('a=list&c=dnstemplate');
	$bak = $ghtml->getFullUrl('a=show&o=lxbackup');
	if (is_string($dns) && str_contains($dns, 'frm_o_cname=dnstemplate')
		&& !str_contains($dns, 'lxbackup')
		&& is_string($bak) && str_contains($bak, 'lxbackup')
		&& !str_contains($bak, 'dnstemplate')) {
		ok('dashboard DNS Template URL is not Backup/Restore');
	} else {
		bad('dashboard DNS Template URL is not Backup/Restore', (string)$dns . ' / ' . (string)$bak);
	}
} else {
	bad('dashboard DNS Template URL is not Backup/Restore', 'ghtml missing');
}

$menu = is_string($menu) ? $menu : (string)file_get_contents($doc . '/theme/skin/simplicity/default/menu.php');
if (str_contains($menu, 'frm_o_o[1][class]=serverftp')
	&& str_contains($menu, 'kloxo_menu_title("a=show&o=serverftp")')
	&& !str_contains(strstr($menu, 'a=show&o=serverftp') ?: '', 'lxbackup')) {
	ok('FTP Configure menu href is serverftp, not backup ftp_conf');
} else {
	bad('FTP Configure menu href is serverftp, not backup ftp_conf');
}

if (str_contains($menu, 'localhostquery;?>frm_o_cname=ftpsession')
	&& str_contains($menu, 'serverquery;?>frm_o_cname=ftpsession')
	&& str_contains($menu, 'kloxo_menu_title("a=list&c=ftpsession")')) {
	$ftpHome = false;
	foreach (explode("\n", $menu) as $line) {
		if (str_contains($line, 'frm_o_cname=ftpsession') && !str_contains($line, 'localhostquery')
			&& !str_contains($line, 'serverquery') && !str_contains($line, 'clientquery')) {
			$ftpHome = true;
			break;
		}
	}
	if (!$ftpHome) {
		ok('FTP Sessions menu href is pserver, not a parentless client list');
	} else {
		bad('FTP Sessions menu href is pserver, not a parentless client list');
	}
} else {
	bad('FTP Sessions menu href is pserver, not a parentless client list');
}

$parsed = array();
if (class_exists('ftpsession__pureftp') && is_callable(array('ftpsession__pureftp', 'getFtpList'))) {
	try {
		$parsed = ftpsession__pureftp::getFtpList(null);
		if (is_array($parsed)) {
			ok('ftpsession__pureftp::getFtpList returns an array');
		} else {
			bad('ftpsession__pureftp::getFtpList returns an array');
		}
	} catch (Throwable $e) {
		bad('ftpsession__pureftp::getFtpList returns an array', $e->getMessage());
	}
} else {
	bad('ftpsession__pureftp::getFtpList returns an array', 'class missing');
}

if (class_exists('ftpsession') && is_callable(array('ftpsession', 'initThisList'))) {
	try {
		$empty = ftpsession::initThisList(null, 'ftpsession');
		if (is_array($empty) && $empty === array()) {
			ok('ftpsession::initThisList accepts a missing parent');
		} else {
			bad('ftpsession::initThisList accepts a missing parent');
		}
	} catch (Throwable $e) {
		bad('ftpsession::initThisList accepts a missing parent', $e->getMessage());
	}
} else {
	bad('ftpsession::initThisList accepts a missing parent', 'class missing');
}

if (class_exists('serverftp') && is_callable(array('serverftp', 'initThisObjectRule'))) {
	$ps = new pserver(null, null, 'localhost');
	$name = serverftp::initThisObjectRule($ps, 'serverftp');
	$ftp = new serverftp(null, 'localhost', is_string($name) ? $name : 'pserver-localhost');
	$ftp->dbaction = 'add';
	$ftp->setDefault();
	try {
		$form = $ftp->updateform('update', array());
		if ($name === 'pserver-localhost' && $ftp->isSync() === false
			&& is_array($form) && array_key_exists('enable_anon_ftp', $form)) {
			ok('FTP Configure show does not sync a missing serverftp row');
		} else {
			bad('FTP Configure show does not sync a missing serverftp row',
				$name . ' dbaction=' . (string)$ftp->dbaction . ' sync=' . var_export($ftp->isSync(), true));
		}
	} catch (Throwable $e) {
		bad('FTP Configure show does not sync a missing serverftp row', $e->getMessage());
	}
} else {
	bad('FTP Configure show does not sync a missing serverftp row', 'class missing');
}

$ps = new pserver(null, null, 'localhost');
$ps->nname = 'localhost';
$ps->syncserver = 'localhost';
try {
	$form = $ps->commandCenter(null);
	if (is_array($form) && array_key_exists('ccenter_command', $form)
		&& array_key_exists('ccenter_output', $form)) {
		ok('Command Center form loads without a submitted command');
	} else {
		bad('Command Center form loads without a submitted command');
	}
} catch (Throwable $e) {
	bad('Command Center form loads without a submitted command', $e->getMessage());
}

try {
	$tz = pserver::getTimeZoneList();
	if (is_array($tz) && in_array('UTC', $tz, true) && in_array('Europe/Paris', $tz, true)) {
		ok('timezone list is PHP identifiers not a null zoneinfo walk');
	} else {
		bad('timezone list is PHP identifiers not a null zoneinfo walk');
	}
} catch (Throwable $e) {
	bad('timezone list is PHP identifiers not a null zoneinfo walk', $e->getMessage());
}

try {
	$tzform = $ps->updateform('timezone', array());
	if (is_array($tzform) && isset($tzform['timezone'][1]) && is_array($tzform['timezone'][1])) {
		ok('Timezone form has a selectable list');
	} else {
		bad('Timezone form has a selectable list');
	}
} catch (Throwable $e) {
	bad('Timezone form has a selectable list', $e->getMessage());
}

$sshsrc = (string)file_get_contents($doc . '/driver/pserver/sshclientlib.php');
if (!str_contains($sshsrc, '<applet') && str_contains($sshsrc, 'ssh -p')
	&& str_contains($sshsrc, 'only_admin_can_access')) {
	ok('SSH Terminal shows a copyable ssh command instead of a Java applet');
} else {
	bad('SSH Terminal shows a copyable ssh command instead of a Java applet');
}
$clsrc = (string)file_get_contents($doc . '/lib/client/clientlib.php');
$clbase = (string)file_get_contents($doc . '/lib/client/clientbaselib.php');
$pssrc = (string)file_get_contents($doc . '/driver/pserver/pservercorelib.php');
if (!str_contains($clsrc, 'o=sshclient') && !str_contains($clbase, 'sshclient_o')
	&& str_contains($pssrc, 'sshclient_o')) {
	ok('SSH Terminal is on the server object, not client home');
} else {
	bad('SSH Terminal is on the server object, not client home');
}
$fqs = (string)file_get_contents($doc . '/lib/html/lib.php');
if (getFQDNforServer('') === null
	&& str_contains($fqs, 'isset($server[0])')
	&& str_contains($fqs, 'is_array($ipaddr)')) {
	ok('SSH Terminal host lookup does not TypeError on a missing FQDN');
} else {
	bad('SSH Terminal host lookup does not TypeError on a missing FQDN');
}

$cfg = new sshconfig(null, null, 'localhost');
try {
	$sshform = $cfg->updateform('ssh_port', array());
	if (is_array($sshform) && array_key_exists('ssh_port', $sshform)
		&& !str_contains((string)file_get_contents($doc . '/driver/pserver/sshconfiglib.php'), 'cat /etc/ssh/sshd_config')) {
		ok('SSH Configure reads sshd_config without a shell pipeline');
	} else {
		bad('SSH Configure reads sshd_config without a shell pipeline');
	}
} catch (Throwable $e) {
	bad('SSH Configure reads sshd_config without a shell pipeline', $e->getMessage());
}

$stat = coreFfile::createFfileVariables(array('uid' => 1, 'gid' => 1));
$emptyStat = coreFfile::createFfileVariables(null);
if (is_array($stat) && isset($stat['other_username']) && is_array($emptyStat)) {
	ok('File Manager owner lookup accepts a missing posix user');
} else {
	bad('File Manager owner lookup accepts a missing posix user');
}

if (os_get_user_from_uid(999999) === '999999' && os_get_group_from_gid(999999) === '999999') {
	ok('os_get_user_from_uid returns the numeric id when posix has no name');
} else {
	bad('os_get_user_from_uid returns the numeric id when posix has no name');
}

$parent = new pserver(null, null, 'localhost');
$parent->nname = 'localhost';
$parent->username = 'root';
$parent->__masterserver = null;
$parent->__readserver = 'localhost';
$login = new stdClass();
$login->nname = 'admin';
$GLOBALS['login'] = $login;
try {
	$add = Cron::addform($parent, 'cron', array('val' => 'simple'));
	if (is_array($add) && isset($add['variable']['simple_cron']) && $add['action'] === 'add') {
		ok('Cron Tasks simple add form does not need a priv object');
	} else {
		bad('Cron Tasks simple add form does not need a priv object');
	}
} catch (Throwable $e) {
	bad('Cron Tasks simple add form does not need a priv object', $e->getMessage());
}

$cronRow = new Cron(null, null, 'dummy-cron');
$cronRow->ttype = 'simple';
$cronRow->simple_cron = 'weekly';
if ($cronRow->display('command') === '-') {
	ok('Cron list display does not use an undefined cell');
} else {
	bad('Cron list display does not use an undefined cell');
}

try {
	$tform = Ticket::addform($parent, 'ticket');
	if (is_array($tform) && isset($tform['variable']) && array_key_exists('subject', $tform['variable'])
		&& array_key_exists('category', $tform['variable'])) {
		ok('HelpDesk add form loads without helpdeskcategory_a');
	} else {
		bad('HelpDesk add form loads without helpdeskcategory_a');
	}
} catch (Throwable $e) {
	bad('HelpDesk add form loads without helpdeskcategory_a', $e->getMessage());
}

$wsrc = (string)file_get_contents($doc . '/lib/ticket/ticketlib.php');
if (str_contains($wsrc, 'Welcome to Kloxo') && str_contains($wsrc, "createParentName('pserver', 'Kloxo')")) {
	ok('Welcome ticket is Created By Kloxo with subject Welcome to Kloxo');
} else {
	bad('Welcome ticket is Created By Kloxo with subject Welcome to Kloxo');
}

if (function_exists('kloxo_implode') && function_exists('kloxo_list_from')) {
	if (kloxo_implode(',', 'localhost') === 'localhost'
		&& kloxo_implode(',', array('a', 'b')) === 'a,b'
		&& kloxo_implode(',', null) === '') {
		ok('kloxo_implode accepts a string or array');
	} else {
		bad('kloxo_implode accepts a string or array');
	}
	$mails = kloxo_list_from('a@example.com, b@example.com');
	if ($mails === array('a@example.com', 'b@example.com')
		&& kloxo_list_from('a@example.com') === array('a@example.com')
		&& kloxo_list_from(array('x@y.z')) === array('x@y.z')
		&& kloxo_list_from('') === array()) {
		ok('kloxo_list_from splits contact emails without implode-on-string');
	} else {
		bad('kloxo_list_from splits contact emails without implode-on-string');
	}
} else {
	bad('kloxo_implode accepts a string or array', 'helpers missing');
	bad('kloxo_list_from splits contact emails without implode-on-string');
}

$addsrc = (string)file_get_contents($doc . '/lib/client/clientbaselib.php');
if (str_contains($addsrc, 'kloxo_list_from($param[\'contactemail\'])')
	&& !str_contains($addsrc, 'implode(",", str_replace(" ", "", $param[\'contactemail\']))')) {
	ok('client add does not implode() a contactemail string');
} else {
	bad('client add does not implode() a contactemail string');
}

$plansrc = (string)file_get_contents($doc . '/lib/html/resourceplanlib.php');
$postadd = $plansrc;
if (preg_match('/function postAdd\(\)\s*\{.*?\n\t\}/s', $plansrc, $m)) {
	$postadd = $m[0];
}
if (str_contains($postadd, 'new ListPriv')
	&& str_contains($postadd, "getIpaddress(array('localhost'))")
	&& !str_contains($postadd, 'getDnsTemplateList')) {
	ok('resourceplan postAdd does not require a DNS template');
} else {
	bad('resourceplan postAdd does not require a DNS template');
}
if (str_contains($plansrc, 'fix_nname_to_be_variable($param[\'realname\']')
	&& str_contains($plansrc, 'validate_plan_name($param[\'realname\'])')) {
	ok('resourceplan add sanitizes the name before validating');
} else {
	bad('resourceplan add sanitizes the name before validating');
}

$privsrc = (string)file_get_contents($doc . '/lib/html/objectactionlib.php');
if (str_contains($privsrc, "\$class === 'resourceplan'")) {
	ok('resourceplan add does not consume parent quota like a client');
} else {
	bad('resourceplan add does not consume parent quota like a client');
}

$initsrc = (string)file_get_contents($doc . '/lib/php/lxclass.php');
if (str_contains($initsrc, '!isset($this->listpriv) || !is_object($this->listpriv)')) {
	ok('initThisDef creates listpriv on a new object');
} else {
	bad('initThisDef creates listpriv on a new object');
}

try {
	$admin = new Client(null, null, 'admin');
	$admin->nname = 'admin';
	$admin->cttype = 'admin';
	$ret = resourceplan::addform($admin, 'resourceplan');
	if (is_array($ret) && ($ret['action'] ?? '') === 'add' && isset($ret['variable']['realname'])) {
		ok('resourceplan addform returns a form');
	} else {
		bad('resourceplan addform returns a form');
	}
} catch (Throwable $e) {
	bad('resourceplan addform returns a form', $e->getMessage());
}

try {
	$admin = new Client(null, null, 'admin');
	$admin->nname = 'admin';
	$admin->cttype = 'admin';
	$p = resourceplan::add($admin, 'resourceplan', array('realname' => 'Default Plan'));
	if (($p['realname'] ?? '') === 'default_plan') {
		ok('resourceplan add accepts a display name with spaces');
	} else {
		bad('resourceplan add accepts a display name with spaces', (string)($p['realname'] ?? ''));
	}
} catch (Throwable $e) {
	bad('resourceplan add accepts a display name with spaces', $e->getMessage());
}

try {
	$ps = new pserver(null, null, 'localhost');
	$ps->nname = 'localhost';
	$sw = new serverweb(null, 'localhost', 'pserver-localhost');
	$sw->syncserver = 'localhost';
	$sw->__parent_o = $ps;
	$uflist = $sw->createShowUpdateform();
	$need = array('php_used', 'php_branch', 'multiple_php_activate', 'multiple_php_install',
		'multiple_php_remove', 'php_type', 'apache_optimize', 'mysql_convert', 'fix_chownchmod');
	$okform = true;
	foreach ($need as $k) {
		if (!array_key_exists($k, $uflist)) {
			$okform = false;
			break;
		}
	}
	if ($okform && !array_key_exists('enable_php52m_fpm', $uflist)) {
		ok('Webserver Configure shows the Kloxo-MR sections without php52m');
	} else {
		bad('Webserver Configure shows the Kloxo-MR sections without php52m');
	}
} catch (Throwable $e) {
	bad('Webserver Configure shows the Kloxo-MR sections without php52m', $e->getMessage());
}

try {
	$sw = new serverweb(null, 'localhost', 'pserver-localhost');
	$sw->syncserver = 'localhost';
	$used = $sw->updateform('php_used', array());
	$branch = $sw->updateform('php_branch', array());
	$inst = $sw->updateform('multiple_php_install', array());
	$opt = $sw->updateform('apache_optimize', array());
	$phpUsed = $used['php_used'][1] ?? array();
	$avail = $inst['multiple_php_install'][1] ?? array();
	if (is_array($phpUsed) && $phpUsed !== [] && !in_array('--PHP Branch--', $phpUsed, true)
		&& is_array($branch['php_branch'][1] ?? null)
		&& is_array($avail) && !in_array('m', $avail, true) && !in_array('php52m', $avail, true)
		&& array_key_exists('apache_optimize', $opt) && array_key_exists('enable_keepalive', $opt)) {
		ok('Webserver Configure PHP lists are Ubuntu phpX.Y not php52m');
	} else {
		bad('Webserver Configure PHP lists are Ubuntu phpX.Y not php52m');
	}
} catch (Throwable $e) {
	bad('Webserver Configure PHP lists are Ubuntu phpX.Y not php52m', $e->getMessage());
}

$webSrc = (string)file_get_contents($doc . '/driver/web/serverweblib.php');
if (!str_contains($webSrc, 'getCleanRpmBranchListOnList')
	&& !str_contains($webSrc, "exec(\"cat /etc/httpd")
	&& str_contains($webSrc, 'KloxoPhpver::phpUsedChoices')) {
	ok('Webserver Configure does not parse CentOS php.lst or cat httpd');
} else {
	bad('Webserver Configure does not parse CentOS php.lst or cat httpd');
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
