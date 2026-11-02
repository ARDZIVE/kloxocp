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

$check(kloxo_pma_url() === '/thirdparty/phpMyAdmin/kloxo-signon.php', 'SQL Manager URL is the MySQL logon');
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
$check(kloxo_pma_href(true, 'localhost') === '/thirdparty/phpMyAdmin/kloxo-signon.php', 'localhost SQL Manager href is MySQL logon');
$check(kloxo_pma_href(false, '') === '/thirdparty/phpMyAdmin/kloxo-signon.php', 'empty remote server stays MySQL logon');
$remote = kloxo_pma_href(false, 'bad host');
$check(str_contains($remote, 'kloxo-signon.php') && !str_contains($remote, ' '), 'unsafe remote host does not invent a URL');
$login = kloxo_pma_login('/tmp/kloxo-pma-missing');
$check($login['user'] === 'root', 'sign-on user is MariaDB root');
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

$tmp = sys_get_temp_dir() . '/kloxo-pma-' . bin2hex(random_bytes(4));
mkdir($tmp . '/etc/conf', 0750, true);
file_put_contents($tmp . '/etc/conf/pma.pass', "secret-pma-pass\n");
$with = kloxo_pma_login($tmp);
$check($with['pass'] === 'secret-pma-pass', 'reads pma.pass');
$check(strpos($with['pass'], '?') === false, 'password is not a URL query');
@unlink($tmp . '/etc/conf/pma.pass');
@rmdir($tmp . '/etc/conf');
@rmdir($tmp . '/etc');
@rmdir($tmp);

exit($fail === 0 ? 0 : 1);
