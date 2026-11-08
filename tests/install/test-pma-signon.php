<?php
declare(strict_types=1);

/**
 * php-cli checks for phpMyAdmin panel sign-on. No MariaDB required.
 */

$root = dirname(__DIR__, 2);
$helper = $root . '/kloxo/httpdocs/lib/php/kloxo_pma.php';
if (!is_readable($helper)) {
	fwrite(STDERR, "missing kloxo_pma.php\n");
	exit(1);
}
require $helper;

$fail = 0;
$check = static function (bool $ok, string $msg) use (&$fail): void {
	if (!$ok) {
		fwrite(STDERR, "FAIL $msg\n");
		$fail++;
	}
};

$check(kloxo_pma_url() === '/thirdparty/phpMyAdmin/kloxo-signon.php', 'SQL Manager URL is phpMyAdmin sign-on');
$check(kloxo_pma_index_url() === '/thirdparty/phpMyAdmin/index.php', 'phpMyAdmin index is after logon');
$html = kloxo_pma_signon_form_html('/tmp/kloxo-pma-missing');
$check(str_contains($html, 'MySQL logon') && str_contains($html, 'name="pma_user"')
	&& str_contains($html, 'name="pma_pass"') && str_contains($html, 'Go'),
	'MySQL logon form has user, password and Go');
$check((bool)preg_match('/name="kloxo_pma_csrf" value="[0-9]{10}\.[a-f0-9]{64}"/', $html),
	'MySQL logon CSRF token does not need a PHP session');
$tok = kloxo_pma_csrf_issue('/tmp/kloxo-pma-missing');
$check(kloxo_pma_csrf_ok($tok, '/tmp/kloxo-pma-missing'), 'issued CSRF token verifies');
$check(!kloxo_pma_csrf_ok('nope', '/tmp/kloxo-pma-missing'), 'junk CSRF token is rejected');
$check(!kloxo_pma_csrf_ok('1000000000.' . str_repeat('a', 64), '/tmp/kloxo-pma-missing'),
	'tampered CSRF token is rejected');
$check(kloxo_pma_is_loopback('127.0.0.1') && kloxo_pma_is_loopback('::1')
	&& kloxo_pma_is_loopback('::ffff:127.0.0.1') && !kloxo_pma_is_loopback('8.8.8.8'),
	'loopback FastCGI REMOTE_ADDR is recognized');
$check(str_contains(kloxo_pma_link_attrs(), 'target="_blank"')
	&& str_contains(kloxo_pma_link_attrs(), 'noopener'), 'SQL Manager link opens a new tab');
$check(kloxo_pma_href(true, 'localhost') === '/thirdparty/phpMyAdmin/kloxo-signon.php', 'localhost SQL Manager href is phpMyAdmin sign-on');
$check(kloxo_pma_login_is_admin() === false, 'SQL Manager admin check is false without a login');
$pmaTile = kloxo_pma_action_object(true, 'localhost');
$check(is_object($pmaTile) && ($pmaTile->url ?? '') === kloxo_pma_url()
	&& str_contains((string) ($pmaTile->purl ?? ''), 'phpmyadmin')
	&& str_contains((string) ($pmaTile->target ?? ''), '_blank'),
	'SQL Manager action object is phpMyAdmin in a new tab');
$check(kloxo_pma_href(false, '') === '/thirdparty/phpMyAdmin/kloxo-signon.php', 'empty remote server stays phpMyAdmin sign-on');
$check(kloxo_pma_try_admin_sso('/tmp/kloxo-pma-missing') === false, 'SSO without a panel session does not redirect');
$check(kloxo_pma_same_origin_nav() === true, 'missing Referer is not treated as cross-site');
$_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
$check(kloxo_pma_same_origin_nav() === false, 'cross-site GET does not auto-SSO');
unset($_SERVER['HTTP_SEC_FETCH_SITE']);
$_SERVER['HTTPS'] = 'on';
$_SERVER['HTTP_HOST'] = '37.59.173.71:7777';
$_SERVER['SERVER_NAME'] = '37.59.173.71';
$_SERVER['SERVER_PORT'] = '7777';
$_SERVER['HTTP_REFERER'] = 'https://37.59.173.71:7777/display.php';
$check(kloxo_pma_same_origin_nav() === true, 'panel Referer is same-origin');
$_SERVER['HTTP_REFERER'] = 'https://evil.example/';
$check(kloxo_pma_same_origin_nav() === false, 'foreign Referer does not auto-SSO');
unset($_SERVER['HTTPS'], $_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME'], $_SERVER['SERVER_PORT'], $_SERVER['HTTP_REFERER']);
$src = (string)file_get_contents($helper);
$check(str_contains($src, 'kloxo_pma_try_admin_sso')
	&& str_contains($src, 'kloxo_pma_write_signon_and_redirect')
	&& str_contains($src, 'kloxo_pma_same_origin_nav'),
	'sign-on GET uses panel-admin SSO then phpMyAdmin');
$remote = kloxo_pma_href(false, 'bad host');
$check(str_contains($remote, 'kloxo-signon.php') && !str_contains($remote, ' '), 'unsafe remote host does not invent a URL');
$login = kloxo_pma_login('/tmp/kloxo-pma-missing');
$check($login['user'] === 'kloxo_pma', 'sign-on user is kloxo_pma (root@localhost is unix_socket: error 1698 over TCP)');
$check($login['host'] === '127.0.0.1', 'sign-on host is 127.0.0.1');
$check($login['pass'] === '', 'missing pma.pass does not invent a password');
$check(kloxo_pma_panel_is_admin('/tmp/kloxo-pma-missing') === false, 'no cookies is not admin');
$emptyCred = kloxo_pma_signon_credentials('/tmp/kloxo-pma-missing');
$check($emptyCred === array('', ''), 'SignonScript without a panel session returns no password');
$check(kloxo_pma_same_ip('127.0.0.1', '127.0.0.1'), 'same IPv4 matches');
$check(kloxo_pma_same_ip('127.0.0.1', '::ffff:127.0.0.1'), 'IPv4-mapped IPv6 matches');
$check(!kloxo_pma_same_ip('127.0.0.1', '8.8.8.8'), 'different IPs do not match');
$ck = kloxo_pma_signon_cookie_params();
$check(($ck['path'] ?? '') === '/' && ($ck['samesite'] ?? '') === 'Lax',
	'signon cookie is visible to phpMyAdmin index.php');

// Redirect-loop guard: a rejected MariaDB login must not bounce panel -> phpMyAdmin -> panel forever.
$bad = kloxo_pma_check_login('kloxo_nobody', 'wrong', '127.0.0.1');
$check(!extension_loaded('pdo_mysql') || str_contains($bad, 'MariaDB refused the login'),
	'a login MariaDB rejects is reported instead of being handed to phpMyAdmin');
ob_start();
$_COOKIE[kloxo_pma_sso_marker_name()] = (string)time();
$bounce = kloxo_pma_bounced_back();
$_COOKIE[kloxo_pma_sso_marker_name()] = (string)(time() - 60);
$stale = kloxo_pma_bounced_back();
unset($_COOKIE[kloxo_pma_sso_marker_name()]);
$none = kloxo_pma_bounced_back();
ob_end_clean();
$check(str_contains($bounce, 'phpMyAdmin') && $stale === '' && $none === '',
	'coming straight back from phpMyAdmin shows the form instead of signing in again');
$src2 = (string)file_get_contents($helper);
$check(str_contains($src2, 'kloxo_pma_bounced_back()') && str_contains($src2, 'kloxo_pma_check_login('),
	'sign-on page uses the login check and the bounce guard');

$tmp = sys_get_temp_dir() . '/kloxo-pma-' . bin2hex(random_bytes(4));
mkdir($tmp . '/etc/conf', 0750, true);
mkdir($tmp . '/session', 0750, true);
file_put_contents($tmp . '/etc/conf/pma.pass', "secret-pma-pass\n");
$with = kloxo_pma_login($tmp);
$check($with['pass'] === 'secret-pma-pass', 'reads pma.pass');
$check(strpos($with['pass'], '?') === false, 'password is not a URL query');

// Panel sessions are JSON files in <root>/session/<id> (not the ssession table).
$sid = 'AbC123xyzSESSIONid0123456789abcdefghijklmnopqrstuv';
$sess = static function (string $parent, int $last, string $ip = '127.0.0.1') use ($tmp, $sid): void {
	file_put_contents($tmp . '/session/' . $sid, json_encode(array(
		'nname' => $sid, 'parent_clname' => $parent, 'ip_address' => $ip,
		'last_access' => $last, 'timeout' => $last + 100,
	)));
};
$_COOKIE['kloxo-classname'] = 'client';
$_COOKIE['kloxo-clientname'] = 'admin';
$_COOKIE['kloxo-session-id'] = $sid;
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$check(kloxo_pma_panel_is_admin($tmp) === false, 'cookie without a session file is not admin');
$sess('client-admin', time());
$row = kloxo_pma_session_row($sid, $tmp);
$check(is_array($row) && ($row['parent_clname'] ?? '') === 'client-admin', 'session row is read from the session file');
$check(kloxo_pma_panel_is_admin($tmp) === true, 'live admin session file is admin');
$check(kloxo_pma_signon_credentials($tmp) === array('kloxo_pma', 'secret-pma-pass'),
	'SignonScript returns kloxo_pma + pma.pass for a live admin session');
$sess('client-bob', time());
$check(kloxo_pma_panel_is_admin($tmp) === false, 'non-admin session file is not admin');
$sess('client-admin', time() - 90000);
$check(kloxo_pma_panel_is_admin($tmp) === false, 'stale admin session file is not admin');
$sess('client-admin', time(), '203.0.113.9');
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$check(kloxo_pma_panel_is_admin($tmp) === false, 'admin session from another IP is not admin');
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_COOKIE['kloxo-session-id'] = '../etc/conf/pma.pass';
$check(kloxo_pma_panel_is_admin($tmp) === false, 'path-like session id is rejected');
$_COOKIE['kloxo-session-id'] = '..';
$check(kloxo_pma_panel_is_admin($tmp) === false, 'dot session id is rejected');
unset($_COOKIE['kloxo-classname'], $_COOKIE['kloxo-clientname'], $_COOKIE['kloxo-session-id'], $_SERVER['REMOTE_ADDR']);
@unlink($tmp . '/session/' . $sid);
@rmdir($tmp . '/session');
@unlink($tmp . '/etc/conf/pma.pass');
@rmdir($tmp . '/etc/conf');
@rmdir($tmp . '/etc');
@rmdir($tmp);

exit($fail === 0 ? 0 : 1);
