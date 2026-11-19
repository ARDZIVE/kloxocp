<?php
/**
 * Server menu titles: PHP 8.5 strpos(null) aborted get_classvar_description
 * on a=show items (sshclient), which cut every page before #mmm.
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
error_reporting(E_ALL);
ini_set('display_errors', '0');

include_once $doc . '/lib/html/include.php';

try {
	$beg = csb(null, '__');
	if ($beg === false) {
		ok('csb(null) does not TypeError');
	} else {
		bad('csb(null) does not TypeError', 'returned ' . var_export($beg, true));
	}
} catch (Throwable $e) {
	bad('csb(null) does not TypeError', $e->getMessage());
}

try {
	$a = csa(null, 'N');
	if ($a === false) {
		ok('csa(null) does not TypeError');
	} else {
		bad('csa(null) does not TypeError', 'returned ' . var_export($a, true));
	}
} catch (Throwable $e) {
	bad('csa(null) does not TypeError', $e->getMessage());
}

try {
	if (class_exists('sshclient')) {
		ok('sshclient class loads on PHP 8');
	} else {
		bad('sshclient class loads on PHP 8');
	}
} catch (Throwable $e) {
	bad('sshclient class loads on PHP 8', $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

try {
	$desc = get_classvar_description('sshclient');
	if (is_array($desc) && isset($desc[2]) && $desc[2] !== '') {
		ok('sshclient __desc has a title');
	} else {
		bad('sshclient __desc has a title', var_export($desc, true));
	}
} catch (Throwable $e) {
	bad('sshclient __desc has a title', $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

try {
	$show = get_classvar_description('sshclient', '__acdesc_show');
	if (is_array($show) && isset($show[2]) && $show[2] !== '') {
		ok('sshclient show action has a title');
	} else {
		bad('sshclient show action has a title', var_export($show, true));
	}
} catch (Throwable $e) {
	bad('sshclient show action has a title', $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

try {
	$t = kloxo_menu_title('a=show&o=sshclient');
	if (is_string($t) && $t !== '') {
		ok('kloxo_menu_title returns a string for sshclient');
	} else {
		bad('kloxo_menu_title returns a string for sshclient', var_export($t, true));
	}
} catch (Throwable $e) {
	bad('kloxo_menu_title returns a string for sshclient', $e->getMessage());
}

// While viewing PHP Configure, left-menu titles must not all become "PHP Configure"
// (getFullUrl must not rebuild frm_o_o from __c_object after menu_title clears it).
try {
	global $ghtml, $gbl;
	$prevHtml = $ghtml;
	$prevGbl = $gbl ?? null;
	$ghtml = new HtmlLib();
	$ghtml->__http_vars = array(
		'frm_action' => 'show',
		'frm_o_o' => array(
			array('class' => 'pserver', 'nname' => 'localhost'),
			array('class' => 'phpini'),
		),
	);
	if (!isset($gbl) || !is_object($gbl)) {
		$gbl = new stdClass();
	}
	$gbl->__c_object = new phpini(null, 'localhost', 'pserver-localhost');
	$sshTitle = kloxo_menu_title('a=show&o=sshclient');
	$svcTitle = kloxo_menu_title('a=list&c=service');
	$cliTitle = kloxo_menu_title('a=list&c=all_client');
	$phpTitle = kloxo_menu_title('a=show&o=phpini');
	$gbl = $prevGbl;
	$ghtml = $prevHtml;
	$okMenu = is_string($sshTitle) && $sshTitle !== '' && stripos($sshTitle, 'PHP Configure') === false
		&& is_string($svcTitle) && $svcTitle !== '' && stripos($svcTitle, 'PHP Configure') === false
		&& is_string($cliTitle) && $cliTitle !== '' && stripos($cliTitle, 'PHP Configure') === false
		&& is_string($phpTitle) && stripos($phpTitle, 'PHP Configure') !== false;
	if ($okMenu) {
		ok('menu titles on PHP Configure page stay distinct (not all PHP Configure)');
	} else {
		bad('menu titles on PHP Configure page stay distinct (not all PHP Configure)',
			'ssh=' . (string) $sshTitle . ' svc=' . (string) $svcTitle
				. ' cli=' . (string) $cliTitle . ' php=' . (string) $phpTitle);
	}
} catch (Throwable $e) {
	bad('menu titles on PHP Configure page stay distinct (not all PHP Configure)',
		$e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

// Menu titles must not assign $ghtml->frm_o_o (real property shadows __http_vars
// and content tabs then lose the object path → "Home" instead of "PHP Configure").
try {
	global $ghtml, $gbl;
	$prevHtml = $ghtml;
	$ghtml = new HtmlLib();
	$ghtml->__http_vars = array(
		'frm_action' => 'show',
		'frm_o_o' => array(
			array('class' => 'client', 'nname' => 'admin'),
			array('class' => 'phpini'),
		),
	);
	$pathBefore = $ghtml->frm_o_o;
	kloxo_menu_title('a=show&o=phpini');
	$pathAfter = $ghtml->frm_o_o;
	$realProp = array_key_exists('frm_o_o', get_object_vars($ghtml));
	$url = null;
	$advUrl = null;
	if (method_exists($ghtml, 'getFullUrl')) {
		$url = $ghtml->getFullUrl('a=show');
		$advUrl = $ghtml->getFullUrl('a=updateform&sa=extraedit');
	}
	// Even if a real null property remains, getFullUrl must still use __http_vars.
	$ghtml->frm_o_o = null;
	$shadowUrl = method_exists($ghtml, 'getFullUrl')
		? $ghtml->getFullUrl('a=updateform&sa=extraedit') : '';
	unset($ghtml->frm_o_o);
	$gblPrev = $gbl ?? null;
	if (!isset($gbl) || !is_object($gbl)) {
		$gbl = new stdClass();
	}
	$gbl->__c_object = new phpini(null, 'localhost', 'client-admin');
	$homeLabel = kloxo_content_tab_label('Home', 'display.php?frm_action=show');
	$advLabel = kloxo_content_tab_label('Home',
		is_string($advUrl) ? $advUrl : 'a=updateform&sa=extraedit&o=phpini');
	$showLabel = kloxo_content_tab_label('Home',
		is_string($url) ? $url : 'a=show&o=phpini');
	$gbl->__c_object = new lxbackup(null, 'localhost', 'client-admin');
	$bakHomeLabel = kloxo_content_tab_label('Home', 'display.php?frm_action=show');
	$ghtml->__http_vars['frm_o_o'] = array(
		array('class' => 'client', 'nname' => 'admin'),
		array('class' => 'lxbackup'),
	);
	$ghtml->__http_vars['frm_action'] = 'show';
	unset($ghtml->__http_vars['frm_subaction']);
	// Null real property (old menu_title bug): Backup tabs must still keep lxbackup.
	$ghtml->frm_o_o = null;
	$bakUrl = method_exists($ghtml, 'getFullUrl') ? $ghtml->getFullUrl('a=show') : '';
	$bakFtpUrl = method_exists($ghtml, 'getFullUrl')
		? $ghtml->getFullUrl('a=updateform&sa=ftp_conf') : '';
	$bakSchedUrl = method_exists($ghtml, 'getFullUrl')
		? $ghtml->getFullUrl('a=updateform&sa=schedule_conf') : '';
	$bakFmUrl = method_exists($ghtml, 'getFullUrl')
		? $ghtml->getFullUrl('a=show&l[class]=ffile&l[nname]=/') : '';
	$bakUpUrl = method_exists($ghtml, 'getFullUrl')
		? $ghtml->getFullUrl('l[class]=ffile&l[nname]=/&a=updateform&sa=upload') : '';
	$bakTabLabel = kloxo_content_tab_label('Home', is_string($bakUrl) ? $bakUrl : '');
	$bakFtpLabel = kloxo_content_tab_label('Update_ftp_conf',
		is_string($bakFtpUrl) ? $bakFtpUrl : '');
	$bakSchedLabel = kloxo_content_tab_label('Update_schedule_conf',
		is_string($bakSchedUrl) ? $bakSchedUrl : '');
	$bakFmLabel = kloxo_content_tab_label('home', is_string($bakFmUrl) ? $bakFmUrl : '');
	$bakUpLabel = kloxo_content_tab_label('Upload', is_string($bakUpUrl) ? $bakUpUrl : '');
	$bakFtpSelect = false;
	$bakSchedSelect = false;
	$bakFmSelect = false;
	$bakUpSelect = false;
	if (is_string($bakFtpUrl) && method_exists($ghtml, 'compare_urls')) {
		$ghtml->__http_vars['frm_action'] = 'updateform';
		$ghtml->__http_vars['frm_subaction'] = 'ftp_conf';
		$cur = 'display.php?' . $ghtml->get_get_from_current_post(null);
		$bakFtpSelect = $ghtml->compare_urls($cur, $bakFtpUrl)
			&& !$ghtml->compare_urls($cur, is_string($bakUrl) ? $bakUrl : '');
	}
	if (is_string($bakSchedUrl) && method_exists($ghtml, 'compare_urls')) {
		$ghtml->__http_vars['frm_action'] = 'updateform';
		$ghtml->__http_vars['frm_subaction'] = 'schedule_conf';
		$cur = 'display.php?' . $ghtml->get_get_from_current_post(null);
		$bakSchedSelect = $ghtml->compare_urls($cur, $bakSchedUrl)
			&& !$ghtml->compare_urls($cur, is_string($bakFtpUrl) ? $bakFtpUrl : '')
			&& !$ghtml->compare_urls($cur, is_string($bakUrl) ? $bakUrl : '');
	}
	if (is_string($bakFmUrl) && method_exists($ghtml, 'compare_urls')) {
		// On File Manager, tabs use goback=1 so Backup / Restore does not also match.
		$ghtml->__http_vars['frm_action'] = 'show';
		unset($ghtml->__http_vars['frm_subaction']);
		$ghtml->__http_vars['frm_o_o'] = array(
			array('class' => 'client', 'nname' => 'admin'),
			array('class' => 'lxbackup'),
			array('class' => 'ffile', 'nname' => '/'),
		);
		$ghtml->frm_o_o = null;
		$fmTabs = array();
		foreach (lxbackup::backupPropertyTabs() as $tabUrl) {
			$fmTabs[] = $ghtml->getFullUrl(
				(is_string($tabUrl) && !str_starts_with($tabUrl, 'goback='))
					? ('goback=1&' . $tabUrl) : $tabUrl);
		}
		$cur = 'display.php?' . $ghtml->get_get_from_current_post(null);
		$fmHits = 0;
		$fmHitLabel = '';
		foreach ($fmTabs as $tu) {
			if ($ghtml->compare_urls($cur, $tu)) {
				$fmHits++;
				$fmHitLabel = kloxo_content_tab_label('', $tu);
			}
		}
		$bakFmSelect = $fmHits === 1 && $fmHitLabel === 'File Manager'
			&& is_string($bakFmUrl) && str_contains($bakFmUrl, 'ffile')
			&& str_contains($bakFmUrl, 'lxbackup');
	}
	if (is_string($bakUpUrl) && method_exists($ghtml, 'compare_urls')) {
		$ghtml->__http_vars['frm_action'] = 'updateform';
		$ghtml->__http_vars['frm_subaction'] = 'upload';
		$ghtml->__http_vars['frm_o_o'] = array(
			array('class' => 'client', 'nname' => 'admin'),
			array('class' => 'lxbackup'),
			array('class' => 'ffile', 'nname' => '/'),
		);
		$ghtml->frm_o_o = null;
		$upTabs = array();
		foreach (lxbackup::backupPropertyTabs() as $tabUrl) {
			$upTabs[] = $ghtml->getFullUrl(
				(is_string($tabUrl) && !str_starts_with($tabUrl, 'goback='))
					? ('goback=1&' . $tabUrl) : $tabUrl);
		}
		$cur = 'display.php?' . $ghtml->get_get_from_current_post(null);
		$upHits = 0;
		$upHitLabel = '';
		foreach ($upTabs as $tu) {
			if ($ghtml->compare_urls($cur, $tu)) {
				$upHits++;
				$upHitLabel = kloxo_content_tab_label('', $tu);
			}
		}
		$bakUpSelect = $upHits === 1 && $upHitLabel === 'Upload'
			&& is_string($bakUpUrl) && str_contains($bakUpUrl, 'ffile')
			&& str_contains($bakUpUrl, 'lxbackup')
			&& str_contains($bakUpUrl, 'upload');
	}
	$gbl = $gblPrev;
	$ghtml = $prevHtml;
	$pathOk = is_array($pathBefore) && is_array($pathAfter)
		&& isset($pathAfter[1]['class']) && $pathAfter[1]['class'] === 'phpini'
		&& !$realProp;
	$urlOk = is_string($url) && str_contains($url, 'frm_o_o') && str_contains($url, 'phpini')
		&& is_string($advUrl) && str_contains($advUrl, 'extraedit') && str_contains($advUrl, 'phpini')
		&& is_string($shadowUrl) && str_contains($shadowUrl, 'phpini') && str_contains($shadowUrl, 'extraedit')
		&& is_string($bakUrl) && str_contains($bakUrl, 'lxbackup')
		&& is_string($bakFtpUrl) && str_contains($bakFtpUrl, 'lxbackup')
		&& str_contains($bakFtpUrl, 'ftp_conf')
		&& $bakFtpSelect
		&& is_string($bakSchedUrl) && str_contains($bakSchedUrl, 'lxbackup')
		&& str_contains($bakSchedUrl, 'schedule_conf')
		&& $bakSchedSelect
		&& $bakFmSelect
		&& $bakUpSelect;
	$labelOk = $homeLabel === 'PHP Configure'
		&& $showLabel === 'PHP Configure'
		&& $advLabel === 'Advanced PHP Configure'
		&& $bakHomeLabel === 'Backup / Restore'
		&& $bakTabLabel === 'Backup / Restore'
		&& $bakFtpLabel === 'FTP Configuration'
		&& $bakSchedLabel === 'Schedule Configuration'
		&& $bakFmLabel === 'File Manager'
		&& $bakUpLabel === 'Upload';
	$src = (string) file_get_contents($doc . '/lib/php/lxlib.php');
	$htmlSrc = (string) file_get_contents($doc . '/theme/htmllib.php');
	$srcOk = str_contains($src, 'Do not assign $ghtml->frm_o_o')
		&& str_contains($src, 'unset($ghtml->frm_o_o)')
		&& str_contains($src, "\$cur === 'lxbackup'")
		&& str_contains($htmlSrc, "__http_vars['frm_o_o']");
	if ($pathOk && $urlOk && $labelOk && $srcOk) {
		ok('kloxo_menu_title does not shadow frm_o_o (PHP Configure tabs keep path)');
	} else {
		bad('kloxo_menu_title does not shadow frm_o_o (PHP Configure tabs keep path)',
			'path=' . ($pathOk ? 'ok' : 'no')
				. ' url=' . (is_string($url) ? $url : 'none')
				. ' adv=' . (is_string($advUrl) ? $advUrl : 'none')
				. ' bak=' . (is_string($bakUrl) ? $bakUrl : 'none')
				. ' bakFtp=' . (is_string($bakFtpUrl) ? $bakFtpUrl : 'none')
				. ' bakFtpSelect=' . ($bakFtpSelect ? 'ok' : 'no')
				. ' bakSched=' . (is_string($bakSchedUrl) ? $bakSchedUrl : 'none')
				. ' bakSchedSelect=' . ($bakSchedSelect ? 'ok' : 'no')
				. ' bakFm=' . (is_string($bakFmUrl) ? $bakFmUrl : 'none')
				. ' bakFmSelect=' . ($bakFmSelect ? 'ok' : 'no')
				. ' bakUp=' . (is_string($bakUpUrl) ? $bakUpUrl : 'none')
				. ' bakUpSelect=' . ($bakUpSelect ? 'ok' : 'no')
				. ' bakLabel=' . (string) $bakHomeLabel . '/' . (string) $bakTabLabel
				. '/' . (string) $bakFtpLabel . '/' . (string) $bakSchedLabel
				. '/' . (string) $bakFmLabel . '/' . (string) $bakUpLabel
				. ' labels=' . $showLabel . '/' . $advLabel
				. ' prop=' . ($realProp ? 'yes' : 'no'));
	}
} catch (Throwable $e) {
	bad('kloxo_menu_title does not shadow frm_o_o (PHP Configure tabs keep path)',
		$e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

try {
	$ddDesc = get_classvar_description('domaindefault');
	$ddKey = is_array($ddDesc) && isset($ddDesc[2]) ? (string) $ddDesc[2] : '';
	$ddUrl = 'display.php?frm_action=updateform&frm_subaction=update'
		. '&frm_o_o[0][class]=domaindefault&frm_o_o[0][nname]=admin';
	$ddTab = kloxo_content_tab_label('domain_defaults', $ddUrl);
	$ddHome = kloxo_content_tab_label('Home', $ddUrl);
	$ddOld = kloxo_content_tab_label('Client Processed Logs', $ddUrl);
	$descSrc = (string) file_get_contents($doc . '/lang/en-us/desclib.php');
	$ddOk = $ddKey === 'domain_defaults'
		&& $ddTab === 'Client Processing Logs'
		&& $ddHome === 'Client Processing Logs'
		&& $ddOld === 'Client Processing Logs'
		&& str_contains($descSrc, '"Client Processing Logs"')
		&& !str_contains($descSrc, '"Client Processed Logs"');
	if ($ddOk) {
		ok('Client Processing Logs tab label');
	} else {
		bad('Client Processing Logs tab label',
			'key=' . $ddKey . ' tab=' . $ddTab . ' home=' . $ddHome . ' old=' . $ddOld);
	}
} catch (Throwable $e) {
	bad('Client Processing Logs tab label',
		$e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
