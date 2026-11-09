<?php

class clientmail extends lxclass {

	static $__desc = array("n", "",  "mails_per_client");
	static $__desc_clientname = array("n", "",  "clientname");
	static $__desc_mailnum = array("n", "",  "number_of_mails");
	static $__acdesc_list = array("n", "",  "mails_per_client");

	/** @var string Override for tests. */
	public static $logFile = '';

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

	static function serverNameForParent($parent)
	{
		if (!is_object($parent)) {
			return 'localhost';
		}
		if (method_exists($parent, 'getClass') && $parent->getClass() === 'pserver'
			&& isset($parent->nname) && is_string($parent->nname) && $parent->nname !== '') {
			return $parent->nname;
		}
		if (isset($parent->syncserver) && is_string($parent->syncserver) && $parent->syncserver !== '') {
			return $parent->syncserver;
		}
		if (isset($parent->nname) && is_string($parent->nname) && $parent->nname !== '') {
			return $parent->nname;
		}

		return 'localhost';
	}

	static function initThisList($parent, $class)
	{
		$sync = self::serverNameForParent($parent);
		try {
			$res = rl_exec_get(null, $sync, array('clientmail', 'readtotallog'), array());
			if (is_array($res)) {
				return $res;
			}
		} catch (Throwable $e) {
			if (function_exists('log_log')) {
				log_log('error', 'clientmail: ' . $e->getMessage());
			}
		}
		try {
			$res = self::readtotallog();
		} catch (Throwable $e) {
			return array();
		}

		return is_array($res) ? $res : array();
	}

	static function mailLogPath()
	{
		if (is_string(self::$logFile) && self::$logFile !== '' && is_readable(self::$logFile)) {
			return self::$logFile;
		}
		foreach (array('/var/log/exim4/mainlog', '/var/log/mail.log', '/var/log/maillog') as $p) {
			if (is_readable($p)) {
				return $p;
			}
		}

		return '';
	}

	static function readtotallog()
	{
		$total = self::countFromLog();
		$res = array();
		foreach ($total as $k => $v) {
			$re = array();
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

	/**
	 * Count mail submissions in the last two days.
	 * Exim `<=` (auth id preferred), Postfix from=<>, qmail relay, lx-sending.
	 *
	 * @return array<string,int>
	 */
	static function countFromLog()
	{
		$total = array();
		$path = self::mailLogPath();
		if ($path === '') {
			return $total;
		}
		$fp = @fopen($path, 'r');
		if (!is_resource($fp)) {
			return $total;
		}
		$since = time() - 2 * 24 * 3600;
		$n = 0;
		while (!feof($fp)) {
			if (++$n > 2000000) {
				break;
			}
			$s = fgets($fp);
			if (!is_string($s)) {
				break;
			}
			$s = trim($s);
			if ($s === '') {
				continue;
			}
			$t = self::lineTime($s);
			if ($t !== null && $t < $since) {
				continue;
			}
			$id = self::lineSender($s);
			if ($id === '') {
				continue;
			}
			if (!isset($total[$id])) {
				$total[$id] = 1;
			} else {
				$total[$id]++;
			}
		}
		fclose($fp);

		return $total;
	}

	/** @return int|null unix time, or null when the line has no date */
	static function lineTime($s)
	{
		if (!is_string($s) || $s === '') {
			return null;
		}
		if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $s, $m) === 1) {
			$t = strtotime($m[1]);

			return ($t !== false) ? $t : null;
		}
		if (preg_match('/^([A-Z][a-z]{2}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})/', $s, $m) === 1) {
			$year = (int) date('Y');
			$t = strtotime($m[1] . ' ' . $year);
			if ($t !== false && $t > time() + 86400) {
				$t = strtotime($m[1] . ' ' . ($year - 1));
			}

			return ($t !== false) ? $t : null;
		}

		return null;
	}

	static function lineSender($s)
	{
		if (!is_string($s) || $s === '') {
			return '';
		}
		if (preg_match('/\sA=[A-Za-z0-9_]+:(\S+)/', $s, $m) === 1) {
			return self::cleanId($m[1]);
		}
		if (preg_match('/\s<=\s+(\S+)/', $s, $m) === 1
			&& (strpos($s, ' P=local') !== false || preg_match('/\sU=[A-Za-z0-9._-]+/', $s) === 1)) {
			return self::cleanId($m[1]);
		}
		if (preg_match('/postfix\/(?:qmgr|cleanup).*from=<([^>]+)>/', $s) === 1) {
			preg_match('/from=<([^>]+)>/', $s, $m);

			return self::cleanId(isset($m[1]) ? $m[1] : '');
		}
		if (function_exists('csa') && csa($s, 'client allowed to relay')) {
			$v = function_exists('strfrom') ? strfrom($s, 'rcpt: from <') : $s;
			$v = function_exists('strtilfirst') ? strtilfirst($v, '>') : $v;
			$parts = explode(':', $v);
			$id = isset($parts[1]) ? $parts[1] : $v;

			return self::cleanId($id);
		}
		if (function_exists('csa') && csa($s, 'lx-sending')) {
			$v = function_exists('strfrom') ? strfrom($s, 'mail for') : $s;

			return self::cleanId($v);
		}

		return '';
	}

	static function cleanId($id)
	{
		$id = trim((string) $id);
		$id = trim($id, '<>');
		if ($id === '' || $id === '<>' || strcasecmp($id, 'MAILER-DAEMON') === 0) {
			return '';
		}
		if (strlen($id) > 200 || strpbrk($id, " \t\n\r") !== false) {
			return '';
		}
		if (!self::isClientAddress($id)) {
			return '';
		}

		return $id;
	}

	/**
	 * Real mailbox (user@domain.tld). Drops Unix-user@hostname (root@srv, lxlabs@srv).
	 */
	static function isClientAddress($id)
	{
		if (preg_match('/^([A-Za-z0-9._+-]+)@([A-Za-z0-9.-]+)$/', (string) $id, $m) !== 1) {
			return false;
		}
		$local = strtolower($m[1]);
		$dom = strtolower(rtrim($m[2], '.'));
		static $system = array(
			'root', 'lxlabs', 'www-data', 'debian-exim', 'mail', 'postfix', 'nobody',
			'daemon', 'bin', 'sys', 'ubuntu', 'messagebus', 'systemd-network', 'exim',
		);
		if (in_array($local, $system, true)) {
			return false;
		}
		if ($dom === '' || strpos($dom, '.') === false) {
			return false;
		}
		if ($dom === 'localhost' || $dom === 'localhost.localdomain' || $dom === 'localdomain') {
			return false;
		}

		return true;
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
		return self::countFromLog();
	}

	static function readMaillog()
	{
		return array();
	}
}
