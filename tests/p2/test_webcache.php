<?php
/**
 * Switch Program web cache: only "none" is offered, switching is a safe no-op
 * (no yum/chkconfig/rpm), and unknown legacy drivers do not fatal. php-cli only.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function ok(string $d): void { global $pass; $pass++; printf("  ok   %s\n", $d); }
function bad(string $d, string $x = ''): void { global $fail; $fail++; printf("  FAIL %s\n", $d); if ($x !== '') { printf("       %s\n", $x); } }

$root = dirname(__DIR__, 2) . '/kloxo';
$doc = $root . '/httpdocs';
putenv('KLOXO_ROOT=' . $root);
$_SERVER['DOCUMENT_ROOT'] = $doc;
chdir($doc);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
$restartDir = $root . '/etc/.restart';
$hadRestartDir = is_dir($restartDir);

include_once $doc . '/lib/html/include.php';

// driver lists
$lists = array();
foreach (array('debian.inc', 'rhel.inc') as $inc) {
	$driver = array();
	include $root . '/file/driver/' . $inc;
	$lists[$inc] = $driver['webcache'] ?? null;
}
if ($lists['debian.inc'] === array('none', 'nginx') && $lists['rhel.inc'] === array('none', 'nginx')) {
	ok('Switch Program web cache list is none or nginx (no varnish/squid/trafficserver)');
} else {
	bad('Switch Program web cache list is none or nginx', json_encode($lists));
}
if (getAllRealWebCacheDriverList() === array() && class_exists('webcache__nginx')) {
	ok('no legacy per-driver cache config is rendered (fixwebcache has nothing to do) and webcache__nginx loads');
} else {
	bad('no legacy per-driver cache config is rendered, webcache__nginx loads');
}

// switching never throws, including legacy driver names from an old database
$err = '';
foreach (array(array('none', 'none'), array('varnish', 'none'), array('squid', 'none'), array('trafficserver', 'none'), array('none', 'varnish')) as $p) {
	try {
		Lxclass::switchDriver('webcache', $p[0], $p[1]);
	} catch (Throwable $e) {
		$err = $p[0] . '->' . $p[1] . ': ' . $e->getMessage();
	}
}
try {
	WebCache::switchProgramPost('none', 'none');
} catch (Throwable $e) {
	$err = 'post: ' . $e->getMessage();
}
if ($err === '') {
	ok('web cache switch (incl. legacy varnish/squid/trafficserver names) does not throw');
} else {
	bad('web cache switch does not throw', $err);
}

// switching to nginx and back never throws either (nginx may be missing on this box: then installMe throws a clear lxException)
$err2 = '';
try {
	Lxclass::switchDriver('webcache', 'none', 'nginx');
} catch (lxException $e) {
	$err2 = str_contains($e->getMessage(), 'nginx is not installed') ? '' : $e->getMessage();
} catch (Throwable $e) {
	$err2 = get_class($e) . ': ' . $e->getMessage();
}
if ($err2 === '') {
	ok('webcache__nginx installMe passes or fails with a clear nginx-not-installed message');
} else {
	bad('webcache__nginx installMe', $err2);
}

// no CentOS tooling in the driver, and the old yum/chkconfig base class is gone
$none = (string) file_get_contents($doc . '/driver/web/webcache__nonelib.php');
$code = preg_replace('#//[^\n]*#', '', $none) ?? '';
if (!is_file($doc . '/driver/web/webcache__lib.php')
	&& !preg_match('/\b(yum|rpm|chkconfig|setRpm)/i', $code)
	&& str_contains($none, 'extends lxDriverClass')) {
	ok('webcache none driver has no yum/chkconfig base class');
} else {
	bad('webcache none driver has no yum/chkconfig base class');
}

// ---- per-domain TLS records ----------------------------------------------------
$tmp = sys_get_temp_dir() . '/kloxo-wc-p2-' . getmypid();
mkdir($tmp . '/etc/webfront/domains', 0755, true);
$sgbl->__path_program_root = $tmp;
$rec = KloxoWebcache::buildRecord(array(
	'domainname' => 'Example.COM',
	'wildcards' => true,
	'serveraliases' => array('alias.example.net', array('alias' => 'a2.example.net'), 'bad;name', "x\ny"),
	'parkdomains' => array(array('parkdomain' => 'park.org')),
	'certnamelist' => array('*' => '192_0_2_1'),
));
$recOk = is_array($rec) && $rec['domain'] === 'example.com'
	&& $rec['names'] === array('www.example.com', '*.example.com', 'alias.example.net', 'a2.example.net', 'park.org', 'www.park.org')
	&& $rec['cert'] === '/home/kloxo/ssl/192_0_2_1'
	&& KloxoWebcache::buildRecord(array('domainname' => 'example.com', 'certnamelist' => array())) === null
	&& KloxoWebcache::buildRecord(array('domainname' => 'example.com', 'certnamelist' => array('*' => '../../etc/passwd'))) === null
	&& KloxoWebcache::buildRecord(array('domainname' => '../x', 'certnamelist' => array('*' => 'a'))) === null;
$written = KloxoWebcache::writeRecord(array('domainname' => 'example.com', 'certnamelist' => array('*' => 'c1')));
$file = $tmp . '/etc/webfront/domains/example.com.json';
$j = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
$recOk = $recOk && $written && is_array($j) && $j['cert'] === '/home/kloxo/ssl/c1';
KloxoWebcache::writeRecord(array('domainname' => 'example.com', 'certnamelist' => array()));
$recOk = $recOk && !is_file($file);
KloxoWebcache::writeRecord(array('domainname' => 'a.com', 'certnamelist' => array('*' => 'c')));
KloxoWebcache::writeRecord(array('domainname' => 'b.com', 'certnamelist' => array('*' => 'c')));
KloxoWebcache::removeRecord('a.com');
$recOk = $recOk && !is_file($tmp . '/etc/webfront/domains/a.com.json') && is_file($tmp . '/etc/webfront/domains/b.com.json');
KloxoWebcache::removeAllRecords();
$recOk = $recOk && (glob($tmp . '/etc/webfront/domains/*.json') ?: array()) === array();
if ($recOk) {
	ok('domain TLS records: valid names and certificate only, written/removed with the domain');
} else {
	bad('domain TLS records: valid names and certificate only, written/removed with the domain', json_encode($rec));
}

// apply(): validates the driver, reports a missing tool, maps to install-webcache modes
$a1 = KloxoWebcache::apply('varnish');
$a2 = KloxoWebcache::apply('nginx');
if ($a1['ok'] === false && $a1['error'] === 'invalid_webcache_driver'
	&& $a2['ok'] === false && str_contains($a2['error'], 'install-webcache is missing')
	&& KloxoWebcache::modeFor('nginx') === 'front' && KloxoWebcache::modeFor('none') === 'none') {
	ok('KloxoWebcache::apply rejects unknown drivers and reports a missing tool');
} else {
	bad('KloxoWebcache::apply rejects unknown drivers and reports a missing tool', json_encode(array($a1, $a2)));
}
exec('rm -rf ' . escapeshellarg($tmp));

// ---- site templates move behind the cache ---------------------------------------------
$tdir = sys_get_temp_dir() . '/kloxo-wc-tpl-' . getmypid();
mkdir($tdir, 0755, true);
file_put_contents($tdir . '/c.pem', "x\n");
file_put_contents($tdir . '/c.key', "x\n");
$tplIn = function (string $cache) use ($tdir): array {
	return array(
		'domainname' => 'example.com', 'user' => 'bob', 'webcache' => $cache, 'rootpath' => '/home/bob/example.com',
		'certnamelist' => array('*' => $tdir . '/c'), 'indexorder' => array('index.php'), 'enablephp' => true,
		'enablessl' => true, 'disabled' => false, 'timeout' => '300', 'phpselected' => 'php', 'wildcards' => false,
		'serveraliases' => array(), 'parkdomains' => array(), 'blockips' => array(), 'apacheextratext' => '',
	);
};
$apTpl = (string) file_get_contents($root . '/file/apache/tpl/domains.conf.tpl');
$apDef = (string) file_get_contents($root . '/file/apache/tpl/defaults.conf.tpl');
$apNone = getParseInlinePhp($apTpl, $tplIn('none'));
$apNginx = getParseInlinePhp($apTpl, $tplIn('nginx'));
$apDefNginx = getParseInlinePhp($apDef, array_merge($tplIn('nginx'), array('certnamelist' => array('*' => $tdir . '/c'))));
$apDefNone = getParseInlinePhp($apDef, array_merge($tplIn('none'), array('certnamelist' => array('*' => $tdir . '/c'))));
$tplOk = str_contains($apNone, '<VirtualHost *:80>') && str_contains($apNone, '<VirtualHost *:443>')
	&& str_contains($apNginx, '<VirtualHost 127.0.0.1:8081>') && str_contains($apNginx, '<VirtualHost 127.0.0.1:8444>')
	&& !str_contains($apNginx, '*:80>') && !str_contains($apNginx, ':8080') && !str_contains($apNginx, ':8443')
	&& str_contains($apNginx, 'SSLEngine on')
	&& str_contains($apDefNginx, '<VirtualHost 127.0.0.1:8081>') && str_contains($apDefNone, '<VirtualHost *:80>');
$g = $root . '/file/nginx/conf/globals/';
$nginxBack = array(
	'listen_nonssl_back.conf' => 'listen 127.0.0.1:8081;',
	'listen_nonssl_back_default.conf' => 'listen 127.0.0.1:8081 default_server;',
	'listen_ssl_back.conf' => 'listen 127.0.0.1:8444 ssl;',
	'listen_ssl_back_default.conf' => 'listen 127.0.0.1:8444 ssl default_server;',
);
foreach ($nginxBack as $f => $want) {
	$tplOk = $tplOk && trim((string) file_get_contents($g . $f)) === $want;
}
$tplOk = $tplOk && trim((string) file_get_contents($g . 'listen_nonssl_front.conf')) !== ''
	&& str_contains((string) file_get_contents($g . 'listen_nonssl_front.conf'), 'listen 80;');
if ($tplOk) {
	ok('with a web cache the Apache and Nginx site servers listen on 127.0.0.1:8081 (http) and :8444 (https); without it they keep 80/443');
} else {
	bad('with a web cache the Apache and Nginx site servers listen on loopback 8081/8444', $apNginx);
}
exec('rm -rf ' . escapeshellarg($tdir));

// ---- Switch Program wiring: regenerate, apply, revert on failure -------------------------
$psrc = (string) file_get_contents($doc . '/driver/pserver/pserverlib.php');
$i = strpos($psrc, 'if ($class === \'webcache\')');
$blk = $i === false ? '' : substr($psrc, $i, 1800);
$iFix = strpos($blk, '/script/fixweb');
$iApply = strpos($blk, '\'KloxoWebcache\', \'apply\'), array($v)');
$iRevert = strpos($blk, 'changeDriver($this->nname, $class, $oldcache)');
$iBack = strpos($blk, '\'KloxoWebcache\', \'apply\'), array($oldcache)');
$wsrc = (string) file_get_contents($doc . '/driver/web/web__lib.php');
if ($iFix !== false && $iApply !== false && $iRevert !== false && $iBack !== false
	&& $iFix < $iApply && $iApply < $iRevert && $iRevert < $iBack
	&& str_contains($blk, 'throw new lxException')
	&& str_contains($wsrc, 'KloxoWebcache::writeRecord($input)')
	&& str_contains($wsrc, 'KloxoWebcache::removeRecord(')
	&& str_contains($wsrc, 'KloxoWebcache::removeAllRecords()')) {
	ok('Switch Program web cache: fixweb, then install-webcache, then revert and regenerate on failure; domain records follow the domain');
} else {
	bad('Switch Program web cache wiring', json_encode(array($iFix, $iApply, $iRevert, $iBack)));
}

// the harness may create the restart queue dir; leave the tree clean
if (!$hadRestartDir && is_dir($restartDir)) {
	foreach (glob($restartDir . '/{,.}*', GLOB_BRACE) ?: array() as $f) {
		if (is_file($f)) {
			@unlink($f);
		}
	}
	@rmdir($restartDir);
}
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
