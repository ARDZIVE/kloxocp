<?php

include_once "lib/html/include.php";

error_reporting(0);
pmaster_main();

function pmaster_main()
{
	global $gbl, $sgbl, $login, $ghtml;
	global $argv;

	ob_start();

	$pass = slave_get_db_pass();
	$tfile = ltempnam("/tmp", "mastertmp");
	$dbf = $sgbl->__var_dbf;
	$list = parse_opt($argv);
	$slavepass = isset($list['slavepass']) ? (string) $list['slavepass'] : '';
	if ($slavepass === '' || strlen($slavepass) > 128) {
		fwrite(STDERR, "slavepass required\n");
		exit(1);
	}
	$dbIdent = is_string($dbf) && preg_match('/^[A-Za-z0-9_]+$/', $dbf) === 1 ? $dbf : '';
	if ($dbIdent === '') {
		fwrite(STDERR, "invalid database name\n");
		exit(1);
	}
	add_line_to_master_mycnf();

	$conn = mysqli_connect("localhost", "root", $pass);
	if (!$conn) {
		fwrite(STDERR, "mysql connect failed\n");
		exit(1);
	}

	// Bound password — never interpolate into GRANT (security BB1).
	$stmt = $conn->prepare(
		"CREATE USER IF NOT EXISTS 'lxlabsslave'@'%' IDENTIFIED BY ?"
	);
	if ($stmt) {
		$stmt->bind_param('s', $slavepass);
		$stmt->execute();
		$stmt->close();
	}
	$conn->query("GRANT REPLICATION SLAVE ON *.* TO 'lxlabsslave'@'%'");
	$conn->query("FLUSH PRIVILEGES");

	// Never put the DB password on the command line (security BB1).
	$cnf = kloxo_setupprimary_defaults_file((string) $pass);
	if ($cnf === '') {
		fwrite(STDERR, "cannot write mysql defaults file\n");
		exit(1);
	}
	try {
		$dump = is_executable('/usr/bin/mysqldump') ? '/usr/bin/mysqldump'
			: (is_executable('/usr/bin/mariadb-dump') ? '/usr/bin/mariadb-dump' : 'mysqldump');
		$argvDump = array(
			$dump,
			'--defaults-extra-file=' . $cnf,
			'--master-data',
			'--single-transaction',
			$dbIdent,
		);
		$desc = array(
			0 => array('file', '/dev/null', 'r'),
			1 => array('file', $tfile, 'w'),
			2 => array('pipe', 'w'),
		);
		$p = @proc_open($argvDump, $desc, $pipes);
		if (!is_resource($p)) {
			fwrite(STDERR, "mysqldump spawn failed\n");
			exit(1);
		}
		fclose($pipes[2]);
		$rc = proc_close($p);
		if ($rc !== 0) {
			fwrite(STDERR, "mysqldump failed\n");
			exit(1);
		}
	} finally {
		@unlink($cnf);
	}

	ob_clean();

	readfile($tfile);

	ob_start();
	unlink($tfile);
	ob_clean();
}

/** 0600 MySQL client defaults; password never on argv (security BB1). */
function kloxo_setupprimary_defaults_file(string $pass): string
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
	$file = @tempnam($dir, 'pmcnf-');
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

function add_line_to_master_mycnf()
{
	global $gbl, $sgbl, $login, $ghtml;

	$dbf = $sgbl->__var_dbf;

	if (!lxfile_exists("/etc/primary_master.copy.my.cnf")) {
		lxfile_cp("/etc/my.cnf", "/etc/primary_master.copy.my.cnf");
	}

	$v = lfile_get_contents("/etc/my.cnf");

	if (csa($v, "binlog-do-db")) {
		print("Line already exists in /etc/my.cnf\n");
		return;
	}

	$list = lfile_trim("/etc/my.cnf");

	foreach($list as $k => $l) {
		$ll[] = $l;

		if ($l == '[mysqld]') {
			$ll[] = "log-bin=mysql-bin";
			$ll[] = "binlog-do-db=$dbf";
			$ll[] = "server-id=1";
		}
	}

	lfile_put_contents("/etc/my.cnf", implode("\n", $ll));

	$desc = array(
		0 => array('file', '/dev/null', 'r'),
		1 => array('file', '/dev/null', 'w'),
		2 => array('file', '/dev/null', 'w'),
	);
	$p = @proc_open(array('sh', '/script/restart-mysql'), $desc, $pipes);
	if (is_resource($p)) {
		proc_close($p);
	}
}
