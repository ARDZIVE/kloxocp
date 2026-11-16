<?php
/**
 * Reset the MariaDB root password (CLI / panel path).
 *
 * Prefer live unix_socket as root + ALTER USER (no password on disk, no shell
 * interpolation). Legacy mysqld_safe --init-file remains only as a last resort
 * with a 0600 temp SQL file under the program root (security V1/V2).
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

declare(strict_types=1);

include_once 'lib/html/include.php';

if (!function_exists('os_isSelfSystemOrLxlabsUser') || !os_isSelfSystemOrLxlabsUser()) {
	fwrite(STDERR, "Must be root\n");
	exit(1);
}

$pass = isset($argv[1]) ? (string) $argv[1] : '';
if ($pass === '') {
	$pass = function_exists('randomString') ? randomString(12) : bin2hex(random_bytes(8));
}
if (strlen($pass) > 64 || str_contains($pass, "\0")) {
	fwrite(STDERR, "Invalid password\n");
	exit(1);
}

$mariadbLib = dirname(__DIR__, 3) . '/httpdocs/lib/php/kloxo_mariadb.php';
if (is_file($mariadbLib)) {
	include_once $mariadbLib;
}

$ok = false;
if (class_exists('KloxoMariadb', false) && method_exists('KloxoMariadb', 'setRootPassword')) {
	try {
		print("Resetting MariaDB root via unix_socket ALTER USER...\n");
		$db = KloxoMariadb::connectAdmin();
		$db->setRootPassword($pass);
		$ok = true;
	} catch (Throwable $e) {
		fwrite(STDERR, "Live ALTER USER failed: " . $e->getMessage() . "\n");
		fwrite(STDERR, "Falling back to init-file reset...\n");
	}
}

if (!$ok) {
	global $sgbl;
	$root = (isset($sgbl) && is_object($sgbl) && !empty($sgbl->__path_program_root))
		? rtrim((string) $sgbl->__path_program_root, '/')
		: '/opt/kloxo';
	$dir = $root . '/serverfile';
	if (!is_dir($dir)) {
		@mkdir($dir, 0750, true);
	}
	$sqlFile = tempnam($dir, 'reset-mysql-');
	if ($sqlFile === false) {
		fwrite(STDERR, "Could not create temp SQL file\n");
		exit(1);
	}
	@chmod($sqlFile, 0600);

	$q = class_exists('KloxoMariadb', false)
		? KloxoMariadb::quoteString($pass)
		: ("'" . str_replace(array('\\', "'"), array('\\\\', "''"), $pass) . "'");
	$text = "ALTER USER 'root'@'localhost' IDENTIFIED BY {$q};\n"
		. "ALTER USER 'root'@'127.0.0.1' IDENTIFIED BY {$q};\n"
		. "FLUSH PRIVILEGES;\n";
	if (@file_put_contents($sqlFile, $text) === false) {
		@unlink($sqlFile);
		fwrite(STDERR, "Could not write temp SQL file\n");
		exit(1);
	}
	@chmod($sqlFile, 0600);

	$unit = 'mariadb';
	if (function_exists('lxshell_return')) {
		print("Stop MariaDB...\n");
		lxshell_return('systemctl', 'stop', 'mariadb');
		lxshell_return('systemctl', 'stop', 'mysql');
		print("Apply init-file...\n");
		// Password is only in the 0600 file — never on the argv of mysql/mysqladmin.
		lxshell_return('mysqld_safe', '--defaults-file=/etc/mysql/my.cnf', '--init-file=' . $sqlFile);
		usleep(1500000);
		print("Start MariaDB...\n");
		lxshell_return('systemctl', 'start', 'mariadb');
		if ((int) lxshell_return('systemctl', 'is-active', '--quiet', 'mariadb') !== 0) {
			lxshell_return('systemctl', 'start', 'mysql');
		}
	}
	@unlink($sqlFile);
}

if (function_exists('slave_save_db')) {
	slave_save_db('dbadmin', array('mysql' => array('dbpassword' => $pass)));
}

print("MariaDB root password updated.\n");
exit(0);
