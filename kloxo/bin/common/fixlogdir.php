<?php

include_once "lib/html/include.php";

fixlogdir_main();

function fixlogdir_main()
{
	global $gbl, $sgbl, $login, $ghtml;

	$progname = $sgbl->__var_program_name;

	$logl = lscandir_without_dot("../log");
	lxfile_mkdir("../processed_log");
	$dir = getNotexistingFile("../processed_log", "proccessed");
	// Safe dir name + argv mv (security DD6).
	$dir = is_string($dir) ? basename($dir) : '';
	if ($dir === '' || preg_match('/^[A-Za-z0-9._-]+$/', $dir) !== 1) {
		fwrite(STDERR, "invalid processed_log name\n");
		exit(1);
	}
	$dest = "../processed_log/{$dir}";
	$desc = array(
		0 => array('file', '/dev/null', 'r'),
		1 => array('file', '/dev/null', 'w'),
		2 => array('file', '/dev/null', 'w'),
	);
	$p = @proc_open(array('mv', '-f', '--', '../log', $dest), $desc, $pipes);
	if (is_resource($p)) {
		proc_close($p);
	}
	mkdir("../log");

	$list = lscandir_without_dot("../processed_log");

	foreach ($list as $l) {
		remove_directory_if_older_than_a_day("../processed_log/$l", 6);
	}

	foreach ($logl as $l) {
		lxfile_touch("../log/$l");
	}

	lxfile_generic_chown_rec("../log", "lxlabs:lxlabs");

	os_restart_program();
}
