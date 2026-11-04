<?php

class Service extends Lxdb
{
	static $__desc = array("", "", "service");

	static $__desc_nname = array("", "", "name");
	static $__desc_servicename = array("", "", "name");
	static $__desc_install_state = array("e", "", "i");
	static $__desc_install_state_v_on = array("", "", "installed");
	static $__desc_install_state_v_dull = array("", "", "not_installed");
	static $__desc_syncserver = array("", "", "server_name");
	static $__desc_description = array("", "", "description");
	static $__desc_grepstring = array("", "", "grep:string_to_search_in_process_list");
	static $__desc_boot_state = array("e", "", "sb:boot_status", "a=update&sa=toggle_boot_state");
	static $__desc_boot_state_v_on = array("", "", "service_started_at_boot");
	static $__desc_boot_state_v_off = array("", "", "service_not_started_at_boot");
	static $__desc_boot_state_v_dull = array("", "", "service_not_installed");

	static $__desc_state = array("e", "", "state", URL_TOGGLE_STATE);
	static $__desc_button_stop_f = array("b", "", "", "a=update&sa=stop");
	static $__desc_button_restart_f = array("b", "", "", "a=update&sa=restart");
	static $__desc_button_start_f = array("b", "", "", "a=update&sa=start");

	static $__desc_state_v_on = array("", "", "running");
	static $__desc_state_v_off = array("", "", "stopped");
	static $__desc_state_v_dull = array("", "", "not_installed");

	static $__acdesc_update_stop = array("", "", "stop");
	static $__acdesc_update_start = array("", "", "start");
	static $__acdesc_update_restart = array("", "", "restart");

	static $__rewrite_nname_const = Array("servicename", "syncserver");

	//// These functions shouldn't exist. This is very wrong.

	function update($subaction, $param)
	{
		$param['non_f'] = 'hello';
		
		return $param;
	}

	static function createListAlist($parent, $class)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$alist[] = "a=list&c=$class";
		
		return $alist;
	}

	static function createListNlist($parent, $view)
	{
		global $gbl, $sgbl, $login, $ghtml;
		
		$driverapp = $gbl->getSyncClass($parent->__masterserver, $parent->__readserver, 'service');

	//	$nlist["install_state"] = "5%";
		$nlist["boot_state"] = "5%";
		$nlist["state"] = "5%";
	//	$nlist["nname"] = "10%";
	//	$nlist["syncserver"] = "10%";
		$nlist["servicename"] = "10%";

		if ($driverapp === 'redhat') {
			$nlist["grepstring"] = "10%";
		}

		$nlist["button_start_f"] = '5%';
		$nlist["button_stop_f"] = '5%';
		$nlist["button_restart_f"] = '5%';
		$nlist["description"] = "60%";

		return $nlist;
	}

	function isAction($var)
	{
		if ($var === 'state' || $var === 'boot_state') {
			if ($this->install_state === 'dull') {
				return false;
			}
		}

		$svc = isset($this->servicename) ? (string) $this->servicename : '';
		$protected = class_exists('Service__Linux', false) && Service__Linux::isProtectedUnit($svc);
		// Hide stop / restart / state-toggle / boot-toggle for panel-critical units
		// (MariaDB, kloxo-php, kloxo-web, kloxo-backend). Stopping them blanks the UI.
		if ($protected) {
			if ($var === 'state' || $var === 'boot_state'
				|| $var === 'button_stop_f' || $var === 'button_restart_f') {
				return false;
			}
		}

		return true;
	}

	function assertMayControl($act)
	{
		global $login;

		$svc = isset($this->servicename) ? (string) $this->servicename : '';
		$unit = class_exists('Service__Linux', false)
			? Service__Linux::resolveInstalledUnit($svc)
			: $svc;
		if ($unit === '') {
			$unit = $svc;
		}
		if (!class_exists('Service__Linux', false) || Service__Linux::mayControlUnit($unit, (string) $act)) {
			return;
		}
		$msg = (is_object($login) && method_exists($login, 'getThrow'))
			? $login->getThrow('cannot_control_critical_service')
			: 'cannot_control_critical_service';
		throw new lxException($msg, '', $svc !== '' ? $svc : $unit);
	}

	static function add($parent, $class, $param)
	{
		$param['syncserver'] = $parent->nname;
		$param['boot_state'] = 'on';

		$param['state'] = 'off';
		
		return $param;
	}

	static function addform($parent, $class, $typetd = null)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$driverapp = $gbl->getSyncClass($parent->__masterserver, $parent->__readserver, 'service');

		$list = kloxo_rl_driver($parent->__masterserver, $parent->__readserver, 'service', $driverapp, 'getServiceList', null);

		if (!is_array($list) || $list === array()) {
			$list = array('httpd', 'lxmail');
		}

		$vlist['servicename'] = array('s', $list);
		$vlist['description'] = array('m', null);

		if ($driverapp === 'redhat') {
			$vlist['grepstring'] = array('m', null);
		}

		$ret['variable'] = $vlist;
		$ret['action'] = "add";

		return $ret;
	}

	static function searchVar()
	{
		return "description";
	}

	function display($var)
	{
		global $gbl, $sgbl, $login, $ghtml;

		if ($var === 'state' || $var === 'boot_state') {
			if ($this->install_state === 'dull') {
				return 'dull';
			}
		}
		
		return parent::display($var);
	}

	function updateToggle_State($param)
	{
		// Turning a running critical unit off would stop MariaDB / panel PHP and
		// blank every page with "No such file or directory" (missing DB socket).
		$next = $this->isOn('state') ? 'off' : 'on';
		$this->assertMayControl($next === 'on' ? 'start' : 'stop');
		$this->state = $next;
		$this->setUpdateSubaction('toggle_state');

		return null;
	}

	function updateToggle_boot_state($param)
	{
		$next = $this->isOn('boot_state') ? 'off' : 'on';
		$this->assertMayControl($next === 'on' ? 'enable' : 'disable');
		$this->boot_state = $next;
		$this->setUpdateSubaction('toggle_boot_state');

		return null;
	}

	function updateStart($param)
	{
		$this->assertMayControl('start');
		$this->state = 'on';
		$this->setUpdateSubaction('start');

		return null;
	}

	function updateStop($param)
	{
		$this->assertMayControl('stop');
		$this->state = 'off';
		$this->setUpdateSubaction('stop');

		return null;
	}

	function updateRestart($param)
	{
		$this->assertMayControl('restart');
		$this->setUpdateSubaction('restart');

		return null;
	}

	static function canGetSingle()
	{
		return true;
	}

	static function parseServiceNname($name)
	{
		$name = (string) $name;
		if (preg_match('/^(.+)___(.+)$/', $name, $m)) {
			return array($m[1], $m[2]);
		}

		return array($name, 'localhost');
	}

	static function isSafeServiceId($name)
	{
		return is_string($name) && $name !== '' && preg_match('/^[A-Za-z0-9_.:@+-]+$/', $name);
	}

	static function serverForParent($parent)
	{
		if (is_object($parent) && method_exists($parent, 'getClass')
			&& strtolower((string) $parent->getClass()) === 'pserver'
			&& isset($parent->nname) && self::isSafeServiceId($parent->nname)) {
			return $parent->nname;
		}

		return 'localhost';
	}

	static function initThisListRule($parent, $class)
	{
	}

	static function initThisObject($parent, $class, $name = null)
	{
		list($svc, $server) = self::parseServiceNname($name);
		if (!self::isSafeServiceId($svc)) {
			$svc = 'unknown';
		}
		if (!self::isSafeServiceId($server)) {
			$server = 'localhost';
		}

		$master = is_object($parent) && isset($parent->__masterserver) ? $parent->__masterserver : null;
		$nname = $svc . '___' . $server;
		$ob = new Service($master, $server, $nname);
		$row = null;

		try {
			$sql = new Sqlite($master, 'service');
			$res = $sql->getRowsWhere("nname = '" . addslashes($nname) . "'");
			if (is_array($res) && isset($res[0]) && is_array($res[0])) {
				$row = $res[0];
			}
		} catch (Throwable $e) {
			$row = null;
		}

		if (is_array($row)) {
			$ob->setFromArray($row);
		} else {
			$ob->nname = $nname;
			$ob->servicename = $svc;
			$ob->syncserver = $server;
			$ob->parent_clname = 'pserver-' . $server;
			$ob->description = $svc;
			$ob->boot_state = 'off';
			$ob->state = 'off';
			$installed = class_exists('Service__Linux')
				? (Service__Linux::resolveInstalledUnit($svc) !== '')
				: (function_exists('isServiceExists') && isServiceExists($svc));
			$ob->install_state = $installed ? 'on' : 'dull';
			$ob->dbaction = 'clean';
		}

		return $ob;
	}

	static function defaultServiceList()
	{
		return array(
			'nginx', 'apache2', 'bind9', 'pdns', 'exim4', 'postfix', 'dovecot',
			'rspamd', 'mariadb', 'php-fpm', 'kloxo-php', 'hiawatha', 'pure-ftpd',
		);
	}

	static function catalogServiceNames()
	{
		$names = self::defaultServiceList();
		$php = class_exists('Service__Linux') ? Service__Linux::phpFpmUnitNames() : array();
		if (is_array($php) && $php !== array()) {
			$names = array_values(array_filter($names, function ($n) {
				return $n !== 'php-fpm';
			}));
			foreach ($php as $u) {
				if (is_string($u) && $u !== '' && !in_array($u, $names, true)) {
					$names[] = $u;
				}
			}
		}

		return $names;
	}

	static function serviceDescription($svc)
	{
		global $sgbl;

		$svc = (string) $svc;
		if (isset($sgbl) && is_object($sgbl) && isset($sgbl->__var_service_desc[$svc])
			&& is_string($sgbl->__var_service_desc[$svc]) && $sgbl->__var_service_desc[$svc] !== '') {
			return $sgbl->__var_service_desc[$svc];
		}
		if (preg_match('/^php[0-9.]+-fpm$/', $svc) === 1) {
			return 'Php Fastcgi Process Manager';
		}

		return $svc;
	}

	static function onlyInstalledRows($rows)
	{
		$out = array();
		if (!is_array($rows)) {
			return $out;
		}
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			if (isset($row['install_state']) && $row['install_state'] === 'on') {
				$out[] = $row;
			}
		}

		return $out;
	}

	static function initThisList($parent, $class)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$server = self::serverForParent($parent);
		$pcl = 'pserver-' . $server;
		$parentCl = (is_object($parent) && method_exists($parent, 'getClname'))
			? (string) $parent->getClname() : $pcl;

		$list = array();
		try {
			$sql = new Sqlite(is_object($parent) ? $parent->__masterserver : null, 'service');
			$where = "syncserver = '" . addslashes($server) . "'";
			if ($parentCl !== '' && $parentCl !== $pcl) {
				$where .= " OR parent_clname = '" . addslashes($parentCl) . "'";
			}
			$where .= " OR parent_clname = '" . addslashes($pcl) . "'";
			$got = $sql->getRowsWhere($where);
			if (is_array($got)) {
				$list = $got;
			}
		} catch (Throwable $e) {
			$list = array();
		}

		$nlist = array();
		foreach ($list as $l) {
			if (!is_array($l) || !isset($l['servicename'])) {
				continue;
			}
			if (!isset($l['nname']) || $l['nname'] === '') {
				$l['nname'] = $l['servicename'] . '___' . $server;
			}
			if (!isset($l['syncserver']) || $l['syncserver'] === '') {
				$l['syncserver'] = $server;
			}
			$nlist[$l['servicename']] = $l;
		}

		foreach (self::catalogServiceNames() as $svc) {
			if (isset($nlist[$svc])) {
				continue;
			}
			$nlist[$svc] = array(
				'nname' => $svc . '___' . $server,
				'servicename' => $svc,
				'syncserver' => $server,
				'parent_clname' => $pcl,
				'description' => self::serviceDescription($svc),
				'grepstring' => class_exists('Service__Linux')
					? Service__Linux::unitName($svc) : $svc,
				'boot_state' => 'off',
				'state' => 'off',
				'install_state' => 'dull',
			);
		}

		$master = is_object($parent) && isset($parent->__masterserver) ? $parent->__masterserver : null;
		$read = is_object($parent) && isset($parent->__readserver) ? $parent->__readserver : $server;
		$driverapp = null;
		try {
			$driverapp = $gbl->getSyncClass($master, $read, 'service');
		} catch (Throwable $e) {
			$driverapp = null;
		}

		$probed = $nlist;
		if ($driverapp) {
			try {
				$res = kloxo_rl_driver($master, $read, 'service', $driverapp, 'getServiceDetails', array($nlist));
				if (is_array($res) && $res !== array()) {
					$probed = $res;
				}
			} catch (Throwable $e) {
				$probed = $nlist;
			}
		}
		if (!is_array($probed) || $probed === array()) {
			try {
				$probed = Service__Linux::getServiceDetails($nlist);
			} catch (Throwable $e) {
				$probed = $nlist;
			}
		}

		return self::onlyInstalledRows(array_values(is_array($probed) ? $probed : array()));
	}
}

