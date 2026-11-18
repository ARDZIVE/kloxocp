<?php
/**
 * Servers list: second toolbar action is Hardrefresh; T (ostype) uses fedora hat.
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

$refresh = kloxo_content_tab_label('Refresh', '/display.php?frm_action=refresh&frm_o_cname=ffile');
$hard = kloxo_content_tab_label('Hardrefresh', '/display.php?frm_action=hardrefresh&frm_o_cname=ffile');
if ($refresh === 'Refresh' && $hard === 'Hardrefresh') {
	ok('toolbar labels: refresh=Refresh, hardrefresh=Hardrefresh');
} else {
	bad('toolbar labels: refresh=Refresh, hardrefresh=Hardrefresh', "got refresh='$refresh' hard='$hard'");
}

$psSrc = (string) file_get_contents($doc . '/driver/pserver/pservercorelib.php');
if (str_contains($psSrc, 'static function isHardRefresh()')
	&& preg_match('/static function isHardRefresh\(\)\s*\{\s*return true;/s', $psSrc)) {
	ok('pserver isHardRefresh() is true (second toolbar button shown)');
} else {
	bad('pserver isHardRefresh() is true (second toolbar button shown)');
}

$htmlSrc = (string) file_get_contents($doc . '/theme/htmllib.php');
$hasHatMap = str_contains($htmlSrc, "'ostype' => '&#xf3f1;'")
	&& str_contains($htmlSrc, "'debian' => '&#xf3f1;'")
	&& str_contains($htmlSrc, "'fedora' => '&#xf3f1;'")
	&& str_contains($htmlSrc, "'rhel' => '&#xf3f1;'");
$hasHardIcon = str_contains($htmlSrc, "\$var === 'hardrefresh'")
	&& str_contains($htmlSrc, '&#xf66c;');
$hasRefreshIcon = str_contains($htmlSrc, "\$var === 'refresh'")
	&& str_contains($htmlSrc, '&#xf078;');

if ($hasHatMap) {
	ok('T column ostype/debian/fedora/rhel map to fedora hat glyph f3f1');
} else {
	bad('T column ostype/debian/fedora/rhel map to fedora hat glyph f3f1');
}
if ($hasRefreshIcon && $hasHardIcon) {
	ok('toolbar uses distinct refresh (f078) and hardrefresh (f66c) glyphs');
} else {
	bad('toolbar uses distinct refresh (f078) and hardrefresh (f66c) glyphs');
}

$lxSrc = (string) file_get_contents($doc . '/lib/php/lxlib.php');
$collapse = "if (\$act === 'refresh' || \$act === 'hardrefresh')";
if (str_contains($lxSrc, "\$act === 'hardrefresh'")
	&& str_contains($lxSrc, "return 'Hardrefresh';")
	&& str_contains($lxSrc, "return 'Refresh';")
	&& !str_contains($lxSrc, $collapse)) {
	ok('kloxo_content_tab_label does not collapse hardrefresh into Refresh');
} else {
	bad('kloxo_content_tab_label does not collapse hardrefresh into Refresh');
}

printf("\n%d ok, %d fail\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
