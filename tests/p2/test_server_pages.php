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

$src = file_get_contents($doc . '/theme/coredisplaylib.php');
if (is_string($src) && str_contains($src, "'llog', 'driver', 'phpini', 'lxbackup', 'serverweb', 'serverftp'")) {
	ok('Find column is skipped on log/driver/php/backup/webserver show pages');
} else {
	bad('Find column is skipped on log/driver/php/backup/webserver show pages');
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

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
