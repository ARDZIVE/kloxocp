<?php
declare(strict_types=1);

/**
 * phpMyAdmin SignonScript. Copied into thirdparty/phpMyAdmin by install-phpmyadmin.
 * Returns MariaDB kloxo_pma credentials (root@localhost is unix_socket) when the Kloxo admin panel session is live.
 */

$root = dirname(__DIR__, 3);
$helper = $root . '/httpdocs/lib/php/kloxo_pma.php';
if (is_readable($helper)) {
	require_once $helper;
}

/**
 * @param mixed $user
 * @return array{0:string,1:string}
 */
function get_login_credentials($user = '')
{
	if (!function_exists('kloxo_pma_signon_credentials')) {
		return array('', '');
	}

	return kloxo_pma_signon_credentials();
}
