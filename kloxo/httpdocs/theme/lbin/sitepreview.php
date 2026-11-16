<?php
declare(strict_types=1);

/**
 * DNSless Preview: /sitepreview/<domain>/… (Hiawatha 404 ErrorHandler).
 * Requires a panel login; fetches the site on loopback with Host: domain
 * (no /etc/hosts). Rewrites HTML so links stay under /sitepreview/.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

chdir('../../');
include_once 'lib/html/displayinclude.php';
include_once 'lib/php/kloxo_sitepreview.php';

$request = (string) ($_SERVER['REQUEST_URI'] ?? '');

// Hiawatha routes every 404 here; only handle /sitepreview/.
$parsed = KloxoSitepreview::parseRequestUri($request);
if ($parsed === null) {
	http_response_code(404);
	header('Content-Type: text/plain; charset=UTF-8');
	echo "404 Not Found\n";
	exit;
}

try {
	initProgram();
} catch (Throwable $e) {
	http_response_code(403);
	header('Content-Type: text/html; charset=UTF-8');
	$loginUrl = '/login/?frm_emessage=session_timeout';
	echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Login required</title></head><body>';
	echo '<p>DNSless Preview needs a panel login. <a href="'
		. htmlspecialchars($loginUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
		. '">Sign in</a>.</p></body></html>';
	exit;
}

global $login;
if (!is_object($login)) {
	http_response_code(403);
	header('Content-Type: text/plain; charset=UTF-8');
	echo "Forbidden\n";
	exit;
}

$domain = $parsed['domain'];
$path = $parsed['path'];
$query = $parsed['query'];

try {
	$meta = KloxoSitepreview::lookupDomain($domain);
} catch (KloxoSitepreviewException $e) {
	http_response_code(404);
	header('Content-Type: text/plain; charset=UTF-8');
	echo "Domain not found\n";
	exit;
}

if (!KloxoSitepreview::loginMayPreview($login, $meta['parent_clname'])) {
	http_response_code(403);
	header('Content-Type: text/plain; charset=UTF-8');
	echo "Forbidden\n";
	exit;
}

try {
	$got = KloxoSitepreview::fetch($domain, $path, $query);
} catch (KloxoSitepreviewException $e) {
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	echo "Bad preview request\n";
	exit;
}

$code = (int) ($got['code'] ?? 502);
$body = (string) ($got['body'] ?? '');
$ctype = (string) ($got['ctype'] ?? '');
if ($ctype === '' || $ctype === 'application/octet-stream') {
	$ctype = KloxoSitepreview::contentTypeForPath($path);
}

if ($code < 100 || $code > 599) {
	$code = 502;
}
http_response_code($code);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'none'; img-src data: https: http:; style-src 'unsafe-inline' https: http:; font-src https: http: data:; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");

if (KloxoSitepreview::isRewritableHtml($ctype, $path)) {
	header('Content-Type: text/html; charset=UTF-8');
	echo KloxoSitepreview::rewriteHtml($domain, $body);
	exit;
}

// Non-HTML (security R5): only safe image/css/font/plain; never JS/SVG/HTML-as-image.
$ctypeLower = strtolower($ctype);
if (str_contains($ctypeLower, 'javascript') || str_contains($ctypeLower, 'svg')
	|| str_contains($ctypeLower, 'html') || str_contains($ctypeLower, 'xml')) {
	header('Content-Type: text/plain; charset=UTF-8');
	echo "Blocked content type in preview\n";
	exit;
}
$safeType = preg_match('#^(image/(png|jpe?g|gif|webp)|text/(css|plain)|font/)#i', $ctype) === 1;
if (!$safeType) {
	header('Content-Type: application/octet-stream');
	header('Content-Disposition: attachment');
	echo $body;
	exit;
}

header('Content-Type: ' . $ctype);
echo $body;
