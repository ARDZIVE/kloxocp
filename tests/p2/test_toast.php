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

// Status toast must be printed before .div_fixed_right so a restore message
// does not sit in the Status/Show/Clock flex row.
$toastPos = strpos($topbar, 'print_toast');
$rightPos = strpos($topbar, 'class="div_fixed_right"');
if ($toastPos !== false && $rightPos !== false && $toastPos < $rightPos
	&& str_contains($topbar, "'div_cfstatus', \$toast_display, true)")) {
	ok('Status toast is outside the topbar flex strip');
} else {
	bad('Status toast is outside the topbar flex strip', "toast=$toastPos right=$rightPos");
}

ob_start();
$h->print_toast('Restore completed.', 'success', 'div_cfstatus', 'flex', true);
$statusToast = (string) ob_get_clean();
if (str_contains($statusToast, 'id="div_cfstatus"')
	&& str_contains($statusToast, 'div_header_message_status')
	&& str_contains($statusToast, 'kloxo-toast-success')
	&& !str_contains($statusToast, 'kloxo-inline-alert')
	&& str_contains($statusToast, 'Restore completed.')) {
	ok('Status toast skips legacy inline-alert classes');
} else {
	bad('Status toast skips legacy inline-alert classes', $statusToast);
}

$bak = (string) file_get_contents($doc . '/lib/html/lxbackuplib.php');
if (str_contains($bak, "throw new lxException('restore_failed'")
	&& !str_contains($bak, "getThrow('restore_failed')")) {
	ok('restore failure uses messagelib key restore_failed for the toast');
} else {
	bad('restore failure uses messagelib key restore_failed for the toast');
}

if (str_contains($htmlLib, 'kloxo_toast_plain')) {
	ok('Status peek uses kloxo_toast_plain for short text');
} else {
	bad('Status peek uses kloxo_toast_plain for short text');
}

$core = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
if (substr_count($core, "print_inline_alert('Form could not be loaded.', 'error')") >= 2
	&& str_contains($core, "'warning'")) {
	ok('form-load failures and the password-force warning use the same toast');
} else {
	bad('form-load failures and the password-force warning use the same toast');
}


if (function_exists('kloxo_toast_plain')) {
	global $g_language_mes, $__emessage;
	if ((!isset($g_language_mes) || !is_object($g_language_mes) || empty($g_language_mes->__emessage))
		&& is_file($doc . '/lang/en-us/messagelib.php')) {
		include $doc . '/lang/en-us/messagelib.php';
		if (!isset($g_language_mes) || !is_object($g_language_mes)) {
			if (!class_exists('Language_Mes', false)) {
				eval('class Language_Mes {}');
			}
			$g_language_mes = new Language_Mes();
		}
		if (isset($__emessage) && is_array($__emessage)) {
			$g_language_mes->__emessage = $__emessage;
		}
	}
	$plainAdd = kloxo_toast_plain('added_successfully', 'Client');
	$plainLong = kloxo_toast_plain('smtp_server_not_running');
	$plainKey = kloxo_toast_plain('create_certificate_failed', 'certbot failed: ' . str_repeat('x', 80));
	$plainFree = kloxo_toast_plain('Domain successfully deleted.');
	$plainUpload = kloxo_toast_plain('file_uploaded');
	$okPlain = str_contains(strtolower($plainAdd), 'added')
		&& strlen($plainLong) <= 100
		&& strlen($plainKey) <= 100
		&& str_contains(strtolower($plainFree), 'deleted')
		&& ($plainUpload === 'File uploaded.');
	if ($okPlain) {
		ok('kloxo_toast_plain is short, resolves keys, and humanizes free text');
	} else {
		bad('kloxo_toast_plain is short, resolves keys, and humanizes free text',
			"add=$plainAdd long=$plainLong key=$plainKey free=$plainFree up=$plainUpload");
	}
} else {
	bad('kloxo_toast_plain is short, resolves keys, and humanizes free text', 'missing function');
}

$core = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
if (str_contains($core, "frm_smessage=updated_successfully")
	&& str_contains($core, "frm_smessage=deleted_successfully")
	&& str_contains($core, 'frm_emessage=update_failed')) {
	ok('update/delete redirects use short toast language keys');
} else {
	bad('update/delete redirects use short toast language keys');
}

$msgLib = (string) file_get_contents($doc . '/lang/en-us/messagelib.php');
if (str_contains($msgLib, "deleted_successfully")
	&& str_contains($msgLib, "update_failed")
	&& str_contains($msgLib, "file_uploaded")
	&& !preg_match('/\\$__emessage\\[\'smtp_server_not_running\'\\]\\s*=\\s*\'[^\']{101,}\'/', $msgLib)) {
	ok('messagelib toast strings stay short and include delete/update keys');
} else {
	bad('messagelib toast strings stay short and include delete/update keys');
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
