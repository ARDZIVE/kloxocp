<?php
declare(strict_types=1);

/**
 * DNSless site preview: show a hosted site via /sitepreview/<domain>/
 * without public DNS. Fetches 127.0.0.1 with a Host header (no /etc/hosts).
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoSitepreviewException extends RuntimeException
{
}

final class KloxoSitepreview
{
	/** @var callable|null fn(string $domain): ?array{parent_clname?:string, syncserver?:string} */
	public static $resolveDomain = null;

	/** @var callable|null fn(string $url, array $headers): array{code:int,body:string,ctype:string,headers:array<string,string>} */
	public static $httpGet = null;

	/**
	 * Ports to try. Null = derive from Switch Program web/webcache
	 * (site :80, webcache back :8081, cache :8080).
	 *
	 * @var list<int>|null
	 */
	public static ?array $loopbackPorts = null;

	/**
	 * IPs to try. Null = 127.0.0.1 then global IPv4s (Apache often binds only
	 * 127.0.0.1:80 while Nginx listens on the public address).
	 *
	 * @var list<string>|null
	 */
	public static ?array $previewIps = null;

	/** Override Switch Program web driver for tests: nginx|apache|null=auto. */
	public static ?string $webDriver = null;

	/** Override Switch Program webcache driver for tests: none|nginx|null=auto. */
	public static ?string $webcacheDriver = null;

	public static function isDomainName(string $domain): bool
	{
		$domain = strtolower(trim($domain));
		if ($domain === '' || strlen($domain) > 253) {
			return false;
		}
		if (filter_var($domain, FILTER_VALIDATE_IP)) {
			return false;
		}
		if (preg_match('/[^a-z0-9.-]/', $domain) === 1) {
			return false;
		}
		if (str_contains($domain, '..') || $domain[0] === '-' || str_ends_with($domain, '-')
			|| $domain[0] === '.' || str_ends_with($domain, '.')) {
			return false;
		}

		return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i', $domain) === 1;
	}

	/**
	 * @return array{domain:string,path:string,query:?string}|null
	 */
	public static function parseRequestUri(string $uri): ?array
	{
		$uri = trim($uri);
		if ($uri === '' || !str_contains($uri, 'sitepreview/')) {
			return null;
		}
		$path = parse_url($uri, PHP_URL_PATH);
		$query = parse_url($uri, PHP_URL_QUERY);
		if (!is_string($path) || $path === '') {
			// REQUEST_URI without scheme: path may be the whole string before ?
			$qpos = strpos($uri, '?');
			$path = $qpos === false ? $uri : substr($uri, 0, $qpos);
			$query = $qpos === false ? null : substr($uri, $qpos + 1);
		}
		$path = rawurldecode($path);
		if (preg_match('#(?:^|/)sitepreview/([^/]+)(/[^?]*)?$#', $path, $m) !== 1
			&& preg_match('#(?:^|/)sitepreview/([^/]+)(/[^?]*)?#', $path, $m) !== 1) {
			return null;
		}
		$domain = strtolower((string) $m[1]);
		if (!self::isDomainName($domain)) {
			return null;
		}
		$rest = isset($m[2]) && $m[2] !== '' ? (string) $m[2] : '/';
		if (!str_starts_with($rest, '/')) {
			$rest = '/' . $rest;
		}
		foreach (explode('/', $rest) as $seg) {
			if ($seg === '..') {
				return null;
			}
		}
		$q = is_string($query) && $query !== '' ? $query : null;

		return array('domain' => $domain, 'path' => $rest, 'query' => $q);
	}

	/**
	 * @param object|null $login
	 */
	public static function loginMayPreview($login, string $parentClname): bool
	{
		if (!is_object($login)) {
			return false;
		}
		if (method_exists($login, 'isAdmin') && $login->isAdmin()) {
			return true;
		}
		$cl = '';
		if (method_exists($login, 'getClName')) {
			$cl = (string) $login->getClName();
		} elseif (isset($login->nname) && method_exists($login, 'get__table')) {
			$cl = (string) $login->get__table() . '-' . (string) $login->nname;
		}

		return $cl !== '' && $parentClname !== '' && $parentClname === $cl;
	}

	/**
	 * @return array{parent_clname:string, syncserver:string}
	 */
	public static function lookupDomain(string $domain): array
	{
		if (!self::isDomainName($domain)) {
			throw new KloxoSitepreviewException('invalid domain');
		}
		if (self::$resolveDomain !== null) {
			$row = (self::$resolveDomain)($domain);
			if (!is_array($row)) {
				throw new KloxoSitepreviewException('domain not found');
			}

			return array(
				'parent_clname' => (string) ($row['parent_clname'] ?? ''),
				'syncserver' => (string) ($row['syncserver'] ?? 'localhost'),
			);
		}
		if (!class_exists('Sqlite', false)) {
			throw new KloxoSitepreviewException('domain not found');
		}
		$sq = new Sqlite(null, 'web');
		// Domain was charset-validated; getRows quotes (security SS1).
		$res = $sq->getRows('nname', $domain);
		if (!is_array($res) || $res === array() || !isset($res[0])) {
			$sqd = new Sqlite(null, 'domain');
			$res = $sqd->getRows('nname', $domain);
		}
		if (!is_array($res) || $res === array() || !isset($res[0])) {
			throw new KloxoSitepreviewException('domain not found');
		}
		$row = $res[0];

		return array(
			'parent_clname' => (string) ($row['parent_clname'] ?? ''),
			'syncserver' => (string) ($row['syncserver'] ?? 'localhost'),
		);
	}

	public static function contentTypeForPath(string $path): string
	{
		$ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));
		$map = array(
			'html' => 'text/html; charset=UTF-8',
			'htm' => 'text/html; charset=UTF-8',
			'css' => 'text/css; charset=UTF-8',
			'js' => 'application/javascript; charset=UTF-8',
			'json' => 'application/json',
			'png' => 'image/png',
			'jpg' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'gif' => 'image/gif',
			'webp' => 'image/webp',
			'svg' => 'image/svg+xml',
			'ico' => 'image/x-icon',
			'woff' => 'font/woff',
			'woff2' => 'font/woff2',
			'txt' => 'text/plain; charset=UTF-8',
			'xml' => 'application/xml',
			'pdf' => 'application/pdf',
		);
		if ($ext === '' || $ext === 'php' || $ext === 'php8') {
			return 'text/html; charset=UTF-8';
		}

		return $map[$ext] ?? 'application/octet-stream';
	}

	public static function isRewritableHtml(string $ctype, string $path): bool
	{
		$ctype = strtolower($ctype);
		if (str_contains($ctype, 'text/html') || str_contains($ctype, 'application/xhtml')) {
			return true;
		}
		$ext = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?: $path, PATHINFO_EXTENSION));

		return $ext === '' || $ext === 'html' || $ext === 'htm' || $ext === 'php' || $ext === 'php8';
	}

	/**
	 * Rewrite absolute and root-relative links so navigation stays under /sitepreview/<domain>/.
	 */
	public static function rewriteHtml(string $domain, string $html): string
	{
		if (!self::isDomainName($domain) || $html === '') {
			return $html;
		}
		$base = '/sitepreview/' . $domain;
		$quoted = preg_quote($domain, '#');
		$html = preg_replace(
			'#https?://(?:www\.)?' . $quoted . '(?::\d+)?#i',
			$base,
			$html
		) ?? $html;

		$html = preg_replace_callback(
			'#\b(href|src|action|poster|data)=([\'"])/(?!/|sitepreview/)([^\'"]*)\2#i',
			static function (array $m) use ($base): string {
				return $m[1] . '=' . $m[2] . $base . '/' . ltrim($m[3], '/') . $m[2];
			},
			$html
		) ?? $html;

		// srcset: /img.png 1x, /img2.png 2x
		$html = preg_replace_callback(
			'#\bsrcset=([\'"])([^\'"]+)\1#i',
			static function (array $m) use ($base): string {
				$parts = preg_split('/\s*,\s*/', $m[2]) ?: array();
				$out = array();
				foreach ($parts as $part) {
					$part = trim($part);
					if ($part === '') {
						continue;
					}
					if (preg_match('#^/(?!/|sitepreview/)(\S+)(.*)$#', $part, $pm) === 1) {
						$out[] = $base . '/' . ltrim($pm[1], '/') . $pm[2];
					} else {
						$out[] = $part;
					}
				}

				return 'srcset=' . $m[1] . implode(', ', $out) . $m[1];
			},
			$html
		) ?? $html;

		// Strip active content so a malicious site cannot run script in the
		// panel origin (security N8). Preview is informational only.
		$html = self::sanitizePreviewHtml($html);

		return $html;
	}

	/** Remove scripts, event handlers, and dangerous URLs from preview HTML. */
	public static function sanitizePreviewHtml(string $html): string
	{
		$html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
		$html = preg_replace('#<iframe\b[^>]*>.*?</iframe>#is', '', $html) ?? $html;
		$html = preg_replace('#<object\b[^>]*>.*?</object>#is', '', $html) ?? $html;
		$html = preg_replace('#<embed\b[^>]*/?>#is', '', $html) ?? $html;
		$html = preg_replace('#<link\b[^>]*rel\s*=\s*([\'"])?import\1[^>]*>#is', '', $html) ?? $html;
		$html = preg_replace('#\son[a-z]+\s*=\s*([\'"]).*?\1#is', '', $html) ?? $html;
		$html = preg_replace('#\son[a-z]+\s*=\s*[^\s>]+#is', '', $html) ?? $html;
		$html = preg_replace('#\b(href|src|action|xlink:href)\s*=\s*([\'"])\s*javascript:[^\'"]*\2#is', '$1=$2#$2', $html) ?? $html;
		$html = preg_replace('#\b(href|src|action)\s*=\s*javascript:[^\s>]+#is', '$1="#"', $html) ?? $html;
		$html = preg_replace('#<meta\b[^>]*http-equiv\s*=\s*([\'"])?refresh\1[^>]*>#is', '', $html) ?? $html;
		$html = preg_replace('#<base\b[^>]*>#is', '', $html) ?? $html;

		return $html;
	}

	/** Switch Program web driver (nginx|apache). */
	public static function activeWebDriver(): string
	{
		if (self::$webDriver === 'nginx' || self::$webDriver === 'apache') {
			return self::$webDriver;
		}
		$d = self::switchDrivers();
		$w = strtolower((string) ($d['web'] ?? 'nginx'));

		return $w === 'apache' ? 'apache' : 'nginx';
	}

	/** Switch Program webcache driver (none|nginx). */
	public static function activeWebcacheDriver(): string
	{
		if (self::$webcacheDriver === 'nginx' || self::$webcacheDriver === 'none') {
			return self::$webcacheDriver;
		}
		$d = self::switchDrivers();
		$w = strtolower((string) ($d['webcache'] ?? 'none'));

		return $w === 'nginx' ? 'nginx' : 'none';
	}

	/**
	 * @return array<string,string>
	 */
	public static function switchDrivers(): array
	{
		$root = '';
		if (function_exists('kloxo_program_root')) {
			$r = kloxo_program_root();
			$root = is_string($r) ? $r : '';
		}
		if ($root === '') {
			$root = dirname(__DIR__, 3);
		}
		if (class_exists('KloxoWatchdog', false)) {
			return KloxoWatchdog::drivers($root);
		}
		$file = rtrim($root, '/') . '/etc/slavedb/driver';
		if (!is_file($file)) {
			return array();
		}
		$raw = @file_get_contents($file);
		$o = is_string($raw) && $raw !== ''
			? @unserialize($raw, array('allowed_classes' => false)) : false;
		$a = is_object($o) ? (array) $o : (is_array($o) ? $o : array());
		$data = $a['data'] ?? array();
		$out = array();
		if (is_array($data)) {
			foreach ($data as $k => $v) {
				if (is_string($k) && is_string($v) && preg_match('/^[a-z0-9-]+$/', $v) === 1) {
					$out[$k] = $v;
				}
			}
		}

		return $out;
	}

	/**
	 * Port order for the active web stack. Prefer the site back-end (8081)
	 * when a front webcache owns :80, so preview does not hit a leftover
	 * Apache default page still bound to 80.
	 *
	 * @return list<int>
	 */
	public static function previewPorts(): array
	{
		if (self::$loopbackPorts !== null) {
			return self::$loopbackPorts;
		}
		$web = self::activeWebDriver();
		$cache = self::activeWebcacheDriver();
		if ($cache === 'nginx') {
			// Cache/front on 80/443; real site server on 8081.
			return array(8081, 80, 8080);
		}
		// Prefer :80 for the selected stack; keep fallbacks if a leftover
		// unit still answers first with the wrong Server header.
		if ($web === 'apache') {
			return array(80, 8081, 8080);
		}

		return array(80, 8081, 8080);
	}

	/**
	 * Addresses to fetch. Loopback first, then global IPv4s — a leftover
	 * Apache `Listen 127.0.0.1:80` otherwise shadows Nginx on the public IP.
	 *
	 * @return list<string>
	 */
	public static function previewAddresses(): array
	{
		if (self::$previewIps !== null) {
			return self::$previewIps;
		}
		$out = array('127.0.0.1');
		foreach (self::globalIpv4Addresses() as $ip) {
			if ($ip !== '' && $ip !== '127.0.0.1' && !in_array($ip, $out, true)) {
				$out[] = $ip;
			}
		}

		return $out;
	}

	/**
	 * @return list<string>
	 */
	public static function globalIpv4Addresses(): array
	{
		$out = array();
		$bin = is_executable('/usr/bin/ip') ? '/usr/bin/ip'
			: (is_executable('/sbin/ip') ? '/sbin/ip' : '');
		if ($bin === '') {
			return $out;
		}
		$argv = array($bin, '-4', '-o', 'addr', 'show', 'scope', 'global');
		$spec = array(
			0 => array('file', '/dev/null', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$p = @proc_open($argv, $spec, $pipes);
		if (!is_resource($p)) {
			return $out;
		}
		$raw = (string) stream_get_contents($pipes[1]);
		stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($p);
		foreach (preg_split("/\r\n|\n|\r/", $raw) ?: array() as $line) {
			if (preg_match('/\binet\s+(\d{1,3}(?:\.\d{1,3}){3})\b/', $line, $m) !== 1) {
				continue;
			}
			$ip = $m[1];
			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
				&& !str_starts_with($ip, '127.')
				&& !str_starts_with($ip, '169.254.')) {
				$out[] = $ip;
			}
		}

		return array_values(array_unique($out));
	}

	/**
	 * True when the response is clearly from the other web stack.
	 * Use the Server header only — Nginx often still serves the leftover
	 * /var/www/html "Apache2 Ubuntu Default Page" HTML after apache2 was
	 * removed; matching that text falsely blocked every preview.
	 *
	 * @param array{code?:int,body?:string,ctype?:string,headers?:array<string,string>} $got
	 */
	public static function isWrongStackResponse(string $webDriver, array $got): bool
	{
		$server = strtolower((string) (($got['headers']['server'] ?? '')));
		if ($server === '') {
			return false;
		}
		if ($webDriver === 'nginx') {
			return str_contains($server, 'apache');
		}
		if ($webDriver === 'apache') {
			return str_contains($server, 'nginx') && !str_contains($server, 'apache');
		}

		return false;
	}

	/**
	 * @return array{code:int,body:string,ctype:string}
	 */
	public static function fetch(string $domain, string $path, ?string $query = null): array
	{
		if (!self::isDomainName($domain)) {
			throw new KloxoSitepreviewException('invalid domain');
		}
		$path = $path === '' ? '/' : $path;
		if (!str_starts_with($path, '/')) {
			$path = '/' . $path;
		}
		foreach (explode('/', $path) as $seg) {
			if ($seg === '..') {
				throw new KloxoSitepreviewException('invalid path');
			}
		}
		$suffix = $path;
		if ($query !== null && $query !== '') {
			$suffix .= '?' . $query;
		}
		$headers = array(
			'Host: ' . $domain,
			'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
			'User-Agent: KloxoCP-Sitepreview/1',
			'Connection: close',
		);
		$web = self::activeWebDriver();
		$last = array('code' => 502, 'body' => '', 'ctype' => 'text/plain; charset=UTF-8');
		$wrong = null;
		$tried = array();
		foreach (self::previewAddresses() as $ip) {
			if (!is_string($ip) || $ip === '' || str_contains($ip, ':')) {
				// IPv4 only for simple http://IP:port URLs.
				continue;
			}
			if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
				continue;
			}
			foreach (self::previewPorts() as $port) {
				$port = (int) $port;
				if ($port < 1 || $port > 65535) {
					continue;
				}
				$tried[] = "{$ip}:{$port}";
				$url = 'http://' . $ip . ':' . $port . $suffix;
				$got = self::httpGet($url, $headers);
				$code = (int) ($got['code'] ?? 0);
				if ($code === 0 || $code === 502 || $code === 503 || $code === 504) {
					$last = array(
						'code' => $code > 0 ? $code : 502,
						'body' => (string) ($got['body'] ?? ''),
						'ctype' => (string) ($got['ctype'] ?? 'text/plain; charset=UTF-8'),
					);
					continue;
				}
				// Follow one local redirect that stays on this domain.
				if (in_array($code, array(301, 302, 303, 307, 308), true)) {
					$loc = (string) ($got['headers']['location'] ?? '');
					$next = self::redirectTarget($domain, $loc);
					if ($next !== null) {
						$url2 = 'http://' . $ip . ':' . $port . $next;
						$got2 = self::httpGet($url2, $headers);
						$code2 = (int) ($got2['code'] ?? 0);
						if ($code2 > 0 && $code2 < 500) {
							if (self::isWrongStackResponse($web, $got2)) {
								$wrong = $got2;
								continue;
							}

							return array(
								'code' => $code2,
								'body' => (string) ($got2['body'] ?? ''),
								'ctype' => (string) ($got2['ctype'] ?? self::contentTypeForPath($next)),
							);
						}
					}
				}

				if (self::isWrongStackResponse($web, $got)) {
					$wrong = $got;
					continue;
				}

				return array(
					'code' => $code,
					'body' => (string) ($got['body'] ?? ''),
					'ctype' => (string) ($got['ctype'] ?? self::contentTypeForPath($path)),
				);
			}
		}

		// Only the wrong stack answered (e.g. Apache on 127.0.0.1:80 while
		// Nginx listens on the public IP — or Apache still owns *:80).
		if (is_array($wrong) && $web === 'nginx') {
			$triedTxt = htmlspecialchars(implode(', ', array_slice($tried, 0, 12)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

			return array(
				'code' => 502,
				'body' => '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Preview</title></head><body style="font:14px/1.4 system-ui,sans-serif;max-width:40rem;margin:2rem">'
					. '<p>DNSless Preview only reached an <b>Apache</b> default page, '
					. 'but Switch Program Web is <b>Nginx</b>.</p>'
					. '<p>Usual cause: leftover <code>apache2</code> still bound to port 80 '
					. '(sometimes only on <code>127.0.0.1</code>), so preview never hits Nginx.</p>'
					. '<p>On the server run:</p>'
					. '<pre style="background:#f4f4f4;padding:0.75rem;overflow:auto">'
					. "sudo systemctl disable --now apache2\n"
					. "sudo systemctl enable --now nginx\n"
					. "sudo ss -lptn 'sport = :80'</pre>"
					. '<p>Confirm <code>ss</code> shows <b>nginx</b> on :80 (not apache2), then retry Preview.</p>'
					. '<p style="color:#666;font-size:12px">Tried: ' . $triedTxt . '</p>'
					. '</body></html>',
				'ctype' => 'text/html; charset=UTF-8',
			);
		}

		return $last;
	}

	/** @return string|null path+query on this domain */
	public static function redirectTarget(string $domain, string $location): ?string
	{
		$location = trim($location);
		if ($location === '') {
			return null;
		}
		if (str_starts_with($location, '/')) {
			if (str_starts_with($location, '//')) {
				return null;
			}
			foreach (explode('/', parse_url($location, PHP_URL_PATH) ?: $location) as $seg) {
				if ($seg === '..') {
					return null;
				}
			}

			return $location;
		}
		$parts = parse_url($location);
		if (!is_array($parts) || empty($parts['host'])) {
			return null;
		}
		$host = strtolower((string) $parts['host']);
		if ($host !== strtolower($domain) && $host !== 'www.' . strtolower($domain)
			&& $host !== '127.0.0.1' && $host !== 'localhost') {
			return null;
		}
		$path = (string) ($parts['path'] ?? '/');
		if ($path === '') {
			$path = '/';
		}
		if (!empty($parts['query'])) {
			$path .= '?' . $parts['query'];
		}

		return $path;
	}

	/**
	 * @param list<string> $headers
	 * @return array{code:int,body:string,ctype:string,headers:array<string,string>}
	 */
	public static function httpGet(string $url, array $headers): array
	{
		if (self::$httpGet !== null) {
			$r = (self::$httpGet)($url, $headers);

			return array(
				'code' => (int) ($r['code'] ?? 0),
				'body' => (string) ($r['body'] ?? ''),
				'ctype' => (string) ($r['ctype'] ?? 'application/octet-stream'),
				'headers' => is_array($r['headers'] ?? null) ? $r['headers'] : array(),
			);
		}
		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			if ($ch === false) {
				return array('code' => 0, 'body' => '', 'ctype' => '', 'headers' => array());
			}
			$rawHeaders = '';
			curl_setopt_array($ch, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER => true,
				CURLOPT_HTTPHEADER => $headers,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT => 45,
				CURLOPT_FOLLOWLOCATION => false,
				// Loopback self-signed site certs: verify_peer with allow self-signed (N16).
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 0,
				CURLOPT_SSL_OPTIONS => defined('CURLSSLOPT_ALLOW_BEAST') ? 0 : 0,
			));
			// PHP curl has no CURLOPT_SSL_ALLOW_SELF_SIGNED; for 127.0.0.1 we
			// still need peer false when the site uses a panel self-signed cert.
			$host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
			if ($host === '127.0.0.1' || $host === 'localhost' || $host === '::1') {
				curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
			}
			$raw = curl_exec($ch);
			$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
			if (function_exists('kloxo_curl_close')) {
				kloxo_curl_close($ch);
			} else {
				curl_close($ch);
			}
			if (!is_string($raw)) {
				return array('code' => 0, 'body' => '', 'ctype' => '', 'headers' => array());
			}
			$hdrBlock = substr($raw, 0, $headerSize);
			$body = substr($raw, $headerSize);
			$parsed = self::parseResponseHeaders($hdrBlock);

			return array(
				'code' => $code,
				'body' => is_string($body) ? $body : '',
				'ctype' => $parsed['content-type'] ?? 'application/octet-stream',
				'headers' => $parsed,
			);
		}
		$opts = array(
			'http' => array(
				'method' => 'GET',
				'header' => implode("\r\n", $headers) . "\r\n",
				'timeout' => 45,
				'ignore_errors' => true,
				'follow_location' => 0,
			),
		);
		$ctx = stream_context_create($opts);
		$body = @file_get_contents($url, false, $ctx);
		$parsed = array();
		$code = 0;
		if (isset($http_response_header) && is_array($http_response_header)) {
			$parsed = self::parseResponseHeaders(implode("\r\n", $http_response_header));
			if (isset($http_response_header[0])
				&& preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m) === 1) {
				$code = (int) $m[1];
			}
		}

		return array(
			'code' => $code,
			'body' => is_string($body) ? $body : '',
			'ctype' => $parsed['content-type'] ?? 'application/octet-stream',
			'headers' => $parsed,
		);
	}

	/**
	 * @return array<string,string>
	 */
	public static function parseResponseHeaders(string $raw): array
	{
		$out = array();
		foreach (preg_split("/\r\n|\n|\r/", $raw) ?: array() as $line) {
			$line = trim($line);
			if ($line === '' || !str_contains($line, ':')) {
				continue;
			}
			[$k, $v] = explode(':', $line, 2);
			$out[strtolower(trim($k))] = trim($v);
		}

		return $out;
	}
}
