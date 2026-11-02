<?php
declare(strict_types=1);

/**
 * phpMyAdmin SignonURL. Copied into thirdparty/phpMyAdmin by install-phpmyadmin.
 * Verifies the Kloxo admin panel session, then starts the PMA sign-on session.
 */

$root = dirname(__DIR__, 3);
$helper = $root . '/httpdocs/lib/php/kloxo_pma.php';
if (!is_readable($helper)) {
	header('Location: /login/', true, 302);
	exit;
}
require $helper;
kloxo_pma_signon_redirect($root);
