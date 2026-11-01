<?php

class clientmail extends lxclass {

	static $__desc = array("n", "",  "mails_per_client");
	static $__desc_clientname = array("n", "",  "clientname");
	static $__desc_mailnum = array("n", "",  "number_of_mails");
	static $__acdesc_list = array("n", "",  "mails_per_client");

	function get() {}

	function write() {}

	function doSyncToSystem() {}

	static function createListNlist($parent, $view)
	{
		$nlist['clientname'] = '100%';
		$nlist['mailnum'] = '10%';
		return $nlist;
	}

	function isSelect() { return false ; }

	static function createListBlist($parent, $class) { return null; }

	static function initThisListRule($parent, $class) { return null; }

	static function initThisList($parent, $class)
	{
		$res = rl_exec_get(null, $parent->syncserver,  array("clientmail", "readtotallog"), array());

		return is_array($res) ? $res : array();
	}

	static function mailLogPath()
	{
		foreach (array('/var/log/mail.log', '/var/log/maillog') as $p) {
			if (is_readable($p)) {
				return $p;
			}
		}

		return '';
	}

	static function readtotallog()
	{
		$list = self::readsmtpLog();
		$nlist = self::readMaillog();
		if (!is_array($list)) {
			$list = array();
		}
		if (!is_array($nlist)) {
			$nlist = array();
		}
		$total = lx_merge_good($list, $nlist);
		if (!is_array($total)) {
			return array();
		}

		$res = array();
		foreach($total as $k => $v) {
			$re['clientname'] = $k;
			$re['nname'] = $k;
			$re['mailnum'] = $v;
			$res[$k] = $re;
		}

		return $res;
	}

	static function createListAlist($parent, $class)
	{
		$alist[] = "a=list&c=$class";

		return $alist;
	}

	static function openMailLog()
	{
		$path = self::mailLogPath();
		if ($path === '') {
			return false;
		}
		$fp = @fopen($path, 'r');

		return is_resource($fp) ? $fp : false;
	}

	static function readsmtpLog()
	{
		$total = array();
		$fp = self::openMailLog();
		if ($fp === false) {
			return $total;
		}

		$date = time() - 24 * 3600 * 2;
		$fsize = lxfile_size(self::mailLogPath());
		$pos = lxlabsFindRightPosition($fp, $fsize, $date, time());

		if ($pos === -1) {
			rewind($fp);
		}

		while (!feof($fp)) {
			$s = fgets($fp);
			if (!is_string($s)) {
				break;
			}
			$s = trim($s);

			if (csa($s, "client allowed to relay")) {
				$v = strfrom($s, "rcpt: from <");
				$v = strtilfirst($v, ">");
				$s = explode(":", $v);
				$id = isset($s[1]) ? $s[1] : '';
				if ($id === '') {
					continue;
				}
				if (!isset($total[$id])) {
					$total[$id] = 1;
				} else {
					$total[$id]++;
				}
				continue;
			}

			if (preg_match('/postfix\/(?:qmgr|cleanup).*from=<([^>]+)>/', $s, $m) === 1) {
				$id = $m[1];
				if ($id === '' || $id === '<>') {
					continue;
				}
				if (!isset($total[$id])) {
					$total[$id] = 1;
				} else {
					$total[$id]++;
				}
			}
		}

		fclose($fp);

		return $total;
	}

	static function readMaillog()
	{
		$total = array();
		$fp = self::openMailLog();
		if ($fp === false) {
			return $total;
		}

		$date = time() - 24 * 3600 * 2;
		FindRightPosition($fp, $fsize = lxfile_size(self::mailLogPath()), $date, time(), array("mailtraffic", "getTimeFromOriginalQmailString"));

		if (!is_resource($fp)) {
			return $total;
		}

		while (!feof($fp)) {
			$s = fgets($fp);
			if (!is_string($s)) {
				break;
			}
			$s = trim($s);

			if (!csa($s, "lx-sending")) {
				continue;
			}

			$v = strfrom($s, "mail for");
			$id = $v;

			if (!isset($total[$id])) {
				$total[$id] = 1;
			} else {
				$total[$id]++;
			}
		}

		fclose($fp);

		return $total;
	}
}
