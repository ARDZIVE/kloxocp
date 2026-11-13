<?php
/**
 * Log Manager: Ubuntu catalog + same-page picker/content invariants.
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

$cat = Llog::logCatalog();
if (!is_array($cat) || $cat === array()) {
	bad('logCatalog returns a non-empty array');
} else {
	ok('logCatalog returns a non-empty array');
}

$dead = array(
	'lighttpd/access.log', 'lighttpd/error.log',
	'httpd/access_log', 'httpd/error_log',
	'messages', 'secure', 'cron', 'yum.log',
	'named/', 'djbdns.log', 'nsd.log', 'yadifa/yadifa.log',
	'rkhunter/rkhunter.log', 'httpry/httpry.log',
);
$deadHit = array();
foreach ($dead as $k) {
	if (array_key_exists($k, $cat)) {
		$deadHit[] = $k;
	}
}
if ($deadHit === array()) {
	ok('logCatalog omits removed-stack / CentOS paths');
} else {
	bad('logCatalog omits removed-stack / CentOS paths', implode(',', $deadHit));
}

$need = array(
	'syslog', 'auth.log', 'dpkg.log',
	'exim4/mainlog', 'mail.log',
	'nginx/error.log', 'apache2/error.log',
	'/opt/kloxo/log/hiawatha-system.log',
	'/opt/kloxo/log/php.err',
	'/opt/kloxo/log/watchdog.log',
	'fail2ban.log', 'pureftpd.log', 'mysql/error.log',
);
$missing = array();
foreach ($need as $k) {
	if (!array_key_exists($k, $cat)) {
		$missing[] = $k;
	}
}
if ($missing === array()) {
	ok('logCatalog includes Ubuntu panel / service logs');
} else {
	bad('logCatalog includes Ubuntu panel / service logs', implode(',', $missing));
}

if (Llog::isValidLogKey('syslog') && !Llog::isValidLogKey('../etc/passwd')
	&& !Llog::isValidLogKey('not-in-catalog') && !Llog::isValidLogKey('')) {
	ok('isValidLogKey allows catalog keys only');
} else {
	bad('isValidLogKey allows catalog keys only');
}

if (Llog::resolveLogPath('nginx/error.log') === '/var/log/nginx/error.log'
	&& Llog::resolveLogPath('/opt/kloxo/log/php.err') === '/opt/kloxo/log/php.err') {
	ok('resolveLogPath maps relative and absolute keys');
} else {
	bad('resolveLogPath maps relative and absolute keys');
}

if (Llog::catalogKeyFromPath('/var/log/nginx/error.log') === 'nginx/error.log'
	&& Llog::catalogKeyFromPath('/opt/kloxo/log/php.err') === '/opt/kloxo/log/php.err') {
	ok('catalogKeyFromPath reverses absolute paths');
} else {
	bad('catalogKeyFromPath reverses absolute paths');
}

$llog = new Llog(null, 'localhost', 'pserver-localhost');
$llog->__masterserver = null;
$llog->__readserver = 'localhost';
$prop = array();
$llog->createShowPropertyList($prop);
$props = $prop['property'] ?? array();
if (is_array($props) && $props === array('a=show')) {
	ok('Log Manager show has a single Log Manager tab (no File Manager /)');
} else {
	bad('Log Manager show has a single Log Manager tab (no File Manager /)', var_export($props, true));
}

$sc = $llog->createShowSclist();
if (isset($sc['ffile']) && $sc['ffile'] === $cat) {
	ok('createShowSclist uses logCatalog');
} else {
	bad('createShowSclist uses logCatalog');
}

global $ghtml;
$prevHtml = $ghtml;
$ghtml = new HtmlLib();
$ghtml->__http_vars = array();
$ghtml->__http_vars['frm_llog_file'] = 'nginx/error.log';
if (Llog::selectedLogKey() === 'nginx/error.log') {
	ok('selectedLogKey reads frm_llog_file');
} else {
	bad('selectedLogKey reads frm_llog_file', Llog::selectedLogKey());
}
$ghtml->__http_vars['frm_llog_file'] = '../etc/passwd';
if (Llog::selectedLogKey() === '') {
	ok('selectedLogKey rejects path traversal');
} else {
	bad('selectedLogKey rejects path traversal');
}
unset($ghtml->__http_vars['frm_llog_file']);

$core = file_get_contents($doc . '/theme/coredisplaylib.php');
$coreOk = is_string($core)
	&& str_contains($core, 'function kloxo_print_llog_picker')
	&& str_contains($core, 'function kloxo_print_llog_content')
	&& str_contains($core, 'frm_llog_file')
	&& str_contains($core, 'kloxo_print_llog_content($object)')
	&& !str_contains($core, 'frm_o_o[<?= (int) $n ?>][class]" value="ffile"')
	&& str_contains($core, 'isLogManagerView')
	&& str_contains($core, 'catalogKeyFromPath');
if ($coreOk) {
	ok('same-page picker uses frm_llog_file and prints content below');
} else {
	bad('same-page picker uses frm_llog_file and prints content below');
}

// Inline content HTML when a key is selected (no backend read required for empty key).
ob_start();
kloxo_print_llog_content($llog);
$emptyHtml = (string) ob_get_clean();
if (str_contains($emptyHtml, 'Select a log file above')) {
	ok('empty selection shows on-page hint under the dropdown');
} else {
	bad('empty selection shows on-page hint under the dropdown', substr($emptyHtml, 0, 200));
}

$ghtml->__http_vars['frm_llog_file'] = 'syslog';
$llogStub = new class {
	function readLogView($key)
	{
		return array(
			'key' => $key,
			'label' => 'Syslog',
			'path' => '/var/log/syslog',
			'content' => "line1\nline2<script>",
			'truncated' => true,
			'missing' => false,
			'error' => '',
		);
	}
};
ob_start();
kloxo_print_llog_content($llogStub);
$viewHtml = (string) ob_get_clean();
unset($ghtml->__http_vars['frm_llog_file']);
$ghtml = $prevHtml;
if (str_contains($viewHtml, 'kloxo-llog-pre')
	&& str_contains($viewHtml, 'line1')
	&& str_contains($viewHtml, '&lt;script&gt;')
	&& str_contains($viewHtml, '/var/log/syslog')
	&& str_contains($viewHtml, 'last')) {
	ok('selected log renders escaped content under the picker');
} else {
	bad('selected log renders escaped content under the picker', substr($viewHtml, 0, 300));
}

printf("\n%d ok, %d FAIL\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
