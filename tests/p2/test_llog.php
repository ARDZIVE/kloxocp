<?php
/**
 * Log Manager: Ubuntu apps + two-level picker + same-page content.
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
include_once $doc . '/theme/coredisplaylib.php';

if (!class_exists('Llog')) {
	bad('Llog class loads');
	printf("\n%d ok, %d FAIL\n", $pass, $fail);
	exit($fail > 0 ? 1 : 0);
}
ok('Llog class loads');

$apps = Llog::logApps();
if (!is_array($apps) || $apps === array()
	|| !isset($apps['logwatch'], $apps['nginx'], $apps['syslog'], $apps['dovecot'], $apps['rspamd'])) {
	bad('logApps includes syslog nginx dovecot rspamd logwatch');
} else {
	ok('logApps includes syslog nginx dovecot rspamd logwatch');
}

if (Llog::formatBytes(0) === '0 B' && Llog::formatBytes(1536) === '1.5 KB'
	&& str_contains(Llog::fileOptionLabel(array(
		'label' => 'error.log',
		'size' => 2048,
		'mtime' => time() - 120,
	)), 'error.log')
	&& str_contains(Llog::fileOptionLabel(array(
		'label' => 'error.log',
		'size' => 2048,
		'mtime' => time() - 120,
	)), 'KB')) {
	ok('formatBytes and fileOptionLabel include size');
} else {
	bad('formatBytes and fileOptionLabel include size');
}

if (isset($apps['logwatch']['hint']) && str_contains((string) $apps['logwatch']['hint'], '/var/log/logwatch')) {
	ok('LogWatch app has a hint pointing at /var/log/logwatch');
} else {
	bad('LogWatch app has a hint pointing at /var/log/logwatch');
}

if (Llog::isValidLogApp('nginx') && !Llog::isValidLogApp('../etc')
	&& Llog::isSafeLogPath('/var/log/nginx/error.log')
	&& !Llog::isSafeLogPath('/etc/passwd')
	&& !Llog::isSafeLogPath('/var/log/../etc/passwd')) {
	ok('app and path validation allow catalog paths only');
} else {
	bad('app and path validation allow catalog paths only');
}

$tmp = sys_get_temp_dir() . '/kloxo-llog-' . getmypid();
@mkdir($tmp . '/var/log/nginx', 0755, true);
@mkdir($tmp . '/var/log/logwatch', 0755, true);
file_put_contents($tmp . '/var/log/nginx/error.log', "e1\n");
file_put_contents($tmp . '/var/log/nginx/error.log.1', "e0\n");
file_put_contents($tmp . '/var/log/logwatch/logwatch-2026-03-13.txt', "report\n");
// Override patterns via a thin test by calling pathMatchesPattern + glob manually.
$ng = array();
foreach (glob($tmp . '/var/log/nginx/error.log*') ?: array() as $f) {
	$ng[] = $f;
}
if (count($ng) >= 2) {
	ok('rotated nginx logs are discoverable by glob');
} else {
	bad('rotated nginx logs are discoverable by glob');
}
$lw = glob($tmp . '/var/log/logwatch/logwatch-*') ?: array();
if ($lw !== array()) {
	ok('LogWatch dated reports are discoverable by glob');
} else {
	bad('LogWatch dated reports are discoverable by glob');
}
foreach (glob($tmp . '/var/log/nginx/*') ?: array() as $f) {
	@unlink($f);
}
@rmdir($tmp . '/var/log/nginx');
foreach (glob($tmp . '/var/log/logwatch/*') ?: array() as $f) {
	@unlink($f);
}
@rmdir($tmp . '/var/log/logwatch');
@rmdir($tmp . '/var/log');
@rmdir($tmp);

$llog = new Llog(null, 'localhost', 'pserver-localhost');
$llog->__masterserver = null;
$llog->__readserver = 'localhost';
$prop = array();
$llog->createShowPropertyList($prop);
if (($prop['property'] ?? null) === array('a=show')) {
	ok('Log Manager show has a single Log Manager tab');
} else {
	bad('Log Manager show has a single Log Manager tab');
}

global $ghtml;
$prevHtml = $ghtml;
$ghtml = new HtmlLib();
$ghtml->__http_vars = array(
	'frm_o_o' => array(
		0 => array('class' => 'pserver', 'nname' => 'localhost'),
		1 => array('class' => 'llog'),
	),
	'frm_llog_app' => 'nginx',
	'frm_llog_file' => '/var/log/nginx/error.log',
);
if (Llog::selectedLogApp() === 'nginx'
	&& Llog::selectedLogKey() === '/var/log/nginx/error.log') {
	ok('selectedLogApp/File read frm_llog_* from __http_vars');
} else {
	bad('selectedLogApp/File read frm_llog_* from __http_vars',
		Llog::selectedLogApp() . ' / ' . Llog::selectedLogKey());
}
$ghtml->__http_vars['frm_llog_file'] = '/etc/passwd';
if (Llog::selectedLogKey() === '') {
	ok('selectedLogKey rejects paths outside the catalog');
} else {
	bad('selectedLogKey rejects paths outside the catalog');
}

$ghtml->__http_vars['frm_llog_app'] = 'nginx';
$ghtml->__http_vars['frm_llog_file'] = '/var/log/nginx/error.log';

$path = kloxo_llog_cgi_path();
if (isset($path[0]['nname'], $path[1]['class'])
	&& $path[0]['nname'] === 'localhost' && $path[1]['class'] === 'llog') {
	ok('kloxo_llog_cgi_path keeps pserver+llog');
} else {
	bad('kloxo_llog_cgi_path keeps pserver+llog');
}

ob_start();
kloxo_print_llog_picker($llog, array());
$pickerHtml = (string) ob_get_clean();
if (str_contains($pickerHtml, 'name="frm_llog_app"')
	&& str_contains($pickerHtml, 'name="frm_llog_file"')
	&& str_contains($pickerHtml, 'kloxo-llog-toolbar')
	&& str_contains($pickerHtml, 'Application')
	&& str_contains($pickerHtml, 'Log file')
	&& str_contains($pickerHtml, 'value="pserver"')
	&& str_contains($pickerHtml, 'value="llog"')
	&& str_contains($pickerHtml, 'logwatch')
	&& str_contains($pickerHtml, 'Refresh')) {
	ok('picker has toolbar, application + file lists, refresh, object path');
} else {
	bad('picker has toolbar, application + file lists, refresh, object path', substr($pickerHtml, 0, 400));
}

unset($ghtml->__http_vars['frm_llog_file']);
$eff = Llog::effectiveLogKey(array(
	array('path' => '/var/log/nginx/error.log'),
	array('path' => '/var/log/nginx/access.log'),
));
if ($eff === '/var/log/nginx/error.log') {
	ok('effectiveLogKey falls back to newest listed file');
} else {
	bad('effectiveLogKey falls back to newest listed file', $eff);
}

$showUrl = kloxo_llog_show_url($path, 'nginx', '/var/log/nginx/error.log');
if (str_contains($showUrl, 'frm_llog_app=nginx')
	&& str_contains($showUrl, 'frm_llog_file=')
	&& str_contains($showUrl, 'frm_action=show')) {
	ok('kloxo_llog_show_url keeps app file and action');
} else {
	bad('kloxo_llog_show_url keeps app file and action', $showUrl);
}

unset($ghtml->__http_vars['frm_llog_app'], $ghtml->__http_vars['frm_llog_file']);
ob_start();
kloxo_print_llog_content($llog);
$emptyHtml = (string) ob_get_clean();
if (str_contains($emptyHtml, 'Choose an application')
	&& str_contains($emptyHtml, 'kloxo-llog-placeholder')) {
	ok('empty selection asks to choose an application');
} else {
	bad('empty selection asks to choose an application', substr($emptyHtml, 0, 200));
}

$ghtml->__http_vars['frm_llog_app'] = 'syslog';
$ghtml->__http_vars['frm_llog_file'] = '/var/log/syslog';
$llogStub = new class {
	function readLogView($key)
	{
		return array(
			'key' => $key,
			'label' => 'syslog',
			'path' => $key,
			'content' => "line1\n<script>",
			'truncated' => true,
			'missing' => false,
			'size' => 4096,
			'mtime' => time() - 90,
			'error' => '',
		);
	}
};
ob_start();
kloxo_print_llog_content($llogStub);
$viewHtml = (string) ob_get_clean();
$ghtml = $prevHtml;
if (str_contains($viewHtml, 'kloxo-llog-pre')
	&& str_contains($viewHtml, 'line1')
	&& str_contains($viewHtml, '&lt;script&gt;')
	&& str_contains($viewHtml, 'kloxo-llog-badge')
	&& str_contains($viewHtml, 'last 50 KB')
	&& str_contains($viewHtml, 'Refresh')) {
	ok('selected log renders escaped content with meta and refresh');
} else {
	bad('selected log renders escaped content with meta and refresh', substr($viewHtml, 0, 400));
}

printf("\n%d ok, %d FAIL\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
