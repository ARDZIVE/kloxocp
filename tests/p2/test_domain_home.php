<?php
/**
 * Domain Home: tiles must render when parent priv / web fields are incomplete
 * after a fast Add Domain (php-cli; no live panel).
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

$root = dirname(__DIR__, 2);
$doc = $root . '/kloxo/httpdocs';
$_SERVER['PHP_SELF'] = '/display.php';
chdir($doc);
include_once $doc . '/lib/html/include.php';

global $gbl, $sgbl, $login, $ghtml;
if (!is_object($ghtml)) {
	$ghtml = new HtmlLib();
	$GLOBALS['ghtml'] = $ghtml;
}

$login = new Client(null, 'localhost', 'admin');
$login->nname = 'admin';
$login->cttype = 'admin';
$login->syncserver = 'localhost';
$login->priv = new priv(null, null, 'admin');
$login->priv->dns_manage_flag = 'on';
$login->priv->runstats_flag = 'on';
$GLOBALS['login'] = $login;

$gen = new general(null, 'localhost', 'admin');
$gen->nname = 'admin';
$gen->generalmisc_b = new Generalmisc_b(null, null, 'admin');
$gen->generalmisc_b->webstatisticsprogram = 'awstats';
$login->general_o = $gen;

$parent = new Client(null, 'localhost', 'mesrob');
$parent->nname = 'mesrob';
$parent->cttype = 'customer';
$parent->syncserver = 'localhost';
$parent->priv = null; // fast-add / incomplete client row

$dom = new domain(null, 'localhost', 'mesrob.com');
$dom->nname = 'mesrob.com';
$dom->syncserver = 'localhost';
$dom->ttype = 'virtual';
$dom->dtype = 'domain';
$dom->docroot = 'mesrob.com';
$dom->status = 'on';
$dom->priv = new priv(null, null, 'mesrob.com');
$dom->priv->awstats_flag = 'on';
$dom->used = new Used(null, null, 'mesrob.com');
$dom->__parent_o = $parent;

$web = new web(null, 'localhost', 'mesrob.com');
$web->nname = 'mesrob.com';
$web->syncserver = 'localhost';
$web->dbaction = 'clean';
// Empty ftp/docroot like a half-written web row
$dom->web_o = $web;

$mmail = new mmail(null, 'localhost', 'mesrob.com');
$mmail->nname = 'mesrob.com';
$mmail->syncserver = 'localhost';
$mmail->ttype = 'virtual';
$mmail->dbaction = 'clean';
$dom->mmail_o = $mmail;

$dns = new dns(null, 'localhost', 'mesrob.com');
$dns->nname = 'mesrob.com';
$dns->syncserver = 'localhost';
$dns->dbaction = 'clean';
$dom->dns_o = $dns;

$alist = array();
$alistErr = '';
try {
	$dom->createShowAlist($alist);
} catch (Throwable $e) {
	$alistErr = $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
}
$titles = 0;
foreach ($alist as $k => $v) {
	if (is_string($k) && str_starts_with($k, '__title')) {
		$titles++;
	}
}
if ($alistErr === '' && count($alist) >= 10 && $titles >= 2) {
	ok('createShowAlist fills Domain Home tiles when parent priv is null');
} else {
	bad('createShowAlist fills Domain Home tiles when parent priv is null',
		$alistErr !== '' ? $alistErr : ('count=' . count($alist) . ' titles=' . $titles));
}

// Live bug: parent has a priv object but webhosting_flag was never set —
// checkButton/isOn treats that as false and used to hide every web tile.
$parent2 = new Client(null, 'localhost', 'mesrob');
$parent2->nname = 'mesrob';
$parent2->cttype = 'customer';
$parent2->syncserver = 'localhost';
$parent2->priv = new priv(null, null, 'mesrob');
// deliberately no webhosting_flag
$dom2 = new domain(null, 'localhost', 'mesrob.com');
$dom2->nname = 'mesrob.com';
$dom2->syncserver = 'localhost';
$dom2->ttype = 'virtual';
$dom2->dtype = 'domain';
$dom2->docroot = 'mesrob.com';
$dom2->status = 'on';
$dom2->priv = new priv(null, null, 'mesrob.com');
$dom2->priv->awstats_flag = 'on';
$dom2->used = new Used(null, null, 'mesrob.com');
$dom2->__parent_o = $parent2;
$dom2->web_o = $web;
$dom2->mmail_o = $mmail;
$dom2->dns_o = $dns;
$alist2 = array();
$alist2Err = '';
try {
	$dom2->createShowAlist($alist2);
} catch (Throwable $e) {
	$alist2Err = $e->getMessage();
}
$titles2 = 0;
$hasWebTitle = false;
foreach ($alist2 as $k => $v) {
	if (is_string($k) && str_starts_with($k, '__title')) {
		$titles2++;
		if (str_contains($k, 'classweb') || str_contains($k, 'domain_log')) {
			$hasWebTitle = true;
		}
	}
}
if ($alist2Err === '' && count($alist2) >= 10 && $titles2 >= 2 && $hasWebTitle) {
	ok('createShowAlist keeps web tiles when parent priv has unset webhosting_flag');
} else {
	bad('createShowAlist keeps web tiles when parent priv has unset webhosting_flag',
		$alist2Err !== '' ? $alist2Err : ('count=' . count($alist2) . ' titles=' . $titles2));
}

// Virtual domain with webhosting_flag=off must STILL get web tiles (live
// mesrob.com had Info/FTP but a stale off flag → mmail-only → blank right).
$parentOff = new Client(null, 'localhost', 'mesrob');
$parentOff->nname = 'mesrob';
$parentOff->priv = new priv(null, null, 'mesrob');
$parentOff->priv->webhosting_flag = 'off';
$domOff = new domain(null, 'localhost', 'mesrob.com');
$domOff->nname = 'mesrob.com';
$domOff->syncserver = 'localhost';
$domOff->ttype = 'virtual';
$domOff->dtype = 'domain';
$domOff->docroot = 'mesrob.com';
$domOff->status = 'on';
$domOff->priv = new priv(null, null, 'mesrob.com');
$domOff->__parent_o = $parentOff;
$domOff->web_o = $web;
$domOff->mmail_o = $mmail;
$alistOff = array();
try {
	$domOff->createShowAlist($alistOff);
} catch (Throwable $e) {
	$alistOff = array('__err' => $e->getMessage());
}
$hasWebOff = false;
foreach ($alistOff as $k => $v) {
	if (is_string($k) && (str_contains($k, 'classweb') || str_contains($k, 'domain_log'))) {
		$hasWebOff = true;
	}
}
if ($hasWebOff && count($alistOff) >= 10) {
	ok('virtual domain keeps web tiles even when webhosting_flag is off');
} else {
	bad('virtual domain keeps web tiles even when webhosting_flag is off',
		'count=' . count($alistOff));
}

// Non-virtual + explicit off → mmail-only (or minimal fallback, never empty).
$domPark = new domain(null, 'localhost', 'parked.example');
$domPark->nname = 'parked.example';
$domPark->ttype = 'parked';
$domPark->dtype = 'domain';
$domPark->__parent_o = $parentOff;
$domPark->mmail_o = $mmail;
$alistPark = array();
$domPark->createShowAlist($alistPark);
$hasWebPark = false;
foreach ($alistPark as $k => $v) {
	if (is_string($k) && str_contains($k, 'classweb')) {
		$hasWebPark = true;
	}
}
if (!$hasWebPark && count($alistPark) >= 1) {
	ok('non-virtual with webhosting off stays mmail/fallback and never empty');
} else {
	bad('non-virtual with webhosting off stays mmail/fallback and never empty',
		'count=' . count($alistPark) . ' hasWeb=' . ($hasWebPark ? 'Y' : 'N'));
}

$ilist = null;
$ilistErr = '';
try {
	$ilist = $dom->createShowInfoList(null);
} catch (Throwable $e) {
	$ilistErr = $e->getMessage();
}
$ftp = is_array($ilist) ? (string) ($ilist['FTP User'] ?? '') : '';
$docroot = is_array($ilist) ? (string) ($ilist['DocRoot'] ?? '') : '';
$home = is_array($ilist) ? (string) ($ilist['Home'] ?? '') : '';
if ($ilistErr === '' && $ftp === 'mesrob.com' && str_contains($docroot, 'mesrob.com')
	&& str_contains($home, '/home/mesrob/')) {
	ok('createShowInfoList falls back to domain nname/docroot and parent home');
} else {
	bad('createShowInfoList falls back to domain nname/docroot and parent home',
		$ilistErr !== '' ? $ilistErr : ("ftp={$ftp} doc={$docroot} home={$home}"));
}

$domSrc = (string) file_get_contents($doc . '/lib/domain/domainlib.php');
$lxclass = (string) file_get_contents($doc . '/lib/php/lxclass.php');
$lxlib = (string) file_get_contents($doc . '/lib/php/lxlib.php');
$showFn = '';
if (preg_match('/function createShowAlist\(&\$alist.*?^[\t ]*static function get_full_alist/sm', $domSrc, $m) === 1) {
	$showFn = $m[0];
}
$infoFn = '';
if (preg_match('/function createShowInfoList\(\$subaction\).*?^[\t ]*function createShowIlist/sm', $domSrc, $m) === 1) {
	$infoFn = $m[0];
}
$gsa = '';
if (preg_match('/function getShowActions\(&\$alist.*?^[\t ]*static function get_child_full_alist/sm', $lxclass, $m) === 1) {
	$gsa = $m[0];
}
$fin = '';
if (preg_match('/function kloxo_finish_domain_add\(.*?^function /sm', $lxlib, $m) === 1) {
	$fin = $m[0];
}
if ($showFn !== ''
	&& str_contains($showFn, 'webhosting_flag')
	&& str_contains($showFn, 'isDomainVirtual')
	&& str_contains($domSrc, 'createShowAlistWebBody')
	&& !str_contains($showFn, 'getParentO()->priv->isOn')
	&& !str_contains($showFn, "checkButton('webhosting_flag')")
	&& $infoFn !== ''
	&& str_contains($infoFn, 'substr($this->nname, 0, 31)')
	&& $gsa !== ''
	&& str_contains($gsa, '$onl = array()')
	&& str_contains($gsa, 'is_object($ghtml)')
	&& $fin !== ''
	&& str_contains($fin, 'ftpusername')
	&& str_contains($fin, 'docroot')) {
	ok('source: virtual overrides webhosting gate, web-body fallback, info/finish guards');
} else {
	bad('source: virtual overrides webhosting gate, web-body fallback, info/finish guards');
}
$coreShow = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
if (str_contains($coreShow, 'kloxo-tile-fallback')
	&& str_contains($coreShow, 'show tiles empty')
	&& str_contains($coreShow, 'head + $acalist + $rest')
	&& str_contains($coreShow, 'Stream the left column before tiles')
	&& !str_contains($coreShow, 'array_splice($aalist')) {
	ok('source: show path seeds fallback tiles, flushes left column, avoids array_splice');
} else {
	bad('source: show path seeds fallback tiles, flushes left column, avoids array_splice');
}

$htmlLib = (string) file_get_contents($doc . '/theme/htmllib.php');
$expandFn = '';
if (preg_match('/function get_expand_url\(\)\s*\{.*?\n\t\}/s', $htmlLib, $m) === 1) {
	$expandFn = $m[0];
}
$printDivFn = '';
if (preg_match('/function print_div_button\(.*?^[\t ]*function print_action_block/sm', $htmlLib, $m) === 1) {
	$printDivFn = $m[0];
}
$divButtonFn = '';
if (preg_match('/function print_div_for_divbutton\(.*?^[\t ]*static function mailTileFaName/sm', $htmlLib, $m) === 1) {
	$divButtonFn = $m[0];
}
$printActionFn = '';
if (preg_match('/function print_action_block\(.*?^[\t ]*function cginum/sm', $htmlLib, $m) === 1) {
	$printActionFn = $m[0];
}
if ($expandFn !== ''
	&& str_contains($expandFn, 'catch (Throwable')
	&& str_contains($expandFn, "getSpecialObject('sp_specialplay')")
	&& str_contains($htmlLib, 'function kloxo_button_type')
	&& str_contains($htmlLib, 'function kloxo_ajax_enabled')
	&& $printDivFn !== ''
	&& str_contains($printDivFn, 'kloxo_button_type()')
	&& !str_contains($printDivFn, "getSpecialObject('sp_specialplay')->button_type")
	&& $divButtonFn !== ''
	&& str_contains($divButtonFn, 'kloxo_button_type()')
	&& str_contains($divButtonFn, 'kloxo_ajax_enabled()')
	&& !str_contains($divButtonFn, "getSpecialObject('sp_specialplay')->button_type")
	&& $printActionFn !== ''
	&& str_contains($printActionFn, '__kloxo_ajax_after_tiles')
	&& str_contains($printActionFn, 'print_dialog($talist, $obj)')
	&& strpos($printActionFn, 'div class="section"') < strpos($printActionFn, 'print_dialog($talist, $obj)')) {
	ok('source: get_expand_url/tile buttons safe; ajax dialog after visible tiles');
} else {
	bad('source: get_expand_url/tile buttons safe; ajax dialog after visible tiles');
}

// Behaviour: specialplay boom must not blank Domain Home sections (security-wave regression).
class KloxoDomainHomeBoomClient extends Client
{
	function getSpecialObject($class)
	{
		throw new Exception('specialplay boom');
	}

	function getSkinDir()
	{
		return '/theme/skin/simplicity/default/';
	}

	function getSkinColor()
	{
		return 'EFE8E0';
	}
}
$boomLogin = new KloxoDomainHomeBoomClient(null, 'localhost', 'admin');
$boomLogin->nname = 'admin';
$boomLogin->cttype = 'admin';
$boomLogin->syncserver = 'localhost';
$boomLogin->priv = new priv(null, null, 'admin');
$GLOBALS['login'] = $boomLogin;
$gbl->__c_object = $dom;
$nalistBoom = array(
	'__title_domain_log' => 'Traffic',
	0 => $ghtml->getFullUrl('a=list&c=domaintraffichistory'),
	'__title_domain_classweb' => 'web',
	1 => $ghtml->getFullUrl('n=web&a=show&l[class]=ffile&l[nname]=/'),
);
$boomErr = '';
ob_start();
try {
	$ghtml->print_object_action_block($dom, $nalistBoom, 6);
	$boomOut = ob_get_clean();
} catch (Throwable $e) {
	ob_end_clean();
	$boomOut = '';
	$boomErr = $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine();
}
$boomSections = preg_match_all('/class="section"/', $boomOut);
$boomButtons = preg_match_all('/class="div_container"/', $boomOut);
if ($boomErr === '' && $boomSections >= 2 && $boomButtons >= 2) {
	ok('print_object_action_block still paints tiles when sp_specialplay throws');
} else {
	bad('print_object_action_block still paints tiles when sp_specialplay throws',
		$boomErr !== '' ? $boomErr : ("sections={$boomSections} buttons={$boomButtons}"));
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
