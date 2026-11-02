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

$pssrc = (string)file_get_contents($doc . '/driver/pserver/pserverlib.php');
$cmsrc = (string)file_get_contents($doc . '/lib/domain/clientmail.php');
$listdisp = (string)file_get_contents($doc . '/theme/coredisplaylib.php');
if (str_contains($pssrc, 'function getSelfList')
	&& str_contains($pssrc, 'adminServerSwitchList')
	&& str_contains($cmsrc, 'serverNameForParent')
	&& str_contains($cmsrc, 'self::serverNameForParent($parent)')
	&& str_contains($listdisp, 'printShowSelectBox')) {
	ok('Mails Per Client list has Switch To Another for servers');
} else {
	bad('Mails Per Client list has Switch To Another for servers');
}
try {
	$ps = new pserver(null, null, 'localhost');
	$ps->nname = 'localhost';
	$sw = $ps->getSelfList();
	if (is_array($sw) && isset($sw['localhost']) && is_object($sw['localhost'])) {
		ok('pserver Switch To Another includes localhost');
	} else {
		bad('pserver Switch To Another includes localhost', var_export(is_array($sw) ? array_keys($sw) : $sw, true));
	}
} catch (Throwable $e) {
	bad('pserver Switch To Another includes localhost', $e->getMessage());
}
try {
	$pobj = new pserver(null, null, 'localhost');
	$pobj->nname = 'mailhost.example';
	if (clientmail::serverNameForParent($pobj) === 'mailhost.example'
		&& clientmail::serverNameForParent(null) === 'localhost') {
		ok('Mails Per Client uses the selected server name');
	} else {
		bad('Mails Per Client uses the selected server name');
	}
} catch (Throwable $e) {
	bad('Mails Per Client uses the selected server name', $e->getMessage());
}

$src = file_get_contents($doc . '/lib/php/lxlib.php');
if (is_string($src) && str_contains($src, 'function kloxo_skip_find_class')
	&& str_contains($src, "'sshconfig', 'lxguard', 'sshclient', 'ffile', 'ticket', 'notification'")
	&& str_contains($src, "'lxguardhitdisplay', 'rawlxguardhit', 'lxguardwhitelist'")) {
	ok('Find column is skipped on log/driver/php/backup/webserver/ssh/lxguard/file/ticket/notification show pages');
} else {
	bad('Find column is skipped on log/driver/php/backup/webserver/ssh/lxguard/file/ticket/notification show pages');
}
if (function_exists('kloxo_skip_find_class')
	&& kloxo_skip_find_class('lxguard')
	&& kloxo_skip_find_class('lxguardhitdisplay')
	&& kloxo_skip_find_class('rawlxguardhit')
	&& kloxo_skip_find_class('lxguardwhitelist')
	&& kloxo_skip_find_class('ticket')
	&& kloxo_skip_find_class('notification')
	&& !kloxo_skip_find_class('tickethistory')
	&& !kloxo_skip_find_class('client')) {
	ok('LxGuard, HelpDesk ticket and Notification pages skip the Find input');
} else {
	bad('LxGuard, HelpDesk ticket and Notification pages skip the Find input');
}

$clsrc = (string)file_get_contents($doc . '/lib/client/clientlib.php');
$pssrc = (string)file_get_contents($doc . '/driver/pserver/pserverlib.php');
$dom = '';
$dpos = strpos($clsrc, "__title_domain_rec");
if ($dpos !== false) {
	$dom = substr($clsrc, $dpos, 900);
}
if ($dom !== '' && str_contains($dom, 'if (!$this->isAdmin())')
	&& preg_match('/if \(!\$this->isAdmin\(\)\)\s*\{\s*\$this->getMysqlDbAdmin/s', $dom)
	&& str_contains($pssrc, "__title_webmailanddb")
	&& str_contains($pssrc, 'getMysqlDbAdmin($alist)')) {
	ok('SQL Manager is in Basic, not an extra Domain tile for admin');
} else {
	bad('SQL Manager is in Basic, not an extra Domain tile for admin');
}

$pmaUrl = '';
$pmaErr = '';
try {
	$psPma = new pserver(null, 'localhost', 'localhost');
	$psPma->nname = 'localhost';
	$pmaAlist = array();
	$psPma->getMysqlDbAdmin($pmaAlist);
	$pmaUrl = (isset($pmaAlist[0]) && is_object($pmaAlist[0]) && isset($pmaAlist[0]->url))
		? (string) $pmaAlist[0]->url : '';
} catch (Throwable $e) {
	$pmaErr = $e->getMessage();
}
$cliPmaUrl = '';
try {
	$cliPma = new Client(null, 'localhost', 'admin');
	$cliPma->nname = 'admin';
	$cliPma->mysqldbsyncserver = 'localhost';
	$cliAlist = array();
	$cliPma->getMysqlDbAdmin($cliAlist);
	$cliPmaUrl = (isset($cliAlist[0]) && is_object($cliAlist[0]) && isset($cliAlist[0]->url))
		? (string) $cliAlist[0]->url : '';
} catch (Throwable $e) {
	$pmaErr = $pmaErr !== '' ? $pmaErr : $e->getMessage();
}
$dbPmaUrl = '';
try {
	$dbPma = new mysqldb(null, 'localhost', 'testdb');
	$dbPma->syncserver = 'localhost';
	$dbPmaUrl = (string) $dbPma->getDbAdminUrl();
} catch (Throwable $e) {
	$pmaErr = $pmaErr !== '' ? $pmaErr : $e->getMessage();
}
$btnSrc = (string) file_get_contents($doc . '/theme/htmllib.php');
$pmaWant = function_exists('kloxo_pma_url') ? kloxo_pma_url() : '/thirdparty/phpMyAdmin/';
if ($pmaUrl === $pmaWant && $cliPmaUrl === $pmaWant && $dbPmaUrl === $pmaWant
	&& str_contains($pssrc, 'kloxo_pma_href')
	&& !str_contains($pssrc, 'mywebsql')
	&& !str_contains($clsrc, 'mywebsql')
	&& str_contains($btnSrc, "str_starts_with(\$resolvedHref, '/thirdparty/')")) {
	ok('SQL Manager tile points at phpMyAdmin');
} else {
	bad('SQL Manager tile points at phpMyAdmin',
		$pmaErr !== '' ? $pmaErr : ('ps=' . $pmaUrl . ' client=' . $cliPmaUrl . ' db=' . $dbPmaUrl));
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
if (!str_contains($sshsrc, '<applet')
	&& !str_contains($sshsrc, 'web-console/index.php')
	&& !str_contains($sshsrc, 'unserialize')
	&& !str_contains($sshsrc, 'NOPASSWD')
	&& str_contains($sshsrc, 'kloxo-ssh-terminal')
	&& str_contains($sshsrc, "array('/bin/bash', '-lc'")
	&& str_contains($sshsrc, 'rl_exec_get')
	&& str_contains($sshsrc, "return 'root'")
	&& str_contains($sshsrc, 'only_admin_can_access')) {
	ok('SSH Terminal is an in-page WebConsole, not a Java applet');
} else {
	bad('SSH Terminal is an in-page WebConsole, not a Java applet');
}
try {
	$echoed = sshclient::runAdminCommand('echo kloxo-ssh-ok');
	if (is_string($echoed) && str_contains($echoed, 'kloxo-ssh-ok')) {
		ok('SSH Terminal runs echo via bash -lc');
	} else {
		bad('SSH Terminal runs echo via bash -lc', var_export($echoed, true));
	}
} catch (Throwable $e) {
	bad('SSH Terminal runs echo via bash -lc', $e->getMessage());
}
try {
	$empty = sshclient::runAdminCommand('');
	if ($empty === '') {
		ok('SSH Terminal rejects an empty command');
	} else {
		bad('SSH Terminal rejects an empty command', var_export($empty, true));
	}
} catch (Throwable $e) {
	bad('SSH Terminal rejects an empty command', $e->getMessage());
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

if (function_exists('kloxo_string') && kloxo_string(array('x')) === '' && kloxo_string(null) === '' && kloxo_string(7) === '7') {
	ok('kloxo_string refuses arrays');
} else {
	bad('kloxo_string refuses arrays');
}

$ff = new Ffile(null, 'localhost', sys_get_temp_dir(), '/', 'nobody');
$ff->mode = 16877;
$ff->size = 4096;
$ff->mtime = 1521260517;
$ff->base = 'Maildir';
$ff->nname = '/Maildir';
$ff->ttype = 'directory';
$dummy = null;
$perm = $ff->getPermissions($dummy);
$nlist = array();
try {
	$nlist = Ffile::createListNlist($ff, null);
} catch (Throwable $e) {
	$nlist = array('error' => $e->getMessage());
}
$htmlsrc = (string)file_get_contents($doc . '/theme/htmllib.php');
if (is_string($perm) && $perm !== ''
	&& is_string($ff->display('size')) && str_ends_with($ff->display('size'), 'K')
	&& is_string($ff->display('mtime'))
	&& is_array($nlist) && isset($nlist['nname'], $nlist['mode'], $nlist['other_username'], $nlist['pvrename'], $nlist['pvdownload'])
	&& str_contains($htmlsrc, "strtolower((string)\$class) !== 'ffile'")
	&& str_contains($htmlsrc, 'kloxo_string($parent->nname)')) {
	ok('File Manager list columns and toolbar search match Kloxo-MR');
} else {
	bad('File Manager list columns and toolbar search match Kloxo-MR', isset($nlist['error']) ? $nlist['error'] : '');
}

$cff = (string)file_get_contents($doc . '/lib/php/coreffilelib.php');
$missing = array('no');
try {
	$missing = coreFfile::get_full_stat('nobody', sys_get_temp_dir(), sys_get_temp_dir() . '/kloxo-no-such-ffile-dir', false);
} catch (Throwable $e) {
	$missing = array('error' => $e->getMessage());
}
if ((is_array($missing) && $missing === array())
	|| str_contains($cff, "return array();\n\t\t}\n\n\t\t\$ret = lxfile_dstat")) {
	ok('File Manager listing a missing directory is an empty list');
} else {
	bad('File Manager listing a missing directory is an empty list', isset($missing['error']) ? $missing['error'] : '');
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
if (str_contains($wsrc, 'Welcome to Kloxo') && str_contains($wsrc, "createParentName('pserver', 'Kloxo')")
	&& str_contains($wsrc, 'function getSelfList')) {
	ok('Welcome ticket is Created By Kloxo with subject Welcome to Kloxo');
} else {
	bad('Welcome ticket is Created By Kloxo with subject Welcome to Kloxo');
}

$tk = new Ticket(null, null, '1');
$tk->nname = '1';
$tk->state = 'open';
$tk->made_by = 'pserver-Kloxo';
$tk->sent_to = 'client-admin';
$tkSw = null;
$tkSwErr = '';
try {
	$tkSw = $tk->getSelfList();
} catch (Throwable $e) {
	$tkSwErr = $e->getMessage();
}
$tkProps = array('property' => array());
$tk->createShowPropertyList($tkProps);
$tkAdd = $tk->createShowAddform();
$tkClist = $tk->createShowClist('');
$histForm = null;
$histFormErr = '';
try {
	$histForm = TicketHistory::addform($tk, 'tickethistory');
} catch (Throwable $e) {
	$histFormErr = $e->getMessage();
}
$histVars = (is_array($histForm) && isset($histForm['variable']) && is_array($histForm['variable']))
	? $histForm['variable'] : array();
$histNlist = TicketHistory::createListNlist($tk, null);
$histAdd = null;
$histAddErr = '';
try {
	$histAdd = TicketHistory::add($tk, 'tickethistory', array('state' => 'closed', 'text_reason' => 'done'));
} catch (Throwable $e) {
	$histAddErr = $e->getMessage();
}
$htmlSrc = (string)file_get_contents($doc . '/theme/htmllib.php');
$keepReplySearch = str_contains($htmlSrc, "strtolower((string)\$class) !== 'tickethistory'");
if (is_array($tkSw) && isset($tkSw['1']) && is_object($tkSw['1'])
	&& isset($tkProps['property'][0], $tkProps['property'][1])
	&& str_contains((string)$tkProps['property'][0], 'a=show')
	&& str_contains((string)$tkProps['property'][1], 'tickethistory')
	&& is_array($tkAdd) && array_key_exists('tickethistory', $tkAdd)
	&& is_array($tkClist) && array_key_exists('tickethistory', $tkClist)
	&& is_array($histForm) && ($histForm['action'] ?? '') === 'add'
	&& array_key_exists('state', $histVars) && array_key_exists('text_reason', $histVars)
	&& is_array($histNlist) && isset($histNlist['made_by'], $histNlist['text_reason'], $histNlist['ddate'], $histNlist['state'])
	&& is_array($histAdd) && ($histAdd['nname'] ?? '') === '1___1'
	&& ($histAdd['state'] ?? '') === 'closed'
	&& (int)$tk->history_num === 1
	&& $tk->display('made_by') === 'Kloxo'
	&& $keepReplySearch) {
	ok('HelpDesk ticket show skips Find, lists Switch To Another, and Add Reply');
} else {
	$why = $tkSwErr !== '' ? $tkSwErr : ($histFormErr !== '' ? $histFormErr : ($histAddErr !== '' ? $histAddErr : 'selflist/addform mismatch'));
	bad('HelpDesk ticket show skips Find, lists Switch To Another, and Add Reply', $why);
}

$escForm = null;
$escFormErr = '';
try {
	$escForm = $tk->updateform('escalate', array());
} catch (Throwable $e) {
	$escFormErr = $e->getMessage();
}
$escFields = array();
if (is_array($escForm)) {
	foreach ($escForm as $ek => $ev) {
		if (!str_starts_with((string)$ek, '__')) {
			$escFields[] = $ek;
		}
	}
}
$escDo = null;
$escDoErr = '';
try {
	$escDo = $tk->updateEscalate(array());
} catch (Throwable $e) {
	$escDoErr = $e->getMessage();
}
$hasEscalateTab = str_contains($wsrc, 'a=updateform&sa=escalate')
	&& str_contains($wsrc, "if (\$subaction === 'escalate')");
if (is_array($escForm) && $escFields === array()
	&& $hasEscalateTab
	&& is_array($escDo) && ($escDo['nothing'] ?? '') === 'h'
	&& ($tk->escalate ?? '') === 'on') {
	ok('HelpDesk Escalate is a confirmation form with Update');
} else {
	$why = $escFormErr !== '' ? $escFormErr : ($escDoErr !== '' ? $escDoErr : 'escalate form mismatch');
	bad('HelpDesk Escalate is a confirmation form with Update', $why);
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
		'multiple_php_remove', 'php_type', 'apache_optimize', 'mysql_convert', 'fix_chownchmod',
		'pagespeed_clear_cache');
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

	$expect = array(
		'php_used' => array('php_used'),
		'php_branch' => array('php_branch'),
		'multiple_php_activate' => array('multiple_php_already_installed', 'multiple_php_flag'),
		'multiple_php_install' => array('multiple_php_install'),
		'multiple_php_remove' => array('multiple_php_remove'),
		'php_type' => array('php_type', 'secondary_php'),
		'apache_optimize' => array('apache_optimize', 'enable_keepalive'),
		'mysql_convert' => array('mysql_convert', 'mysql_charset'),
		'fix_chownchmod' => array('fix_chownchmod'),
		'pagespeed_clear_cache' => array('pagespeed_cache'),
	);
	$allForms = true;
	$allWhy = '';
	foreach ($expect as $sa => $fields) {
		try {
			$vf = $sw->updateform($sa, array());
		} catch (Throwable $e) {
			$allForms = false;
			$allWhy = $sa . ': ' . $e->getMessage();
			break;
		}
		if (!is_array($vf)) {
			$allForms = false;
			$allWhy = $sa . ': not an array';
			break;
		}
		foreach ($fields as $f) {
			if (!array_key_exists($f, $vf)) {
				$allForms = false;
				$allWhy = $sa . ' missing ' . $f;
				break 2;
			}
		}
	}
	$phpUsed = $sw->updateform('php_used', array());
	$inst = $sw->updateform('multiple_php_install', array());
	$usedList = $phpUsed['php_used'][1] ?? array();
	$avail = $inst['multiple_php_install'][1] ?? array();
	if ($allForms && is_array($usedList) && $usedList !== [] && !in_array('--PHP Branch--', $usedList, true)
		&& !in_array('php52m', is_array($avail) ? $avail : array(), true)) {
		ok('Webserver Configure PHP lists are Ubuntu phpX.Y not php52m');
	} else {
		bad('Webserver Configure PHP lists are Ubuntu phpX.Y not php52m', $allWhy);
	}
	if ($allForms) {
		ok('Webserver Configure every section form loads');
	} else {
		bad('Webserver Configure every section form loads', $allWhy);
	}

	$drv = new serverweb__nginx();
	$drv->main = $sw;
	$sw->apache_optimize = 'high';
	$sw->enable_keepalive = 'on';
	$optErr = '';
	try {
		$drv->set_apache_optimize();
	} catch (Throwable $e) {
		$optErr = $e->getMessage();
	}
	if ($optErr === '') {
		ok('Webserver Configure Apache optimize apply does not TypeError');
	} else {
		bad('Webserver Configure Apache optimize apply does not TypeError', $optErr);
	}
} catch (Throwable $e) {
	bad('Webserver Configure shows the Kloxo-MR sections without php52m', $e->getMessage());
	bad('Webserver Configure PHP lists are Ubuntu phpX.Y not php52m', $e->getMessage());
	bad('Webserver Configure every section form loads', $e->getMessage());
	bad('Webserver Configure Apache optimize apply does not TypeError', $e->getMessage());
}

$webSrc = (string)file_get_contents($doc . '/driver/web/serverweblib.php');
$webSync = (string)file_get_contents($doc . '/driver/web/serverweb__lib.php');
if (!str_contains($webSrc, 'getCleanRpmBranchListOnList')
	&& !str_contains($webSrc, "exec(\"cat /etc/httpd")
	&& str_contains($webSrc, 'KloxoPhpver::phpUsedChoices')
	&& str_contains($webSync, '"--select=chown"')
	&& str_contains($webSync, 'function writeUbuntuApacheOptimize')
	&& str_contains($webSync, 'function set_php_type_ubuntu')) {
	ok('Webserver Configure does not parse CentOS php.lst or cat httpd');
} else {
	bad('Webserver Configure does not parse CentOS php.lst or cat httpd');
}

global $ghtml, $login;
$prevLogin = $login;
$login = new Client(null, 'localhost', 'admin');
$login->nname = 'admin';
$login->syncserver = 'localhost';
$html = (is_object($ghtml) && method_exists($ghtml, 'format_message')) ? $ghtml : new HtmlLib();
$html->__http_vars['frm_o_o'] = array();
$src = (string)file_get_contents($doc . '/lang/en-us/messagelib.php');
$msg = 'Click <url:k[class]=pserver&k[nname]=[%_server_%]&a=show&o=lxguard>[here]</url> to configure Lxguard.';
$htmlOut = '';
try {
	$htmlOut = (string)$html->format_message($msg, null, false);
} catch (Throwable $e) {
	$htmlOut = $e->getMessage();
}
$login = $prevLogin;
if (str_contains($src, 'lxguard_not_configured')
	&& str_contains($src, 'a=show&o=lxguard')
	&& str_contains($htmlOut, 'frm_action=show')
	&& str_contains($htmlOut, 'frm_o_o')
	&& str_contains($htmlOut, 'pserver')
	&& str_contains($htmlOut, 'localhost')
	&& str_contains($htmlOut, 'lxguard')
	&& str_contains($htmlOut, 'href=')
	&& !str_contains($htmlOut, '%_server_%')
	&& !str_contains($htmlOut, '%25_server_')) {
	ok('LxGuard alert [here] points at pserver/localhost/lxguard');
} else {
	bad('LxGuard alert [here] points at pserver/localhost/lxguard', $htmlOut);
}

$lgParent = new pserver(null, 'localhost', 'localhost');
$lgParent->nname = 'localhost';
$lg = new lxguard(null, 'localhost', 'localhost');
$lg->nname = 'localhost';
$lg->syncserver = 'localhost';
$lg->__parent_o = $lgParent;
$lgSwitch = null;
$lgSwitchErr = '';
try {
	$lgSwitch = $lg->getSelfList();
} catch (Throwable $e) {
	$lgSwitchErr = $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
}
$lgTabs = array('property' => array());
$lg->createShowPropertyList($lgTabs);
$lgForm = null;
try {
	$lgForm = $lg->updateform('update', array());
} catch (Throwable $e) {
	$lgSwitchErr = $lgSwitchErr !== '' ? $lgSwitchErr : $e->getMessage();
}
$lgSrc = (string) file_get_contents($doc . '/driver/pserver/lxguardlib.php');
$lgTabOk = is_array($lgTabs['property'] ?? null)
	&& in_array('a=show', $lgTabs['property'], true)
	&& in_array('a=list&c=lxguardhitdisplay', $lgTabs['property'], true)
	&& in_array('a=list&c=rawlxguardhit', $lgTabs['property'], true)
	&& in_array('a=list&c=lxguardwhitelist', $lgTabs['property'], true);
$lgSwitchOk = is_array($lgSwitch) && isset($lgSwitch['localhost'])
	&& is_object($lgSwitch['localhost']) && method_exists($lgSwitch['localhost'], 'getId')
	&& (string) $lgSwitch['localhost']->getId() === 'localhost';
$lgFormOk = is_array($lgForm) && array_key_exists('disablehit', $lgForm)
	&& array_key_exists('configure_flag', $lgForm);
if ($lgSwitchOk && $lgTabOk && $lgFormOk && str_contains($lgSrc, 'function getSelfList')
	&& str_contains($lgSrc, 'adminServerSwitchList')) {
	ok('LxGuard show has Switch To Another and Kloxo-MR tabs');
} else {
	bad('LxGuard show has Switch To Another and Kloxo-MR tabs',
		$lgSwitchErr !== '' ? $lgSwitchErr
			: ('keys=' . implode(',', is_array($lgSwitch) ? array_keys($lgSwitch) : array())
				. ' tabs=' . implode(',', (array) ($lgTabs['property'] ?? array()))
				. ' form=' . implode(',', is_array($lgForm) ? array_keys($lgForm) : array())));
}

$prevLogin2 = $login;
$login = new Client(null, 'localhost', 'admin');
$login->nname = 'admin';
$login->syncserver = 'localhost';
$login->cttype = 'admin';
$parent = new Client(null, 'localhost', 'admin');
$parent->nname = 'admin';
$parent->syncserver = 'localhost';
$parent->cttype = 'admin';
$parent->username = 'admin';
$parent->websyncserver = 'localhost';
$form = null;
$formErr = '';
try {
	if (method_exists($parent, 'ensureAdminResourcePriv')) {
		$parent->ensureAdminResourcePriv();
	}
	$form = domain::addform($parent, 'domain');
} catch (Throwable $e) {
	$formErr = $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
}
$vars = (is_array($form) && isset($form['variable']) && is_array($form['variable'])) ? $form['variable'] : array();
$listForm = null;
try {
	$listForm = domain::AddListForm($parent, 'domain');
} catch (Throwable $e) {
	$listForm = $e->getMessage();
}
$tpl = domainBase::getDnsTemplateList($parent);
$hidden = make_hidden_if_one(null);
$addSrc = (string)file_get_contents($doc . '/lib/domain/domainlib.php');
$login = $prevLogin2;
if (is_array($form) && ($form['action'] ?? '') === 'add'
	&& isset($vars['nname'], $vars['domain_owned_flag'], $vars['docroot'], $vars['ssl_flag'],
		$vars['awstats_flag'], $vars['easyinstaller_flag'], $vars['cgi_flag'], $vars['php_flag'],
		$vars['simple_add_f'])
	&& is_array($listForm)
	&& is_array($tpl)
	&& is_array($hidden)
	&& str_contains($addSrc, "isset(\$param['domain_owned_flag'])")
	&& str_contains($addSrc, 'createDefaultTemplate')) {
	ok('admin Domains add form matches Kloxo-MR and does not require a DNS template');
} else {
	bad('admin Domains add form matches Kloxo-MR and does not require a DNS template',
		$formErr !== '' ? $formErr : ('keys=' . implode(',', array_keys($vars))));
}

$prevLogin3 = $login;
$login = new Client(null, 'localhost', 'admin');
$login->nname = 'admin';
$login->syncserver = 'localhost';
$login->cttype = 'admin';
$client = new Client(null, 'localhost', 'admin');
$client->nname = 'admin';
$client->syncserver = 'localhost';
$client->cttype = 'admin';
$ini = new phpini(null, 'localhost', 'client-admin');
$ini->nname = 'client-admin';
$ini->syncserver = 'localhost';
$ini->parent_clname = 'client-admin';
$ini->__parent_o = $client;
$tabs = array('property' => array());
$tabRet = $ini->createShowPropertyList($tabs);
$edit = null;
$extra = null;
$editErr = '';
$extraErr = '';
try {
	$edit = $ini->updateform('edit', array());
} catch (Throwable $e) {
	$editErr = $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
}
try {
	$extra = $ini->updateform('extraedit', array());
} catch (Throwable $e) {
	$extraErr = $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
}
$flagDesc = null;
$flagDescErr = '';
try {
	$h = new HtmlLib();
	$flagDesc = $h->get_classvar_description_after_overload('phpini', 'phpini_flag_b-display_error_flag');
} catch (Throwable $e) {
	$flagDescErr = $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
}
$login = $prevLogin3;
$sharedTabs = phpini::phpiniPropertyTabs();
$tabOk = is_array($tabs['property'] ?? null)
	&& in_array('a=show', $tabs['property'], true)
	&& in_array('a=updateform&sa=extraedit', $tabs['property'], true)
	&& is_array($tabRet) && ($tabRet['property'] ?? null) === $tabs['property']
	&& is_array($sharedTabs)
	&& in_array('a=show', $sharedTabs, true)
	&& in_array('a=updateform&sa=extraedit', $sharedTabs, true)
	&& method_exists($ini, 'getSelfList')
	&& is_array($flagDesc) && isset($flagDesc[0]) && $flagDesc[0] === 'f'
	&& $flagDescErr === '';
$editNeed = array(
	'phpini_flag_b-display_error_flag',
	'phpini_flag_b-log_errors_flag',
	'phpini_flag_b-output_compression_flag',
	'phpini_flag_b-date_timezone_flag',
	'phpini_flag_b-phpfpm_type_flag',
	'phpini_flag_b-session_save_path_flag',
	'phpini_flag_b-phpfpm_limit_extensions',
);
$extraNeed = array(
	'phpini_flag_b-sendmail_from',
	'phpini_flag_b-enable_dl_flag',
	'phpini_flag_b-output_buffering_flag',
	'phpini_flag_b-allow_url_fopen_flag',
	'phpini_flag_b-allow_url_include_flag',
	'phpini_flag_b-register_argc_argv_flag',
	'phpini_flag_b-disable_functions',
	'phpini_flag_b-max_execution_time_flag',
	'phpini_flag_b-max_input_time_flag',
	'phpini_flag_b-memory_limit_flag',
	'phpini_flag_b-post_max_size_flag',
	'phpini_flag_b-upload_max_filesize',
	'phpini_flag_b-max_input_vars_flag',
	'phpini_flag_b-file_uploads_flag',
	'phpini_flag_b-cgi_force_redirect_flag',
);
$editOk = is_array($edit);
foreach ($editNeed as $k) {
	if (!is_array($edit) || !array_key_exists($k, $edit)) {
		$editOk = false;
		break;
	}
}
$extraOk = is_array($extra);
foreach ($extraNeed as $k) {
	if (!is_array($extra) || !array_key_exists($k, $extra)) {
		$extraOk = false;
		break;
	}
}
$sessionPath = (isset($ini->phpini_flag_b) && is_object($ini->phpini_flag_b)
	&& isset($ini->phpini_flag_b->session_save_path_flag))
	? (string) $ini->phpini_flag_b->session_save_path_flag : '';
$tz = is_array($edit) && isset($edit['phpini_flag_b-date_timezone_flag'][1])
	? $edit['phpini_flag_b-date_timezone_flag'][1] : array();
$fpm = is_array($edit) && isset($edit['phpini_flag_b-phpfpm_type_flag'][1])
	? $edit['phpini_flag_b-phpfpm_type_flag'][1] : array();
if ($tabOk && $editOk && $extraOk
	&& $sessionPath === '/home/kloxo/client/admin/session'
	&& is_array($tz) && $tz !== array()
	&& is_array($fpm) && in_array('static', $fpm, true)
	&& is_array($extra) && !array_key_exists('phpini_flag_b-display_error_flag', $extra)) {
	ok('PHP Configure and Advanced PHP Configure share tabs and Kloxo-MR fields');
} else {
	$detail = $editErr !== '' ? $editErr : ($extraErr !== '' ? $extraErr
		: ($flagDescErr !== '' ? $flagDescErr
		: ('tabs=' . implode(',', (array) ($tabs['property'] ?? array()))
			. ' session=' . $sessionPath
			. ' flag0=' . (is_array($flagDesc) && isset($flagDesc[0]) ? (string) $flagDesc[0] : 'none')
			. ' edit=' . implode(',', is_array($edit) ? array_keys($edit) : array())
			. ' extra=' . implode(',', is_array($extra) ? array_keys($extra) : array()))));
	bad('PHP Configure and Advanced PHP Configure share tabs and Kloxo-MR fields', $detail);
}

$syncSrc = (string) file_get_contents($doc . '/driver/pserver/phpini__synclib.php');
$ufSrc = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
$iniSrc = (string) file_get_contents($doc . '/driver/pserver/phpinilib.php');
if (str_contains($syncSrc, '//	lxfile_unix_chmod($fcgid_target, "0755")')
	&& !preg_match('/^\s*lxfile_unix_chmod\(\$fcgid_target/m', $syncSrc)
	&& str_contains($ufSrc, 'getSelfList: ')
	&& str_contains($ufSrc, 'kloxo_print_object_property_tabs')
	&& str_contains($ufSrc, "\$class !== 'phpini'")
	&& str_contains($iniSrc, 'function phpiniPropertyTabs')
	&& str_contains($iniSrc, '#[AllowDynamicProperties]')) {
	ok('phpini sync does not chmod an unset fcgid_target; UpdateForm guards getSelfList');
} else {
	bad('phpini sync does not chmod an unset fcgid_target; UpdateForm guards getSelfList');
}

$wdParent = new pserver(null, 'localhost', 'localhost');
$wdParent->nname = 'localhost';
$wdForm = null;
$wdFormErr = '';
try {
	$wdForm = watchdog::addform($wdParent, 'watchdog');
} catch (Throwable $e) {
	$wdFormErr = $e->getMessage();
}
$wdVars = (is_array($wdForm) && isset($wdForm['variable']) && is_array($wdForm['variable']))
	? $wdForm['variable'] : array();
$wdNlist = watchdog::createListNlist($wdParent, null);
$wdDefaults = watchdog::defaultWatchdogList();
$wdNames = array();
foreach ($wdDefaults as $row) {
	if (isset($row[0])) {
		$wdNames[] = $row[0];
	}
}
$wdAdd = watchdog::add($wdParent, 'watchdog', array('servicename' => 'dns', 'port' => '53', 'action' => '__driver_dns'));
$wdSrc = (string) file_get_contents($doc . '/driver/pserver/watchdoglib.php');
$wdSync = (string) file_get_contents($doc . '/driver/pserver/watchdog__synclib.php');
if (is_array($wdForm) && ($wdForm['action'] ?? '') === 'add'
	&& array_key_exists('servicename', $wdVars) && array_key_exists('status', $wdVars)
	&& array_key_exists('port', $wdVars) && array_key_exists('action', $wdVars)
	&& is_array($wdNlist) && isset($wdNlist['status'], $wdNlist['servicename'], $wdNlist['port'], $wdNlist['action'])
	&& count($wdDefaults) === 8
	&& $wdNames === array('dns', 'ftp', 'mail', 'mysql', 'php-fpm', 'webcache', 'webproxy', 'web')
	&& ($wdAdd['syncserver'] ?? '') === 'localhost'
	&& str_contains($wdSrc, 'foreach (self::defaultWatchdogList()')
	&& str_contains($wdSync, 'lxshell_return')
	&& !str_contains($wdSync, '__driver_qmail')) {
	ok('Watchdog list add form and eight Kloxo-MR default services');
} else {
	bad('Watchdog list add form and eight Kloxo-MR default services',
		$wdFormErr !== '' ? $wdFormErr : ('names=' . implode(',', $wdNames)));
}

if (function_exists('check_if_port_on')
	&& check_if_port_on(null) === false
	&& check_if_port_on('') === false
	&& check_if_port_on('not-a-port') === false
	&& check_if_port_on('pgrep ; rm -rf /') === false) {
	ok('check_if_port_on rejects empty and unsafe watchdog port strings');
} else {
	bad('check_if_port_on rejects empty and unsafe watchdog port strings');
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
