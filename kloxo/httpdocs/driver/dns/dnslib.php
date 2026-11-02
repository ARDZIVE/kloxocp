<?php

class Dns extends DnsBase
{
	// Core
	static $__desc = array("", "", "DNS");
	static $__desc_nname = array("", "", "domain_name", "a=show");
	static $__desc_webipaddress = array("", "", "web_ipaddress");
	static $__desc_mmailipaddress = array("", "", "mail_ipaddress");
	static $__acdesc_show = array("", "", "Manage DNS");
	static $__acdesc_list = array("", "", "dns");
	static $__table = 'dns';

	function createExtraVariablesHyperVM()
	{
		global $gbl, $sgbl, $login, $ghtml;

		$revc = $login->getObject('general')->reversedns_b;

		$this->syncserver = implode(",", $revc->dns_slave_list);
		$this->fixDateSerial();

		$dbaddon = new Sqlite(null, "dns");
		$addr = $dbaddon->getTable(array('nname'));
		$serverlist = explode(",", $this->syncserver);

		foreach ($serverlist as $server) {
			$dlistv = "__var_domainlist_$server";
			$this->$dlistv = $addr;
		}
	}

	function createExtraVariables()
	{
		// Not here. Two different extra variables are needed, so they are created in synctosystem.
		// Brought back here, since the secondary server concept has been abolished in favor of multiple primary servers.

		global $gbl, $sgbl, $login, $ghtml;

		if ($sgbl->isHyperVm()) {
			$this->createExtraVariablesHyperVM();

			return;
		}

		$addr = array();
		$serverlist = explode(',', (string)($this->syncserver ?? ''));
		try {
			$db = new Sqlite($this->__masterserver ?? null, "dns");
			foreach ($serverlist as $server) {
				$server = trim((string)$server);
				if ($server === '') {
					continue;
				}
				$nlist = $db->getRowsWhere("syncserver LIKE '%$server%'", array('nname'));
				$dlistv = "__var_domainlist_$server";
				$this->$dlistv = is_array($nlist) ? $nlist : array();
			}
		} catch (Throwable $e) {
		}

		try {
			$dbaddon = new Sqlite(null, "addondomain");
			$got = $dbaddon->getTable(array('nname'));
			$addr = is_array($got) ? $got : array();
		} catch (Throwable $e) {
			$addr = array();
		}

		foreach ($serverlist as $server) {
			$server = trim((string)$server);
			if ($server === '') {
				continue;
			}
			$dlistv = "__var_domainlist_$server";
			$cur = (isset($this->$dlistv) && is_array($this->$dlistv)) ? $this->$dlistv : array();
			$this->$dlistv = lx_array_merge(array($cur, $addr));
		}

		$this->fixDateSerial();

		$this->__var_addonlist = array();
		try {
			$parent = $this->getParentO();
			if (is_object($parent) && method_exists($parent, 'getList')) {
				$got = $parent->getList('addondomain');
				if (is_array($got)) {
					$this->__var_addonlist = $got;
				}
			}
		} catch (Throwable $e) {
			$this->__var_addonlist = array();
		}

		try {
			$mydb = new Sqlite(null, "ipaddress");
			$sync = str_replace("'", '', (string)($this->syncserver ?? ''));
			$got = $mydb->getRowsWhere("syncserver = '$sync'", array('ipaddr', 'nname'));
			$this->__var_ipssllist = is_array($got) ? $got : array();
		} catch (Throwable $e) {
			$this->__var_ipssllist = array();
		}
	}

	function fixDateSerial()
	{
		$ddate = @ date("Ymd");

		// MR -- error for create new domain
		$this->serial = (isset($this->serial)) ? $this->serial + 1 : 0;

		if ($this->serial > 99) {
			$this->serial = 0;
		}

		$v = $this->serial;

		if ($v < 10) {
			$v = "0$v";
		}

		$ddate = "$ddate{$v}";

		$this->__var_ddate = $ddate;

		$p = $this->getClientParentO();

	/*
		// MR -- move to hostmaster param
		if ($p->contactemail) {
			$this->__var_email = str_replace("@", ".", $p->contactemail);
		} else {
			$this->__var_email = "admin.{$this->nname}";
		}
	*/
	}

	static function switchProgramPre($old, $new)
	{
		// issue #589 - Change httpd config structure

		// MR -- and then make a simple
		exec_class_method("dns__{$old}", "uninstallMe");
		exec_class_method("dns__{$new}", "installMe");
	}

	static function switchProgramPost($old, $new)
	{
	//	createRestartFile($new);
		createRestartFile("restart-dns");
	}

	static function removeOtherDriver()
	{
		// MR -- and then make a simple
	//	removeOtherDrivers($class = 'dns', $nolog = true);
	}

	function inheritSynserverFromParent() { return false; }

	function oldsyncToSystem()
	{
		global $gbl, $sgbl, $login, $ghtml;

		//Clients who do not have dns permission should be able to create domains.
		if (!$login->priv->isOn('dns_manage_flag')) {
			if ($this->dbaction === 'update') {
				throw new lxException($login->getThrow('no_dns_permission'));
			}
		}
	}

	static function add($parent, $class, $param)
	{
		global $gbl, $sgbl, $login, $ghtml;

		if (!is_array($param)) {
			$param = array();
		}
		validate_domain_name((string)($param['nname'] ?? ''));

		$revc = null;
		try {
			$gen = (is_object($login) && method_exists($login, 'getObject'))
				? $login->getObject('general') : null;
			$revc = (is_object($gen) && isset($gen->reversedns_b)) ? $gen->reversedns_b : null;
		} catch (Throwable $e) {
			$revc = null;
		}
		$param['nameserver_f'] = (is_object($revc) && isset($revc->primarydns))
			? $revc->primarydns : '';
		$param['secnameserver_f'] = (is_object($revc) && isset($revc->secondarydns))
			? $revc->secondarydns : '';

		return $param;
	}

	static function addform($parent, $class, $typetd = null)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$revc = $login->getObject('general')->reversedns_b;

		$res = get_namelist_from_objectlist($parent->vmipaddress_a);

		if (!$res) {
			throw new lxException($login->getThrow('no_ip_address'));
		}

		$vlist['nname'] = null;
		$vlist['webipaddress'] = array('s', $res);
		$vlist['mmailipaddress'] = array('s', $res);
		$vlist['nameserver_f'] = array('M', $revc->primarydns);
		$vlist['secnameserver_f'] = array('M', $revc->secondarydns);
		$ret['action'] = 'add';
		$ret['variable'] = $vlist;

		return $ret;
	}
}

class all_dns extends dns
{

	static $__desc = array("n", "", "all_dns");
	static $__acdesc_list = array("", "", "all_dns");
	static $__desc_parent_name_f = array("n", "", "owner");
	static $__desc_parent_clname = array("n", "", "owner");

	function isSelect() { return false; }

	static function initThisListRule($parent, $class)
	{
		global $login;

		if (!$parent->isAdmin()) {
			throw new lxException($login->getThrow("only_admin_can_access"));
		}

		return "__v_table";
	}

	static function createListAlist($parent, $class)
	{
		global $gbl, $sgbl, $login, $ghtml;

	//	$alist[] = "a=list&c=reversedns";

		if ($login->isAdmin()) {
			$alist[] = "o=general&a=updateform&sa=reversedns";
			$alist[] = "a=list&c=all_dns";
			$alist[] = "a=list&c=all_reversedns";
		}

		return $alist;
	}

	static function createListSlist($parent)
	{
		$nlist['nname'] = null;
		$nlist['parent_clname'] = null;

		return $nlist;
	}

	static function createListNlist($parent, $view)
	{
		$nlist['nname'] = '50%';
		$nlist['parent_name_f'] = '50%';

		return $nlist;
	}
}

