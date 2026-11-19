<?php

class userlist_a 
{
}

class databasecore extends Lxdb
{
	static $__desc_username = array("n", "",  "user_name", "a=show");
	static $__desc_dbtype = array("", "",  "database_type");
	static $__desc_syncserver = array("", "",  "database_server");
	static $__desc_dbpassword = array("n", "",  "password");
	// EasyInstaller "used by app" column removed (security OO1).
	// static $__desc_easyinstaller_flag = array("e", "",  "used");
	// static $__desc_easyinstaller_flag_v_dull = array("", "",  "Not Used by Application");
	// static $__desc_easyinstaller_flag_v_on = array("", "",  "Used By Application");
	static $__desc_mysqldb_usage = array("q", "",  "database_disk_usage_(mb)");
	static $__desc_mssqldb_usage = array("q", "",  "database_disk_usage_(mb)");
	static $__desc_phpmyadmin_f  = array("b", "",  "", "__stub_phpmyadmin_url");

	static $__acdesc_update_update = array("", "",  "edit_db");
	static $__acdesc_update_phpmyadmin = array("", "",  "phpmyadmin");

	static $__desc_clientname_as_prefix = array("f", "",  "clientname_as_prefix");

	function inheritSynserverFromParent()
	{ 
		return false; 
	}
	
	function isCoreBackup() 
	{ 
		return true; 
	}

	function createExtraVariables()
	{
		$parent = null;
		try {
			$parent = $this->getTrueParentO();
		} catch (Throwable $e) {
			$parent = null;
		}
		if (is_object($parent) && method_exists($parent, 'getPrimaryDb')) {
			try {
				$pdb = $parent->getPrimaryDb();
				if (is_object($pdb) && isset($pdb->nname) && is_string($pdb->nname) && $pdb->nname !== '') {
					$this->__var_primary_user = $pdb->nname;
				}
			} catch (Throwable $e) {
			}
		}
		$dbtype = (isset($this->dbtype) && is_string($this->dbtype) && $this->dbtype !== '')
			? $this->dbtype : 'mysql';
		if ($dbtype === 'mysql') {
			try {
				$ret = $this->getDbAdminPass();
				$this->__var_dbadmin = $ret['dbadmin'];
				$this->__var_dbpassword = $ret['dbpassword'];
			} catch (Throwable $e) {
				if (!function_exists('kloxo_pma_login')) {
					include_once dirname(__DIR__, 2) . '/lib/php/kloxo_pma.php';
				}
				$cred = function_exists('kloxo_pma_login') ? kloxo_pma_login() : array('user' => 'kloxo_pma', 'pass' => '');
				$this->__var_dbadmin = $cred['user'] ?? 'kloxo_pma';
				$this->__var_dbpassword = $cred['pass'] ?? '';
			}
		}
		// No md5 of DB password — MariaDB uses the plain via KloxoMariadb (security JJ3).
		if (!isset($this->__var_enc_pass)) {
			$this->__var_enc_pass = '';
		}
	}

	function getQuotaNeedVar()
	{
		return array('dbname' => $this->dbname);
	}

	static function add($parent, $class, $param)
	{
		global $gbl, $sgbl, $login, $ghtml;

		if ($parent->isAdmin()) {
			$param['nname'] = $param['nname'];
		} else {
			if (isset($param['clientname_as_prefix']) && $param['clientname_as_prefix'] === 'on') {
				$param['nname'] = substr($parent->nname, 0, 15) . "_" . $param['nname'];
			} else {
				$param['nname'] = random_string_lcase(4) . "_" . $param['nname'];
			}
		}

		$param['nname'] = trim($param['nname']);
		$param['dbpassword'] = trim($param['dbpassword']);

		validate_database_name($param['nname']);
		validate_password($param['dbpassword']);

		$param['dbname'] = $param['nname'];

		if ($parent->isAdmin()) {
			$param['username'] = substr($param['username'], 0, 15);

			// MR -- use database name for validate of admin's database username
			validate_database_name($param['nname']);
		} else {
			$param['username'] = substr($param['nname'], 0, 15);
		}

		$param['dbtype'] = strtil($class, "db");

	/*
		if (!check_if_many_server()) {
			$param['syncserver'] = 'localhost';
		}
	*/
		return $param;
	}

	static function getDbName($parentname, $name)
	{
		$dbprefix = self::fixDbname($parentname);
		$name = $dbprefix . $name;
		$name = substr($name, 0, 63);
		
		return $name;
	}

	function postAdd()
	{
		global $login;

		$parent = $this->getParentO();
		$nname = $this->username;
		$pp = $this->getRealClientParentO();
		$this->syncserver = $pp->mysqldbsyncserver;
		$this->fixSyncServer();
		
		if (exists_in_db($parent->__masterserver, 'mysqldbuser', $nname)) {
		//	throw new lxException($login->getThrow('database_user_already_exists'), '', $nname);
		}
	}

	Function display($var)
	{
		return parent::display($var);
	}

	function extraBackup()
	{ 
		return true; 
	}

	static function mysql_dbase_usage($name)
	{
		return lxfile_dirsize("/var/lib/mysql/$name");
	}

	static function findDdatabaseUsage($name, $dbtype)
	{
		$func = $dbtype . "_dbase_usage";
		$val = self::$func($name);
		
		return round($val / 1024);
	}

	function getquotaDdatabase_usage()
	{
		global $gbl, $sgbl, $login, $ghtml;
		
		return $sgbl->__var_ddatabase_usage[$this->nname];
	}

	function createShowUpdateform()
	{
		$uflist['update'] = null;
		
		return $uflist;

	}

	function createShowRlist($subaction)
	{
		return null;
		$rlist['priv'] = null;
		return $rlist;

	}

	function getDbAdminUrl()
	{
		if (($this->dbtype ?? 'mysql') === 'pgsql') {
			$url = "/thirdparty/phpPgAdmin/";
			if (method_exists($this, 'isLocalhost') && $this->isLocalhost()) {
				return $url;
			}
			$fqdn = function_exists('getFQDNforServer')
				? (string) getFQDNforServer($this->syncserver) : '';
			if ($fqdn === '' || preg_match('/^[A-Za-z0-9._-]+$/', $fqdn) !== 1) {
				return $url;
			}
			$ssl = function_exists('http_is_self_ssl') && http_is_self_ssl();
			$port = function_exists('get_kloxo_port')
				? (string) get_kloxo_port($ssl ? 'ssl' : 'nonssl')
				: ($ssl ? '7777' : '7778');
			if (preg_match('/^[0-9]{2,5}$/', $port) !== 1) {
				$port = $ssl ? '7777' : '7778';
			}

			return ($ssl ? 'https://' : 'http://') . $fqdn . ':' . $port . $url;
		}

		if (!function_exists('kloxo_pma_href')) {
			include_once dirname(__DIR__, 2) . '/lib/php/kloxo_pma.php';
		}
		$local = !method_exists($this, 'isLocalhost') || $this->isLocalhost();
		$server = (isset($this->syncserver) && is_string($this->syncserver))
			? $this->syncserver : 'localhost';

		return kloxo_pma_href($local, $server);
	}

	/**
	 * Per-database SQL Manager (phpMyAdmin in a new tab). The sign-on URL
	 * carries only the mysqldb nname; the handler logs in as that row's
	 * MariaDB user with only_db, never kloxo_pma.
	 */
	function phpMyAdminActionObject()
	{
		if (!function_exists('kloxo_pma_db_href')) {
			include_once dirname(__DIR__, 2) . '/lib/php/kloxo_pma.php';
		}
		$nname = (isset($this->nname) && is_string($this->nname)) ? $this->nname : '';
		if ($nname === '' || !function_exists('kloxo_pma_db_href')) {
			return null;
		}
		$local = !method_exists($this, 'isLocalhost') || $this->isLocalhost();
		$server = (isset($this->syncserver) && is_string($this->syncserver))
			? $this->syncserver : 'localhost';
		$payload = array(
			'url' => kloxo_pma_db_href($nname, $local, $server),
			'purl' => 'c=mysqldb&a=updateform&sa=phpmyadmin',
			'target' => function_exists('kloxo_pma_link_attrs')
				? kloxo_pma_link_attrs() : 'target="_blank" rel="noopener noreferrer"',
		);
		if (function_exists('create_simpleObject')) {
			return create_simpleObject($payload);
		}

		return (object) $payload;
	}

	function createShowPropertyList(&$alist)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$alist['property'][] = 'a=show';
		$this->getSwitchServerUrl($alist['property']);
		$user = $this->username;

		$pass = $this->dbpassword;

		try {
			$pma = $this->phpMyAdminActionObject();
			if (is_object($pma)) {
				$alist['property'][] = $pma;
			}
		} catch (Exception $e) {}

	}

	function createShowAlist(&$alist, $subaction = null)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$user = $this->username;
		$pass = $this->dbpassword;
		$dbadminUrl = $this->getDbAdminUrl();
		$servernum = $this->getDbServerNum();

		return $alist;
	}

	static function createListAlist($parent, $class)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$alist[] = "a=list&c=$class";
	//	$alist['__v_dialog_add'] = "a=addform&c=$class";
	//	$alist[] = create_simpleObject(array('url' => "/thirdparty/phpMyAdmin/", 'purl' => "c=ddatabase&a=updateform&sa=phpmyadmin", 'target' => "target='_blank'"));

		return $alist;
	}

	function isSelect()
	{
		if ($this->isOn('primarydb')) {
			return false;
		}

		return true;
	}

	static function createListNlist($parent, $view)
	{
		global $login;

		$nlist['phpmyadmin_f'] = '5%';
		$nlist['parent_clname'] = '5%';
		$nlist['syncserver'] = '5%';
		$nlist['username'] =  '10%';
		$nlist['nname'] =  '70%';
		$nlist['dbtype'] = '10%';
		return $nlist;
	}

	function updateform($subaction, $param)
	{
		global $gbl, $sgbl, $login, $ghtml;
		
		switch($subaction) {
			case "update":
				$vlist['nname'] = array('M', null);
				$vlist['dbtype'] = array('M', null);
				$vlist['syncserver'] = array('M', null);
				$vlist['username'] = array('M', null);
				$vlist['dbpassword'] = null;
				
				return $vlist;
		}
		
		return parent::updateform($subaction, $param);
	}

	static function fixDbname($pname)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$dbprefix = str_replace(".", "", $pname);
		$dbprefix = str_replace("-", "", $dbprefix);
		$dbprefix = substr($dbprefix, 0, 8);
		$dbprefix .= "_";
		
		return $dbprefix;
	}

	static function AddListForm($parent, $class)
	{
		return self::addform($parent, $class);
	}

	static function addform($parent, $class, $typetd = null)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$dbprefix = null;

	//	if (!$parent->isAdmin()) {
	//		$dbprefix = self::fixDbname($parent->nname);
	//	}

		if ($parent->isAdmin()) {
			$vlist['nname'] = null;
		} else {
			$vlist['nname'] = array('m', array('pretext' => 'prefix_'));
		}

	//	$vlist['dbtype'] = $class;
		
		if (0 && check_if_many_server()) {
			$var = "{$class}pserver_list";

		//	if ($parent->is__table('domain')) {
			if ($parent->getClass() === 'domain') {
				$pp = $parent->getRealClientParentO();
			} else {
				$pp = $parent;
			}

			$list = $pp->listpriv->$var;

			if (!$list) {
				throw new lxException($login->getThrow('no_database_server_pool_in_client'), '', $class);
			}

			$vlist['syncserver'] = array('s', $pp->listpriv->$var);
		}

		if ($parent->isAdmin()) {
			$vlist['username'] = null;
		} else {
			$vlist['clientname_as_prefix'] = null;
		}

	//	$vlist['username'] = array('m', array('pretext' => $dbprefix));
		$vlist['dbpassword'] = null;

		$ret['variable'] = $vlist;
		$ret['action'] = 'add';
		
		return $ret;
	}

	static function loadExtension($dbtype)
	{
		if ($dbtype === 'mysql') {
			return extension_loaded('mysqli') || extension_loaded('pdo_mysql');
		}
		if (!extension_loaded($dbtype)) {
			dprint("Warning No $dbtype <br> ");
			return false;
		}

		return true;
	}

	function getDbAdminPass()
	{
		global $gbl, $sgbl, $login, $ghtml;
		
		$db = new Sqlite($this->__masterserver, 'dbadmin');

		$dbtype = (isset($this->dbtype) && is_string($this->dbtype) && $this->dbtype !== '')
			? $this->dbtype : 'mysql';
		$sync = (isset($this->syncserver) && is_string($this->syncserver) && $this->syncserver !== '')
			? $this->syncserver : 'localhost';
		$res = $db->getRowsWhere(kloxo_sql_and_eq(array(
			'dbtype' => $dbtype,
			'syncserver' => $sync,
		)));

		if (!$res) {
			dprintr("NO database admin entries... <br> ");
			
			if ($login->isAdmin()) {
				$err = 'e_no_dbadmin_entries_admin';
			} else {
				$err = 'e_no_dbadmin_entries';
			}
			
			throw new lxException($err, '', $sync);
		}

		$ret['dbadmin'] = $res[0]['dbadmin_name'];
		$ret['dbpassword'] = $res[0]['dbpassword'];

		return $ret;
	}
}

/**
 * MSSQL removed on Ubuntu (security SS2 / RR3). Stub refuses if loaded.
 */
class mssqldb extends databasecore
{
	static $__table = 'mssqldb';
	static $__desc = array("", "", "mssql_database");

	function dbactionAdd()
	{
		global $login;

		throw new lxException($login ? $login->getThrow('mssql_not_available') : 'mssql_not_available', '');
	}

	function dbactionUpdate($subaction)
	{
		global $login;

		throw new lxException($login ? $login->getThrow('mssql_not_available') : 'mssql_not_available', '');
	}

	function dbactionDelete()
	{
		global $login;

		throw new lxException($login ? $login->getThrow('mssql_not_available') : 'mssql_not_available', '');
	}
}

class mysqldb extends databasecore
{
	static $__table =  'mysqldb';
	static $__desc = array("", "",  "mysql_database");
	static $__desc_nname = array("n", "",  "Db Name", URL_SHOW);
	static $__desc_dbname = array("n", "",  "mysql_database_name", URL_SHOW);
	static $__desc_mysqldb_usage = array("q", "",  "mysqldisk:mysql_disk_usage");

//	static $__desc_mysqldbuser_l = array("db", "", "");

	/**
	 * Create/update/drop in MariaDB from the panel process (kloxo_pma).
	 * rl_exec_set never falls back as the panel user, so a down or failing
	 * kloxo-backend used to toast "added" while only panel.sqlite had the row.
	 */
	function syncToSystem()
	{
		global $login;

		if (!$this->syncToSystemCommon()) {
			return null;
		}

		$sync = isset($this->syncserver) && is_string($this->syncserver)
			? trim($this->syncserver) : 'localhost';
		$local = ($sync === '' || $sync === 'localhost' || $sync === '127.0.0.1');
		if (!$local) {
			return parent::syncToSystem();
		}

		if (!function_exists('kloxo_mysqldb_create_live')) {
			include_once dirname(__DIR__, 2) . '/lib/php/kloxo_mariadb.php';
		}

		$act = (string) $this->dbaction;
		try {
			if ($act === 'add' || $act === 'syncadd') {
				kloxo_mysqldb_create_live($this);

				return null;
			}
			if ($act === 'delete' || $act === 'syncdelete') {
				kloxo_mysqldb_drop_live($this);

				return null;
			}
			if ($act === 'update') {
				kloxo_mysqldb_update_live($this);

				return null;
			}
		} catch (KloxoMariadbException $e) {
			throw new lxException(
				is_object($login) ? $login->getThrow('could_not_connect_to_db') : 'could_not_connect_to_db',
				'',
				$e->getMessage()
			);
		}

		return parent::syncToSystem();
	}

	function getStubUrl($name)
	{
		if ($name == '__stub_phpmyadmin_url') {
			return $this->phpMyAdminActionObject();
		}
	}

	static function findTotalUsage($driver, $list)
	{
		foreach($list as $k => $l) {
			$name = $l['dbname'];
			$ret[$k] = lxfile_dirsize("__path_mysql_datadir/$name");
		}

		return $ret;
	}

	function isRealQuotaVariable($k)
	{
		$list['mysqldb_usage'] = 'a';

		return isset($list[$k]);
	}

	function createShowPropertyList(&$alist)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$alist['property'][] = 'a=show';
		$this->getSwitchServerUrl($alist['property']);
		// updateform (not a=update): a bare update tab ran download on click and
		// looked wrong among the beige tabs; Restore already uses updateform.
		$alist['property'][] = 'a=updateform&sa=backup';
		$alist['property'][] = 'a=updateform&sa=restore';
		$alist['property'][] = 'a=updateform&sa=changeowner';

		$pma = $this->phpMyAdminActionObject();
		if (is_object($pma)) {
			$alist['property'][] = $pma;
		}
	}

	function createShowAlist(&$alist, $subaction = null)
	{
		global $gbl, $sgbl, $login, $ghtml;
		
	//	$alist['__title_main'] = $login->getKeywordUc('resource');
	//	$alist[] = "a=list&c=mysqldbuser";
		$alist = parent::createShowAlist($alist);

		return $alist;
	}
}

class all_mysqldb extends mysqldb
{
	static $__desc = array("", "",  "all_mysql_database");
	static $__desc_parent_name_f =  array("n", "",  "owner");
	static $__desc_parent_clname =  array("n", "",  "owner");

	function isSelect()
	{
		return false;
	}

	static function initThisListRule($parent, $class)
	{
		global $login;

		if (!$parent->isAdmin()) {
			throw new lxException($login->getThrow("only_admin_can_access"));
		}

		return "__v_table";
	}

	static function createListSlist($parent)
	{
		$nlist['nname'] = null;
		$nlist['parent_clname'] = null;

		return $nlist;
	}

	static function AddListForm($parent, $class)
	{
		return null;
	}

	static function createListAlist($parent, $class)
	{
		return all_domain::createListAlist($parent, $class);
	}

	static function createListNlist($parent, $view)
	{
		$nlist['nname'] = '50%';
		$nlist['parent_name_f'] = '50%';
		
		return $nlist;
	}

	static function createListUpdateForm($object, $class)
	{
		return null;
	}
}
