<?php
/**
 * install-webcache against fake nginx/systemctl/apache2 binaries and temp dirs.
 * php-cli only; never touches the system.
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

$repo = dirname(__DIR__, 2);
$tool = $repo . '/kloxo/bin/install/install-webcache';
$t = sys_get_temp_dir() . '/kloxo-wcache-test-' . getmypid();
$bin = $t . '/bin';
$root = $t . '/kloxo';
$ssl = $t . '/ssl';
$confd = $t . '/nginx-confd';
$apache = $t . '/apache2';
$log = $t . '/calls.log';
$state = $t . '/state';
foreach (array($bin, $root . '/etc/webfront/domains', $root . '/etc/flag', $root . '/file/webfront', $ssl, $confd,
	$apache . '/conf-available', $state, $t . '/cache', $t . '/logs') as $d) {
	mkdir($d, 0755, true);
}
copy($repo . '/kloxo/file/webfront/webcache.conf', $root . '/file/webfront/webcache.conf');
$sideConf = (string) file_get_contents($root . '/file/webfront/webcache.conf');

$fake = array(
	'nginx' => <<<'SH'
#!/bin/sh
echo "nginx $*" >> "$FAKE_LOG"
case "$1" in
  -v) echo "nginx version: nginx/${FAKE_NGINX_VERSION:-1.24.0}" >&2; exit 0 ;;
  -t)
    if [ "$2" = "-c" ]; then
      grep -q BROKEN "$3" && { echo "nginx: [emerg] broken" >&2; exit 1; }
      exit 0
    fi
    [ -f "$FAKE_STATE/backend_fail" ] && { echo "nginx: [emerg] site config" >&2; exit 1; }
    exit 0 ;;
esac
exit 0
SH,
	'systemctl' => <<<'SH'
#!/bin/sh
echo "systemctl $*" >> "$FAKE_LOG"
case "$1" in
  is-active) [ -f "$FAKE_STATE/active_$3" ] && exit 0; exit 3 ;;
  restart|start) [ "$2" = "kloxo-webcache" ] && [ -f "$FAKE_STATE/cache_fail" ] && { echo "unit failed" >&2; exit 1; } ;;
esac
exit 0
SH,
	'apache2ctl' => "#!/bin/sh\necho \"apache2ctl \$*\" >> \"\$FAKE_LOG\"\nexit 0\n",
	'a2enmod' => "#!/bin/sh\necho \"a2enmod \$*\" >> \"\$FAKE_LOG\"\nexit 0\n",
	'a2enconf' => "#!/bin/sh\necho \"a2enconf \$*\" >> \"\$FAKE_LOG\"\nexit 0\n",
	'a2disconf' => "#!/bin/sh\necho \"a2disconf \$*\" >> \"\$FAKE_LOG\"\nexit 0\n",
);
foreach ($fake as $n => $body) {
	file_put_contents($bin . '/' . $n, $body);
	chmod($bin . '/' . $n, 0755);
}
putenv('PATH=' . $bin . ':/usr/bin:/bin');
putenv('FAKE_LOG=' . $log);
putenv('FAKE_STATE=' . $state);

// certificates and per-domain records
foreach (array('default', 'example.com', 'other.org') as $c) {
	file_put_contents($ssl . '/' . $c . '.pem', "cert\n");
	file_put_contents($ssl . '/' . $c . '.key', "key\n");
}
$rec = function (string $file, array $data) use ($root): void {
	file_put_contents($root . '/etc/webfront/domains/' . $file . '.json', json_encode($data));
};
$rec('example.com', array('domain' => 'example.com', 'names' => array('www.example.com', '*.example.com'), 'cert' => $ssl . '/example.com'));
$rec('other.org', array('domain' => 'other.org', 'names' => array('www.other.org'), 'cert' => $ssl . '/other.org'));
$rec('evil', array('domain' => 'evil.test', 'names' => array('a;b', "x\ny", 'ok.evil.test'), 'cert' => $ssl . '/example.com'));
$rec('outside', array('domain' => 'outside.test', 'names' => array(), 'cert' => '/etc/ssl/private/ssl-cert-snakeoil'));
$rec('traversal', array('domain' => 'trav.test', 'names' => array(), 'cert' => $ssl . '/../ssl/example.com'));
$rec('nocert', array('domain' => 'nocert.test', 'names' => array(), 'cert' => $ssl . '/missing'));

$stockPorts = "Listen 80\n<IfModule ssl_module>\n\tListen 443\n</IfModule>\n";
file_put_contents($apache . '/ports.conf', $stockPorts);
touch($state . '/active_nginx');
touch($state . '/active_apache2');

$srv = array();
$ports = array();
for ($i = 0; $i < 2; $i++) {
	$s = stream_socket_server('tcp://127.0.0.1:0');
	$srv[] = $s;
	$ports[] = (int) substr((string) stream_socket_get_name($s, false), strrpos((string) stream_socket_get_name($s, false), ':') + 1);
}
$health = '127.0.0.1:' . $ports[0] . ',127.0.0.1:' . $ports[1];

$common = array(
	'--root=' . $root, '--ssl-dir=' . $ssl, '--nginx-confd=' . $confd, '--apache-dir=' . $apache,
	'--cache-dir=' . $t . '/cache', '--log-dir=' . $t . '/logs', '--ipv6=1', '--check-backends',
);
$run = function (array $args) use ($tool, $common, $health): array {
	$cmd = array_merge(array(PHP_BINARY, $tool), $common, $args);
	if (!in_array('--no-health', $args, true) && !preg_grep('/^--health=/', $args)) {
		$cmd[] = '--health=' . $health;
	}
	$p = proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	$out = (string) stream_get_contents($pipes[1]);
	$err = (string) stream_get_contents($pipes[2]);

	return array(proc_close($p), $out, $err);
};
$lines = function () use ($log): array {
	return is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : array();
};
$idx = function (array $ls, string $needle): int {
	foreach ($ls as $i => $l) {
		if (str_contains($l, $needle)) {
			return $i;
		}
	}

	return -1;
};
$confFile = $root . '/etc/webfront/webcache.conf';

// ---- front -----------------------------------------------------------------
[$rc, $out, $err] = $run(array('--mode=front'));
$conf = is_file($confFile) ? (string) file_get_contents($confFile) : '';
if ($rc === 0
	&& str_contains($conf, 'listen 80 default_server;')
	&& str_contains($conf, 'listen [::]:80 default_server;')
	&& str_contains($conf, 'listen 8080;')
	&& str_contains($conf, 'listen 443 ssl http2 default_server;')
	&& str_contains($conf, 'server 127.0.0.1:8081;') && str_contains($conf, 'server 127.0.0.1:8444;')
	&& str_contains($conf, 'proxy_pass http://kloxo_back_http;') && str_contains($conf, 'proxy_pass https://kloxo_back_https;')
	&& str_contains($conf, 'proxy_ssl_name $host;')
	&& str_contains($conf, 'proxy_cache_valid 200 301 60s;')) {
	ok('front: cache config listens on 80/443/8080 and proxies to the loopback backends');
} else {
	bad('front: cache config listens on 80/443/8080 and proxies to the loopback backends', $err . $out);
}
if (str_contains($conf, 'server_name example.com www.example.com *.example.com;')
	&& str_contains($conf, 'ssl_certificate ' . $ssl . '/example.com.pem;')
	&& str_contains($conf, 'ssl_certificate ' . $ssl . '/default.pem;')
	&& str_contains($conf, 'server_name other.org www.other.org;')
	&& str_contains($conf, 'server_name evil.test ok.evil.test;')
	&& !str_contains($conf, 'a;b') && !str_contains($conf, "x\ny")
	&& !str_contains($conf, 'outside.test') && !str_contains($conf, 'trav.test') && !str_contains($conf, 'nocert.test')) {
	ok('front: HTTPS blocks come from valid records only (bad names, outside/traversal/missing certs dropped)');
} else {
	bad('front: HTTPS blocks come from valid records only', $conf);
}
foreach (array('wordpress_logged_in', 'PHPSESSID', '\\.well-known/', 'proxy_cache_bypass $kloxo_nocache;', 'proxy_no_cache $kloxo_nocache;',
	'proxy_cache_lock on;') as $needle) {
	if (!str_contains($conf, $needle)) {
		bad('front: cache bypasses sessions, auth and ACME', 'missing ' . $needle);
		break;
	}
	if ($needle === 'proxy_cache_lock on;') {
		ok('front: cache bypasses sessions, auth and ACME challenges');
	}
}
$apPorts = (string) file_get_contents($apache . '/ports.conf');
$ls = $lines();
if (str_contains($apPorts, 'Listen 127.0.0.1:8081') && str_contains($apPorts, 'Listen 127.0.0.1:8444')
	&& !preg_match('/^Listen (80|443)$/m', $apPorts)
	&& (string) file_get_contents($apache . '/ports.conf.kloxo-orig') === $stockPorts
	&& str_contains((string) file_get_contents($confd . '/kloxo-webcache-realip.conf'), 'set_real_ip_from 127.0.0.1;')
	&& str_contains((string) file_get_contents($apache . '/conf-available/kloxo-webcache-realip.conf'), 'RemoteIPHeader X-Forwarded-For')
	&& is_file($root . '/etc/flag/webcache_front.flg')
	&& $idx($ls, 'a2enmod -q remoteip') >= 0 && $idx($ls, 'a2enconf -q kloxo-webcache-realip') >= 0) {
	ok('front: Apache listens on loopback only, real client IP is trusted from the cache, state flag set');
} else {
	bad('front: Apache listens on loopback only, real client IP is trusted from the cache', $apPorts);
}
$iReload = $idx($ls, 'systemctl reload nginx');
$iApache = $idx($ls, 'systemctl restart apache2');
$iCache = $idx($ls, 'systemctl restart kloxo-webcache');
if ($iReload >= 0 && $iApache >= 0 && $iCache > $iReload && $iCache > $iApache) {
	ok('front: site servers move to their new ports before the cache takes 80/443');
} else {
	bad('front: site servers move to their new ports before the cache takes 80/443', implode(' | ', $ls));
}

// newer nginx uses the http2 directive
@unlink($log);
putenv('FAKE_NGINX_VERSION=1.26.1');
[$rc] = $run(array('--mode=front', '--ttl=30'));
$conf = (string) file_get_contents($confFile);
if ($rc === 0 && str_contains($conf, "http2 on;") && !str_contains($conf, 'ssl http2') && str_contains($conf, 'proxy_cache_valid 200 301 30s;')) {
	ok('front: nginx 1.25.1+ gets "http2 on", ttl option applies');
} else {
	bad('front: nginx 1.25.1+ gets "http2 on", ttl option applies');
}
putenv('FAKE_NGINX_VERSION');

// ---- none ------------------------------------------------------------------
@unlink($log);
[$rc, $out, $err] = $run(array('--mode=none'));
$ls = $lines();
$iStop = $idx($ls, 'systemctl stop kloxo-webcache');
$iReload = $idx($ls, 'systemctl reload nginx');
$iStart = $idx($ls, 'systemctl restart kloxo-webcache');
if ($rc === 0 && (string) file_get_contents($confFile) === $sideConf
	&& (string) file_get_contents($apache . '/ports.conf') === $stockPorts
	&& !is_file($confd . '/kloxo-webcache-realip.conf')
	&& !is_file($apache . '/conf-available/kloxo-webcache-realip.conf')
	&& !is_file($root . '/etc/flag/webcache_front.flg')
	&& $idx($ls, 'a2disconf -q kloxo-webcache-realip') >= 0
	&& $iStop >= 0 && $iReload > $iStop && $iStart > $iReload) {
	ok('none: side cache restored, Apache ports and real-ip files reverted, cache stops before the site server rebinds 80/443');
} else {
	bad('none: side cache restored, Apache ports and real-ip files reverted', $err . $out . implode(' | ', $ls));
}

// ---- failures roll back -------------------------------------------------------
$before = (string) file_get_contents($confFile);
touch($state . '/backend_fail');
[$rc, , $err] = $run(array('--mode=front'));
if ($rc !== 0 && str_contains($err, 'nginx -t (site server) failed')
	&& (string) file_get_contents($confFile) === $before
	&& (string) file_get_contents($apache . '/ports.conf') === $stockPorts
	&& !is_file($confd . '/kloxo-webcache-realip.conf')
	&& !is_file($root . '/etc/flag/webcache_front.flg')) {
	ok('front: a failing site-server check rolls every file back');
} else {
	bad('front: a failing site-server check rolls every file back', $err);
}
@unlink($state . '/backend_fail');

touch($state . '/cache_fail');
[$rc, , $err] = $run(array('--mode=front'));
if ($rc !== 0 && str_contains($err, 'systemctl restart kloxo-webcache failed')
	&& (string) file_get_contents($confFile) === $before
	&& (string) file_get_contents($apache . '/ports.conf') === $stockPorts
	&& !is_file($root . '/etc/flag/webcache_front.flg')) {
	ok('front: the cache unit failing to start rolls the files back');
} else {
	bad('front: the cache unit failing to start rolls the files back', $err);
}
@unlink($state . '/cache_fail');

[$rc, , $err] = $run(array('--mode=front', '--health=127.0.0.1:1'));
if ($rc !== 0 && str_contains($err, 'Nothing is listening') && (string) file_get_contents($confFile) === $before
	&& !is_file($root . '/etc/flag/webcache_front.flg')) {
	ok('front: a failed health check rolls the files back');
} else {
	bad('front: a failed health check rolls the files back', $err);
}

// a config nginx rejects is never installed
file_put_contents($ssl . '/default.pem', "BROKEN\n");
file_put_contents($root . '/etc/webfront/domains/example.com.json', json_encode(array(
	'domain' => 'example.com', 'names' => array('www.example.com'), 'cert' => $ssl . '/example.com')));
$brokenSide = $root . '/file/webfront/webcache.conf';
file_put_contents($brokenSide, "BROKEN\n");
[$rc, , $err] = $run(array('--mode=none'));
if ($rc !== 0 && str_contains($err, 'nginx -t (cache config) failed') && (string) file_get_contents($confFile) === $before) {
	ok('none: a config that nginx -t rejects is not installed');
} else {
	bad('none: a config that nginx -t rejects is not installed', $err);
}
file_put_contents($brokenSide, $sideConf);

// ---- arguments ----------------------------------------------------------------
[$rc1] = $run(array());
[$rc2] = $run(array('--mode=sideways'));
[$rc3] = $run(array('--mode=front', '--ttl=0'));
[$rc4] = $run(array('--mode=front', '--ttl=abc'));
[$rc5] = $run(array('--mode=front', '--root=/opt/kloxo/../etc'));
[$rc6] = $run(array('--mode=front', '--ssl-dir=relative/path'));
[$rc7, $out7] = $run(array('--mode=front', '--dry-run'));
if ($rc1 !== 0 && $rc2 !== 0 && $rc3 !== 0 && $rc4 !== 0 && $rc5 !== 0 && $rc6 !== 0
	&& $rc7 === 0 && str_contains($out7, 'plan: install-webcache --mode=front') && str_contains($out7, '3 record(s)')) {
	ok('arguments are validated; --dry-run plans without changing anything');
} else {
	bad('arguments are validated; --dry-run plans without changing anything', "$rc1 $rc2 $rc3 $rc4 $rc5 $rc6 $rc7 $out7");
}

$src = (string) file_get_contents($tool);
if (str_contains($src, 'declare(strict_types=1)') && !preg_match('/\b(shell_exec|system|passthru|exec)\s*\(/', $src)
	&& str_contains($src, 'proc_open')) {
	ok('install-webcache uses argument-array proc_open only');
} else {
	bad('install-webcache uses argument-array proc_open only');
}

exec('rm -rf ' . escapeshellarg($t));
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
