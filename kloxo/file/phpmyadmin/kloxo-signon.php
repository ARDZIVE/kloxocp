<?php
declare(strict_types=1);

/**
 * phpMyAdmin SignonURL / MySQL logon. Copied into thirdparty/phpMyAdmin
 * by install-phpmyadmin. SQL Manager opens this first; Go redirects into
 * phpMyAdmin (Kloxo-MR cookie-logon order).
 */

$root = dirname(__DIR__, 3);
$helper = $root . '/httpdocs/lib/php/kloxo_pma.php';
if (!is_readable($helper)) {
	header('Location: /login/', true, 302);
	exit;
}
require $helper;
kloxo_pma_signon_page($root);
