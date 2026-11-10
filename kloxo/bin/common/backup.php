<?php

/**
 * Account Backup Now (admin and client). Always chdir to httpdocs so the
 * relative include works when launched by KloxoBackup::run from any cwd
 * (kloxo-backend PrivateTmp, cron, /script/backup).
 *
 * Panel Backup Now prefers lxbackup::backupAccountInProcess on kloxo-backend
 * (root); this script remains for CLI / cron / non-root fallback.
 */

$httpdocs = dirname(__DIR__, 2) . '/httpdocs';
if (is_dir($httpdocs)) {
	chdir($httpdocs);
}
include_once $httpdocs . '/lib/html/include.php';

backup_main();

function backup_main()
{
	global $argc, $argv;
	global $gbl, $sgbl, $login, $ghtml;

	@set_time_limit(0);
	@ignore_user_abort(true);

	if ($argc === 1) {
		print("Usage: sh /script/backup --class= --name= --v-backup_file_name= \n");
		exit(64);
	}

	$opt = parse_opt($argv);

	$class = $opt['class'] ?? '';
	$name = $opt['name'] ?? '';
	if (!is_string($class) || $class === '' || !is_string($name) || $name === '') {
		print("Backup failed due to: --class= and --name= are required\n");
		exit(64);
	}
	$param = get_variable($opt);
	if (!is_array($param)) {
		$param = array();
	}
	initProgram("admin");
	$object = new $class(null, 'localhost', $name);
	$object->get();

	if ($object->dbaction === 'add') {
		print("Backup failed due to: {$class} {$name} does not exist\n");
		exit(1);
	}

	if (!$object->isLxclient()) {
		print("This is not a backupable object... This object alone cannot be backed up\n");
	}

	$backup = $object->getObject('lxbackup');
	if (!is_object($backup)) {
		print("Backup failed due to: no lxbackup object for {$name}\n");
		exit(1);
	}

	$lock = "{$class}-{$name}.backup";
	if (lx_core_lock($lock)) {
		$backup->backupstage = 'Failed due to: another backup for this account is already running';
		clearLxbackup($backup);
		print("Backup failed due to: another backup for this account is already running\n");
		exit(1);
	}

	if (!empty($opt['v-backup_file_name'])) {
		$param['backup_to_file_f'] = $opt['v-backup_file_name'];
	} else {
		$param['backup_to_file_f'] = "kloxo-scheduled";
	}

	foreach ($opt as $k => $v) {
		if (csb($k, "--v-backupextra_")) {
			$kk = strfrom($k, "--v-");
			$param[$kk] = $v;
		}
	}

	log_log("backup", "*** Backup from '{$name}' user - BEGIN ***");

	try {
		$backup->doUpdateBackup($param);
		$backup->backupstage = 'done';
		$parent = null;
		try {
			$parent = $backup->getParentO();
		} catch (Throwable $e) {
			$parent = $object;
		}
		$table = (is_object($parent) && method_exists($parent, 'get__table')) ? $parent->get__table() : 'client';
		$pname = (is_object($parent) && isset($parent->nname)) ? $parent->nname : $name;
		print("Backup has been saved in {$sgbl->__path_program_home}/{$table}/{$pname}/__backup/\n");
	} catch (Throwable $e) {
		$mess = isset($e->__full_message) ? (string) $e->__full_message : $e->getMessage();
		if ($mess === '') {
			$mess = 'unknown backup error';
		}
		$backup->backupstage = "Failed due to: {$mess}";
		print("Backup failed due to: {$mess}\n");
		if (isset($object->contactemail) && is_string($object->contactemail) && $object->contactemail !== '') {
			@mail($object->contactemail, "Backup Failed..", "Backup Failed for {$object->nname} with the Message {$mess}");
		}
	}

	clearLxbackup($backup);
	if (function_exists('kloxo_release_core_lock')) {
		kloxo_release_core_lock($lock);
	}

	log_log("backup", "*** Backup from '{$name}' user - END ***");
}
