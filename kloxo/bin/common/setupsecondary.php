<?php 

include_once "lib/html/include.php"; 

setupsecondary_main();

function setupsecondary_main()
{
	global $gbl, $sgbl, $login, $ghtml; 
	global $argv;

	$dbf = $sgbl->__var_dbf;
	$prgm = $sgbl->__var_program_name;

	$list = parse_opt($argv);

	if (!isset($list['primary-master'])) {
		print("need --primary-master=\n");
		exit;
	}
	if (!isset($list['sshport'])) {
		print("need --sshport=\n");
		exit;
	}

	$master = (string) $list['primary-master'];
	$sshport = (string) $list['sshport'];
	if (!preg_match('/^[A-Za-z0-9.-]{1,253}$/', $master)
		|| !preg_match('/^[0-9]{1,5}$/', $sshport)
		|| (int) $sshport < 1 || (int) $sshport > 65535) {
		print("invalid --primary-master or --sshport\n");
		exit(1);
	}

	print("Taking backup of the current database anyway...\n");
	lxshell_php("../bin/common/mebackup.php");


	$slavepass = randomString(8);

	print("Setting up mysql to receive data from master\n");
	add_line_to_secondary_mycnf($master, $slavepass);
	$pass = slave_get_db_pass();

	$conn = mysqli_connect("localhost", "root", $pass);
	mysqli_query($conn, "stop slave");

	print("Getting initial data from the master\n");
	// Argument arrays only (security N28) — no interpolated shell string.
	// Never put the DB password on mysql argv (security CC2).
	$prgmSafe = preg_match('/^[A-Za-z0-9_-]+$/', (string) $prgm) ? (string) $prgm : 'kloxo';
	$dbIdent = is_string($dbf) && preg_match('/^[A-Za-z0-9_]+$/', $dbf) === 1 ? $dbf : '';
	if ($dbIdent === '') {
		print("invalid database name\n");
		exit(1);
	}
	$cnf = kloxo_setupsecondary_defaults_file((string) $pass);
	if ($cnf === '') {
		print("cannot write mysql defaults file\n");
		exit(1);
	}
	$sshCmd = [
		'/usr/bin/ssh', '-p', $sshport, $master,
		'(cd /opt/kloxo/' . $prgmSafe . '/httpdocs ; lxphp.exe ../bin/common/setupprimarymaster.php --slavepass=' . escapeshellarg($slavepass) . ')',
	];
	$mysqlBin = is_executable('/usr/bin/mysql') ? '/usr/bin/mysql'
		: (is_executable('/usr/bin/mariadb') ? '/usr/bin/mariadb' : 'mysql');
	$mysqlCmd = [$mysqlBin, '--defaults-extra-file=' . $cnf, $dbIdent];
	$desc = [
		0 => ['pipe', 'r'],
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	];
	try {
		$ssh = @proc_open($sshCmd, $desc, $sshPipes, null, null, ['bypass_shell' => true]);
		$my = @proc_open($mysqlCmd, [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		], $myPipes, null, null, ['bypass_shell' => true]);
		if (is_resource($ssh) && is_resource($my) && isset($sshPipes[1], $myPipes[0])) {
			stream_copy_to_stream($sshPipes[1], $myPipes[0]);
			foreach (array_merge($sshPipes, $myPipes) as $p) {
				if (is_resource($p)) {
					fclose($p);
				}
			}
			proc_close($ssh);
			proc_close($my);
		}
	} finally {
		@unlink($cnf);
	}
	print("starting mysql data getting process\n");

	$stmt = mysqli_prepare($conn, 'CHANGE MASTER TO MASTER_HOST=?, MASTER_PASSWORD=?');
	if ($stmt) {
		mysqli_stmt_bind_param($stmt, 'ss', $master, $slavepass);
		mysqli_stmt_execute($stmt);
		mysqli_stmt_close($stmt);
	}
	mysqli_query($conn, "start slave");

	lxfile_touch("../etc/secondary_master");
	lxfile_touch("../etc/running_secondary");
}

function check_if_skip($l)
{
	$vlist = array("server-id", "master-host", "master-user", "master-password");

	foreach($vlist as $v) {
		if (csb($l, $v)) {
			return true;
		}
	}

	return false;
}

function add_line_to_secondary_mycnf($master, $slavepass)
{
	global $gbl, $sgbl, $login, $ghtml; 

	if (!lxfile_exists("/etc/secondary_master.copy.my.cnf")) {
		lxfile_cp("/etc/my.cnf", "/etc/secondary_master.copy.my.cnf");
	}

	$list = lfile_trim("/etc/my.cnf");

	foreach($list as $k => $l) {
		if (check_if_skip($l)) {
			continue;
		}
		$ll[] = $l;
		if ($l == '[mysqld]') {
			$ll[] = "server-id=2";
			$ll[] = "master-host=$master";
			$ll[] = "master-user=lxlabsslave";
			$ll[] = "master-password=$slavepass";
		}
	}

	lfile_put_contents("/etc/my.cnf", implode("\n", $ll));

	$desc = [
		0 => ['file', '/dev/null', 'r'],
		1 => ['file', '/dev/null', 'w'],
		2 => ['file', '/dev/null', 'w'],
	];
	$p = @proc_open(['sh', '/script/restart-mysql'], $desc, $pipes);
	if (is_resource($p)) {
		proc_close($p);
	}
}

/** 0600 MySQL client defaults; password never on argv (security CC2). */
function kloxo_setupsecondary_defaults_file(string $pass): string
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
	$file = @tempnam($dir, 'secmyc-');
	if ($file === false) {
		return '';
	}
	@chmod($file, 0600);
	$body = "[client]\nuser=root\npassword=" . str_replace(["\n", "\r"], '', $pass) . "\n";
	if (@file_put_contents($file, $body) === false) {
		@unlink($file);

		return '';
	}
	@chmod($file, 0600);

	return $file;
}
