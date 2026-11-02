<?php

class Dnstemplate extends DnsBase 
{
	// Core
	static $__desc = array("", "",  "DNS_template");
	static $__desc_nname = array("n", "",  "DNS_template_name", URL_SHOW);
	static $__desc_owner_f = array("e", "",  "owner");
	static $__desc_owner_f_v_on = array("e", "",  "owner");
	static $__desc_owner_f_v_off = array("e", "",  "not_owner");
	static $__desc_parent_clname = array("n", "",  "real_owner", URL_SHOW);
	static $__desc_used_f = array("e", "",  "used");
	static $__desc_used_f_v_on = array("", "",  "used");
	static $__desc_used_f_v_off = array("", "",  "not_used");
	static $__desc_webipaddress = array("", "",  "web_ipaddress");
	static $__desc_mmailipaddress = array("", "",  "mail_ipaddress");
	static $__acdesc_update_ipaddress = array("", "",  "ipaddress");

	function update($subaction, $param)
	{
		global $login;

		if ($this->getParentO()->getClName() !== $this->parent_clname) {
		//	throw new lxException($login->getThrow('template_not_owner'));
		}
		
		return $param;
	}

	static function createListAlist($parent, $class)
	{
		$alist[] = "a=list&c=$class";

		return $alist;
	}

	static function createListNlist($parent, $view)
	{
	//	$nlist['used_f'] = '5%';
	//	$nlist['owner_f'] = '5%';
		$nlist['nname'] = '80%';
		$nlist['parent_clname'] = '20%';
	//	$nlist['ipaddress'] = '10%';
		
		return $nlist;
	}

	function createUsed()
	{
		if (isset($this->used_f)) {
			return $this->used_f;
		}

		$db = new Sqlite($this->__masterserver, 'domaintemplate');
		$res = $db->getRowsWhere("dnstemplate = '$this->nname'");
		
		if ($res) {
			$this->used_f = 'on';
		} else {
			$this->used_f = 'off';
		}
		
		return $this->used_f;
	}

	function display($var)
	{

		return parent::display($var);
	}

	function isSelect()
	{
		return true;
		
		$this->createUsed();
		
		if ($this->isOn('used_f')) {
			return false;
		}
		return $this->isRightParent();
		
		return true;
	}

	static function add($parent, $class, $param)
	{
		global $login;

		if (!is_array($param)) {
			$param = array();
		}

		$name = strtolower(trim((string)($param['nname'] ?? '')));
		if (substr($name, -5) === '.dnst') {
			$name = substr($name, 0, -5);
		}
		$name = preg_replace('/[^a-z0-9.-]+/', '-', $name);
		$name = trim((string)$name, '.-');

		if ($name === '' || !preg_match('/^[a-z0-9]([a-z0-9.-]{0,58}[a-z0-9])?$/', $name)) {
			throw new lxException($login->getThrow('invalid_char_in_template_name'), '', $name);
		}

		if (strlen($name) > 60) {
			throw new lxException($login->getThrow('template_name_over_char_limit'), '', $name);
		}

		$param['nname'] = "{$name}.dnst";
		$param['shared'] = 'on';

		return $param;
	}

	static function AddListForm($parent, $class)
	{
		try {
			return self::addform($parent, $class);
		} catch (Throwable $e) {
			log_log('error', 'dnstemplate AddListForm: ' . $e->getMessage());

			return null;
		}
	}

	static function addform($parent, $class, $typetd = null)
	{
		global $login;

		$res = array();
		try {
			$got = Dnsbase::getIpaddressList($parent);
			if (is_array($got)) {
				$res = $got;
			}
		} catch (Throwable $e) {
			$res = array();
		}

		$vlist['nname'] = array('m', array('value' => 'default'));
		if ($res !== array()) {
			$vlist['webipaddress'] = array('s', $res);
			$vlist['mmailipaddress'] = array('s', $res);
		} else {
			$vlist['webipaddress'] = null;
			$vlist['mmailipaddress'] = null;
			$vlist['__m_message_pre'] = 'no_ip_address';
		}
		$vlist['nameserver_f'] = array('m', array('value' => 'ns1'));
		$vlist['secnameserver_f'] = array('m', array('value' => 'ns2'));
		$ret['action'] = 'add';
		$ret['variable'] = $vlist;

		return $ret;
	}

	static function initThisObjectRule($parent, $class, $name = null) { return  null; }
	
	static function initThisObject($parent, $class, $name = null)
	{
		$obj = new $class($parent->__masterserver, $parent->syncserver, $name);
		$obj->get();
		
		return $obj;
	}

	function isSync()
	{
		return false;
	}
}

