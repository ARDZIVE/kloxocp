<?php

class package__aptget extends lxDriverClass
{
	static function getPackages($nocache = false)
	{
		if (!is_executable('/usr/bin/apt-get') && !is_executable('/usr/sbin/apt-get')) {
			return array();
		}

		$val = lxshell_output('apt-get', '-s', 'upgrade');
		if (!is_string($val) || trim($val) === '') {
			return array();
		}

		$res = array();
		foreach (preg_split("/\r\n|\n|\r/", $val) as $l) {
			$l = trim($l);
			if ($l === '' || !preg_match('/^Inst\s+(\S+)/', $l, $m)) {
				continue;
			}
			$ver = '';
			if (preg_match('/\(([^)]+)\)/', $l, $vm)) {
				$parts = explode(' ', $vm[1]);
				$ver = $parts[0];
			}
			$res[] = array(
				'nname' => $m[1],
				'update_version' => $ver,
				'kloxo_status' => 'off',
				'repo' => 'apt',
			);
		}

		return $res;
	}

	static function doUpdate($list)
	{
		if (!is_array($list) || $list === array()) {
			return;
		}

		$safe = array();
		foreach ($list as $pkg) {
			$pkg = (string) $pkg;
			if ($pkg !== '' && preg_match('/^[A-Za-z0-9.+_-]+$/', $pkg)) {
				$safe[] = $pkg;
			}
		}
		if ($safe === array()) {
			return;
		}

		$args = array_merge(array('apt-get', '-y', 'install'), $safe);
		call_user_func_array('lxshell_return', $args);
	}
}
