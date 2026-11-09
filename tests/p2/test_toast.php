<?php
/**
 * Panel toasts: top-centre white cards with a typed icon.
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
putenv('KLOXO_ROOT=' . $root . '/kloxo');
$_SERVER['DOCUMENT_ROOT'] = $doc;
chdir($doc);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

include_once $doc . '/lib/html/include.php';

if (function_exists('kloxo_toast_kind')
	&& kloxo_toast_kind('you_have_unread_message', 'info') === 'info'
	&& kloxo_toast_kind('added_successfully', 'info') === 'success'
	&& kloxo_toast_kind('ufw_rule_added', 's') === 'success'
	&& kloxo_toast_kind('contact_not_set', 'info') === 'warning'
	&& kloxo_toast_kind('security_warning', 'error') === 'warning'
	&& kloxo_toast_kind('file_exists', 'error') === 'error'
	&& kloxo_toast_kind('', 'alert') === 'error'
	&& kloxo_toast_kind('', 'info') === 'info') {
	ok('toast kinds: info, success, warning, error');
} else {
	bad('toast kinds: info, success, warning, error');
}

$h = new HtmlLib();
$out = array();
foreach (array('info', 'success', 'warning', 'error', 'alert') as $kind) {
	ob_start();
	$h->print_inline_alert('Hello', $kind);
	$out[$kind] = (string) ob_get_clean();
}
$info = $out['info'];
$okInfo = str_contains($info, 'kloxo-toast')
	&& str_contains($info, 'kloxo-toast-info')
	&& str_contains($info, 'kloxo-inline-info')
	&& str_contains($info, 'kloxo-toast-icon')
	&& str_contains($info, '#3498db')
	&& str_contains($info, 'Hello');
$okSucc = str_contains($out['success'], 'kloxo-toast-success') && str_contains($out['success'], '#27ae60');
$okWarn = str_contains($out['warning'], 'kloxo-toast-warning') && str_contains($out['warning'], '#f39c12');
$okErr = str_contains($out['error'], 'kloxo-toast-error') && str_contains($out['error'], '#e74c3c');
$okAlert = str_contains($out['alert'], 'kloxo-toast-error');
if ($okInfo && $okSucc && $okWarn && $okErr && $okAlert) {
	ok('print_inline_alert emits a typed toast with a coloured icon');
} else {
	bad('print_inline_alert emits a typed toast with a coloured icon');
}

$css = (string) file_get_contents($doc . '/theme/css/kloxo-toast.css');
if (str_contains($css, 'top: 12px')
	&& str_contains($css, 'left: 50%')
	&& str_contains($css, 'translateX(-50%)')
	&& str_contains($css, 'background: #fff')
	&& str_contains($css, 'box-shadow: 0 4px 16px')) {
	ok('toast CSS is top-centre, white, with a light shadow');
} else {
	bad('toast CSS is top-centre, white, with a light shadow');
}

$htmlLib = (string) file_get_contents($doc . '/theme/htmllib.php');
$loginIdx = (string) file_get_contents($doc . '/login/index.php');
$js = (string) file_get_contents($doc . '/theme/js/kloxo-toast.js');
$topbar = (string) file_get_contents($doc . '/theme/skin/simplicity/default/topbar_right.php');
if (str_contains($htmlLib, 'kloxo-toast.js')
	&& str_contains($htmlLib, 'kloxo-toast.css')
	&& str_contains($loginIdx, '/theme/js/kloxo-toast.js')
	&& str_contains($loginIdx, '/theme/css/kloxo-toast.css')
	&& str_contains($js, 'DELAY = 15000')
	&& str_contains($js, '.kloxo-toast')
	&& str_contains($topbar, "print_toast")
	&& str_contains($topbar, "'div_cfstatus'")
	&& !str_contains($topbar, 'cf_fade')) {
	ok('every panel page and login load the same toast JS/CSS; Status uses print_toast');
} else {
	bad('every panel page and login load the same toast JS/CSS; Status uses print_toast');
}

$core = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
if (substr_count($core, "print_inline_alert('The requested form could not be loaded.', 'error')") >= 2
	&& str_contains($core, "print_inline_alert(htmlspecialchars(\$msg")
	&& str_contains($core, "'warning'")) {
	ok('form-load failures and the password-force warning use the same toast');
} else {
	bad('form-load failures and the password-force warning use the same toast');
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
