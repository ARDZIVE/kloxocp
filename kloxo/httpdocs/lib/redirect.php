<?php
/**
 * Pre-login host/SSL redirect. Must not reflect raw REQUEST_URI into HTML/JS
 * (security V3). Prefer an HTTP Location header with a validated URL.
 */

$kloxopath = '/opt/kloxo';
$initpath = "{$kloxopath}/init";
$loginpath = "{$kloxopath}/httpdocs/login";

$a = $_SERVER;
$state = 0;

$host = isset($a['HTTP_HOST']) ? (string) $a['HTTP_HOST'] : '';
$splitter = explode(':', $host);
$domain = isset($splitter[0]) ? (string) $splitter[0] : '';
$port = (isset($splitter[1]) && $splitter[1] !== '') ? (string) $splitter[1] : '7778';
$requesturi = isset($a['REQUEST_URI']) ? (string) $a['REQUEST_URI'] : '/';
$scheme = isset($a['HTTP_SCHEME']) ? strtolower((string) $a['HTTP_SCHEME']) : '';
if ($scheme !== 'http' && $scheme !== 'https') {
	$https = !empty($a['HTTPS']) && (string) $a['HTTPS'] !== 'off';
	$scheme = $https ? 'https' : 'http';
}

$domain_pure = preg_replace('/(cp\.|webmail\.|www\.|mail\.)(.*)/i', '$2', $domain);
if (is_string($domain_pure) && $domain_pure !== '' && $domain_pure !== $domain) {
	$state += 1;
	$domain = $domain_pure;
}

if (file_exists("{$loginpath}/redirect-to-ssl")) {
	if ($scheme === 'http') {
		$state += 2;
		$sslPort = @file_get_contents("{$initpath}/port-ssl");
		$port = is_string($sslPort) ? trim($sslPort) : '7777';
		$scheme = 'https';
	}
}

if (file_exists("{$loginpath}/redirect-to-domain")) {
	$forced = @file_get_contents("{$loginpath}/redirect-to-domain");
	$forced = is_string($forced) ? trim($forced) : '';
	if ($forced !== '') {
		$domain = $forced;
		if ($domain . ':' . $port !== $host) {
			$state += 4;
		}
	}
}

if ($state === 0) {
	return;
}

// Strict URL pieces only — refuse open redirects / XSS via Host or URI.
if (!preg_match('/^[A-Za-z0-9.-]{1,253}$/', $domain)) {
	return;
}
if (!preg_match('/^[0-9]{1,5}$/', $port) || (int) $port < 1 || (int) $port > 65535) {
	$port = ($scheme === 'https') ? '7777' : '7778';
}
if ($requesturi === '' || $requesturi[0] !== '/'
	|| strpbrk($requesturi, "\r\n\0") !== false
	|| preg_match('/^[\\s\\S]*javascript:/i', $requesturi)) {
	$requesturi = '/';
}
// Cap length; strip characters that break Location / JS string contexts.
$requesturi = substr($requesturi, 0, 2048);
$requesturi = preg_replace('/[^A-Za-z0-9._~!$&\'()*+,;=:@\\/%?#[\\]-]/', '', $requesturi) ?? '/';
if ($requesturi === '' || $requesturi[0] !== '/') {
	$requesturi = '/';
}

$url = $scheme . '://' . $domain . ':' . $port . $requesturi;
if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
	return;
}

header('Location: ' . $url, true, 302);
exit;
