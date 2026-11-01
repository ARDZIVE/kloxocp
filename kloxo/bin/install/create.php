<?php 
include_once "lib/html/include.php";
include_once "lib/html/initlib.php";
include_once "../bin/install/sql.php";
include_once "../bin/install/init.php";

create_main();

function kloxo_is_ubuntu()
{
	$os = @file_get_contents('/etc/os-release');
	return is_string($os) && (strpos($os, 'ID=ubuntu') !== false || strpos($os, "ID='ubuntu'") !== false);
}

function kloxo_run_init_panel_db($opt, $admin_pass)
{
	global $sgbl;

	$tool = $sgbl->__path_program_root . "/bin/install/init-panel-db";
	if (!is_file($tool)) {
		print(">>> init-panel-db missing; using in-process create_mysql_db/create_database <<<\n");
		create_mysql_db('master', $opt, $admin_pass);
		create_database();
		update_database();
		return;
	}

	$php = function_exists('kloxo_php_binary') ? kloxo_php_binary() : $sgbl->__path_php_path;
	putenv('KLOXO_ADMIN_PASSWORD=' . $admin_pass);
	$args = array(
		$php,
		$tool,
		"--root={$sgbl->__path_program_root}",
		"--admin-password-env=KLOXO_ADMIN_PASSWORD",
	);
	if (isset($opt['db-rootuser']) && $opt['db-rootuser'] !== '') {
		$args[] = "--db-rootuser={$opt['db-rootuser']}";
	}
	if (isset($opt['db-rootpassword']) && $opt['db-rootpassword'] !== '') {
		$args[] = "--db-rootpassword={$opt['db-rootpassword']}";
	}

	print(">>> Execute init-panel-db (Ubuntu MariaDB schema) <<<\n");
	$ret = call_user_func_array('lxshell_return', $args);
	putenv('KLOXO_ADMIN_PASSWORD');
	if ($ret) {
		print("init-panel-db failed (exit {$ret}); falling back to in-process import\n");
		create_mysql_db('master', $opt, $admin_pass);
		create_database();
		update_database();
	}
}

function create_main()
{
	global $argc, $argv;
	global $gbl, $sgbl, $login, $ghtml;

	$opt = parse_opt($argv);
	if (!is_array($opt) || !isset($opt['install-type'])) {
		print("Need --install-type=master|slave|supernode\n");
		exit(1);
	}

	lxfile_mkdir("{$sgbl->__path_program_etc}/conf");
	lxfile_mkdir("{$sgbl->__path_program_root}/pid");
	lxfile_mkdir("{$sgbl->__path_program_root}/log");
	lxfile_mkdir("{$sgbl->__path_httpd_root}");

	print(">>> Execute os_fix_lxlabs_permission() <<<\n");
	os_fix_lxlabs_permission();
	print(">>> Execute os_create_program_service() <<<\n");
	os_create_program_service();
	print(">>> Execute os_create_kloxo_service_once() <<<\n");
	os_create_kloxo_service_once();

	if (isset($opt['admin-password']) && $opt['admin-password'] !== '') {
		$admin_pass = $opt['admin-password'];
		if (strlen($admin_pass) < 8) {
			print("Admin password must be at least 8 characters\n");
			exit(1);
		}
	} else {
		$admin_pass = bin2hex(random_bytes(9));
		$uiPassFile = $sgbl->__path_program_etc . "/conf/admin.ui.pass";
		lfile_put_contents($uiPassFile, $admin_pass . "\n");
		@chmod($uiPassFile, 0600);
		print(">>> no --admin-password given; wrote {$uiPassFile} (0600) <<<\n");
	}

	if ($opt['install-type'] == 'master') {
		print(">>> Execute schema installer for MASTER <<<\n");
		kloxo_run_init_panel_db($opt, $admin_pass);
		print(">>> Execute create_general() for MASTER <<<\n");
		create_general();
		print(">>> Execute init_main() for MASTER <<<\n");
		init_main($admin_pass);
		print(">>> Execute collectquota.php... for MASTER <<<\n");
		@lxshell_return($sgbl->__path_php_path, "../bin/collectquota.php");
		if (kloxo_is_ubuntu()) {
			print(">>> skip tmpupdatecleanup.php (CentOS-era; not used on Ubuntu) <<<\n");
		} else {
			print(">>> Execute tmpupdatecleanup.php for MASTER... <<<\n");
			print("- This will take a long time... Please wait...\n");
			$php = function_exists('kloxo_php_binary') ? kloxo_php_binary() : $sgbl->__path_php_path;
			lxshell_return($php, "../bin/common/tmpupdatecleanup.php", "--type=master");
		}
	} else if ($opt['install-type'] == 'slave') {
		init_slave($admin_pass);
		if (kloxo_is_ubuntu()) {
			print(">>> skip tmpupdatecleanup.php (CentOS-era; not used on Ubuntu) <<<\n");
		} else {
			print(">>> Execute tmpupdatecleanup.php for SLAVE... <<<\n");
			$php = function_exists('kloxo_php_binary') ? kloxo_php_binary() : $sgbl->__path_php_path;
			lxshell_return($php, "../bin/common/tmpupdatecleanup.php", "--type=slave");
		}
	} else if ($opt['install-type'] == 'supernode'){
		$sgbl->__path_sql_file = $sgbl->__path_sql_file_supernode;
		$sgbl->__var_dbf = $sgbl->__path_supernode_db;
		$sgbl->__path_admin_pass = $sgbl->__path_super_pass;
		$sgbl->__var_admin_user = $sgbl->__var_super_user;
		print(">>> Execute create_mysql_db() for SUPERNODE <<<\n");
		create_mysql_db('super', $opt, $admin_pass);
		init_supernode($admin_pass);
		print("\n");
	} else {
		print("Unknown Install type\n");
		exit(1);
	}

	print(">>> Create slavedb_driver <<<\n");
	os_create_default_slave_driver_db();
}
