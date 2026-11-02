<?php

class watchdog extends lxdb
{
	static $__desc = array("", "", "service");
	static $__desc_port = array("", "", "port");
	static $__desc_servicename = array("", "", "servicename", "a=show");
	static $__desc_action = array("", "", "action");
	static $__desc_status = array("ef", "", "status");
	static $__desc_status_v_on = array("", "", "enabled");
	static $__desc_status_v_off = array("", "", "disabled");
	static $__rewrite_nname_const = Array("servicename", "syncserver");
	static $__acdesc_list = array("", "", "watchdog");
	static $__acdesc_update_update = array("", "", "edit");

	function createExtraVariables()
	{
		$this->__var_watchlist = array();
		$sync = isset($this->syncserver) ? (string) $this->syncserver : '';
		if ($sync === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $sync)) {
			return;
		}
		if (!function_exists('lfile_exists') || !lfile_exists('__path_admin_pass')) {
			return;
		}
		try {
			$sq = new Sqlite(null, 'watchdog');
			$rows = $sq->getRowsWhere("syncserver = '{$sync}'");
			$this->__var_watchlist = is_array($rows) ? $rows : array();
		} catch (Throwable $e) {
			$this->__var_watchlist = array();
		}
	}

	function createShowUpdateform()
	{
		$uflist['update'] = null;
		
		return $uflist;
	}

	function getId()
	{
		return strtil($this->nname, "___");
	}

	static function createListAlist($parent, $class)
	{
		$alist[] = "a=list&c=$class";

		return $alist;
	}

	function updateform($subaction, $param)
	{
		$vlist['servicename'] = array('M', null);
		$vlist['status'] = null;
	//	$vlist['port'] = array('M', null);
		$vlist['port'] = null;

	//	if ($this->isOn('added_by_system')) {
	//		$vlist['action'] = array('M', null);
	//	} else {
			$vlist['action'] = null;
	//	}

		return $vlist;
	}

	static function createListNlist($parent, $view)
	{
		$nlist['status'] = '5%';
		$nlist['servicename'] = '20%';
		$nlist['port'] = '10%';
		$nlist['action'] = '65%';

		return $nlist;
	}

/*
	// MR -- disabled because want able to deleted from list
	static function createListBlist($parent, $class)
	{
		return null;
	}
*/
	static function defaultWatchdogList()
	{
		return array(
			array('dns', '53', '__driver_dns', 'on'),
			array('ftp', 'pgrep ^pure-ftpd', '__driver_ftp', 'on'),
			array('mail', '25', '__driver_mail', 'on'),
			array('mysql', 'pgrep ^mysql', '__driver_mysql', 'on'),
			array('php-fpm', 'pgrep ^php-fpm', '__driver_php-fpm', 'on'),
			array('webcache', '8080', '__driver_web', 'off'),
			array('webproxy', '30080', '__driver_web', 'off'),
			array('web', '80', '__driver_web', 'on'),
		);
	}

	static function addDefaultWatchdog($pserver)
	{
		$pserver = is_string($pserver) ? $pserver : '';
		if ($pserver === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $pserver)) {
			$pserver = 'localhost';
		}

		foreach (self::defaultWatchdogList() as $row) {
			self::addOneWatchdog($pserver, $row[0], $row[1], $row[2], $row[3]);
		}
	}

	static function addOneWatchdog($pserver, $service, $port, $command, $status = null)
	{
		$pserver = is_string($pserver) ? $pserver : '';
		$service = is_string($service) ? $service : '';
		if ($pserver === '' || $service === ''
			|| !preg_match('/^[A-Za-z0-9._-]+$/', $pserver)
			|| !preg_match('/^[A-Za-z0-9._-]+$/', $service)) {
			return;
		}

		$v = new watchdog(null, $pserver, "{$service}___$pserver");
		if (function_exists('lfile_exists') && lfile_exists('__path_admin_pass')) {
			try {
				$v->get();
			} catch (Throwable $e) {
				return;
			}
			if ($v->dbaction !== 'add') {
				dprint("$service $pserver already exists...\n");
				return;
			}
		}

		$v->servicename = $service;
		$v->port = is_scalar($port) ? (string) $port : '';
		$v->action = is_scalar($command) ? (string) $command : '';
		$v->status = ($status) ? $status : "on";
		$v->added_by_system = "on";
		$v->syncserver = $pserver;
		$v->parent_clname = createClName('pserver', $pserver);
		$v->dbaction = 'add';
		$v->createExtraVariables();
		if (function_exists('lfile_exists') && lfile_exists('__path_admin_pass')) {
			try {
				$v->was();
			} catch (Throwable $e) {
			}
		}
	}

	static function add($parent, $class, $param)
	{
		$server = (is_object($parent) && isset($parent->nname)) ? (string) $parent->nname : 'localhost';
		if ($server === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $server)) {
			$server = 'localhost';
		}
		$param['syncserver'] = $server;
		$param['parent_clname'] = createClName('pserver', $server);
		$svc = isset($param['servicename']) ? trim((string) $param['servicename']) : '';
		if ($svc === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $svc)) {
			$svc = 'service';
		}
		$param['servicename'] = $svc;
		if (!isset($param['status']) || ($param['status'] !== 'on' && $param['status'] !== 'off')) {
			$param['status'] = 'off';
		}
		if (!isset($param['added_by_system'])) {
			$param['added_by_system'] = 'off';
		}

		return $param;
	}

	static function AddListForm($parent, $class)
	{
		return self::addform($parent, $class);
	}

	static function addform($parent, $class, $typetd = null)
	{
		$vlist['servicename'] = null;
		$vlist['status'] = null;
		$vlist['port'] = null;
		$vlist['action'] = null;

		$ret['action'] = 'add';
		$ret['variable'] = $vlist;

		return $ret;
	}
}
