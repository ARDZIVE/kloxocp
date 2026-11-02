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

$check(kloxo_pma_url() === '/thirdparty/phpMyAdmin/', 'SQL Manager URL is /thirdparty/phpMyAdmin/');
$login = kloxo_pma_login('/tmp/kloxo-pma-missing');
$check($login['user'] === 'root', 'sign-on user is MariaDB root');
$check($login['host'] === '127.0.0.1', 'sign-on host is 127.0.0.1');
$check($login['pass'] === '', 'missing pma.pass does not invent a password');
$check(kloxo_pma_panel_is_admin('/tmp/kloxo-pma-missing') === false, 'no cookies is not admin');

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
