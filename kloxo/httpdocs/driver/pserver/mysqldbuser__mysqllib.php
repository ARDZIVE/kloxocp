<?php

if (!class_exists('KloxoMariadb', false)) {
	include_once dirname(__DIR__, 2) . '/lib/php/kloxo_mariadb.php';
}

class Mysqldbuser__mysql extends lxDriverClass
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

	function createUser()
	{
		global $login;

		try {
			$mdb = $this->mariadb();
			$mdb->ensureUser((string) $this->main->username, (string) $this->main->dbpassword);
			$mdb->grantDatabase((string) $this->main->dbname, (string) $this->main->username);
			$mdb->flushPrivileges();
		} catch (KloxoMariadbException $e) {
			throw new lxException($login->getThrow('could_not_connect_to_db'), '', $e->getMessage());
		}
	}

	function deleteUser()
	{
		global $login;

		try {
			$this->mariadb()->dropUser((string) $this->main->username);
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

	function doSyncToSystemPre()
	{
		global $login;

		if (!databasecore::loadExtension('mysql')) {
			throw new lxException($login->getThrow('could_not_connect_to_db'), '', 'mysqli/pdo_mysql');
		}
	}

	function dbactionAdd()
	{
		$this->createUser();
	}

	function dbactionDelete()
	{
		$this->deleteUser();
	}

	function dbactionUpdate($subaction)
	{
		$this->updateDatabase();
	}
}
