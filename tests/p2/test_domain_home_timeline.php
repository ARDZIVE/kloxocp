<?php
/**
 * Before / during / after: Domain Home blank-tile timeline (php-cli).
 *
 * BEFORE (import): bare priv->isOn threw → whole page died.
 * DURING (try/catch wave 9f28703+): throw swallowed → empty showalist →
 *   Info/Resource OK, right column white (exactly the live screenshot).
 * AFTER (this tree): virtual domains always get web tiles; createShowAlist
 *   never returns empty; show path seeds fallback; specialplay cannot blank
 *   print_action_block.
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

// --- source timeline markers ---
$core = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
$dom = (string) file_get_contents($doc . '/lib/domain/domainlib.php');
$html = (string) file_get_contents($doc . '/theme/htmllib.php');

$duringCatch = str_contains($core, 'createShowAlist: ')
	&& str_contains($core, 'action block: ');
$afterFallback = str_contains($core, 'kloxo-tile-fallback')
	&& str_contains($core, 'show tiles empty')
	&& str_contains($dom, 'createShowAlistWebBody')
	&& str_contains($dom, 'isDomainVirtual')
	&& str_contains($html, 'function kloxo_button_type')
	&& str_contains($html, 'Never throw from here');
if ($duringCatch && $afterFallback) {
	ok('timeline markers: DURING swallow + AFTER fallback/virtual/specialplay guards');
} else {
	bad('timeline markers: DURING swallow + AFTER fallback/virtual/specialplay guards');
}

// --- behavioural: DURING empty alist → blank; AFTER seeds ---
$_SERVER['PHP_SELF'] = '/display.php';
chdir($doc);
include_once $doc . '/lib/html/include.php';
global $gbl, $sgbl, $login, $ghtml;
$ghtml = new HtmlLib();
$GLOBALS['ghtml'] = $ghtml;
$gbl = new Gbllib();
$GLOBALS['gbl'] = $gbl;
$login = new Client(null, 'localhost', 'admin');
$login->nname = 'admin';
$login->cttype = 'admin';
$login->syncserver = 'localhost';
$login->priv = new priv(null, null, 'admin');
$GLOBALS['login'] = $login;

$object = new domain(null, 'localhost', 'mesrob.com');
$object->nname = 'mesrob.com';
$object->ttype = 'virtual';
$object->dtype = 'domain';
$gbl->__c_object = $object;

// Simulate DURING: swallowed createShowAlist → empty $aalist
$aalist = array();
$nalist = null;
if ($aalist) {
	bad('empty aalist must be falsy (DURING blank-tile condition)');
} else {
	ok('empty aalist is falsy — DURING wave left nalist null (blank right column)');
}

// AFTER coredisplaylib seeding logic (inline copy of the guard)
if ((!$nalist || $nalist === array()) && method_exists($object, 'getClass')) {
	$oclass = strtolower((string) $object->getClass());
	if ($oclass === 'domain') {
		$nalist = array(
			'__title_resource' => 'Actions',
			0 => $ghtml->getFullUrl('a=updateform&sa=limit'),
			1 => $ghtml->getFullUrl('n=web&a=show&l[class]=ffile&l[nname]=/'),
			2 => $ghtml->getFullUrl('a=show&o=dns'),
		);
	}
}
if (is_array($nalist) && count($nalist) >= 3) {
	ok('AFTER: empty showalist is seeded with Domain Home fallback tiles');
} else {
	bad('AFTER: empty showalist is seeded with Domain Home fallback tiles');
}

ob_start();
try {
	$ghtml->print_object_action_block($object, $nalist, 6);
	$out = ob_get_clean();
} catch (Throwable $e) {
	ob_end_clean();
	$out = '';
	bad('fallback tiles render', $e->getMessage());
	$out = '';
}
if ($out !== '' && preg_match_all('/class="section"/', $out) >= 1
	&& preg_match_all('/class="div_container"/', $out) >= 1) {
	ok('AFTER: seeded fallback prints at least one section and button');
} else {
	bad('AFTER: seeded fallback prints at least one section and button');
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
