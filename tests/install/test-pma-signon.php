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
$check(($ck['path'] ?? '') === '/thirdparty/phpMyAdmin/' && ($ck['samesite'] ?? '') === 'Lax',
	'signon cookie stays on the phpMyAdmin path');

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
