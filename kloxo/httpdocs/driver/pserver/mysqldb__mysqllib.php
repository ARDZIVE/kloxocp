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
		if (!class_exists('KloxoBackup', false)) {
			include_once dirname(__DIR__, 2) . '/lib/php/kloxo_backup.php';
		}
		KloxoBackup::dumpMysql((string) $dbname, (string) $dbuser, (string) $dbpass, (string) $docf);
	}

	/**
	 * Dump one database to a .sql and register it with fileserv (for panel
	 * Backup Now when FPM cannot run mysqldump itself).
	 *
	 * @return array{file:string,pass?:string,size?:int,type?:string}
	 */
	static function dumpToFileserv($dbname, $dbuser, $dbpass)
	{
		if (!class_exists('KloxoBackup', false)) {
			include_once dirname(__DIR__, 2) . '/lib/php/kloxo_backup.php';
		}
		$dbname = (string) $dbname;
		$dbuser = (string) $dbuser;
		$dbpass = (string) $dbpass;
		if ($dbname === '' || preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbname) !== 1) {
			throw new Exception('invalid database name');
		}
		$dir = rtrim(sys_get_temp_dir(), '/') . '/kloxo-mysqldb-fs-' . getmypid() . '-' . bin2hex(random_bytes(4));
		@mkdir($dir, 0700, true);
		$docf = $dir . '/' . $dbname . '.sql';
		try {
			$cred = KloxoBackup::mysqlLoginForDump($dbuser, $dbpass, $dbuser, $dbpass);
			KloxoBackup::dumpMysql(
				$dbname,
				(string) $cred['user'],
				(string) $cred['pass'],
				$docf,
				(string) ($cred['host'] ?? '127.0.0.1')
			);
			if (!function_exists('cp_fileserv')) {
				throw new Exception('cp_fileserv missing');
			}
			$res = cp_fileserv($docf);
			@unlink($docf);
			@rmdir($dir);

			return $res;
		} catch (Throwable $e) {
			@unlink($docf);
			@rmdir($dir);
			throw $e;
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
		if (!class_exists('KloxoBackup', false)) {
			include_once dirname(__DIR__, 2) . '/lib/php/kloxo_backup.php';
		}
		KloxoBackup::restoreMysql((string) $dbname, (string) $dbuser, (string) $dbpass, (string) $docf);
	}

	/**
	 * Restore a .sql registered with fileserv (panel Backup Now fallback).
	 *
	 * @param array{file:string,pass?:string,size?:int} $filepass
	 */
	static function restoreFromFileserv($dbname, $dbuser, $dbpass, $filepass)
	{
		if (!class_exists('KloxoBackup', false)) {
			include_once dirname(__DIR__, 2) . '/lib/php/kloxo_backup.php';
		}
		$dbname = (string) $dbname;
		if ($dbname === '' || preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbname) !== 1) {
			throw new Exception('invalid database name');
		}
		if (!is_array($filepass) || !isset($filepass['file'])) {
			throw new Exception('missing fileserv token');
		}
		$dir = rtrim(sys_get_temp_dir(), '/') . '/kloxo-mysqldb-rfs-' . getmypid()
			. '-' . bin2hex(random_bytes(4));
		@mkdir($dir, 0700, true);
		$docf = $dir . '/' . $dbname . '.sql';
		try {
			if (!function_exists('getFromFileserv')) {
				throw new Exception('getFromFileserv missing');
			}
			getFromFileserv('localhost', $filepass, $docf);
			if (!is_file($docf) || (int) filesize($docf) < 1) {
				throw new Exception('empty restore file');
			}
			$cred = KloxoBackup::mysqlLoginForDump(
				(string) $dbuser,
				(string) $dbpass,
				(string) $dbuser,
				(string) $dbpass
			);
			KloxoBackup::restoreMysql(
				$dbname,
				(string) $cred['user'],
				(string) $cred['pass'],
				$docf,
				(string) ($cred['host'] ?? '127.0.0.1')
			);

			return 'ok';
		} finally {
			@unlink($docf);
			@rmdir($dir);
		}
	}

	function do_backup()
	{
		global $login;

		if (!class_exists('KloxoBackup', false)) {
			include_once dirname(__DIR__, 2) . '/lib/php/kloxo_backup.php';
		}

		$dbadmin = isset($this->main->__var_dbadmin) ? (string) $this->main->__var_dbadmin : '';
		$dbpass = isset($this->main->__var_dbpassword) ? (string) $this->main->__var_dbpassword : '';
		$dbname = isset($this->main->dbname) ? (string) $this->main->dbname : '';
		$dbUser = isset($this->main->username) ? (string) $this->main->username : '';
		$dbUserPass = isset($this->main->dbpassword) ? (string) $this->main->dbpassword : '';

		$vd = tempnam("/tmp", "mysqldump");
		lunlink($vd);
		mkdir($vd, 0700);

		$docf = "{$vd}/mysql-{$dbname}.dump";

		try {
			// Prefer kloxo_pma@127.0.0.1 (pma.pass). root@localhost is unix_socket
			// and fails for non-root / wrong dbadmin password (error 1698).
			$cred = KloxoBackup::mysqlLoginForDump($dbadmin, $dbpass, $dbUser, $dbUserPass);
			KloxoBackup::dumpMysql(
				$dbname,
				(string) $cred['user'],
				(string) $cred['pass'],
				$docf,
				(string) ($cred['host'] ?? '127.0.0.1')
			);
		} catch (Throwable $e) {
			lxfile_tmp_rm_rec($vd);
			log_log("backup", "- Error '{$e->getMessage()}' for '{$dbname}'");
			if (is_object($login) && method_exists($login, 'getThrow')) {
				throw new lxException($login->getThrow('could_not_connect_to_db'), '', $e->getMessage());
			}
			throw $e;
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
		global $login;

		if (!class_exists('KloxoBackup', false)) {
			include_once dirname(__DIR__, 2) . '/lib/php/kloxo_backup.php';
		}

		$dbadmin = isset($this->main->__var_dbadmin) ? (string) $this->main->__var_dbadmin : '';
		$dbpass = isset($this->main->__var_dbpassword) ? (string) $this->main->__var_dbpassword : '';
		$dbname = isset($this->main->dbname) ? (string) $this->main->dbname : '';

		$vd = tempnam("/tmp", "mysqldump");
		lunlink($vd);
		mkdir($vd, 0700);

		$docf = "{$vd}/mysql-{$dbname}.dump";
		$sqlDirect = "{$vd}/{$dbname}.sql";

		// Plain .sql from Backup Now: copy as the import file.
		$head = is_readable($docd) ? (string) @file_get_contents($docd, false, null, 0, 512) : '';
		$plainSql = str_ends_with(strtolower((string) $docd), '.sql')
			|| stripos($head, 'CREATE TABLE') !== false
			|| stripos($head, 'INSERT INTO') !== false
			|| str_starts_with(ltrim($head), '--');
		if ($plainSql && is_readable($docd)) {
			@copy($docd, $sqlDirect);
			$docf = $sqlDirect;
		} else {
			try {
				KloxoBackup::extractArchive($docd, $vd);
			} catch (Throwable $e) {
				lxshell_unzip('__system__', $vd, $docd);
			}
		}

		if (!lxfile_exists($docf)) {
			// Prefer any .sql/.dump extracted from a legacy archive.
			foreach ((array) @scandir($vd) as $f) {
				if ($f === '.' || $f === '..') {
					continue;
				}
				$low = strtolower($f);
				if (str_ends_with($low, '.sql') || str_ends_with($low, '.dump')) {
					$docf = $vd . '/' . $f;
					break;
				}
			}
		}

		if (!lxfile_exists($docf)) {
			log_log("restore", "- Not match $docf file for database");
			lxfile_tmp_rm_rec($vd);
			if (is_object($login) && method_exists($login, 'getThrow')) {
				throw new lxException($login->getThrow('could_not_get_file'), '',
					'no .sql dump in upload');
			}

			return;
		}

		try {
			$dbUser = isset($this->main->username) ? (string) $this->main->username : '';
			$dbUserPass = isset($this->main->dbpassword) ? (string) $this->main->dbpassword : '';
			$cred = KloxoBackup::mysqlLoginForDump($dbadmin, $dbpass, $dbUser, $dbUserPass);
			KloxoBackup::restoreMysql(
				$dbname,
				(string) $cred['user'],
				(string) $cred['pass'],
				$docf,
				(string) ($cred['host'] ?? '127.0.0.1')
			);
		} catch (Throwable $e) {
			lxfile_tmp_rm_rec($vd);
			if (is_object($login) && method_exists($login, 'getThrow')) {
				throw new lxException($login->getThrow('could_not_connect_to_db'), '', $e->getMessage());
			}
			throw $e;
		}

		lunlink($docf);
		lxfile_tmp_rm_rec($vd);
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

