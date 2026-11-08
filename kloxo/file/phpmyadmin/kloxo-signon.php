<?php
declare(strict_types=1);

/**
 * phpMyAdmin SignonURL. Copied into thirdparty/phpMyAdmin by
 * install-phpmyadmin. SQL Manager opens this in a new tab: a live panel
 * admin session (no ?db=) is signed in as kloxo_pma; ?db=<nname> signs in
 * as that mysqldb's own MariaDB user with only_db. Otherwise the MySQL
 * logon form is shown.
 */

$root = dirname(__DIR__, 3);
$helper = $root . '/httpdocs/lib/php/kloxo_pma.php';
if (!is_readable($helper)) {
	header('Location: /login/', true, 302);
	exit;
}
require $helper;
kloxo_pma_signon_page($root);
