<?php

if (!class_exists('KloxoMariadb', false)) {
	include_once dirname(__DIR__, 2) . '/lib/php/kloxo_mariadb.php';
}

class Mysqldb__mysql extends lxDriverClass
{
	function mariadb()
	{
		if (!class_exists('KloxoMariadb', false)) {
			include_once dirname(__DIR__, 2) . '/lib/php/kloxo_mariadb.php';
		}
		$user = isset($this->main->__var_dbadmin) ? (string) $this->main->__var_dbadmin : '';
		$pass = isset($this->main->__var_dbpassword) ? (string) $this->main->__var_dbpassword : '';

		return KloxoMariadb::connectAdmin($user, $pass);
	}

	function lx_mysql_connect($server, $dbadmin, $dbpass)
	{
		global $login;

		$host = ($server === 'localhost' || $server === '' || $server === null)
			? '127.0.0.1' : $server;
		$rdb = @new mysqli($host, $dbadmin, $dbpass);

		if (!$rdb instanceof mysqli || $rdb->connect_errno) {
			$err = ($rdb instanceof mysqli) ? $rdb->connect_error : 'connect failed';
			log_error($err);
			throw new lxException($login->getThrow('could_not_connect_to_db'), '', $err);
		}

		return $rdb;
	}

	function createDatabase()
	{
		global $login;

		$primary = null;
		if (isset($this->main->__var_primary_user) && is_string($this->main->__var_primary_user)
			&& $this->main->__var_primary_user !== '') {
			$primary = $this->main->__var_primary_user;
		}
		try {
			$this->mariadb()->createDatabase(
				(string) $this->main->dbname,
				(string) $this->main->username,
				(string) $this->main->dbpassword,
				$primary
			);
		} catch (KloxoMariadbException $e) {
			throw new lxException($login->getThrow('could_not_connect_to_db'), '', $e->getMessage());
		}
	}

	function extraGrant()
	{
		global $login;

		try {
			$this->mariadb()->grantDatabase(
				(string) $this->main->dbname,
				(string) $this->main->username
			);
		} catch (KloxoMariadbException $e) {
			throw new lxException($login->getThrow('could_not_connect_to_db'), '', $e->getMessage());
		}
	}

	function deleteDatabase()
	{
		global $login;

		try {
			$this->mariadb()->dropDatabase(
				(string) $this->main->dbname,
				(string) $this->main->username
			);
		} catch (KloxoMariadbException $e) {
			throw new lxException($login->getThrow('could_not_connect_to_db'), '', $e->getMessage());
		}
	}

	function updateDatabase()
	{
		global $login;

		try {
			$this->mariadb()->setPassword(
				(string) $this->main->username,
				(string) $this->main->dbpassword
			);
		} catch (KloxoMariadbException $e) {
			throw new lxException($login->getThrow('could_not_connect_to_db'), '', $e->getMessage());
		}
	}

	function log_error_messages($throwflag = true)
	{
		global $login;

		$rdb = $this->lx_mysql_connect('localhost', $this->main->__var_dbadmin, $this->main->__var_dbpassword);

		if ($rdb->connect_errno) {
			dprint($rdb->connect_error);

			log_error($rdb->connect_error);

			if ($throwflag) {
				throw new lxException($login->getThrow('could_not_connect_to_db'), '', $rdb->connect_error);
			}
		}
	}

	static function take_dump($dbname, $dbuser, $dbpass, $docf)
	{
		// Issue #671 - Fixed backup-restore issue

		global $gbl, $sgbl, $login, $ghtml;

		$arg[0] = $sgbl->__path_mysqldump_path;
		$arg[1] = "--add-drop-table";
		$arg[2] = "-u";
		$arg[3] = $dbuser;
		$arg[4] = $dbname;

		if ($dbpass) {
			$arg[5] = "-p'{$dbpass}'";
		} else {
			$arg[5] = "";
		}

		$cmd = implode(" ", $arg);
	/*
		$output = null;
		$ret = null;

		exec("exec $cmd > $docf", $output, $ret);
	*/

		$link = new mysqli('localhost', $dbadmin, $dbpass);
		$result = $link->query("CREATE DATABASE IF NOT EXISTS {$dbname}");

		try {
			system("{$cmd} > {$docf}");
		} catch (Exception $e) {
			throw new lxException('Error: ' . $e->getMessage(), $dbname);
		}

	}

	static function drop_all_table($dbname, $dbuser, $dbpass)
	{
		$con = new mysqli("localhost", $dbuser, $dbpass, $dbname);
		$query = $con->query($con, "show tables");

		while($res = $query->fetch_array(MYSQLI_ASSOC)) {
			$total[] = getFirstFromList($res);
		}

		foreach($total as $k => $v) {
			$con->query("drop table $v");
		}

		$con->close();
	}

	static function restore_dump($dbname, $dbuser, $dbpass, $docf)
	{
		// Issue #671 - Fixed backup-restore issue

		global $gbl, $sgbl, $login, $ghtml;

		self::drop_all_table($dbname, $dbuser, $dbpass);

		$arg[0] = $sgbl->__path_mysqlclient_path;
		$arg[1] = "-u";
		$arg[2] = $dbuser;

		if ($dbpass) {
			$arg[3] = "-p'{$dbpass}'";
		} else {
			$arg[3] = "";
		}

		$arg[4] = $dbname;

		// MR -- missing this!
		$cmd = implode(" ", $arg);

		try {
			// MR -- remove 'engine=' to make portable
			system("sed -i 's/engine=\([a-zA-z0-9]*\) //gi' {$docf}");
			system("{$cmd} < {$docf}");
		} catch (Exception $e) {
			throw new lxException('Error: ' . $e->getMessage(), $dbname);
		}
	}

	function do_backup()
	{
		// Issue #671 - Fixed backup-restore issue

		global $gbl, $sgbl, $login, $ghtml;

		$dbadmin = $this->main->__var_dbadmin;
		$dbpass = $this->main->__var_dbpassword;
		$dbname = $this->main->dbname;

		$vd = tempnam("/tmp", "mysqldump");
		lunlink($vd);
		mkdir($vd);

		$docf = "{$vd}/mysql-{$dbname}.dump";

		$arg[0] = $sgbl->__path_mysqldump_path;
		$arg[1] = "--add-drop-table";
		$arg[2] = "-u";
		$arg[3] = $dbadmin;

		if ($dbpass) {
			$arg[4] = "-p'{$dbpass}'";
		} else {
			$arg[4] = "";
		}

		$arg[5] = $this->main->dbname;

		$cmd = implode(" ", $arg);

		$link = new mysqli('localhost', $dbadmin, $dbpass);
		$result = $link->query("CREATE DATABASE IF NOT EXISTS {$dbname}");

		try {
			// MR -- remove 'engine=' to make portable
			system("{$cmd} > {$docf}");
			system("sed -i 's/engine=\([a-zA-z0-9]*\) //gi' {$docf}");
		} catch (Exception $e) {
			lxfile_tmp_rm_rec($vd);

		//	throw new lxException('Error: ' . $e->getMessage(), $dbname);
			log_log("backup", "- Error '{$e->getMessage()}' for '{$dbname}'");
		}

		return array($vd, array(basename($docf)));
	}

	function do_backup_cleanup($list)
	{
		lxfile_tmp_rm_rec($list[0]);
	}

	function fix_grant_all()
	{
		global $login;

		try {
			$this->mariadb()->grantDatabase(
				(string) $this->main->dbname,
				(string) $this->main->username
			);
		} catch (KloxoMariadbException $e) {
			throw new lxException($login->getThrow('could_not_connect_to_db'), '', $e->getMessage());
		}
	}

	function do_restore($docd)
	{
		// Issue #671 - Fixed backup-restore issue

		global $gbl, $sgbl, $login, $ghtml;

		$dbadmin = $this->main->__var_dbadmin;
		$dbpass = $this->main->__var_dbpassword;
		$dbname = $this->main->dbname;

		$vd = tempnam("/tmp", "mysqldump");

		lunlink($vd);
		mkdir($vd);

	//	$docf = "$vd/mysql-{$this->main->dbname}.dump";
		$docf = "$vd/mysql-{$dbname}.dump";

	//	$ret = lxshell_unzip_with_throw($vd, $docd);
		$ret = lxshell_unzip('__system__', $vd, $docd);

		if (!lxfile_exists($docf)) {
		//	throw new lxException($login->getThrow('could_not_find_matching_dumpfile_for_db'), '', $docf);
			log_log("restore", "- Not match $docf file for database");
		}

		$arg[0] = $sgbl->__path_mysqlclient_path;
		$arg[1] = "-u";
		$arg[2] = $dbadmin;

		if ($dbpass) {
			$arg[3] = "-p'{$dbpass}'";
		} else {
			$arg[3] = "";
		}

		$arg[4] = $dbname;

		$cmd = implode(" ", $arg);

		$link = new mysqli('localhost', $dbadmin, $dbpass);
		$result = $link->query("CREATE DATABASE IF NOT EXISTS {$dbname}");

		try {
			system("{$cmd} < {$docf}");

			lunlink($docf);
			lxfile_tmp_rm_rec($vd);
		} catch (Exception $e) {
			throw new lxException('Error: ' . $e->getMessage(), $dbname);
		}
	}

	function doSyncToSystemPre()
	{
		global $login;

		if (!databasecore::loadExtension('mysql')) {
			throw new lxException($login->getThrow('could_not_connect_to_db'), '', 'mysqli/pdo_mysql');
		}
	}

	function dbactionAdd()
	{
		$this->createDatabase();
	}

	function dbactionDelete()
	{
		$this->deleteDatabase();
	}

	function dbactionUpdate($subaction)
	{
		$this->fix_grant_all();
		$this->updateDatabase();
	}
}

