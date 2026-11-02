<?php

class ftpsession extends Lxclass
{
	static $__desc = array("", "",  "ftp_session");

	static $__desc_pid = array("", "", "pid");
	static $__desc_nname = array("", "", "pid");
	static $__desc_account = array("", "", "account");
	static $__desc_time = array("", "", "time");
	static $__desc_file = array("", "", "file");
	static $__desc_host = array("", "", "host");
	static $__desc_state = array("", "", "state");

	function get() {}
	function write() {}

	static function createListAlist($parent, $class)
	{
		$alist[] = "a=list&c=$class";

		return $alist;
	}
	static function createListNlist($parent, $view)
	{

		$nlist['pid'] = '10%';
		$nlist['state'] = '10%';
		$nlist['account'] = '50%';
		$nlist['time'] = '10%';
		$nlist['file'] = '10%';
		$nlist['host'] = '10%';

		return $nlist;
	}

	static function initThisListRule($parent, $class) { return null; }

	static function initThisList($parent, $class)
	{
		if (!is_object($parent)) {
			return array();
		}

		try {
			if (method_exists($parent, 'getClass') && $parent->getClass() === 'client') {
				$username = isset($parent->username) ? (string)$parent->username : '';
				if ($username === '') {
					return array();
				}
				$res = rl_exec_in_driver($parent, $class, 'getFtpList', array($username));
			} else {
				$res = rl_exec_in_driver($parent, $class, 'getFtpList', array());
			}
		} catch (Throwable $e) {
			return array();
		}

		if (!is_array($res) || $res === array()) {
			return array();
		}

		$out = array();
		$clname = method_exists($parent, 'getClName') ? $parent->getClName() : '';
		foreach ($res as $row) {
			if (!is_array($row)) {
				continue;
			}
			$row['parent_clname'] = $clname;
			$out[] = $row;
		}

		return $out;
	}
}
