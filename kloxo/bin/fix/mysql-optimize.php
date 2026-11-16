<?php
/**
 * MySQL/MariaDB check/repair/optimize/upgrade (CLI).
 * Passwords never go into a shell string (security V5): defaults-extra-file
 * with mode 0600, then mysqlcheck/mysql_upgrade via lxshell_return argv.
 */

include_once 'lib/html/include.php';

$list = parse_opt($argv);
$select = isset($list['select']) ? strtolower((string) $list['select']) : '';
$database = isset($list['database']) ? (string) $list['database'] : null;

setMysqlOptimize($select, $database);

/**
 * Write a 0600 MySQL client defaults file and return its path (or '').
 */
function kloxo_mysql_defaults_file(string $pass): string
{
	global $sgbl;

	if ($pass === '' || str_contains($pass, "\0")) {
		return '';
	}
	$root = (isset($sgbl) && is_object($sgbl) && !empty($sgbl->__path_program_root))
		? rtrim((string) $sgbl->__path_program_root, '/')
		: '/opt/kloxo';
	$dir = $root . '/pid';
	if (!is_dir($dir)) {
		@mkdir($dir, 0750, true);
	}
	$file = @tempnam($dir, 'mycnf-');
	if ($file === false) {
		return '';
	}
	@chmod($file, 0600);
	$body = "[client]\nuser=root\npassword=" . str_replace(array("\n", "\r"), '', $pass) . "\n";
	if (@file_put_contents($file, $body) === false) {
		@unlink($file);

		return '';
	}
	@chmod($file, 0600);

	return $file;
}

function setMysqlOptimize($select, $database = null)
{
	global $gbl, $sgbl, $login, $ghtml;

	log_cleanup('Mysql Check/Repair/Optimize/Upgrade');

	$database = ($database) ? (string) $database : '_all_';
	if ($database !== '_all_' && !preg_match('/^[A-Za-z0-9_]+$/', $database)) {
		print("- Invalid database name\n");

		return;
	}

	$pass = function_exists('slave_get_db_pass') ? (string) slave_get_db_pass() : '';
	$cnf = kloxo_mysql_defaults_file($pass);
	if ($cnf === '') {
		print("- Could not prepare MySQL client defaults file\n");

		return;
	}

	try {
		if ($select === 'check') {
			log_cleanup('- Checking database');
			if ($database === '_all_') {
				lxshell_return('mysqlcheck', "--defaults-extra-file={$cnf}", '--check', '--all-databases');
			} else {
				lxshell_return('mysqlcheck', "--defaults-extra-file={$cnf}", '--check', '--databases', $database);
			}
		} elseif ($select === 'repair') {
			log_cleanup('- Repairing database');
			if ($database === '_all_') {
				lxshell_return('mysqlcheck', "--defaults-extra-file={$cnf}", '--repair', '--all-databases');
			} else {
				lxshell_return('mysqlcheck', "--defaults-extra-file={$cnf}", '--repair', '--databases', $database);
			}
		} elseif ($select === 'optimize') {
			log_cleanup('- Compacting database');
			if ($database === '_all_') {
				lxshell_return('mysqlcheck', "--defaults-extra-file={$cnf}", '--optimize', '--all-databases');
			} else {
				lxshell_return('mysqlcheck', "--defaults-extra-file={$cnf}", '--optimize', '--databases', $database);
			}
		} elseif ($select === 'upgrade') {
			log_cleanup('- Upgrading database');
			lxshell_return('mysql_upgrade', "--defaults-extra-file={$cnf}", '--force');
		} else {
			print("- Unknown select (use check|repair|optimize|upgrade)\n");
		}
	} finally {
		@unlink($cnf);
	}

	log_cleanup('- MySQL Service restart');
	$ret = lxshell_return('sh', '/script/restart-mysql', '-y');
	if ($ret) {
		print("- MySQL/MariaDB restart failed\n");
	}
}
