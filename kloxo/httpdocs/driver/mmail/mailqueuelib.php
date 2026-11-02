<?php

class mailqueue extends lxclass
{
	static $__desc = array("", "",  "mail_queue");

	static $__desc_nname = array("", "",  "id", "a=show");
	static $__desc_subject = array("", "",  "subject", "a=show");
	static $__desc_from = array("", "",  "from", "a=show");
	static $__desc_message = array("", "",  "message");
	static $__desc_log = array("", "",  "log");
	static $__desc_to = array("", "",  "to");
	static $__desc_type = array("", "",  "type");
	static $__desc_date = array("", "",  "date");
	static $__desc_size = array("", "",  "size");
	static $__desc_type_v_remote = array("", "",  "remote");
	static $__desc_type_v_local = array("", "",  "local");
	static $__acdesc_list = array("", "",  "mail_queue");

	function get() {}
	
	function write() {}

	function createShowUpdateform()
	{
		$uflist['update'] = null;
		
		return $uflist;
	}

	function updateform($subaction, $param)
	{
		$vlist['message'] = array('T', $this->message);
		$vlist['log'] = array('T', $this->log);
		$vlist['__v_button'] = array();
		
		return $vlist;
	}

	static function createListAlist($parent, $class)
	{
		$alist[] = "a=list&c=$class";
		
		return $alist;
	}

	static function createListBlist($parent, $class)
	{
		$blist[] = array("a=update&sa=mailqueuedelete");
		$blist[] = array("a=update&sa=mailqueueflush", 1);
		
		return $blist;
	}

//	function isSelect() { return false; }

	static function canGetSingle() { return true; }

	static function queueDriverApp($parent)
	{
		global $gbl;

		$app = null;
		if (is_object($parent) && is_object($gbl)) {
			$sync = method_exists($parent, 'getSyncServerForChild')
				? $parent->getSyncServerForChild('mailqueue') : $parent->syncserver;
			$app = $gbl->getSyncClass($parent->__masterserver, $sync, 'mailqueue');
		}

		foreach (array('/usr/sbin/postqueue', '/usr/bin/postqueue') as $p) {
			if (is_executable($p)) {
				return 'postfix';
			}
		}

		return (is_string($app) && $app !== '') ? $app : 'qmail';
	}

	static function initThisObject($parent, $class, $name = null)
	{
		$ob = new mailqueue(null, $parent->syncserver, $name);
		$app = self::queueDriverApp($parent);
		$sync = method_exists($parent, 'getSyncServerForChild')
			? $parent->getSyncServerForChild('mailqueue') : $parent->syncserver;

		try {
			$res = kloxo_rl_driver($parent->__masterserver, $sync, 'mailqueue', $app, 'readSingleMail', array($name));
		} catch (Throwable $e) {
			$res = array();
		}

		if (!is_array($res)) {
			$res = array();
		}

		foreach($res as $k => $r) {
			$ob->$k = $r;
			$ob->parent_clname = $parent->getClName();
		}
		
		return $ob;
	}

	function canGetSelfList() { return false; }

	function display($var)
	{
		if ($var === 'size') {
			return getKBOrMB(round($this->$var/1024));
		}
		
		return parent::display($var);
	}

	static function createListNlist($parent, $view)
	{
		$nlist['nname'] = '5%';
		$nlist['type'] = '5%';
		$nlist['from'] = '25%';
		$nlist['to'] = '25%';
		$nlist['subject'] = '50%';
		$nlist['date'] = '10%';
		$nlist['size'] = '5%';
		
		return $nlist;
	}

	static function initThisListRule($parent, $class) { return null; }

	static function initThisList($parent, $class)
	{
		$app = self::queueDriverApp($parent);
		$sync = method_exists($parent, 'getSyncServerForChild')
			? $parent->getSyncServerForChild('mailqueue') : $parent->syncserver;

		try {
			$res = kloxo_rl_driver($parent->__masterserver, $sync, 'mailqueue', $app, 'readMailqueue', array());
		} catch (Throwable $e) {
			return array();
		}

		if (!is_array($res)) {
			return array();
		}

		foreach($res as &$_r) {
			$_r['syncserver'] = is_object($parent) && isset($parent->syncserver) ? $parent->syncserver : 'localhost';
			$_r['parent_clname'] = $parent->getClName();
		}
		unset($_r);

		return $res;
	}
}
