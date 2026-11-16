<?php

include_once "lib/html/displayinclude.php";

mebackup_main();

function mebackup_main()
{
	global $gbl, $sgbl, $login, $ghtml;

	$progname = 'kloxo70';
	$cprogname = ucfirst($progname);
	initProgram('admin');
	lxfile_mkdir("{$sgbl->__path_program_home}/selfbackup/self/__backup");

	$backup = $login->getObject('general')->selfbackupparam_b;
	$dbf = $sgbl->__var_dbf;
	$pass = trim(lfile_get_contents("{$sgbl->__path_program_root}/etc/conf/kloxo.pass"));

	$vd = createTempDir("/tmp", "mebackup");

	$docf = "{$vd}/mebackup.dump";

	// Never put the DB password on the command line (security AA1).
	$cnf = '';
	try {
		$cnf = kloxo_mebackup_defaults_file($pass);
		if ($cnf === '') {
			throw new Exception('cannot write mysql defaults file');
		}
		$dbIdent = preg_match('/^[A-Za-z0-9_]+$/', (string) $dbf) === 1 ? (string) $dbf : '';
		if ($dbIdent === '') {
			throw new Exception('invalid database name');
		}
		$dump = is_executable('/usr/bin/mysqldump') ? '/usr/bin/mysqldump'
			: (is_executable('/usr/bin/mariadb-dump') ? '/usr/bin/mariadb-dump' : 'mysqldump');
		$argv = array(
			$dump,
			'--defaults-extra-file=' . $cnf,
			'--add-drop-table',
			'--default-character-set=utf8mb4',
			'--single-transaction',
			$dbIdent,
		);
		$desc = array(
			0 => array('file', '/dev/null', 'r'),
			1 => array('file', $docf, 'w'),
			2 => array('pipe', 'w'),
		);
		$p = @proc_open($argv, $desc, $pipes);
		if (!is_resource($p)) {
			throw new Exception('mysqldump spawn failed');
		}
		fclose($pipes[2]);
		$rc = proc_close($p);
		if ($rc !== 0 || !is_file($docf) || filesize($docf) === 0) {
			throw new Exception('mysqldump failed');
		}

		// Remove engine= for portability (argv sed).
		$sed = is_executable('/usr/bin/sed') ? '/usr/bin/sed' : 'sed';
		$sp = @proc_open(
			array($sed, '-i', 's/engine=\([a-zA-z0-9]*\) //gi', $docf),
			array(0 => array('file', '/dev/null', 'r'), 1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')),
			$spipes
		);
		if (is_resource($sp)) {
			proc_close($sp);
		}
	} finally {
		if ($cnf !== '' && is_file($cnf)) {
			@unlink($cnf);
		}
	}

	$string = @ date('Y-m-d'). '-' . time();

	$bfile = "{$sgbl->__path_program_home}/selfbackup/self/__backup/{$progname}-scheduled-masterselfbackup-{$string}.zip";
	lxshell_zip($vd, $bfile, array("mebackup.dump"));
	lxfile_tmp_rm_rec($vd);

	if ($backup && $backup->isOn('selfbackupflag')) {
		try {
			lxbackup::upload_to_server($bfile, basename($bfile), $backup);
		} catch (Exception $e) {
			print("Sending warning to $login->contactemail ..\n");

			lx_mail(null, $login->contactemail, "{$cprogname} Self Database Backup Upload Failed on " . date('Y-m-d') . " at " . date('H') ." Hours" ,
				"{$cprogname} Backup upload Failed due to {$e->getMessage()}\n");
		}
	}
	$backup->rm_last_number = 20;
	$backup->nname = 'masterselfbackup';
	lxbackup::clear_extra_backups('selfbackup', 'self', $backup);
}

/** 0600 MySQL client defaults; password never on argv (security AA1). */
function kloxo_mebackup_defaults_file(string $pass): string
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
	$file = @tempnam($dir, 'mebak-');
	if ($file === false) {
		return '';
	}
	@chmod($file, 0600);
	$body = "[client]\nuser=kloxo\npassword=" . str_replace(array("\n", "\r"), '', $pass) . "\n";
	if (@file_put_contents($file, $body) === false) {
		@unlink($file);

		return '';
	}
	@chmod($file, 0600);

	return $file;
}
