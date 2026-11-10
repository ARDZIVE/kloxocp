<?php

/**
 * Account Backup Now (admin and client). Always chdir to httpdocs so the
 * relative include works when launched by KloxoBackup::run from any cwd
 * (kloxo-backend PrivateTmp, cron, /script/backup).
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

	$progname = $sgbl->__var_program_name;

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

	if (lx_core_lock("{$class}-{$name}.backup")) {
		$backup->backupstage = 'Failed due to: another backup for this account is already running';
		clearLxbackup($backup);
		print("Backup failed due to: another backup for this account is already running\n");
		exit(1);
	}

	if ($object->dbaction === 'add') {
		print("Backup failed due to: {$name} doesn't exist\n");
		$backup->backupstage = "Failed due to: {$name} doesn't exist";
		clearLxbackup($backup);
		log_error("Backup.php Client {$name} doesnt exist");
		exit(1);
	}

	if (!empty($opt['priority']) && $opt['priority'] === 'low') {
		// scheduled path may sleep; Backup Now does not set priority=low
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
		$backup->backupstage = "Failed due to: {$mess}";
		print("Backup failed due to: {$mess}\n");
		if (isset($object->contactemail) && is_string($object->contactemail) && $object->contactemail !== '') {
			@mail($object->contactemail, "Backup Failed..", "Backup Failed for {$object->nname} with the Message {$mess}");
		}
	}

	clearLxbackup($backup);

	log_log("backup", "*** Backup from '{$name}' user - END ***");
}
