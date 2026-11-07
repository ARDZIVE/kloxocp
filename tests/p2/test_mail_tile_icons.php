<?php
/**
 * Mail Account dashboard tiles use local Font Awesome Free SVGs
 * (theme/fontawesome/svgs/solid), not the WebHostingHub envelope glyph.
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

$want = array(
	array(array('mailaccount', 'updateform', 'password'), 'key'),
	array(array('mailaccount', 'updateform', 'limit'), 'sliders'),
	array(array('mailaccount', 'updateform', 'filter'), 'filter'),
	array(array('mailaccount', 'updateform', 'configuration'), 'gear'),
	array(array('mailaccount', 'updateform', 'webmail'), 'envelope-open-text'),
	array(array('forward', 'a', 'list'), 'share'),
	array(array('autoresponder', 'list', 'x'), 'reply'),
	array(array('mailcontent', 'list', 'x'), 'shield-halved'),
	array(array('client', 'updateform', 'password'), ''),
	array(array('mailaccount', 'list', 'x'), ''),
);
$allOk = true;
$names = array();
foreach ($want as [$parts, $icon]) {
	$got = HtmlLib::mailTileFaName($parts);
	if ($got !== $icon) {
		$allOk = false;
		bad('mailTileFaName(' . implode('_', $parts) . ')', "got '$got', want '$icon'");
	}
	if ($icon !== '') {
		$names[$icon] = true;
	}
}
if ($allOk) {
	ok('mail tile -> icon mapping (Password, Limit, Forwards, Filter, Configure, Spam, Auto Responders, Webmail)');
}

$svgOk = true;
foreach (array_keys($names) as $n) {
	$svg = HtmlLib::faTileIcon($n, '#e74c3c', 'x');
	if (!str_starts_with($svg, '<svg') || !str_contains($svg, 'fill:#e74c3c') || !str_contains($svg, '<path d="')
		|| !str_contains($svg, 'height="32"')) {
		$svgOk = false;
		bad("faTileIcon($n) renders an inline SVG");
	}
	$file = $doc . '/theme/fontawesome/svgs/solid/' . $n . '.svg';
	if (!is_file($file) || !str_contains((string) file_get_contents($file), 'Font Awesome Free')) {
		$svgOk = false;
		bad("bundled Font Awesome Free file for $n");
	}
}
if ($svgOk) {
	ok('every mapped icon is a bundled Font Awesome Free SVG that renders inline');
}
if (HtmlLib::faTileIcon('../../etc/passwd') === '' && HtmlLib::faTileIcon('nope-missing') === '') {
	ok('faTileIcon ignores path tricks and missing icons');
} else {
	bad('faTileIcon ignores path tricks and missing icons');
}
if (HtmlLib::faTileIcon('key', 'red;x') !== '' && !str_contains(HtmlLib::faTileIcon('key', 'red;x'), 'red;x')) {
	ok('faTileIcon rejects an unsafe colour value');
} else {
	bad('faTileIcon rejects an unsafe colour value');
}

// Real tile renderer with a stub login.
global $login;
$sp = new class { public $button_type = 'font'; public $icon_name = ''; function isOn($x) { return false; } };
$login = new class($sp) {
	private $sp;
	function __construct($sp) { $this->sp = $sp; }
	function getSpecialObject($n) { return $this->sp; }
	function getSkinColor() { return '#fff'; }
	function getSkinDir() { return '/x'; }
	function getKeywordUc($k) { return $k; }
};
$h = new HtmlLib();
$renderOk = true;
foreach (array('mailaccount_updateform_password_ab12', 'forward_a_list_ab12', 'autoresponder_list_ab12',
	'mailcontent_list_ab12', 'mailaccount_updateform_webmail_ab12') as $form) {
	ob_start();
	$h->print_div_for_divbutton('k', true, true, $form, 'n', '/i.gif', array(2 => 'Label', 'desc' => 'Label', 'help' => 'h'));
	$out = (string) ob_get_clean();
	if (!str_contains($out, '<svg') || preg_match("/class='if32'[^>]*>&#x/", $out) === 1) {
		$renderOk = false;
		bad("tile $form renders a Font Awesome SVG");
	}
}
ob_start();
$h->print_div_for_divbutton('k', true, true, 'client_updateform_password_ab12', 'n', '/i.gif', array(2 => 'Label', 'desc' => 'Label', 'help' => 'h'));
$other = (string) ob_get_clean();
if (str_contains($other, '<svg')) {
	$renderOk = false;
	bad('other tiles keep their glyph');
}
if ($renderOk) {
	ok('Mail Account tiles render SVGs through print_div_for_divbutton; other tiles keep their glyph');
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
