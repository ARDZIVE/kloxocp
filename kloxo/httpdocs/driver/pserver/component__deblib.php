<?php

class Component__deb extends lxDriverClass
{
	static function getDetailedInfo($name)
	{
		$ret = lxshell_output("dpkg-query", "-s", $name);

		return is_string($ret) ? $ret : '';
	}

	static function getVersion($list, $name)
	{
		$ret = array();
		if (!is_array($list)) {
			return '';
		}

		foreach ($list as $v) {
			if (csb($v, $name) || csa($v, " $name ")) {
				$ret[] = $v;
			}
		}

		return implode(", ", $ret);
	}

	static function packageStatus($name)
	{
		$out = lxshell_output("dpkg-query", "-W", '-f=${Version}\t${Status}', $name);
		if (!is_string($out) || trim($out) === '') {
			return array('version' => "package {$name} is not installed", 'status' => 'off');
		}

		$parts = explode("\t", trim($out), 2);
		$version = $parts[0];
		$st = isset($parts[1]) ? $parts[1] : '';
		$on = (strpos($st, 'installed') !== false && strpos($st, 'not-installed') === false);

		return array(
			'version' => $on ? $version : "package {$name} is not installed",
			'status' => $on ? 'on' : 'off',
		);
	}

	static function getListVersion($syncserver, $list)
	{
		$t = array();
		$files = glob("../etc/list/set.*.lst");
		if (!is_array($files)) {
			$files = array();
		}

		foreach ($files as $fv) {
			$b = basename($fv);

			if (file_exists("../etc/list/custom.{$b}")) {
				$fv = "../etc/list/custom.{$b}";
			}

			$type = preg_replace("(custom\.|set\.|\.lst)", "", $b);
			if ($type === 'httpd') {
				continue;
			}

			$raw = @file_get_contents($fv);
			if (!is_string($raw) || trim($raw) === '') {
				continue;
			}

			$pkglist = explode(",", trim($raw, "\n"));
			if ($type === 'php') {
				$type = 'php-branch';
			}
			$pkglist = array_values(array_unique(array_filter(array_map('trim', $pkglist))));

			foreach ($pkglist as $v) {
				$st = self::packageStatus($v);
				$t[] = array(
					'nname' => "{$v}___{$syncserver}",
					'componentname' => $v,
					'version' => $st['version'],
					'status' => $st['status'],
					'type' => $type,
				);
			}
		}

		if (function_exists('getCleanRpmBranchListOnList')) {
			$list = getCleanRpmBranchListOnList('php');
			if (is_array($list)) {
				foreach ($list as $v) {
					$on = file_exists("/opt/{$v}/version");
					$t[] = array(
						'nname' => "{$v}___{$syncserver}",
						'componentname' => $v,
						'version' => $on ? ("{$v}-" . (string) file_get_contents("/opt/{$v}/version")) : "package {$v} is not installed",
						'status' => $on ? 'on' : 'off',
						'type' => 'multiple-php',
					);
				}
			}
		}

		return $t;
	}
}
