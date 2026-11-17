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
	&& str_contains($showFn, 'checkButton(\'webhosting_flag\')')
	&& !str_contains($showFn, 'getParentO()->priv->isOn')
	&& $infoFn !== ''
	&& str_contains($infoFn, 'substr($this->nname, 0, 31)')
	&& $gsa !== ''
	&& str_contains($gsa, '$onl = array()')
	&& str_contains($gsa, 'is_object($ghtml)')
	&& $fin !== ''
	&& str_contains($fin, 'ftpusername')
	&& str_contains($fin, 'docroot')) {
	ok('source: checkButton gate, info fallbacks, getShowActions/$ghtml, finish backfills web');
} else {
	bad('source: checkButton gate, info fallbacks, getShowActions/$ghtml, finish backfills web');
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
