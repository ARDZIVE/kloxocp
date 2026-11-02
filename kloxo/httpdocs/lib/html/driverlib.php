<?php

class driver_b extends Lxaclass
{

	static $__desc =  array("", "",  "database_types");
	static $__desc_nname =  array("", "",  "database_types");

	function hasDriverClass() { return false; }
}


class Driver extends Lxdb
{

	static $__desc =  array("", "",  "driver");
	static $__desc_driver_b =  array("", "",  "driver");

	static $__acdesc_update_update = array("", "",  "driver_configuration");

	static function initThisObjectRule($parent, $class, $name = null) { return null; }

	static function initThisObject($parent, $class, $name = null)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$gbl->getSyncClass(null, null, 'fake');
		
		if (isLocalhost($parent->__masterserver)) {
			$master = 'localhost';
		} else {
			$master = $parent->__masterserver;
		}

		$server = is_object($parent) && isset($parent->syncserver) && $parent->syncserver !== ''
			? $parent->syncserver : 'localhost';

		if (!isset($gbl->driver) || !is_array($gbl->driver)
			|| !isset($gbl->driver[$master]) || !is_array($gbl->driver[$master])
		) {
			$gbl->loaddriverappInfo($master);
		}

		if (isset($gbl->driver[$master][$server]) && is_object($gbl->driver[$master][$server])) {
			return $gbl->driver[$master][$server];
		}

		$sync = new Driver($master, null, $server);
		$sync->nname = $server;
		$sync->driver_b = new driver_b(null, null, $server);

		return $sync;
	}

	function isSync()
	{
		return false;
	}

	function hasDriverClass() { return false; }
	
	function createShowUpdateform()
	{
		$uform['update'] = null;
		
		return $uform;
	}

	function createVlistDriver(&$vlist, $driver)
	{
		if (!is_array($driver)) {
			return;
		}

		if (!isset($this->driver_b) || !is_object($this->driver_b)) {
			$this->driver_b = new driver_b(null, null, $this->nname);
		}

		foreach($driver as $k => $v) {
			$descr = get_classvar_description($k);
			
			if (!$descr) {
				continue;
			}
			
			if (!is_array($v) && is_string($v) && csb($v, "__v")) {
				continue;
			}
			
			$field = "pg_$k";
			$current = isset($this->driver_b->$field) ? $this->driver_b->$field : '';

			if (is_array($driver[$k])) {
				$ar = implode(", ", $driver[$k]);
				$vlist["driver_b_s_pg_$k"] = array('M', create_simpleObject(array('descr' => $descr, 'value' => "{$current} ($ar)")));
			} else {
				$vlist["driver_b_s_pg_$k"] = array('M', create_simpleObject(array('descr' => $descr, 'value' => "{$current} ($driver[$k])")));
			}
		}

		$vlist['__v_button'] = array();
	}

	function updateform($subaction, $param)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$parent = $this->getParentO();
		$os = (is_object($parent) && isset($parent->ostype) && $parent->ostype !== '')
			? $parent->ostype : 'debian';
		$inc = ($os === 'debian' || $os === 'ubuntu') ? 'debian.inc' : 'rhel.inc';
		$incpath = "../file/driver/{$inc}";
		if (is_file($incpath)) {
			include_once $incpath;
		}
		if (!isset($driver) || !is_array($driver)) {
			$driver = array();
		}

		$vlist = null;

		$this->createVlistDriver($vlist, $driver);
	
		$list = module::getModuleList();

		$driver = null;
		
		foreach((array) $list as $l) {
		//	$mod = getreal("/module/") . "/$l";
		//	include_once "$mod/lib/driver.inc";
			
			$dlist = $driver[$os];
			
			if (isset($driver['all'])) {
				$dlist = lx_array_merge(array($dlist, $driver['all']));
			}
			
			$this->createVlistDriver($vlist, $dlist);
		}
	
		return $vlist;
	}
}

