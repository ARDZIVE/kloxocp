<?php

class auxiliary extends Lxclient 
{
	static $__desc = array("", "", "auxiliary_login");
	static $__desc_nname = array("", "", "auxiliary_login", "a=show");
	static $__desc_delete_flag = array("f", "", "delete_privilege");
	static $__desc_pserver_flag = array("f", "", "show_server");
	static $__acdesc_update_privilege = array("", "", "privileges");
	static $__table =  'auxiliary';
	
	function isSync() { return false; }

	function createShowUpdateform()
	{
		$uflist['privilege'] = null;
		$uflist['password'] = null;
		
		return $uflist;
	}

	function updateform($subaction, $param)
	{
		if ($subaction === 'privilege') {
			$vlist['delete_flag'] = null;
			$vlist['pserver_flag'] = null;
			return $vlist;
		}
		
		return parent::updateform($subaction, $param);
	}

	static function AddListForm($parent, $class)
	{
		return self::addform($parent, $class);
	}

	static function addform($parent, $class, $typetd = null)
	{
		$vlist['nname'] = array('m', array('posttext' => ".aux"));
		$vlist['delete_flag'] = null;
		$vlist['pserver_flag'] = null;
		$vlist['password'] = null;
		$ret['variable'] = $vlist;
		$ret['action'] = 'add';
		
		return $ret;
	}

	static function createListAlist($parent, $class)
	{
		$alist[] = "a=list&c=auxiliary";
	//	$alist['__v_dialog_add'] = "a=addform&c=auxiliary";

		if ($parent->isAdmin()) {
			$alist[] = "a=list&c=all_auxiliary";
		}

		return $alist;
	}

	static function add($parent, $class, $param)
	{
		global $login;

		$pass = isset($param['password']) ? (string) $param['password'] : '';
		if ($pass === '') {
			throw new lxException($login->getThrow('password_cannot_be_null'), 'password');
		}

		$param['nname'] = self::normalizedLoginName($parent, isset($param['nname']) ? (string) $param['nname'] : '');
		$param['cpstatus'] = 'on';
		$param['status'] = 'on';
		$param['realpass'] = $pass;
		$param['password'] = function_exists('kloxo_password_hash')
			? kloxo_password_hash($pass)
			: crypt($pass, '$1$'.randomString(8).'$');
		$param['delete_flag'] = (isset($param['delete_flag']) && $param['delete_flag'] === 'on') ? 'on' : 'off';
		$param['pserver_flag'] = (isset($param['pserver_flag']) && $param['pserver_flag'] === 'on') ? 'on' : 'off';

		return $param;
	}

	static function normalizedLoginName($parent, $nname)
	{
		global $login;

		$nname = strtolower(trim((string) $nname));
		if (cse($nname, '.aux')) {
			$nname = substr($nname, 0, -4);
		}
		if ($nname === '' || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $nname)) {
			throw new lxException($login->getThrow('invalid_auxiliary_login_name'), 'nname', $nname);
		}

		$nname .= '.aux';
		if (is_object($parent) && method_exists($parent, 'isAdmin') && !$parent->isAdmin()) {
			$pn = strtolower((string) $parent->nname);
			if ($pn === '' || !preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $pn)) {
				throw new lxException($login->getThrow('invalid_auxiliary_login_name'), 'nname', $pn);
			}
			$nname = $pn . '_' . $nname;
		}

		return $nname;
	}

	static function createListNlist($parent, $view = null)
	{
		$nlist['cpstatus'] = '3%';
		$nlist['nname'] = '70%';
		$nlist['delete_flag'] = '10%';
		$nlist['pserver_flag'] = '10%';

		return $nlist;
	}

	static function initThisObjectRule($parent, $class, $name = null) { return null ; }
	
	static function initThisObject($parent, $class, $name = null)
	{
		if (is_string($name) && $name !== '') {
			$obj = new auxiliary(isset($parent->__masterserver) ? $parent->__masterserver : null,
				isset($parent->__readserver) ? $parent->__readserver : null, $name);
			$obj->get();

			return $obj;
		}

		return isset($parent->__auxiliary_object) ? $parent->__auxiliary_object : null;
	}
}

class all_auxiliary extends auxiliary
{
	static $__desc = array("", "", "all_auxiliary");
	static $__desc_parent_name_f = array("", "", "parent");

	static function initThisListRule($parent, $class)
	{
		global $login;
	
		if (!$parent->isAdmin()) {
			throw new lxException($login->getThrow("only_admin_can_access"));
		}

		return "__v_table";
	}

	static function AddListForm($parent, $class)
	{
		return false;
	}
}
