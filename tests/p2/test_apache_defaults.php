<?php
declare(strict_types=1);

/**
 * Apache catch-all must not use ServerAlias *: that steals named Hosts on 2.4.
 * php-cli only; no live Apache.
 */

$root = dirname(__DIR__, 2);
$tpl = $root . '/kloxo/file/apache/tpl/defaults.conf.tpl';
$pass = 0;
$fail = 0;
$ok = static function (string $m) use (&$pass): void {
	$pass++;
	fwrite(STDOUT, "  ok   $m\n");
};
$bad = static function (string $m, string $extra = '') use (&$fail): void {
	$fail++;
	fwrite(STDOUT, "  FAIL $m\n");
	if ($extra !== '') {
		fwrite(STDOUT, '       ' . str_replace("\n", "\n       ", $extra) . "\n");
	}
};

if (!is_readable($tpl)) {
	$bad('defaults.conf.tpl readable');
	fwrite(STDOUT, "$pass passed, $fail failed\n");
	exit(1);
}

$src = (string) file_get_contents($tpl);
if (!preg_match('/^\s*ServerAlias\s+\*/m', $src)) {
	$ok('source has no ServerAlias *');
} else {
	$bad('source has no ServerAlias *');
}

$doc = $root . '/kloxo/httpdocs';
$parse = $doc . '/lib/html/lib.php';
if (!is_readable($parse)) {
	$bad('panel lib for getParseInlinePhp');
	fwrite(STDOUT, "$pass passed, $fail failed\n");
	exit($fail > 0 ? 1 : 0);
}

// Lightweight render: include the tpl with extract() like fixweb.
$tmpdir = sys_get_temp_dir() . '/kloxo-apache-def-' . getmypid();
@mkdir($tmpdir, 0700, true);
$defaultdocroot = $tmpdir . '/default';
$indexorder = 'index.php index.html';
$httpPort = 80;
$httpsPort = 443;
$bind = '*';
$webcache = 'none';
$certnamelist = array();
ob_start();
include $tpl;
$out = (string) ob_get_clean();

if (str_contains($out, 'ServerName _') && str_contains($out, 'DocumentRoot "' . $defaultdocroot . '"')) {
	$ok('rendered catch-all has ServerName _ and DocumentRoot');
} else {
	$bad('rendered catch-all has ServerName _ and DocumentRoot', $out);
}
if (!preg_match('/^\s*ServerAlias\s+\*/m', $out)) {
	$ok('rendered catch-all has no ServerAlias *');
} else {
	$bad('rendered catch-all has no ServerAlias *', $out);
}
if (is_file($defaultdocroot . '/index.html')) {
	$ok('render seeds default index.html');
} else {
	$bad('render seeds default index.html');
}

$outCache = '';
$bind = '127.0.0.1';
$httpPort = 8081;
$httpsPort = 8444;
$webcache = 'nginx';
ob_start();
include $tpl;
$outCache = (string) ob_get_clean();
if (str_contains($outCache, '<VirtualHost 127.0.0.1:8081>') && !preg_match('/^\s*ServerAlias\s+\*/m', $outCache)) {
	$ok('webcache catch-all keeps loopback listen and no ServerAlias *');
} else {
	$bad('webcache catch-all keeps loopback listen and no ServerAlias *', $outCache);
}

if (str_contains($src, 'KloxoSsl::siteCertBase')) {
	$ok('defaults tpl uses siteCertBase');
} else {
	$bad('defaults tpl uses siteCertBase');
}
if (str_contains($src, "strpos((string) \$certbase, '*')")
	|| str_contains($src, 'Never use literal')) {
	$ok('defaults tpl refuses literal star cert basename');
} else {
	$bad('defaults tpl refuses literal star cert basename');
}

// Star preferred must not become /home/kloxo/ssl/* in the rendered SSL block.
$sslSrc = $root . '/kloxo/httpdocs/lib/php/kloxo_ssl.php';
if (is_readable($sslSrc)) {
	require_once $sslSrc;
	$fs = $tmpdir . '/fs';
	@mkdir($fs . '/home/kloxo/ssl', 0755, true);
	@mkdir($fs . '/opt/kloxo/file/ssl', 0755, true);
	file_put_contents($fs . '/opt/kloxo/file/ssl/default.key', "KEY\n");
	file_put_contents($fs . '/opt/kloxo/file/ssl/default.crt', "CRT\n");
	file_put_contents($fs . '/opt/kloxo/file/ssl/default.pem', "KEY\nCRT\n");
	file_put_contents($fs . '/home/kloxo/ssl/default.key', "KEY\n");
	file_put_contents($fs . '/home/kloxo/ssl/default.pem', "KEY\nCRT\n");
	KloxoSsl::$fsPrefix = $fs;
	$certnamelist = array('*' => '*');
	$bind = '*';
	$httpPort = 80;
	$httpsPort = 443;
	$webcache = 'none';
	$defaultdocroot = $tmpdir . '/default2';
	ob_start();
	include $tpl;
	$starOut = (string) ob_get_clean();
	KloxoSsl::$fsPrefix = '';
	if (!str_contains($starOut, '/home/kloxo/ssl/*.')
		&& (str_contains($starOut, '/home/kloxo/ssl/default.pem')
			|| str_contains($starOut, '/home/kloxo/ssl/default.crt')
			|| !str_contains($starOut, 'SSLCertificateFile'))) {
		$ok('star certnamelist does not render SSLCertificate* with *.pem');
	} else {
		$bad('star certnamelist does not render SSLCertificate* with *.pem', $starOut);
	}
	@unlink($defaultdocroot . '/index.html');
	@rmdir($defaultdocroot . '/.well-known/acme-challenge');
	@rmdir($defaultdocroot . '/.well-known');
	@rmdir($defaultdocroot);
}

@unlink($tmpdir . '/default/index.html');
@rmdir($tmpdir . '/default/.well-known/acme-challenge');
@rmdir($tmpdir . '/default/.well-known');
@rmdir($tmpdir . '/default');
@rmdir($tmpdir);

fwrite(STDOUT, "$pass passed, $fail failed\n");
exit($fail > 0 ? 1 : 0);
