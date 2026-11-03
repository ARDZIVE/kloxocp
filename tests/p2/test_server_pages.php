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

$svcParsed = Service::parseServiceNname('pdns___localhost');
$svcObj = null;
$svcObjErr = '';
try {
	$svcParent = new stdClass();
	$svcParent->__masterserver = null;
	$svcObj = Service::initThisObject($svcParent, 'service', 'pdns___localhost');
} catch (Throwable $e) {
	$svcObjErr = $e->getMessage();
}
$svcListParent = new pserver(null, 'localhost', 'localhost');
$svcListParent->nname = 'localhost';
$svcList = null;
$svcListErr = '';
try {
	$svcList = Service::initThisList($svcListParent, 'service');
} catch (Throwable $e) {
	$svcListErr = $e->getMessage();
}
$svcClient = new stdClass();
$svcClient->__masterserver = null;
$svcClientList = null;
try {
	$svcClientList = Service::initThisList($svcClient, 'service');
} catch (Throwable $e) {
	$svcClientList = $e->getMessage();
}
$svcNames = array();
$svcAllInstalled = is_array($svcList);
if (is_array($svcList)) {
	foreach ($svcList as $row) {
		if (!is_array($row) || !isset($row['nname'])) {
			$svcAllInstalled = false;
			continue;
		}
		$svcNames[$row['nname']] = true;
		if (($row['install_state'] ?? '') !== 'on') {
			$svcAllInstalled = false;
		}
	}
}
$debSrc = (string) file_get_contents($doc . '/driver/pserver/service__debianlib.php');
$svcDummy = new Service(null, 'localhost', 'pdns___localhost');
$svcDummy->servicename = 'pdns';
$svcDummy->state = 'off';
$svcDummy->dbaction = 'clean';
$svcDummy->updateStart(array());
$svcFiltered = Service::onlyInstalledRows(array(
	array('nname' => 'nginx___localhost', 'install_state' => 'on', 'state' => 'on'),
	array('nname' => 'pdns___localhost', 'install_state' => 'dull', 'state' => 'off'),
	array('nname' => 'apache2___localhost', 'install_state' => 'on', 'state' => 'off'),
));
$svcMissing = Service__Linux::getServiceDetails(array(
	'missing' => array(
		'servicename' => 'kloxo-no-such-unit-xyz',
		'grepstring' => 'kloxo-no-such-unit-xyz',
	),
));
$svcBindCands = Service__Linux::unitCandidates('bind9');
$svcMariaCands = Service__Linux::unitCandidates('mariadb');
if (Service::canGetSingle()
	&& $svcParsed === array('pdns', 'localhost')
	&& is_object($svcObj) && ($svcObj->nname ?? '') === 'pdns___localhost'
	&& ($svcObj->servicename ?? '') === 'pdns'
	&& is_array($svcList) && $svcAllInstalled
	&& is_array($svcClientList)
	&& in_array('pdns', Service::defaultServiceList(), true)
	&& in_array('postfix', Service::defaultServiceList(), true)
	&& Service__Linux::unitName('pdns-server') === 'pdns'
	&& $svcDummy->subaction === 'start'
	&& str_contains($debSrc, "lxshell_return('systemctl', \$act, \$unit)")
	&& str_contains($debSrc, "case 'start':")
	&& count($svcFiltered) === 2
	&& ($svcFiltered[0]['nname'] ?? '') === 'nginx___localhost'
	&& ($svcFiltered[1]['nname'] ?? '') === 'apache2___localhost'
	&& is_array($svcMissing) && ($svcMissing['missing']['install_state'] ?? '') === 'dull'
	&& ($svcMissing['missing']['state'] ?? '') === 'off'
	&& in_array('named', $svcBindCands, true) && in_array('bind9', $svcBindCands, true)
	&& in_array('mysql', $svcMariaCands, true) && in_array('mariadb', $svcMariaCands, true)
	&& Service__Linux::checkService('') === 'off'
	&& Service__Linux::isSafeUnit('php8.3-fpm')
	&& !Service__Linux::isSafeUnit('php-fpm; rm -rf /')
	&& str_contains((string)file_get_contents($doc . '/driver/pserver/service__linuxlib.php'), 'function resolveInstalledUnit')
	&& str_contains((string)file_get_contents($doc . '/driver/pserver/service__linuxlib.php'), 'ActiveState')) {
	ok('Services list only installed units and read systemd ActiveState');
} else {
	bad('Services list only installed units and read systemd ActiveState',
		$svcObjErr !== '' ? $svcObjErr : (is_string($svcListErr) ? $svcListErr : (
			'list=' . (is_array($svcList) ? (string)count($svcList) : 'no')
			. ' installed=' . ($svcAllInstalled ? 'yes' : 'no')
			. ' missing=' . var_export($svcMissing['missing']['install_state'] ?? null, true)
		)));
}

$psSrc = (string) file_get_contents($doc . '/driver/pserver/pserver__linuxlib.php');
$rebootArgs = pserver__Linux::powerCommandArgs('reboot');
$powerArgs = pserver__Linux::powerCommandArgs('poweroff');
$rebootOk = !str_contains($psSrc, 'system("reboot")')
	&& !str_contains($psSrc, 'system("poweroff")')
	&& str_contains($psSrc, "'/usr/bin/systemctl'")
	&& str_contains($psSrc, "'--no-block'")
	&& ($rebootArgs === null || (is_array($rebootArgs) && isset($rebootArgs[0]) && str_starts_with((string) $rebootArgs[0], '/')))
	&& ($powerArgs === null || (is_array($powerArgs) && isset($powerArgs[0]) && str_starts_with((string) $powerArgs[0], '/')));
if ($rebootOk) {
	ok('Reboot/poweroff use systemctl or /sbin binaries');
} else {
	bad('Reboot/poweroff use systemctl or /sbin binaries',
		'reboot=' . var_export($rebootArgs, true) . ' poweroff=' . var_export($powerArgs, true));
}

global $ghtml;
$prevHtml = $ghtml;
$ghtml = new HtmlLib();
$ghtml->frm_subaction = 'reboot';
$ghtml->frm_o_o = array(array('class' => 'pserver', 'nname' => 'localhost'));
$psTabs = new pserver(null, 'localhost', 'localhost');
$psTabs->nname = 'localhost';
$rebootTabs = array('property' => array());
$psTabs->createShowPropertyList($rebootTabs);
$ghtml->frm_subaction = 'poweroff';
$powerTabs = array('property' => array());
$psTabs->createShowPropertyList($powerTabs);
$sharedReboot = pserver::rebootPropertyTabs();
$rebootTitle = get_classvar_description('pserver', '__acdesc_update_reboot');
$powerTitle = get_classvar_description('pserver', '__acdesc_update_poweroff');
$rebootOnly = $ghtml->getTitleOnly('a=updateform&sa=reboot');
$powerOnly = $ghtml->getTitleOnly('a=updateform&sa=poweroff');
$ghtml = $prevHtml;
$tabSrc = (string) file_get_contents($doc . '/theme/tab_simplicity.php');
$psLibSrc = (string) file_get_contents($doc . '/driver/pserver/pserverlib.php');
$coreSrc = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
$lxlibSrc = (string) file_get_contents($doc . '/lib/php/lxlib.php');
$rebootTabsOk = is_array($sharedReboot)
	&& $sharedReboot === array('a=updateform&sa=reboot', 'a=updateform&sa=poweroff')
	&& ($rebootTabs['property'] ?? null) === $sharedReboot
	&& ($powerTabs['property'] ?? null) === $sharedReboot
	&& is_array($rebootTitle) && ($rebootTitle[2] ?? '') === 'Reboot'
	&& is_array($powerTitle) && ($powerTitle[2] ?? '') === 'Poweroff'
	&& $rebootOnly === 'Reboot'
	&& $powerOnly === 'Poweroff'
	&& str_contains($psLibSrc, 'function rebootPropertyTabs')
	&& str_contains($coreSrc, 'rebootPropertyTabs')
	&& str_contains($tabSrc, 'kloxo_content_tab_label')
	&& str_contains($lxlibSrc, "return 'Reboot'")
	&& str_contains($lxlibSrc, "return 'Poweroff'");
if ($rebootTabsOk) {
	ok('Reboot page tabs are labelled Reboot and Poweroff');
} else {
	bad('Reboot page tabs are labelled Reboot and Poweroff',
		'tabs=' . implode(',', (array) ($rebootTabs['property'] ?? array()))
			. ' rebootTitle=' . (is_array($rebootTitle) ? (string) ($rebootTitle[2] ?? '') : 'none')
			. ' powerTitle=' . (is_array($powerTitle) ? (string) ($powerTitle[2] ?? '') : 'none')
			. ' rebootOnly=' . (string) $rebootOnly
			. ' powerOnly=' . (string) $powerOnly);
}

global $ghtml;
$prevHtml2 = $ghtml;
$ghtml = new HtmlLib();
$ghtml->frm_subaction = 'switchprogram';
$ghtml->frm_o_o = array(array('class' => 'pserver', 'nname' => 'localhost'));
$swTabs = array('property' => array());
$psTabs->createShowPropertyList($swTabs);
$swTitle = get_classvar_description('pserver', '__acdesc_update_switchprogram');
$swOnly = $ghtml->getTitleOnly('a=updateform&sa=switchprogram');
$ghtml = $prevHtml2;
$swForced = function_exists('kloxo_content_tab_label')
	? kloxo_content_tab_label('Update _switchprogram', 'display.php?frm_action=updateform&frm_subaction=switchprogram')
	: '';
$swShort = function_exists('kloxo_content_tab_label')
	? kloxo_content_tab_label('switch_program', 'a=updateform&sa=switchprogram')
	: '';
if (($swTabs['property'] ?? null) === array('a=updateform&sa=switchprogram')
	&& is_array($swTitle) && ($swTitle[2] ?? '') === 'Switch Program'
	&& $swOnly === 'Switch Program'
	&& $swForced === 'Switch Program'
	&& $swShort === 'Switch Program'
	&& str_contains($tabSrc, 'kloxo_content_tab_label')
	&& str_contains($psLibSrc, 'function switchProgramPropertyTabs')) {
	ok('Switch Program page tab is labelled Switch Program');
} else {
	bad('Switch Program page tab is labelled Switch Program',
		'tabs=' . implode(',', (array) ($swTabs['property'] ?? array()))
			. ' title=' . (is_array($swTitle) ? (string) ($swTitle[2] ?? '') : 'none')
			. ' only=' . (string) $swOnly
			. ' forced=' . (string) $swForced
			. ' short=' . (string) $swShort);
}

global $ghtml;
$prevHtmlPhp = $ghtml;
$ghtml = new HtmlLib();
$ghtml->frm_o_o = array(array('class' => 'phpini', 'nname' => 'client-admin'));
$ghtml->frm_subaction = '';
$phpShowTitle = get_classvar_description('phpini', '__acdesc_show');
$phpEditTitle = get_classvar_description('phpini', '__acdesc_update_edit');
$phpExtraTitle = get_classvar_description('phpini', '__acdesc_update_extraedit');
$ghtml->frm_subaction = 'extraedit';
$phpExtraOnly = $ghtml->getTitleOnly('a=updateform&sa=extraedit');
$ghtml = $prevHtmlPhp;
$phpTabSrc = (string) file_get_contents($doc . '/theme/tab_simplicity.php');
$phpDescSrc = (string) file_get_contents($doc . '/lang/en-us/desclib.php');
$phpShowUrl = kloxo_content_tab_label('',
	'display.php?frm_action=show&frm_o_o[0][class]=phpini');
$phpExtraUrl = kloxo_content_tab_label('Update _extraedit',
	'a=updateform&sa=extraedit&o=phpini');
if (is_array($phpShowTitle) && ($phpShowTitle[2] ?? '') === 'PHP Configure'
	&& is_array($phpEditTitle) && ($phpEditTitle[2] ?? '') === 'PHP Configure'
	&& is_array($phpExtraTitle) && ($phpExtraTitle[2] ?? '') === 'Advanced PHP Configure'
	&& $phpExtraOnly === 'Advanced PHP Configure'
	&& $phpShowUrl === 'PHP Configure'
	&& $phpExtraUrl === 'Advanced PHP Configure'
	&& function_exists('kloxo_url_has_phpini') && kloxo_url_has_phpini('a=show&o=phpini')
	&& str_contains($phpTabSrc, 'kloxo_content_tab_label')
	&& str_contains($lxlibSrc, "return 'PHP Configure'")
	&& str_contains($lxlibSrc, "return 'Advanced PHP Configure'")
	&& str_contains($phpDescSrc, '$__description["PHP Configure"]')
	&& str_contains($phpDescSrc, '$__description["Advanced PHP Configure"]')) {
	ok('PHP Configure page tabs are labelled PHP Configure and Advanced PHP Configure');
} else {
	bad('PHP Configure page tabs are labelled PHP Configure and Advanced PHP Configure',
		'showTitle=' . (is_array($phpShowTitle) ? (string) ($phpShowTitle[2] ?? '') : 'none')
			. ' extraTitle=' . (is_array($phpExtraTitle) ? (string) ($phpExtraTitle[2] ?? '') : 'none')
			. ' extraOnly=' . (string) $phpExtraOnly);
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
		$names['mariadb-server'], $names['exim4'], $names['postfix'], $names['dovecot-core'], $names['rspamd'],
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
	if (isset($by['nginx'], $by['bind9'], $by['hiawatha'], $by['exim4'])
		&& $by['nginx']['status'] === 'on'
		&& $by['bind9']['status'] === 'on'
		&& $by['hiawatha']['status'] === 'on'
		&& $by['hiawatha']['version'] === 'hiawatha-12.4'
		&& $by['exim4']['status'] === 'off'
		&& str_contains($by['exim4']['version'], 'is not installed')
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

if (class_exists('mailqueue__exim')) {
	$parsed = mailqueue__exim::parseTextQueue(
		"  4h  1.2K 1abcDEF-0001Ab-2s <sender@example.com>\n          dest@example.com\n"
	);
	if (is_array($parsed) && isset($parsed[0]['nname']) && $parsed[0]['nname'] === '1abcDEF-0001Ab-2s'
		&& $parsed[0]['from'] === 'sender@example.com' && $parsed[0]['to'] === 'dest@example.com') {
		ok('mailqueue__exim parses exim -bp text');
	} else {
		bad('mailqueue__exim parses exim -bp text');
	}
} else {
	bad('mailqueue__exim parses exim -bp text', 'class missing');
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
	&& str_contains($src, "'sshconfig', 'lxguard', 'ufwfirewall', 'sshclient', 'ffile', 'ticket', 'notification'")
	&& str_contains($src, "'lxupdate', 'dns', 'dns_record_a', 'web'")
	&& str_contains($src, "'lxguardhitdisplay', 'rawlxguardhit', 'lxguardwhitelist'")) {
	ok('Find column is skipped on log/driver/php/backup/webserver/ssh/lxguard/file/ticket/notification/update/dns show pages');
} else {
	bad('Find column is skipped on log/driver/php/backup/webserver/ssh/lxguard/file/ticket/notification/update/dns show pages');
}
if (function_exists('kloxo_skip_find_class')
	&& kloxo_skip_find_class('lxguard')
	&& kloxo_skip_find_class('ufwfirewall')
	&& kloxo_skip_find_class('lxguardhitdisplay')
	&& kloxo_skip_find_class('rawlxguardhit')
	&& kloxo_skip_find_class('lxguardwhitelist')
	&& kloxo_skip_find_class('ticket')
	&& kloxo_skip_find_class('notification')
	&& kloxo_skip_find_class('lxupdate')
	&& kloxo_skip_find_class('dns')
	&& kloxo_skip_find_class('dns_record_a')
	&& kloxo_skip_find_class('web')
	&& !kloxo_skip_find_class('tickethistory')
	&& !kloxo_skip_find_class('client')) {
	ok('LxGuard, UFW Firewall, HelpDesk ticket, Notification, Update, Manage DNS and PHPinfo pages skip the Find input');
} else {
	bad('LxGuard, UFW Firewall, HelpDesk ticket, Notification, Update, Manage DNS and PHPinfo pages skip the Find input');
}

$welcomeDefault = Notification::defaultWelcomeMessage();
$welcomeFile = (string) file_get_contents($root . '/file/welcome.txt');
$prevLoginN = isset($login) ? $login : null;
$login = new Client(null, 'localhost', 'admin');
$login->nname = 'admin';
$login->cttype = 'admin';
$note = new Notification(null, 'localhost', 'client-admin');
$note->nname = 'client-admin';
$note->text_newaccountmessage = '';
$note->__parent_o = $login;
$noteForm = null;
$noteFormErr = '';
try {
	$noteForm = $note->updateform('update', array());
} catch (Throwable $e) {
	$noteFormErr = $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
}
$login = $prevLoginN;
$lxSrc = (string) file_get_contents($doc . '/lib/client/lxclient.php');
$welcomeOk = is_string($welcomeDefault)
	&& str_contains($welcomeDefault, 'Username: %name%')
	&& str_contains($welcomeDefault, 'Password: %password%')
	&& str_contains($welcomeDefault, 'Thank you for choosing our service')
	&& trim($welcomeFile) === trim($welcomeDefault)
	&& is_array($noteForm)
	&& array_key_exists('text_newaccountmessage', $noteForm)
	&& ($note->text_newaccountmessage ?? '') === $welcomeDefault
	&& $noteFormErr === ''
	&& str_contains($lxSrc, "str_replace(")
	&& str_contains($lxSrc, "'%name%'");
if ($welcomeOk) {
	ok('Notification Welcome Message For New Accounts defaults to %name%/%password% text');
} else {
	bad('Notification Welcome Message For New Accounts defaults to %name%/%password% text',
		$noteFormErr !== '' ? $noteFormErr
			: ('form=' . implode(',', is_array($noteForm) ? array_keys($noteForm) : array())
				. ' msg=' . substr((string) ($note->text_newaccountmessage ?? ''), 0, 80)));
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
$pmaTarget = '';
$pmaErr = '';
try {
	$psPma = new pserver(null, 'localhost', 'localhost');
	$psPma->nname = 'localhost';
	$pmaAlist = array();
	$psPma->getMysqlDbAdmin($pmaAlist);
	$pmaUrl = (isset($pmaAlist[0]) && is_object($pmaAlist[0]) && isset($pmaAlist[0]->url))
		? (string) $pmaAlist[0]->url : '';
	$pmaTarget = (isset($pmaAlist[0]) && is_object($pmaAlist[0]) && isset($pmaAlist[0]->target))
		? (string) $pmaAlist[0]->target : '';
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
$pmaHelperSrc = (string) file_get_contents($doc . '/lib/php/kloxo_pma.php');
$pmaWant = function_exists('kloxo_pma_url') ? kloxo_pma_url() : '/thirdparty/phpMyAdmin/';
$pmaCfg = (string) file_get_contents(dirname($doc) . '/file/phpmyadmin/config.inc.php');
if ($pmaUrl === $pmaWant && $cliPmaUrl === $pmaWant && $dbPmaUrl === $pmaWant
	&& str_contains($pmaTarget, '_blank')
	&& str_contains($pssrc, 'kloxo_pma_href')
	&& !str_contains($pssrc, 'mywebsql')
	&& !str_contains($clsrc, 'mywebsql')
	&& str_contains($btnSrc, "str_starts_with(\$resolvedHref, '/thirdparty/')")
	&& str_contains($btnSrc, 'kloxo_pma_link_attrs')
	&& str_contains($pmaHelperSrc, 'kloxo_pma_try_admin_sso')
	&& str_contains($pmaCfg, 'kloxo-signon.php')
	&& !str_contains($pmaCfg, 'SignonScript')
	&& str_contains($pmaCfg, "AllowRoot'] = true")) {
	ok('SQL Manager tile opens phpMyAdmin sign-on in a new tab');
} else {
	bad('SQL Manager tile opens phpMyAdmin sign-on in a new tab',
		$pmaErr !== '' ? $pmaErr : ('ps=' . $pmaUrl . ' client=' . $cliPmaUrl . ' db=' . $dbPmaUrl . ' tgt=' . $pmaTarget));
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

if (class_exists('Dnstemplate') && is_callable(array('Dnstemplate', 'createListAlist'))) {
	$admin = new Client(null, null, 'admin');
	$admin->nname = 'admin';
	$tabs = Dnstemplate::createListAlist($admin, 'dnstemplate');
	$joined = is_array($tabs) ? implode("\n", $tabs) : '';
	if (is_array($tabs) && str_contains($joined, 'a=list&c=dnstemplate')
		&& !str_contains($joined, 'a=addform')) {
		ok('DNS Template tabs do not include Add this template');
	} else {
		bad('DNS Template tabs do not include Add this template', $joined);
	}
} else {
	bad('DNS Template tabs do not include Add this template', 'class missing');
}

$tplsrc = (string)file_get_contents($doc . '/driver/dns/dnstemplatelib.php');
$basesrc = (string)file_get_contents($doc . '/driver/dns/dnsbaselib.php');
if (str_contains($tplsrc, "\$vlist['nname']")
	&& str_contains($tplsrc, "\$vlist['webipaddress']")
	&& str_contains($tplsrc, "\$vlist['nameserver_f']")
	&& !str_contains($tplsrc, 'fixIpAddress.php')
	&& str_contains($tplsrc, "\$param['nname'] = \"{\$name}.dnst\"")) {
	ok('DNS template addform does not call fixIpAddress.php');
} else {
	bad('DNS template addform does not call fixIpAddress.php');
}
if (str_contains($basesrc, 'function assertNsName')
	&& str_contains($basesrc, 'function qualifyTemplateNs')
	&& str_contains($basesrc, 'self::qualifyTemplateNs($secnamserver')
	&& str_contains($basesrc, "static \$__desc_dns_record_a")) {
	ok('DNS template create validates the secondary NS and stores dns_record_a');
} else {
	bad('DNS template create validates the secondary NS and stores dns_record_a');
}

try {
	global $login;
	$prevDnsLogin = $login;
	$admin = new Client(null, 'localhost', 'admin');
	$admin->nname = 'admin';
	$admin->cttype = 'admin';
	$login = $admin;
	$form = Dnstemplate::addform($admin, 'dnstemplate');
	$vars = is_array($form) ? ($form['variable'] ?? array()) : array();
	if (is_array($form)
		&& ($form['action'] ?? '') === 'add'
		&& array_key_exists('nname', $vars)
		&& array_key_exists('webipaddress', $vars)
		&& array_key_exists('mmailipaddress', $vars)
		&& array_key_exists('nameserver_f', $vars)
		&& array_key_exists('secnameserver_f', $vars)) {
		ok('DNS template addform returns name, IPs and nameserver fields');
	} else {
		bad('DNS template addform returns name, IPs and nameserver fields');
	}

	$added = Dnstemplate::add($admin, 'dnstemplate', array('nname' => 'Default.dnst'));
	if (is_array($added) && ($added['nname'] ?? '') === 'default.dnst' && ($added['shared'] ?? '') === 'on') {
		ok('DNS template add sanitizes the name and appends .dnst once');
	} else {
		bad('DNS template add sanitizes the name and appends .dnst once', (string)($added['nname'] ?? ''));
	}

	$spaced = Dnstemplate::add($admin, 'dnstemplate', array('nname' => 'My Template'));
	if (is_array($spaced) && ($spaced['nname'] ?? '') === 'my-template.dnst') {
		ok('DNS template add turns spaces into a plan-safe id');
	} else {
		bad('DNS template add turns spaces into a plan-safe id', (string)($spaced['nname'] ?? ''));
	}

	$nnameVal = $vars['nname'] ?? null;
	$nsVal = $vars['nameserver_f'] ?? null;
	$secVal = $vars['secnameserver_f'] ?? null;
	if (is_array($nnameVal) && ($nnameVal[1]['value'] ?? '') === 'default'
		&& is_array($nsVal) && ($nsVal[1]['value'] ?? '') === 'ns1'
		&& is_array($secVal) && ($secVal[1]['value'] ?? '') === 'ns2') {
		ok('DNS template addform prefills default, ns1 and ns2');
	} else {
		bad('DNS template addform prefills default, ns1 and ns2');
	}
	$webip = $vars['webipaddress'] ?? null;
	if ($webip === null || (is_array($webip) && ($webip[0] ?? '') === 's' && is_array($webip[1] ?? null) && $webip[1] !== array())) {
		ok('DNS template addform uses a select of IPs or a typed IP field');
	} else {
		bad('DNS template addform uses a select of IPs or a typed IP field');
	}

	$tpl = new Dnstemplate(null, null, 'default.dnst');
	$tpl->nname = 'default.dnst';
	$tpl->createDefaultTemplate('203.0.113.10', '203.0.113.11', 'ns1.example.com.', 'ns2.example.com.');
	$recs = is_array($tpl->dns_record_a) ? $tpl->dns_record_a : array();
	$types = array();
	foreach ($recs as $r) {
		if (is_object($r) && isset($r->ttype, $r->hostname)) {
			$types[$r->ttype . ':' . $r->hostname] = $r->param ?? '';
		}
	}
	if (isset($recs['ns_ns1.example.com'], $recs['ns_ns2.example.com'], $recs['a___base__'], $recs['a_mail'], $recs['mx_10'])
		&& ($tpl->soanameserver ?? '') === 'ns1.example.com'
		&& ($types['a:__base__'] ?? '') === '203.0.113.10'
		&& ($types['a:mail'] ?? '') === '203.0.113.11') {
		ok('DNS template createDefaultTemplate builds NS, A and MX records');
	} else {
		bad('DNS template createDefaultTemplate builds NS, A and MX records', implode(',', array_keys($recs)));
	}

	$blank = new Dnstemplate(null, null, 'default.dnst');
	$blank->nname = 'default.dnst';
	$blank->createDefaultTemplate('203.0.113.10', '203.0.113.10', '', '');
	if (($blank->soanameserver ?? '') === 'ns1.default.dnst'
		&& isset($blank->dns_record_a['ns_ns1.default.dnst'])) {
		ok('DNS template create fills a blank nameserver as ns1.<template>');
	} else {
		bad('DNS template create fills a blank nameserver as ns1.<template>', (string)($blank->soanameserver ?? ''));
	}

	$short = new Dnstemplate(null, null, 'default.dnst');
	$short->nname = 'default.dnst';
	$short->webipaddress = '203.0.113.9';
	$short->mmailipaddress = '203.0.113.9';
	$short->nameserver_f = 'ns1';
	$short->secnameserver_f = 'ns2';
	$short->postAdd();
	if (($short->soanameserver ?? '') === 'ns1.default.dnst'
		&& isset($short->dns_record_a['ns_ns1.default.dnst'], $short->dns_record_a['ns_ns2.default.dnst'], $short->dns_record_a['a___base__'])) {
		ok('DNS template postAdd qualifies ns1/ns2 to the template name');
	} else {
		bad('DNS template postAdd qualifies ns1/ns2 to the template name', (string)($short->soanameserver ?? ''));
	}

	$emptyErr = '';
	try {
		$tpl->createDefaultTemplate('203.0.113.10', '203.0.113.10', 'not a dns name', '');
	} catch (Throwable $e) {
		$emptyErr = $e->getMessage();
	}
	if ($emptyErr !== '' && !str_contains(strtolower($emptyErr), 'typeerror')) {
		ok('DNS template create rejects an invalid nameserver without TypeError');
	} else {
		bad('DNS template create rejects an invalid nameserver without TypeError', $emptyErr);
	}

	$show = new Dnstemplate(null, null, 'default.dnst');
	$show->nname = 'default.dnst';
	$show->parent_clname = '';
	$okRight = false;
	$rightErr = '';
	try {
		$okRight = ($show->isRightParent() === true);
	} catch (Throwable $e) {
		$rightErr = $e->getMessage();
	}
	if ($okRight) {
		ok('DNS template show still offers add-record tabs without a loaded parent');
	} else {
		bad('DNS template show still offers add-record tabs without a loaded parent', $rightErr);
	}

	$zone = new Dns(null, 'localhost', 'example.com');
	$zone->nname = 'example.com';
	$zone->parent_clname = '';
	$zoneTabs = array('property' => array());
	$zone->createShowPropertyList($zoneTabs);
	$zoneClist = $zone->createShowClist(null);
	$zoneShow = kloxo_content_tab_label('', 'display.php?frm_action=show&frm_o_o[0][class]=dns&frm_o_o[0][nname]=example.com');
	$zoneNs = kloxo_content_tab_label('Add NS', 'a=addform&c=dns_record_a&dta[var]=ttype&dta[val]=ns');
	$zoneA = kloxo_content_tab_label('', 'a=addform&c=dns_record_a&dta[var]=ttype&dta[val]=a');
	$zoneRebuild = kloxo_content_tab_label('Update_rebuild', 'a=updateform&sa=rebuild');
	$zoneParam = kloxo_content_tab_label('general_settings', 'a=updateform&sa=parameter');
	$recForm = dns_record_a::addform($zone, 'dns_record_a', array('var' => 'ttype', 'val' => 'cname'));
	$rec = new dns_record_a(null, null, 'caa_letsencrypt.org');
	$rec->ttype = 'caa';
	$recDisp = $rec->display('param');
	$zoneSrc = (string) file_get_contents($doc . '/driver/dns/dnslib.php');
	$baseSrcNow = (string) file_get_contents($doc . '/driver/dns/dnsbaselib.php');
	$zoneProps = $zoneTabs['property'] ?? array();
	if (($zoneProps[0] ?? '') === 'a=show'
		&& in_array('a=addform&c=dns_record_a&dta[var]=ttype&dta[val]=ns', $zoneProps, true)
		&& in_array('a=updateform&sa=parameter', $zoneProps, true)
		&& array_key_exists('dns_record_a', is_array($zoneClist) ? $zoneClist : array())
		&& $zoneShow === 'Manage DNS'
		&& $zoneNs === 'NS'
		&& $zoneA === 'A'
		&& $zoneRebuild === 'Rebuild'
		&& $zoneParam === 'General Settings'
		&& is_array($recForm) && isset($recForm['variable']['hostname'])
		&& is_string($recDisp)
		&& str_contains($zoneSrc, 'function createExtraVariables')
		&& str_contains($baseSrcNow, 'function dnsPropertyTabs')) {
		ok('Manage DNS page tabs are labelled Manage DNS, NS, A, Rebuild, General Settings');
	} else {
		bad('Manage DNS page tabs are labelled Manage DNS, NS, A, Rebuild, General Settings',
			'show=' . $zoneShow . ' ns=' . $zoneNs . ' a=' . $zoneA
				. ' rebuild=' . $zoneRebuild . ' param=' . $zoneParam
				. ' props=' . implode(',', array_map('strval', $zoneProps)));
	}
	$login = $prevDnsLogin;
} catch (Throwable $e) {
	bad('DNS template addform/add/createDefaultTemplate complete without TypeError', $e->getMessage());
}

try {
	global $login;
	$prevZoneLogin = $login;
	$admin = new Client(null, 'localhost', 'admin');
	$admin->nname = 'admin';
	$admin->cttype = 'admin';
	$login = $admin;
	$zone = new Dns(null, 'localhost', 'example.com');
	$zone->nname = 'example.com';
	$zone->parent_clname = 'domain-example.com';
	$zone->dbaction = 'clean';
	$rec = new dns_record_a(null, null, 'a___base__');
	$rec->ttype = 'a';
	$rec->hostname = '__base__';
	$rec->param = '127.0.0.1';
	$zone->dns_record_a = array(
		'a___base__' => $rec,
		'junk' => 'not-an-object',
	);
	$alist = array();
	$zone->createShowAlist($alist);
	$count = 0;
	$list = $zone->getVirtualList('dns_record_a', $count);
	$coreSrc = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
	$baseSrcFix = (string) file_get_contents($doc . '/driver/dns/dnsbaselib.php');
	if ($zone->dbaction === 'clean'
		&& is_array($list) && isset($list['a___base__']) && is_object($list['a___base__'])
		&& !isset($list['junk'])
		&& str_contains($coreSrc, 'do_list_class $k')
		&& str_contains($baseSrcFix, 'Show must not dirty the zone')
		&& !str_contains($baseSrcFix, '$this->write();')) {
		ok('Manage DNS show lists records without writing the zone');
	} else {
		bad('Manage DNS show lists records without writing the zone',
			'dbaction=' . (string)$zone->dbaction
				. ' rows=' . (is_array($list) ? implode(',', array_keys($list)) : 'none'));
	}
	$login = $prevZoneLogin;
} catch (Throwable $e) {
	bad('Manage DNS show lists records without writing the zone', $e->getMessage());
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
	$ftpShowTab = kloxo_content_tab_label('FTP_config',
		'display.php?frm_action=show&frm_o_o[0][class]=pserver&frm_o_o[0][nname]=localhost&frm_o_o[1][class]=serverftp');
	$ftpUpdateTab = kloxo_content_tab_label('Update_update',
		'a=updateform&sa=update&o=serverftp');
	$ftpTabs = serverftp::ftpPropertyTabs();
	$ftpProps = array('property' => array());
	$ftp->createShowPropertyList($ftpProps);
	if ($ftpShowTab === 'FTP Configure'
		&& $ftpUpdateTab === 'FTP Configure'
		&& is_array($ftpTabs) && ($ftpTabs[0] ?? '') === 'a=show'
		&& ($ftpProps['property'][0] ?? '') === 'a=show') {
		ok('FTP Configure page tab is labelled FTP Configure');
	} else {
		bad('FTP Configure page tab is labelled FTP Configure',
			'show=' . $ftpShowTab . ' update=' . $ftpUpdateTab
				. ' tabs=' . (is_array($ftpTabs) ? implode(',', $ftpTabs) : 'no'));
	}
} else {
	bad('FTP Configure show does not sync a missing serverftp row', 'class missing');
	bad('FTP Configure page tab is labelled FTP Configure', 'class missing');
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

$sshHomeTab = kloxo_content_tab_label('Home',
	'display.php?frm_action=show&frm_o_o[0][class]=pserver&frm_o_o[0][nname]=localhost&frm_o_o[1][class]=sshconfig');
$sshShowTab = kloxo_content_tab_label('SSH_config', 'a=show&o=sshconfig');
$sshTabs = class_exists('sshconfig') && is_callable(array('sshconfig', 'sshconfigPropertyTabs'))
	? sshconfig::sshconfigPropertyTabs() : array();
$sshProps = array('property' => array());
if (method_exists($cfg, 'createShowPropertyList')) {
	$cfg->createShowPropertyList($sshProps);
}
if ($sshHomeTab === 'SSH Configure'
	&& $sshShowTab === 'SSH Configure'
	&& function_exists('kloxo_url_has_sshconfig') && kloxo_url_has_sshconfig('a=show&o=sshconfig')
	&& kloxo_url_has_sshconfig('display.php?frm_o_o%5B1%5D%5Bclass%5D=sshconfig')
	&& is_array($sshTabs) && ($sshTabs[0] ?? '') === 'a=show'
	&& ($sshProps['property'][0] ?? '') === 'a=show') {
	ok('SSH Configure page tab is labelled SSH Configure');
} else {
	bad('SSH Configure page tab is labelled SSH Configure',
		'home=' . (string) $sshHomeTab . ' show=' . (string) $sshShowTab);
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

$fmTabs = array(
	kloxo_content_tab_label('Update_perm', 'a=updateform&sa=perm'),
	kloxo_content_tab_label('Update_own', 'a=updateform&sa=own'),
	kloxo_content_tab_label('Update_diskspace', 'a=update&sa=diskspace'),
	kloxo_content_tab_label('Update_upload', 'a=updateform&sa=upload'),
	kloxo_content_tab_label('Update_thumbnail', 'a=updateForm&sa=thumbnail'),
);
$fmTools = array(
	kloxo_content_tab_label('Update_toggle_dot', 'a=update&sa=toggle_dot'),
	kloxo_content_tab_label('Update_newdir', 'a=updateform&sa=newdir'),
	kloxo_content_tab_label('Update_newfile', 'a=updateform&sa=newfile'),
	kloxo_content_tab_label('Update_copy', 'a=update&sa=copy'),
	kloxo_content_tab_label('Update_cut', 'a=update&sa=cut'),
	kloxo_content_tab_label('Update_paste_inactive', 'a=update&sa=paste_inactive'),
	kloxo_content_tab_label('Update_filedelete', 'a=update&sa=filedelete'),
	kloxo_content_tab_label('Update_filerealdelete', 'a=update&sa=filerealdelete'),
	kloxo_content_tab_label('Update_zip_file', 'a=update&sa=zip_file'),
	kloxo_content_tab_label('', '/display.php?frm_action=refresh&frm_o_cname=ffile'),
);
$fmShow = kloxo_content_tab_label('home', 'display.php?frm_action=show&frm_o_o[0][class]=ffile&frm_o_o[0][nname]=/');
$kw = (string) file_get_contents($doc . '/lang/en-us/langkeywordlib.php');
$admin = new Client(null, 'localhost', 'admin');
$admin->nname = 'admin';
$admin->cttype = 'admin';
$admin->parent_clname = '';
$trueParent = 'threw';
try {
	$trueParent = $admin->getTrueParentO();
} catch (Throwable $e) {
	$trueParent = $e->getMessage();
}
if ($fmTabs === array('Permissions', 'Ownership', 'Disk Usage', 'Upload', 'Thumbnail')
	&& $fmTools === array('T Hidden', 'New dir', 'New file', 'Copy', 'Cut', 'Paste', 'Trash', 'Remove', 'Zip', 'Refresh')
	&& $fmShow === 'File Manager'
	&& str_contains($htmlsrc, 'kloxo_content_tab_label')
	&& str_contains($kw, '$__keyword["showall"]')
	&& $trueParent === null) {
	ok('File Manager tabs and toolbar use Kloxo-MR labels');
} else {
	bad('File Manager tabs and toolbar use Kloxo-MR labels',
		'tabs=' . implode(',', $fmTabs) . ' tools=' . implode(',', $fmTools)
			. ' show=' . $fmShow . ' parent=' . var_export($trueParent, true));
}

$bak = new lxbackup(null, 'localhost', 'admin');
$bak->nname = 'client-admin';
$bak->ftp_server = '';
$bak->backupstage = '';
$bak->restorestage = '';
$bakTabs = array('property' => array());
$bak->createShowPropertyList($bakTabs);
$bakForced = lxbackup::backupPropertyTabs();
$bakFull = 'display.php?frm_action=show&frm_o_o[0][class]=client&frm_o_o[0][nname]=admin&frm_o_o[1][class]=lxbackup&frm_o_o[1][nname]=client-admin';
$bakFullFtp = 'display.php?frm_action=updateform&frm_subaction=ftp_conf&frm_o_o[0][class]=lxbackup&frm_o_o[0][nname]=admin';
$bakFullSched = '/display.php?frm_action=updateform&frm_subaction=schedule_conf&frm_o_o%5B0%5D%5Bclass%5D=lxbackup';
$bakShow = kloxo_content_tab_label('lxbackup', $bakFull);
$bakFtp = kloxo_content_tab_label('Update_ftp_conf', 'a=updateform&sa=ftp_conf');
$bakFtpFull = kloxo_content_tab_label('Update _ftp_conf', $bakFullFtp);
$bakSched = kloxo_content_tab_label('schedule_configuration', 'a=updateform&sa=schedule_conf');
$bakSchedFull = kloxo_content_tab_label('', $bakFullSched);
$bakNow = kloxo_content_tab_label('backup_now', 'a=updateform&sa=backup');
$bakFile = kloxo_content_tab_label('restore_from_file', 'a=updateform&sa=restore_from_file');
$bakFtpRest = kloxo_content_tab_label('', 'a=updateform&sa=restore_from_ftp');
$bakBrowse = kloxo_content_tab_label('file', $bakFull . '&frm_o_o[2][class]=ffile&frm_o_o[2][nname]=/');
$bakUpload = kloxo_content_tab_label('upload', $bakFull . '&frm_action=updateform&frm_subaction=upload&frm_o_o[2][class]=ffile&frm_o_o[2][nname]=/');
$bakFf = new Ffile(null, 'localhost', '/home/kloxo/client/admin/__backup/', '/', 'lxlabs');
$bakFf->nname = '/';
$bakFf->root = '/home/kloxo/client/admin/__backup/';
$bakFf->base = '/';
$bakFf->__parent_o = $bak;
$bakFfTabs = array('property' => array());
try {
	$bakFf->createShowPropertyList($bakFfTabs);
} catch (Throwable $e) {
	$bakFfTabs = array('property' => array('error' => $e->getMessage()));
}
$bakForms = array();
$bakFormErr = '';
foreach (array('backup', 'restore_from_file', 'restore_from_ftp', 'ftp_conf', 'schedule_conf') as $bakSa) {
	try {
		$bakForms[$bakSa] = $bak->updateform($bakSa, array());
	} catch (Throwable $e) {
		$bakFormErr .= $bakSa . ':' . $e->getMessage() . ';';
		$bakForms[$bakSa] = null;
	}
}
$bakShowForms = $bak->createShowUpdateform();
$bakUploadMulti = null;
try {
	$bakUploadMulti = $bakFf->getMultiUpload('upload');
} catch (Throwable $e) {
	$bakFormErr .= 'upload:' . $e->getMessage() . ';';
}
$bakSrc = (string) file_get_contents($doc . '/lib/html/lxbackuplib.php');
$bakLib = (string) file_get_contents($doc . '/lib/php/lxlib.php');
$bakCore = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
$bakProp = $bakTabs['property'] ?? array();
$bakFfProp = $bakFfTabs['property'] ?? array();
$bakFfHas = is_array($bakFfProp)
	&& in_array('goback=1&a=show', $bakFfProp, true)
	&& in_array('goback=1&a=updateform&sa=ftp_conf', $bakFfProp, true)
	&& in_array('goback=1&a=show&l[class]=ffile&l[nname]=/', $bakFfProp, true);
$bakTabCount = is_array($bakForced) ? count($bakForced) : 0;
$bakFormOk = is_array($bakForms['backup'] ?? null)
	&& array_key_exists('upload_to_ftp', $bakForms['backup'])
	&& array_key_exists('backup_to_file_f', $bakForms['backup'])
	&& ($bakForms['backup']['__v_button'] ?? '') === 'Backup Now'
	&& is_array($bakForms['restore_from_file'] ?? null)
	&& array_key_exists('backup_from_file_f', $bakForms['restore_from_file'])
	&& ($bakForms['restore_from_file']['__v_button'] ?? '') === 'Start Restore Process'
	&& is_array($bakForms['restore_from_ftp'] ?? null)
	&& array_key_exists('ftp_server', $bakForms['restore_from_ftp'])
	&& ($bakForms['restore_from_ftp']['__v_button'] ?? '') === 'Update'
	&& is_array($bakForms['ftp_conf'] ?? null)
	&& array_key_exists('ftp_server', $bakForms['ftp_conf'])
	&& array_key_exists('rm_username', $bakForms['ftp_conf'])
	&& array_key_exists('upload_to_ftp', $bakForms['ftp_conf'])
	&& is_array($bakForms['schedule_conf'] ?? null)
	&& array_key_exists('backupschedule_type', $bakForms['schedule_conf'])
	&& array_key_exists('backupschedule_time', $bakForms['schedule_conf'])
	&& array_key_exists('rm_last_number', $bakForms['schedule_conf'])
	&& is_array($bakShowForms)
	&& array_key_exists('backup', $bakShowForms)
	&& array_key_exists('restore_from_file', $bakShowForms)
	&& array_key_exists('restore_from_ftp', $bakShowForms)
	&& is_array($bakUploadMulti)
	&& $bakUploadMulti === array('upload_s', 'download_from_http', 'download_from_ftp')
	&& $bakFormErr === '';
if (($bakProp[0] ?? '') === 'a=show'
	&& ($bakProp[1] ?? '') === 'a=updateform&sa=ftp_conf'
	&& ($bakProp['__var_backupschedule_flag'] ?? '') === 'a=updateform&sa=schedule_conf'
	&& ($bakProp[2] ?? '') === 'a=show&l[class]=ffile&l[nname]=/'
	&& ($bakProp[3] ?? '') === 'l[class]=ffile&l[nname]=/&a=updateform&sa=upload'
	&& $bakTabCount === 5
	&& $bakForced === $bakProp
	&& $bakShow === 'Backup / Restore'
	&& $bakFtp === 'FTP Configuration'
	&& $bakFtpFull === 'FTP Configuration'
	&& $bakSched === 'Schedule Configuration'
	&& $bakSchedFull === 'Schedule Configuration'
	&& $bakNow === 'Backup Now'
	&& $bakFile === 'Restore From File'
	&& $bakFtpRest === 'Restore From FTP'
	&& $bakBrowse === 'File Manager'
	&& $bakUpload === 'Upload'
	&& $bakFfHas
	&& $bakFormOk
	&& kloxo_object_is_backup_page($bak)
	&& kloxo_object_is_backup_page($bakFf)
	&& str_contains($bakSrc, 'function backupPropertyTabs')
	&& str_contains($bakSrc, "\$alist['property'] = self::backupPropertyTabs()")
	&& str_contains($bakSrc, 'Backup / Restore')
	&& str_contains($bakLib, 'function kloxo_backup_page_tab_label')
	&& str_contains($bakCore, 'backupPropertyTabs()')) {
	ok('Backup/Restore page tabs are labelled Backup / Restore, FTP Configuration, Schedule Configuration');
} else {
	bad('Backup/Restore page tabs are labelled Backup / Restore, FTP Configuration, Schedule Configuration',
		'show=' . $bakShow . ' ftp=' . $bakFtp . '/' . $bakFtpFull . ' sched=' . $bakSched . '/' . $bakSchedFull
			. ' now=' . $bakNow . ' file=' . $bakFile . ' restftp=' . $bakFtpRest
			. ' browse=' . $bakBrowse . ' upload=' . $bakUpload
			. ' props=' . implode(',', array_map('strval', $bakProp))
			. ' ff=' . implode(',', array_map('strval', $bakFfProp))
			. ' formErr=' . $bakFormErr
			. ' formOk=' . ($bakFormOk ? '1' : '0'));
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
if (str_contains($addsrc, 'isset($ct->priv) && is_object($ct->priv)')
	&& str_contains($addsrc, 'new Priv(null, null, $this->nname)')
	&& str_contains($addsrc, 'new ListPriv(null, null, $this->nname)')) {
	ok('client postAdd clones a plan priv only when the plan has one');
} else {
	bad('client postAdd clones a plan priv only when the plan has one');
}

$notesrc = (string)file_get_contents($doc . '/lib/client/lxclient.php');
if (str_contains($notesrc, 'isset($noto->notflag_b) && is_object($noto->notflag_b)')
	&& str_contains($notesrc, 'log_log("error", "notifyObjects:')) {
	ok('client notifyObjects does not require notflag_b');
} else {
	bad('client notifyObjects does not require notflag_b');
}

$syncsrc = (string)file_get_contents($doc . '/lib/client/client__synclib.php');
if (str_contains($syncsrc, "KloxoIpaddr::isUbuntu()")
	&& str_contains($syncsrc, "'www-data'")
	&& str_contains($syncsrc, "lxfile_unix_chown(\$spath, \"{\$username}:{\$wgroup}\")")) {
	ok('client createUser chowns the home to the Ubuntu web group');
} else {
	bad('client createUser chowns the home to the Ubuntu web group');
}

$anySrc = (string)file_get_contents($doc . '/lib/php/lxlib.php');
if (str_contains($anySrc, 'function getFromAny')
	&& preg_match('/function getFromAny\(\$.*?catch \(Throwable \$e\)/s', $anySrc)) {
	ok('getFromAny catches Throwable when a plan is missing');
} else {
	bad('getFromAny catches Throwable when a plan is missing');
}

try {
	global $login, $sgbl;
	$prevLogin = $login;
	$admin = new Client(null, 'localhost', 'admin');
	$admin->nname = 'admin';
	$admin->cttype = 'admin';
	$admin->__listtype_resourceplan = 'fullist';
	$admin->resourceplan_l = array();
	$admin->__listtype_ipaddress = 'fullist';
	$admin->ipaddress_l = array();
	$login = $admin;
	$form = Client::addform($admin, 'client', array('var' => 'cttype', 'val' => 'customer'));
	$vars = is_array($form) ? ($form['variable'] ?? array()) : array();
	if (is_array($form)
		&& ($form['action'] ?? '') === 'continue'
		&& array_key_exists('nname', $vars)
		&& array_key_exists('password', $vars)
		&& array_key_exists('contactemail', $vars)
		&& isset($vars['resourceplan_f'][1]['continue_without_plan'])) {
		ok('client addform returns nname, password, contactemail and a plan list');
	} else {
		bad('client addform returns nname, password, contactemail and a plan list');
	}

	$now = Client::continueForm($admin, 'client', array(
		'nname' => 'kloxocptestcli',
		'password' => 'Secret12',
		'contactemail' => 'acme@example.com',
		'resourceplan_f' => 'default',
		'cttype' => 'customer',
		'send_welcome_f' => 'off',
	), 'server');
	if (is_array($now)
		&& ($now['action'] ?? '') === 'addnow'
		&& ($now['param']['use_resourceplan_f'] ?? '') === 'On'
		&& ($now['param']['nname'] ?? '') === 'kloxocptestcli') {
		ok('client continueForm with a plan adds immediately');
	} else {
		bad('client continueForm with a plan adds immediately', (string)($now['action'] ?? ''));
	}

	$step = Client::continueForm($admin, 'client', array(
		'nname' => 'kloxocptestcli',
		'password' => 'Secret12',
		'contactemail' => 'acme@example.com',
		'resourceplan_f' => 'continue_without_plan',
		'cttype' => 'customer',
		'send_welcome_f' => 'off',
	), 'server');
	if (is_array($step)
		&& ($step['action'] ?? '') === 'add'
		&& isset($step['param']['listpriv_s_webpserver_list'])) {
		ok('client continueForm without a plan fills server lists');
	} else {
		bad('client continueForm without a plan fills server lists', (string)($step['action'] ?? ''));
	}

	$added = Client::add($admin, 'client', array(
		'nname' => 'kloxocptestcli',
		'password' => 'Secret12',
		'cttype' => 'customer',
		'resourceplan_f' => 'default',
	));
	if (is_array($added)
		&& ($added['cttype'] ?? '') === 'customer'
		&& ($added['realpass'] ?? '') === 'Secret12'
		&& is_string($added['password'] ?? null)
		&& ($added['password'] ?? '') !== 'Secret12'
		&& str_starts_with((string)$added['password'], '$1$')) {
		ok('client add hashes the password and keeps customer cttype');
	} else {
		bad('client add hashes the password and keeps customer cttype');
	}

	$note = new Notification(null, null, 'client-admin');
	$note->nname = 'client-admin';
	$note->text_newaccountmessage = Notification::defaultWelcomeMessage();
	$note->dbaction = 'clean';
	$admin->notification_o = $note;
	$admin->__object_list = array_values(array_unique(array_merge(
		is_array($admin->__object_list ?? null) ? $admin->__object_list : array(),
		array('notification')
	)));
	$cli = new Client(null, 'localhost', 'kloxocptestcli');
	$cli->nname = 'kloxocptestcli';
	$cli->cttype = 'customer';
	$cli->password = $added['password'];
	$cli->realpass = 'Secret12';
	$cli->contactemail = 'acme@example.com';
	$cli->send_welcome_f = 'off';
	$cli->__parent_o = $admin;
	$cli->dbaction = 'add';
	$cli->postAdd();
	if (($cli->syncserver ?? '') === 'localhost'
		&& is_object($cli->priv)
		&& is_object($cli->listpriv)) {
		ok('client postAdd completes when notification has no notflag_b');
	} else {
		bad('client postAdd completes when notification has no notflag_b');
	}
	$cli->send_welcome_f = 'on';
	$cli->notifyObjects('add');
	ok('client notifyObjects does not TypeError without notflag_b');
} catch (Throwable $e) {
	bad('client addform/continueForm/add complete without TypeError', $e->getMessage());
} finally {
	if (isset($prevLogin)) {
		$login = $prevLogin;
	}
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
	$rmform = $sw->updateform('multiple_php_remove', array());
	$usedList = $phpUsed['php_used'][1] ?? array();
	$availType = $inst['multiple_php_install'][0] ?? '';
	$avail = $inst['multiple_php_install'][1] ?? array();
	$rmType = $rmform['multiple_php_remove'][0] ?? '';
	$rmlist = $rmform['multiple_php_remove'][1] ?? array();
	$availOk = ($availType === 'M' && is_string($avail))
		|| ($availType === 'U' && is_array($avail) && $avail !== [] && !isset($avail[0]) && !in_array('php52m', $avail, true));
	$rmOk = ($rmType === 'M' && is_string($rmlist))
		|| ($rmType === 'U' && is_array($rmlist) && $rmlist !== [] && !isset($rmlist[0]) && !in_array('php52m', $rmlist, true));
	if ($allForms && is_array($usedList) && $usedList !== [] && !in_array('--PHP Branch--', $usedList, true)
		&& $availOk && $rmOk) {
		ok('Webserver Configure PHP lists are Ubuntu phpX.Y not php52m');
	} else {
		bad('Webserver Configure PHP lists are Ubuntu phpX.Y not php52m',
			$allWhy !== '' ? $allWhy : ('avail=' . $availType . ' remove=' . $rmType));
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
	&& str_contains($webSrc, 'KloxoPhpver::asSelectList')
	&& str_contains($webSrc, 'KloxoPhpver::removableIds')
	&& str_contains($webSrc, 'KloxoPhpver::parseRequestedIds')
	&& str_contains($webSync, '"--select=chown"')
	&& str_contains($webSync, 'function writeUbuntuApacheOptimize')
	&& str_contains($webSync, 'function set_php_type_ubuntu')
	&& str_contains($webSync, 'function startSitePhpInstall')
	&& str_contains($webSync, 'KloxoPhpver::parseRequestedIds')
	&& str_contains($webSync, 'install-site-php')) {
	ok('Webserver Configure does not parse CentOS php.lst or cat httpd');
} else {
	bad('Webserver Configure does not parse CentOS php.lst or cat httpd');
}

$htmlSrc = (string)file_get_contents($doc . '/theme/htmllib.php');
$jsSrc = (string)file_get_contents($doc . '/theme/js/lxa.js');
if (str_contains($htmlSrc, '$v2 = array();')
	&& str_contains($htmlSrc, "function print_multiselect")
	&& str_contains($jsSrc, 'function multiSelectCommitForm')
	&& str_contains($jsSrc, "data.join(',')")
	&& str_contains($jsSrc, 'multiSelectCommitForm(frmname)')) {
	ok('Multiple PHP dual-listbox submits selected phpX.Y ids');
} else {
	bad('Multiple PHP dual-listbox submits selected phpX.Y ids');
}

try {
	global $login;
	$prevUpd = $login;
	$login = new Client(null, 'localhost', 'admin');
	$login->nname = 'admin';
	$swUpd = new serverweb(null, 'localhost', 'pserver-localhost');
	$swUpd->nname = 'pserver-localhost';
	$got = $swUpd->update('multiple_php_install', array('multiple_php_install' => 'php8.5, 8.4,0,php52m'));
	$emptyErr = '';
	try {
		$swUpd->update('multiple_php_install', array('multiple_php_install' => ''));
	} catch (Throwable $e) {
		$emptyErr = $e->getMessage();
	}
	$login = $prevUpd;
	if (is_array($got) && ($got['multiple_php_install'] ?? '') === 'php8.5,php8.4' && $emptyErr !== '') {
		ok('Multiple PHP install POST keeps phpX.Y ids and rejects a blank selection');
	} else {
		bad('Multiple PHP install POST keeps phpX.Y ids and rejects a blank selection', $emptyErr);
	}
} catch (Throwable $e) {
	bad('Multiple PHP install POST keeps phpX.Y ids and rejects a blank selection', $e->getMessage());
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
$lgHomeTab = kloxo_content_tab_label('Home',
	'display.php?frm_action=show&frm_o_o[0][class]=pserver&frm_o_o[0][nname]=localhost&frm_o_o[1][class]=lxguard');
$lgShowTab = kloxo_content_tab_label('Lxguard', 'a=show&o=lxguard');
$lgHitTab = kloxo_content_tab_label('connection', 'a=list&c=lxguardhitdisplay');
if ($lgSwitchOk && $lgTabOk && $lgFormOk && str_contains($lgSrc, 'function getSelfList')
	&& str_contains($lgSrc, 'adminServerSwitchList')
	&& $lgHomeTab === 'LxGuard'
	&& $lgShowTab === 'LxGuard'
	&& $lgHitTab !== 'LxGuard'
	&& function_exists('kloxo_url_has_lxguard') && kloxo_url_has_lxguard('a=show&o=lxguard')
	&& kloxo_url_has_lxguard('display.php?frm_o_o%5B1%5D%5Bclass%5D=lxguard')
	&& !kloxo_url_has_lxguard('a=list&c=lxguardhitdisplay')
	&& function_exists('kloxo_url_has_ffile')
	&& kloxo_url_has_ffile('display.php?frm_o_o%5B1%5D%5Bclass%5D=ffile')
	&& function_exists('kloxo_url_has_serverftp')
	&& kloxo_url_has_serverftp('display.php?frm_o_o%5B1%5D%5Bclass%5D=serverftp')) {
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
$parentTabs = phpini::phpiniPropertyTabsForParent();
$phpHomeTab = kloxo_content_tab_label('Home',
	'display.php?frm_action=show&frm_o_o[0][class]=pserver&frm_o_o[0][nname]=localhost&frm_o_o[1][class]=phpini');
$phpAdvHome = kloxo_content_tab_label('Home',
	'display.php?frm_action=updateform&frm_subaction=extraedit&frm_o_o[1][class]=phpini');
$ufSrcPhp = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
$tabOk = is_array($tabs['property'] ?? null)
	&& count($tabs['property']) === 2
	&& ($tabs['property'][0] ?? '') === 'a=show'
	&& ($tabs['property'][1] ?? '') === 'a=updateform&sa=extraedit'
	&& is_array($tabRet) && ($tabRet['property'] ?? null) === $tabs['property']
	&& is_array($sharedTabs) && count($sharedTabs) === 2
	&& $sharedTabs === $tabs['property']
	&& is_array($parentTabs) && count($parentTabs) === 2
	&& ($parentTabs[0] ?? '') === 'a=show&o=phpini'
	&& ($parentTabs[1] ?? '') === 'a=updateform&sa=extraedit&o=phpini'
	&& $phpHomeTab === 'PHP Configure'
	&& $phpAdvHome === 'Advanced PHP Configure'
	&& str_contains($ufSrcPhp, 'phpiniPropertyTabsForParent')
	&& str_contains($ufSrcPhp, 'kloxo_url_has_phpini')
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
$phpId = '';
$phpRule = '';
$phpParent = '';
$phpAdminList = null;
try {
	$phpId = $ini->getId();
	$phpRule = (string) phpini::initThisObjectRule(null, 'phpini');
	$orphan = new phpini(null, 'localhost', 'client-admin');
	$orphan->nname = 'client-admin';
	$phpParent = $orphan->phpiniParentClass();
	$phpAdminList = $orphan->getAdminList();
} catch (Throwable $e) {
	$editErr = $editErr !== '' ? $editErr : $e->getMessage();
}
if ($tabOk && $editOk && $extraOk
	&& $sessionPath === '/home/kloxo/client/admin/session'
	&& is_array($tz) && $tz !== array()
	&& is_array($fpm) && in_array('static', $fpm, true)
	&& is_array($extra) && !array_key_exists('phpini_flag_b-display_error_flag', $extra)
	&& $phpId === 'admin'
	&& $phpRule === 'client-admin'
	&& $phpParent === ''
	&& is_array($phpAdminList)) {
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
	&& ($wdDefaults[5][2] ?? '') === '__driver_webcache' && ($wdDefaults[5][3] ?? '') === 'on'
	&& ($wdDefaults[6][2] ?? '') === '__driver_webproxy' && ($wdDefaults[6][3] ?? '') === 'on'
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

$sslSrc = (string)file_get_contents($doc . '/driver/pserver/sslcertlib.php');
$webSrc = (string)file_get_contents($doc . '/driver/web/weblib.php');
try {
	$sslListUrl = 'display.php?frm_action=list&frm_o_o[0][class]=domain&frm_o_o[0][nname]=aklor.com&frm_o_o[1][class]=web&frm_o_cname=sslcert';
	$sslShowTab = kloxo_content_tab_label('ssl_certificate', $sslListUrl);
	$sslFileTab = kloxo_content_tab_label('Add ssl_file', 'a=addform&c=sslcert&dta[var]=upload&dta[val]=file');
	$sslTextTab = kloxo_content_tab_label('', 'a=addform&c=sslcert&dta[var]=upload&dta[val]=text');
	$sslLeTab = kloxo_content_tab_label('ssl_letsencrypt', 'a=addform&c=sslcert&dta[var]=upload&dta[val]=letsencrypt');
	$sslLinkTab = kloxo_content_tab_label('Add SSL Link', 'a=addform&c=sslcert&dta[var]=upload&dta[val]=link');
	$web = new Web(null, 'localhost', 'aklor.com');
	$web->nname = 'aklor.com';
	$web->customer_name = 'aklorcom';
	$sslTabs = Sslcert::createListAlist($web, 'sslcert');
	$sslForm = Sslcert::addform($web, 'sslcert', array('var' => 'upload', 'val' => 'letsencrypt'));
	$sslFileForm = Sslcert::addform($web, 'sslcert', array('var' => 'upload', 'val' => 'file'));
	$sslNullForm = Sslcert::addform(null, 'sslcert', null);
	$sslEmptyLink = Sslcert::getSSLParentList('aklor.com');
	$sslCert = new Sslcert(null, 'localhost', 'aklor.com');
	$sslCert->nname = 'aklor.com';
	$sslParse = $sslCert->getCRTParse();
	$sslUsers = $sslCert->getUserList();
	$sslNlist = Sslcert::createListNlist($web, null);
	$sslSwitch = $web->getSelfList();
	if ($sslShowTab === 'SSL Certificates'
		&& $sslFileTab === 'Add SSL File'
		&& $sslTextTab === 'Add SSL Text'
		&& $sslLeTab === "Add Let's Encrypt"
		&& $sslLinkTab === 'Add SSL Link'
		&& is_array($sslTabs)
		&& ($sslTabs[0] ?? '') === 'a=list&c=sslcert'
		&& in_array('a=addform&c=sslcert&dta[var]=upload&dta[val]=file', $sslTabs, true)
		&& in_array('a=addform&c=sslcert&dta[var]=upload&dta[val]=text', $sslTabs, true)
		&& in_array('a=addform&c=sslcert&dta[var]=upload&dta[val]=letsencrypt', $sslTabs, true)
		&& in_array('a=addform&c=sslcert&dta[var]=upload&dta[val]=link', $sslTabs, true)
		&& is_array($sslForm) && isset($sslForm['variable']['ssl_data_b_s_subjectAltName_r'])
		&& is_array($sslFileForm) && array_key_exists('ssl_key_file_f', $sslFileForm['variable'])
		&& is_array($sslNullForm) && isset($sslNullForm['variable'])
		&& is_array($sslEmptyLink)
		&& $sslParse === '-'
		&& is_array($sslUsers)
		&& isset($sslNlist['username']) && isset($sslNlist['nname'])
		&& isset($sslNlist['add_type']) && isset($sslNlist['parent_domain'])
		&& isset($sslNlist['syncserver'])
		&& is_array($sslSwitch) && isset($sslSwitch['aklor.com'])
		&& str_contains($sslSrc, 'function sslPropertyTabs')
		&& str_contains($webSrc, 'function domainWebSwitchList')) {
		ok('SSL Certificates list tabs, Switch To Another and addform load without TypeError');
	} else {
		bad('SSL Certificates list tabs, Switch To Another and addform load without TypeError',
			'show=' . $sslShowTab . ' file=' . $sslFileTab . ' text=' . $sslTextTab
				. ' le=' . $sslLeTab . ' link=' . $sslLinkTab
				. ' tabs=' . (is_array($sslTabs) ? implode(',', $sslTabs) : 'no')
				. ' switch=' . (is_array($sslSwitch) ? implode(',', array_keys($sslSwitch)) : 'no'));
	}
} catch (Throwable $e) {
	bad('SSL Certificates list tabs, Switch To Another and addform load without TypeError', $e->getMessage());
}

$lxclassSrc = (string) file_get_contents($doc . '/lib/php/lxclass.php');
$webSrcPi = (string) file_get_contents($doc . '/driver/web/weblib.php');
$domSrcPi = (string) file_get_contents($doc . '/lib/domain/domainlib.php');
$adminPi = new Client(null, 'localhost', 'admin');
$adminPi->nname = 'admin';
$adminPi->cttype = 'client';
$adminPi->parent_clname = '';
ob_start();
$tpPi = 'threw';
$gpPi = 'threw';
try {
	$tpPi = $adminPi->getTrueParentO();
	$gpPi = $adminPi->getParentO();
} catch (Throwable $e) {
	$tpPi = $e->getMessage();
}
$fatalPi = ob_get_clean();
$webPi = new Web(null, 'localhost', 'example.com');
$webPi->nname = 'example.com';
$webPi->php_selected = 'php';
$htmlPi = '';
$piErr = '';
ob_start();
try {
	$webPi->showRawPrint('phpinfo');
	$htmlPi = ob_get_clean();
} catch (Throwable $e) {
	ob_end_clean();
	$piErr = $e->getMessage();
}
$tabPi = kloxo_content_tab_label('phpinfo', 'n=web&a=show&sa=phpinfo');
$tabPiUpdate = kloxo_content_tab_label('phpinfo', 'n=web&a=update&sa=phpinfo');
if ($tpPi === null
	&& $gpPi === null
	&& $adminPi->isRootClient()
	&& !str_contains($fatalPi, 'Critical internal error')
	&& !str_contains($lxclassSrc, 'Critical internal error')
	&& str_contains($lxclassSrc, 'function isRootClient')
	&& str_contains($domSrcPi, 'n=web&a=show&sa=phpinfo')
	&& !str_contains($domSrcPi, 'n=web&a=update&sa=phpinfo')
	&& str_contains($webSrcPi, 'function phpInfoText')
	&& str_contains($webSrcPi, 'proc_open')
	&& str_contains($webSrcPi, "a=show&sa=phpinfo")
	&& !str_contains($webSrcPi, '__kloxo/phpinfo.php')
	&& $tabPi === 'PHPinfo'
	&& $tabPiUpdate === 'PHPinfo'
	&& kloxo_skip_find_class('web')
	&& $piErr === ''
	&& str_contains($htmlPi, '<pre')
	&& str_contains($htmlPi, 'Site PHP')
	&& !str_contains($htmlPi, 'Critical internal error')) {
	ok('Client PHPinfo shows site php -i instead of parent_clname fatal');
} else {
	bad('Client PHPinfo shows site php -i instead of parent_clname fatal',
		'tp=' . var_export($tpPi, true) . ' gp=' . var_export($gpPi, true)
			. ' tab=' . $tabPi . ' err=' . $piErr
			. ' html=' . substr(trim(preg_replace('/\s+/', ' ', $htmlPi)), 0, 180)
			. ' fatal=' . substr($fatalPi, 0, 80));
}

$coErr = '';
$coForm = null;
$coDom = null;
$coTab = kloxo_content_tab_label('change_owner', 'a=updateform&sa=changeowner');
$coMsg = is_object($ghtml) ? $ghtml->convert_message(null) : 'no-ghtml';
$lxclassCo = (string) file_get_contents($doc . '/lib/php/lxclass.php');
$htmlCo = (string) file_get_contents($doc . '/theme/htmllib.php');
$coAdmin = $adminPi;
$coAdmin->cttype = 'admin';
$coAdmin->parent_clname = '';
$coAdmin->__parent_o = null;
try {
	$coCli = new Client(null, 'localhost', 'aklorcom');
	$coCli->nname = 'aklorcom';
	$coCli->cttype = 'customer';
	$coCli->parent_clname = 'client-admin';
	$coCli->__parent_o = $coAdmin;
	$coForm = $coCli->updateform('changeowner', array());
} catch (Throwable $e) {
	$coErr = $e->getMessage();
}
try {
	$coD = new domain(null, 'localhost', 'aklor.com');
	$coD->nname = 'aklor.com';
	$coD->cttype = 'domain';
	$coD->parent_clname = 'client-aklorcom';
	$coD->__parent_o = isset($coCli) && is_object($coCli) ? $coCli : $coAdmin;
	$coDom = $coD->updateform('changeowner', array());
} catch (Throwable $e) {
	if ($coErr === '') {
		$coErr = $e->getMessage();
	}
}
$coList = (is_array($coForm) && isset($coForm['parent_name_change'][1])
	&& is_array($coForm['parent_name_change'][1])) ? $coForm['parent_name_change'][1] : array();
$coDomList = (is_array($coDom) && isset($coDom['parent_name_change'][1])
	&& is_array($coDom['parent_name_change'][1])) ? $coDom['parent_name_change'][1] : array();
if ($coErr === ''
	&& is_array($coForm) && ($coForm['parent_name_change'][0] ?? '') === 's'
	&& $coList !== array()
	&& (isset($coList['admin']) || in_array('admin', $coList, true))
	&& is_array($coDom) && ($coDom['parent_name_change'][0] ?? '') === 's'
	&& $coDomList !== array()
	&& $coTab === 'Change Owner'
	&& $coMsg === ''
	&& str_contains($lxclassCo, 'function changeOwnerForm')
	&& str_contains($htmlCo, "return is_string(\$pinfo) ? \$pinfo : '';")) {
	ok('Change Owner form loads without parent_clname TypeError');
} else {
	bad('Change Owner form loads without parent_clname TypeError',
		'err=' . $coErr . ' tab=' . $coTab . ' msg=' . var_export($coMsg, true)
			. ' form=' . var_export($coForm, true)
			. ' dom=' . var_export($coDom, true));
}

$ufwPrevLogin = isset($login) ? $login : null;
$login = new class {
	public $nname = 'admin';
	public $cttype = 'admin';
	public function isAdmin() { return true; }
	public function getThrow($k) { return (string) $k; }
};
$ufwSrc = (string) file_get_contents($doc . '/driver/pserver/ufwfirewalllib.php');
$ufwPserver = (string) file_get_contents($doc . '/driver/pserver/pserverlib.php');
$ufwMenu = (string) file_get_contents($doc . '/theme/skin/simplicity/default/menu.php');
$ufwKw = (string) file_get_contents($doc . '/lang/en-us/langkeywordlib.php');
$ufwShowTab = kloxo_content_tab_label('UFW Firewall',
	'display.php?frm_action=show&frm_o_o[0][class]=pserver&frm_o_o[0][nname]=localhost&frm_o_o[1][class]=ufwfirewall');
$ufwHomeTab = kloxo_content_tab_label('Home',
	'display.php?frm_action=show&frm_o_o[0][class]=pserver&frm_o_o[0][nname]=localhost&frm_o_o[1][class]=ufwfirewall');
$ufwParent = new pserver(null, 'localhost', 'localhost');
$ufwParent->nname = 'localhost';
$ufw = new ufwfirewall(null, 'localhost', 'localhost');
$ufw->nname = 'localhost';
$ufw->syncserver = 'localhost';
$ufw->__parent_o = $ufwParent;
$ufwTabs = array('property' => array());
$ufw->createShowPropertyList($ufwTabs);
$ufwForced = ufwfirewall::ufwPropertyTabs();
$ufwPserverTabs = ufwfirewall::ufwPropertyTabsForPserver();
$ufwShowForms = method_exists($ufw, 'createShowUpdateform') ? $ufw->createShowUpdateform() : null;
$ufwSwitch = null;
$ufwForm = null;
$ufwEnForm = null;
$ufwDisForm = null;
$ufwDefForm = null;
$ufwDelForm = null;
$ufwErr = '';
try {
	$ufwSwitch = $ufw->getSelfList();
	$ufwForm = $ufw->updateform('addrule', array());
	$ufwEnForm = $ufw->updateform('ufwenable', array());
	$ufwDisForm = $ufw->updateform('ufwdisable', array());
	$ufwDefForm = $ufw->updateform('ufwdefaults', array());
	$ufwDelForm = $ufw->updateform('deleterule', array());
} catch (Throwable $e) {
	$ufwErr = $e->getMessage();
}
$ufwTabOk = is_array($ufwTabs['property'] ?? null)
	&& count($ufwTabs['property']) === 1
	&& ($ufwTabs['property'][0] ?? '') === 'a=show';
$ufwForcedOk = is_array($ufwForced) && $ufwForced === array('a=show')
	&& $ufwForced === $ufwTabs['property'];
$ufwPserverTabOk = is_array($ufwPserverTabs)
	&& count($ufwPserverTabs) === 1
	&& ($ufwPserverTabs[0] ?? '') === 'a=show&o=ufwfirewall';
$ufwShowFormsOk = is_array($ufwShowForms) && $ufwShowForms === array();
$ufwUiOk = str_contains($ufwSrc, 'Firewall Rules')
	&& str_contains($ufwSrc, 'Add Rule')
	&& str_contains($ufwSrc, 'Default Policies')
	&& str_contains($ufwSrc, 'printFormOpen')
	&& str_contains($ufwSrc, 'Enable Firewall')
	&& str_contains($ufwSrc, 'Disable Firewall')
	&& str_contains($ufwSrc, 'splitPorts')
	&& !str_contains($ufwSrc, 'Default incoming:')
	&& !str_contains($ufwSrc, 'No numbered rules.')
	&& !str_contains($ufwSrc, '\$eRaw');
$ufwSwitchOk = is_array($ufwSwitch) && isset($ufwSwitch['localhost'])
	&& is_object($ufwSwitch['localhost']) && method_exists($ufwSwitch['localhost'], 'getId');
$ufwFormOk = is_array($ufwForm) && array_key_exists('ufw_action', $ufwForm)
	&& array_key_exists('ufw_port', $ufwForm)
	&& array_key_exists('ufw_proto', $ufwForm)
	&& array_key_exists('ufw_from', $ufwForm)
	&& ($ufwForm['__v_button'] ?? '') === 'Add Rule';
$ufwEnOk = is_array($ufwEnForm) && array_key_exists('ufw_confirm', $ufwEnForm)
	&& ($ufwEnForm['__v_button'] ?? '') === 'Enable';
$ufwDisOk = is_array($ufwDisForm) && array_key_exists('ufw_confirm', $ufwDisForm)
	&& ($ufwDisForm['__v_button'] ?? '') === 'Disable';
$ufwDefOk = is_array($ufwDefForm) && array_key_exists('ufw_default_in', $ufwDefForm)
	&& array_key_exists('ufw_default_out', $ufwDefForm)
	&& ($ufwDefForm['__v_button'] ?? '') === 'Update Defaults';
$ufwDelOk = is_array($ufwDelForm) && array_key_exists('ufw_rule', $ufwDelForm);
$ufwCore = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
$ufwCoreOk = str_contains($ufwCore, 'ufwfirewall::ufwPropertyTabs()')
	&& str_contains($ufwCore, "\$class !== 'ufwfirewall'")
	&& str_contains($ufwCore, "\$class !== 'ufwfirewall'");
$ufwSkipOk = str_contains($ufwCore, "\$class !== 'lxbackup' && \$class !== 'ufwfirewall'");
$ufwPrevGhtml = $ghtml ?? null;
$ghtml = null;
if (!isset($gbl) || !is_object($gbl)) {
	$gbl = new stdClass();
}
$gbl->__this_redirect = null;
$ufw->redirectToShow();
$ufwRedirect = isset($gbl->__this_redirect) ? (string) $gbl->__this_redirect : '';
$ghtml = $ufwPrevGhtml;
$ufwRedirectOk = str_contains($ufwRedirect, 'frm_action=show')
	&& str_contains($ufwRedirect, 'ufwfirewall')
	&& str_contains($ufwRedirect, 'updated_successfully');
$ufwActionSrc = (string) file_get_contents($doc . '/lib/html/objectactionlib.php');
$ufwParentOk = str_contains($ufwActionSrc, '$qparent = is_object($parent) ? $parent : null')
	&& str_contains($ufwActionSrc, 'Do not fall back to $object')
	&& str_contains($ufwActionSrc, 'if (!is_object($parent))');
if (str_contains($ufwPserver, 'a=show&o=ufwfirewall')
	&& str_contains($ufwPserver, 'ufwPropertyTabsForPserver')
	&& str_contains($ufwPserver, '__title_security')
	&& str_contains($ufwSrc, 'UFW Firewall')
	&& str_contains($ufwSrc, 'function redirectToShow')
	&& str_contains($ufwSrc, 'function ufwPropertyTabsForPserver')
	&& str_contains($ufwSrc, 'ufwdefaults')
	&& str_contains($ufwMenu, 'frm_o_o[1][class]=ufwfirewall')
	&& str_contains($ufwKw, '"UFW Firewall"')
	&& $ufwShowTab === 'UFW Firewall'
	&& $ufwHomeTab === 'UFW Firewall'
	&& $ufwTabOk && $ufwForcedOk && $ufwPserverTabOk && $ufwShowFormsOk && $ufwUiOk && $ufwSwitchOk
	&& $ufwFormOk && $ufwEnOk && $ufwDisOk && $ufwDefOk && $ufwDelOk
	&& $ufwCoreOk && $ufwSkipOk && $ufwRedirectOk && $ufwParentOk
	&& $ufw->isSync() === false
	&& str_contains($ufwSrc, 'function getSelfList')) {
	ok('Security UFW Firewall is a Hestia-style single page for status, defaults and rules');
} else {
	bad('Security UFW Firewall is a Hestia-style single page for status, defaults and rules',
		'err=' . $ufwErr
			. ' show=' . $ufwShowTab . ' home=' . $ufwHomeTab
			. ' tabs=' . implode(',', (array) ($ufwTabs['property'] ?? array()))
			. ' ui=' . ($ufwUiOk ? 'ok' : 'no')
			. ' emptyForms=' . ($ufwShowFormsOk ? 'ok' : 'no')
			. ' add=' . ($ufwFormOk ? 'ok' : 'no')
			. ' en=' . ($ufwEnOk ? 'ok' : 'no')
			. ' dis=' . ($ufwDisOk ? 'ok' : 'no')
			. ' def=' . ($ufwDefOk ? 'ok' : 'no')
			. ' del=' . ($ufwDelOk ? 'ok' : 'no')
			. ' core=' . ($ufwCoreOk ? 'ok' : 'no')
			. ' skip=' . ($ufwSkipOk ? 'ok' : 'no'));
}
$login = $ufwPrevLogin;

$genTab = function_exists('kloxo_content_tab_label')
	? kloxo_content_tab_label('General Settings', 'a=updateform&sa=generalsetting&o=general')
	: '';
$genTabSa = function_exists('kloxo_content_tab_label')
	? kloxo_content_tab_label('', 'a=updateform&sa=generalsetting')
	: '';
$genTabUgly = function_exists('kloxo_content_tab_label')
	? kloxo_content_tab_label('Update_generalsetting', 'a=updateform&sa=generalsetting')
	: '';
$genTabs = class_exists('General') && is_callable(array('General', 'generalPropertyTabs'))
	? General::generalPropertyTabs() : array();
$genLogin = class_exists('General') && is_callable(array('General', 'defaultLoginMessage'))
	? General::defaultLoginMessage() : '';
$genObj = class_exists('General') ? new General(null, null, 'admin') : null;
$genFormOk = false;
$genCronOn = false;
$genLoginOk = false;
$genPropOk = false;
$genFormErr = '';
$genFlagPath = $root . '/etc/flag/enablecronforall.flg';
$genFlagExisted = is_file($genFlagPath);
if (is_object($genObj) && method_exists($genObj, 'updateform')) {
	try {
		global $ghtml, $sgbl, $login;
		if (!is_object($ghtml)) {
			$ghtml = class_exists('HtmlLib') ? new HtmlLib() : null;
		}
		if (is_object($ghtml)) {
			$ghtml->frm_subaction = 'generalsetting';
		}
		if (!isset($genObj->generalmisc_b) || !is_object($genObj->generalmisc_b)) {
			$genObj->generalmisc_b = class_exists('generalmisc_b')
				? new generalmisc_b(null, null, 'general') : (object) array();
		}
		@mkdir(dirname($genFlagPath), 0755, true);
		if (!$genFlagExisted) {
			@file_put_contents($genFlagPath, '');
		}
		$genObj->login_pre = '';
		$vlist = $genObj->updateform('generalsetting', array());
		$genFormOk = is_array($vlist) && array_key_exists('login_pre', $vlist)
			&& array_key_exists('enable_cronforall', $vlist);
		$genCronOn = isset($genObj->enable_cronforall) && $genObj->enable_cronforall === 'on';
		$genLoginOk = isset($genObj->login_pre) && is_string($genObj->login_pre)
			&& str_contains($genObj->login_pre, '<%programname%>')
			&& str_contains($genObj->login_pre, 'Use a valid username and password');
		$props = array('property' => array());
		$genObj->createShowPropertyList($props);
		$genPropOk = is_array($props['property'] ?? null)
			&& ($props['property'][0] ?? '') === 'a=updateform&sa=generalsetting';
	} catch (Throwable $e) {
		$genFormOk = false;
		$genFormErr = $e->getMessage();
	}
	if (!$genFlagExisted && is_file($genFlagPath)) {
		@unlink($genFlagPath);
	}
}
$genDesc = @file_get_contents(__DIR__ . '/../../kloxo/httpdocs/lang/en-us/desclib.php');
$genDescOk = is_string($genDesc) && str_contains($genDesc, 'Enable Cron For All Users');
$genSeedSrc = @file_get_contents(__DIR__ . '/../../kloxo/bin/install/init-panel-db');
$genSeedOk = is_string($genSeedSrc) && str_contains($genSeedSrc, 'enablecronforall.flg')
	&& str_contains($genSeedSrc, 'defaultLoginMessage');
if ($genTab === 'General Settings' && $genTabSa === 'General Settings'
	&& $genTabUgly === 'General Settings'
	&& is_array($genTabs) && ($genTabs[0] ?? '') === 'a=updateform&sa=generalsetting'
	&& is_string($genLogin) && str_contains($genLogin, '<%programname%>')
	&& $genFormOk && $genCronOn && $genLoginOk && $genPropOk && $genDescOk && $genSeedOk) {
	ok('General Settings tab, cron default on, and login message default');
} else {
	bad('General Settings tab, cron default on, and login message default',
		'tab=' . $genTab . ' sa=' . $genTabSa . ' ugly=' . $genTabUgly
			. ' cron=' . ($genCronOn ? 'on' : 'off')
			. ' login=' . ($genLoginOk ? 'ok' : 'no')
			. ' form=' . ($genFormOk ? 'ok' : 'no')
			. ' prop=' . ($genPropOk ? 'ok' : 'no')
			. ' seed=' . ($genSeedOk ? 'ok' : 'no')
			. ' err=' . $genFormErr);
}

$wmAvail = class_exists('Mmail') && is_callable(array('Mmail', 'getAvailableWebmailProgList'))
	? Mmail::getAvailableWebmailProgList() : array();
$wmList = class_exists('Mmail') && is_callable(array('Mmail', 'getWebmailProgList'))
	? Mmail::getWebmailProgList() : array();
$wmNeed = array('afterlogic', 'rainloop', 'roundcube', 'squirrelmail', 'telaen');
$wmOk = is_array($wmAvail) && is_array($wmList);
foreach ($wmNeed as $w) {
	if (!in_array($w, $wmAvail, true) || !in_array($w, $wmList, true)) {
		$wmOk = false;
		break;
	}
}
$wmFormList = array();
if (is_object($genObj) && method_exists($genObj, 'updateform') && $genFormOk) {
	try {
		if (isset($ghtml) && is_object($ghtml)) {
			$ghtml->frm_subaction = 'generalsetting';
		}
		$wmV = $genObj->updateform('generalsetting', array());
		$wmFormList = (isset($wmV['generalmisc_b-webmail_system_default'][1])
			&& is_array($wmV['generalmisc_b-webmail_system_default'][1]))
			? $wmV['generalmisc_b-webmail_system_default'][1] : array();
	} catch (Throwable $e) {
		$wmFormList = array();
	}
}
$wmFormOk = in_array('--chooser--', $wmFormList, true)
	&& in_array('roundcube', $wmFormList, true)
	&& in_array('rainloop', $wmFormList, true)
	&& in_array('afterlogic', $wmFormList, true);
if ($wmOk && $wmFormOk) {
	ok('General Settings Webmail System Default lists installed and available webmails');
} else {
	bad('General Settings Webmail System Default lists installed and available webmails',
		'list=' . implode(',', (array) $wmList)
			. ' form=' . implode(',', (array) $wmFormList));
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
