<?php

class ftpsession__pureftp extends lxDriverClass
{
	function dbactionDelete()
	{
		$pid = isset($this->main->nname) ? (string)$this->main->nname : '';
		if ($pid === '' || !preg_match('/^[0-9]+$/', $pid)) {
			return;
		}
		lxshell_return('kill', $pid);
	}

	static function getFtpList($username = null)
	{
		$ret = array();
		if (!is_callable(array('process__linux', 'readProcessList'))) {
			return $ret;
		}

		$list = process__linux::readProcessList();
		if (!is_array($list)) {
			return $ret;
		}

		$filter = is_string($username) ? $username : '';

		foreach ($list as $l) {
			if (!is_array($l)) {
				continue;
			}
			$command = isset($l['command']) ? (string)$l['command'] : '';
			if ($command === '' || stripos($command, 'pure-ftp') === false) {
				continue;
			}

			$account = isset($l['username']) ? (string)$l['username'] : '';
			if ($filter !== '' && $account !== $filter) {
				continue;
			}

			$pid = isset($l['nname']) ? (string)$l['nname'] : '';
			if ($pid === '') {
				continue;
			}

			$r = array();
			$r['pid'] = $pid;
			$r['nname'] = $pid;
			$r['account'] = $account !== '' ? $account : $filter;
			$r['state'] = isset($l['state']) ? (string)$l['state'] : '';
			$r['time'] = '';
			$r['file'] = '';
			$r['host'] = '';
			$ret[] = $r;
		}

		return $ret;
	}
}
