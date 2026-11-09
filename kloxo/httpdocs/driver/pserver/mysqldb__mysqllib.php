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

	function do_backup()
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

		try {
			KloxoBackup::dumpMysql($dbname, $dbadmin, $dbpass, $docf);
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

		try {
			if (!class_exists('KloxoBackup', false)) {
				include_once dirname(__DIR__, 2) . '/lib/php/kloxo_backup.php';
			}
			KloxoBackup::extractArchive($docd, $vd);
		} catch (Throwable $e) {
			lxshell_unzip('__system__', $vd, $docd);
		}

		if (!lxfile_exists($docf)) {
			log_log("restore", "- Not match $docf file for database");
			lxfile_tmp_rm_rec($vd);

			return;
		}

		try {
			KloxoBackup::restoreMysql($dbname, $dbadmin, $dbpass, $docf);
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

