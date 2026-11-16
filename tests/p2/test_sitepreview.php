<?php
/**
 * DNSless preview (KloxoSitepreview): parse, ACL, HTML rewrite, Host fetch.
 * php-cli only; HTTP is faked via $httpGet.
 */
declare(strict_types=1);

$repo = dirname(__DIR__, 2);
$doc = $repo . '/kloxo/httpdocs';
require_once $doc . '/lib/php/kloxo_sitepreview.php';

$fail = 0;
$ok = 0;

function check(bool $cond, string $msg, string $detail = ''): void
{
	global $fail, $ok;
	if ($cond) {
		echo "  ok   {$msg}\n";
		$ok++;
	} else {
		echo "  FAIL {$msg}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
		$fail++;
	}
}

echo "== parse / validate\n";
check(KloxoSitepreview::isDomainName('example.com')
	&& KloxoSitepreview::isDomainName('a.b.co.uk')
	&& !KloxoSitepreview::isDomainName('127.0.0.1')
	&& !KloxoSitepreview::isDomainName('../evil')
	&& !KloxoSitepreview::isDomainName('a b.com')
	&& !KloxoSitepreview::isDomainName(''),
	'isDomainName accepts FQDNs and rejects IP/traversal');

$p = KloxoSitepreview::parseRequestUri('/sitepreview/example.com/');
check(is_array($p) && ($p['domain'] ?? '') === 'example.com' && ($p['path'] ?? '') === '/',
	'parseRequestUri /sitepreview/example.com/');

$p2 = KloxoSitepreview::parseRequestUri('/sitepreview/blog.example.com/wp-admin/?x=1');
check(is_array($p2) && ($p2['domain'] ?? '') === 'blog.example.com'
	&& ($p2['path'] ?? '') === '/wp-admin/' && ($p2['query'] ?? '') === 'x=1',
	'parseRequestUri keeps path and query');

check(KloxoSitepreview::parseRequestUri('/display.php') === null
	&& KloxoSitepreview::parseRequestUri('/sitepreview/../etc/passwd') === null
	&& KloxoSitepreview::parseRequestUri('/sitepreview/example.com/a/../../etc') === null,
	'parseRequestUri rejects non-preview and traversal');

echo "== ACL\n";
$admin = new class {
	public function isAdmin(): bool
	{
		return true;
	}
	public function getClName(): string
	{
		return 'client-admin';
	}
};
$alice = new class {
	public function isAdmin(): bool
	{
		return false;
	}
	public function getClName(): string
	{
		return 'client-alice';
	}
};
check(KloxoSitepreview::loginMayPreview($admin, 'client-bob')
	&& KloxoSitepreview::loginMayPreview($alice, 'client-alice')
	&& !KloxoSitepreview::loginMayPreview($alice, 'client-bob')
	&& !KloxoSitepreview::loginMayPreview(null, 'client-alice'),
	'loginMayPreview allows admin and owning client only');

echo "== rewrite HTML\n";
$html = '<html><a href="http://example.com/about">A</a>'
	. '<img src="/logo.png">'
	. '<link href="https://www.example.com/x.css">'
	. '<a href="/sitepreview/example.com/keep">K</a>'
	. '<img srcset="/a.png 1x, /b.png 2x">'
	. '</html>';
$out = KloxoSitepreview::rewriteHtml('example.com', $html);
check(str_contains($out, 'href="/sitepreview/example.com/about"')
	&& str_contains($out, 'src="/sitepreview/example.com/logo.png"')
	&& str_contains($out, 'href="/sitepreview/example.com/x.css"')
	&& str_contains($out, 'href="/sitepreview/example.com/keep"')
	&& str_contains($out, 'srcset="/sitepreview/example.com/a.png 1x, /sitepreview/example.com/b.png 2x"')
	&& !str_contains($out, 'http://example.com'),
	'rewriteHtml maps domain and root-relative URLs under /sitepreview/');

$dirty = '<html><script>alert(1)</script><a href="javascript:alert(2)">x</a>'
	. '<img src=/x onerror="alert(3)"><p onclick=evil()>y</p></html>';
$clean = KloxoSitepreview::rewriteHtml('example.com', $dirty);
check(!str_contains($clean, '<script')
	&& !str_contains($clean, 'javascript:')
	&& !str_contains($clean, 'onerror')
	&& !str_contains($clean, 'onclick'),
	'rewriteHtml/sanitizePreviewHtml strips script and handlers');

echo "== fetch via Host header (fake HTTP)\n";
KloxoSitepreview::$webDriver = 'nginx';
KloxoSitepreview::$webcacheDriver = 'none';
KloxoSitepreview::$previewIps = array('127.0.0.1');
KloxoSitepreview::$loopbackPorts = array(80);
$seen = array();
KloxoSitepreview::$httpGet = static function (string $url, array $headers) use (&$seen): array {
	$seen[] = array('url' => $url, 'headers' => $headers);
	$host = '';
	foreach ($headers as $h) {
		if (stripos($h, 'Host:') === 0) {
			$host = trim(substr($h, 5));
		}
	}
	if ($url === 'http://127.0.0.1:80/' && $host === 'example.com') {
		return array(
			'code' => 200,
			'body' => '<a href="/hi">x</a>',
			'ctype' => 'text/html',
			'headers' => array('content-type' => 'text/html', 'server' => 'nginx'),
		);
	}
	if (str_contains($url, ':8081/')) {
		return array('code' => 0, 'body' => '', 'ctype' => '', 'headers' => array());
	}

	return array('code' => 404, 'body' => 'no', 'ctype' => 'text/plain', 'headers' => array());
};
$got = KloxoSitepreview::fetch('example.com', '/');
check(($got['code'] ?? 0) === 200
	&& str_contains((string) ($got['body'] ?? ''), '<a href="/hi">')
	&& isset($seen[0]['url']) && $seen[0]['url'] === 'http://127.0.0.1:80/'
	&& in_array('Host: example.com', $seen[0]['headers'], true),
	'fetch uses 127.0.0.1 with Host: domain (no /etc/hosts)');

KloxoSitepreview::$httpGet = static function (string $url, array $headers): array {
	if (str_contains($url, ':80/')) {
		return array('code' => 0, 'body' => '', 'ctype' => '', 'headers' => array());
	}
	if (str_contains($url, ':8081/gone')) {
		return array(
			'code' => 301,
			'body' => '',
			'ctype' => '',
			'headers' => array('location' => 'http://example.com/here'),
		);
	}
	if (str_contains($url, ':8081/here')) {
		return array(
			'code' => 200,
			'body' => 'ok-here',
			'ctype' => 'text/plain',
			'headers' => array('content-type' => 'text/plain', 'server' => 'nginx'),
		);
	}

	return array('code' => 0, 'body' => '', 'ctype' => '', 'headers' => array());
};
KloxoSitepreview::$loopbackPorts = array(80, 8081);
$got2 = KloxoSitepreview::fetch('example.com', '/gone');
check(($got2['code'] ?? 0) === 200 && ($got2['body'] ?? '') === 'ok-here',
	'fetch falls back to :8081 and follows same-domain redirect');

KloxoSitepreview::$webDriver = 'nginx';
KloxoSitepreview::$previewIps = array('127.0.0.1');
KloxoSitepreview::$loopbackPorts = array(80, 8081);
KloxoSitepreview::$httpGet = static function (string $url, array $headers): array {
	if (str_contains($url, '127.0.0.1:80/')) {
		return array(
			'code' => 200,
			'body' => '<html><title>Apache2 Ubuntu Default Page</title><body>It works!</body></html>',
			'ctype' => 'text/html',
			'headers' => array('content-type' => 'text/html', 'server' => 'Apache/2.4.58 (Ubuntu)'),
		);
	}
	if (str_contains($url, '127.0.0.1:8081/')) {
		return array(
			'code' => 200,
			'body' => '<html><a href="/site">nginx site</a></html>',
			'ctype' => 'text/html',
			'headers' => array('content-type' => 'text/html', 'server' => 'nginx'),
		);
	}

	return array('code' => 0, 'body' => '', 'ctype' => '', 'headers' => array());
};
$got3 = KloxoSitepreview::fetch('example.com', '/');
check(($got3['code'] ?? 0) === 200
	&& str_contains((string) ($got3['body'] ?? ''), 'nginx site')
	&& !str_contains((string) ($got3['body'] ?? ''), 'Apache2 Ubuntu Default Page'),
	'fetch skips Apache default page when web driver is nginx');

// Apache only on loopback; Nginx on the public address (common Listen split).
KloxoSitepreview::$previewIps = array('127.0.0.1', '203.0.113.10');
KloxoSitepreview::$loopbackPorts = array(80);
KloxoSitepreview::$httpGet = static function (string $url, array $headers): array {
	if (str_contains($url, '127.0.0.1:80/')) {
		return array(
			'code' => 200,
			'body' => 'Apache2 Ubuntu Default Page',
			'ctype' => 'text/html',
			'headers' => array('server' => 'Apache/2.4'),
		);
	}
	if (str_contains($url, '203.0.113.10:80/')) {
		return array(
			'code' => 200,
			'body' => '<html>real nginx vhost</html>',
			'ctype' => 'text/html',
			'headers' => array('server' => 'nginx'),
		);
	}

	return array('code' => 0, 'body' => '', 'ctype' => '', 'headers' => array());
};
$got4 = KloxoSitepreview::fetch('example.com', '/');
check(($got4['code'] ?? 0) === 200
	&& str_contains((string) ($got4['body'] ?? ''), 'real nginx vhost'),
	'fetch uses public IPv4 when loopback only serves leftover Apache');

check(KloxoSitepreview::isWrongStackResponse('nginx', array(
		'body' => 'Apache2 Ubuntu Default Page',
		'headers' => array('server' => 'Apache/2.4'),
	))
	&& !KloxoSitepreview::isWrongStackResponse('nginx', array(
		'body' => 'Apache2 Ubuntu Default Page',
		'headers' => array('server' => 'nginx'),
	))
	&& !KloxoSitepreview::isWrongStackResponse('nginx', array(
		'body' => 'hello',
		'headers' => array('server' => 'nginx'),
	)),
	'isWrongStackResponse uses Server header only (not leftover /var/www/html text)');

KloxoSitepreview::$loopbackPorts = null;
KloxoSitepreview::$webcacheDriver = 'nginx';
KloxoSitepreview::$webDriver = 'nginx';
check(KloxoSitepreview::previewPorts() === array(8081, 80, 8080),
	'webcache front prefers site back-end port 8081');

check(KloxoSitepreview::redirectTarget('example.com', '/x') === '/x'
	&& KloxoSitepreview::redirectTarget('example.com', 'http://example.com/y') === '/y'
	&& KloxoSitepreview::redirectTarget('example.com', 'https://evil.test/') === null
	&& KloxoSitepreview::redirectTarget('example.com', '//evil.test/') === null,
	'redirectTarget stays on domain / root-relative only');

echo "== sitepreview.php entry\n";
$sp = (string) file_get_contents($doc . '/theme/lbin/sitepreview.php');
$hia = (string) file_get_contents($repo . '/kloxo/init/hiawatha.conf.base');
check(str_contains($sp, 'KloxoSitepreview::fetch')
	&& str_contains($sp, 'initProgram')
	&& str_contains($sp, 'loginMayPreview')
	&& !str_contains($sp, 'addtoEtcHost')
	&& !str_contains($sp, 'hn_urlrewrite')
	&& !str_contains($sp, 'curl_general_get'),
	'sitepreview.php uses KloxoSitepreview, login, no /etc/hosts or hn_urlrewrite');
check(str_contains($hia, 'ErrorHandler = 404:/theme/lbin/sitepreview.php'),
	'Hiawatha still routes 404 to sitepreview.php');

$lib = (string) file_get_contents($doc . '/lib/domain/domainlib.php');
check(str_contains($lib, '/sitepreview/[%s]')
	&& str_contains($lib, '__stub_domain_preview_url'),
	'Domains list DNSless Preview icon still points at /sitepreview/');

KloxoSitepreview::$httpGet = null;
KloxoSitepreview::$resolveDomain = null;
KloxoSitepreview::$loopbackPorts = null;
KloxoSitepreview::$previewIps = null;
KloxoSitepreview::$webDriver = null;
KloxoSitepreview::$webcacheDriver = null;

echo "\n{$ok} ok, {$fail} fail\n";
exit($fail > 0 ? 1 : 0);
