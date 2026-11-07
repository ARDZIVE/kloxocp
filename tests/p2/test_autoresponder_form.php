<?php
/**
 * Mail Account > Set Auto Responder with no autoresponders threw an lxException
 * that do_updateform's generic catch hid as "The requested form could not be loaded."
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

global $login;
$login = new Client(null, 'localhost', 'admin');
$login->nname = 'admin';
$m = new Mailaccount(null, 'localhost', 'info@mesrob.com');
$m->nname = 'info@mesrob.com';
$m->__listtype_autoresponder = 'fullist';
$m->autoresponder_l = array();
try {
	$m->updateform('autores', array());
	bad('autores form with no autoresponders throws a readable lxException');
} catch (lxInfoException $e) {
	ok('autores form with no autoresponders throws a notice (lxInfoException), not an error');
} catch (lxException $e) {
	bad('autores form with no autoresponders throws a notice (lxInfoException), not an error');
} catch (Throwable $e) {
	bad('autores form with no autoresponders throws a readable lxException', get_class($e));
}

$a = new Autoresponder(null, 'localhost', 'x___info@mesrob.com');
$a->autores_name = 'x';
$m->autoresponder_l = array('x___info@mesrob.com' => $a);
try {
	$f = $m->updateform('autores', array());
	if (isset($f['autores_name'][1]['x___info@mesrob.com'])) {
		ok('autores form lists the existing autoresponder');
	} else {
		bad('autores form lists the existing autoresponder');
	}
} catch (Throwable $e) {
	bad('autores form lists the existing autoresponder', $e->getMessage());
}

// Set / Disable Auto Responder keep all five Auto Responder tabs.
global $ghtml;
$ghtml = new HtmlLib();
$expect = array('a=show', 'a=list&c=autoresponder', 'a=addform&c=autoresponder', 'a=updateform&sa=autores');
foreach (array('autores', 'enable_autorespond', 'disable_autorespond') as $sa) {
	$ghtml->__http_vars['frm_subaction'] = $sa;
	$pl = array('property' => array());
	$m->createShowPropertyList($pl);
	$tabs = $pl['property'];
	if (count($tabs) === 5 && array_slice($tabs, 0, 4) === $expect && str_contains((string)$tabs[4], 'autorespond')) {
		ok("sa=$sa shows Mail Account, Auto Responders, Add, Set and Enable/Disable tabs");
	} else {
		bad("sa=$sa shows all five Auto Responder tabs", json_encode($tabs));
	}
}
$ghtml->__http_vars['frm_subaction'] = 'password';
$pl = array('property' => array());
$m->createShowPropertyList($pl);
if ($pl['property'] === array('a=updateform&sa=password')) {
	ok('other Mail Account pages keep their single tab');
} else {
	bad('other Mail Account pages keep their single tab', json_encode($pl['property']));
}
unset($ghtml->__http_vars['frm_subaction']);

// The info alert the notice is shown in: green info box, plain text, escaped.
$h = new HtmlLib();
ob_start();
$h->print_inline_alert(htmlspecialchars('First add <b>some</b>', ENT_QUOTES), 'info');
$box = (string)ob_get_clean();
if (str_contains($box, 'kloxo-inline-info') && str_contains($box, 'First add &lt;b&gt;some')) {
	ok('notice renders in the info box with escaped text');
} else {
	bad('notice renders in the info box with escaped text', $box);
}

$src = (string)file_get_contents($doc . '/theme/coredisplaylib.php');
if (preg_match('/catch \(lxInfoException \$e\) \{[^}]*print_inline_alert\([^;]*\'info\'\)/s', $src)
	&& preg_match('/catch \(lxException \$e\) \{[^}]*do_updateform[^}]*\$e->getMessage\(\)/s', $src)) {
	ok('show page prints notices as info messages and other lxException text as plain text');
} else {
	bad('show page prints notices as info messages and other lxException text as plain text');
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
