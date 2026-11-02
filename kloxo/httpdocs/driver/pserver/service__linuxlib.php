<?php

class Service__Linux extends Lxlclass
{
	static function getServiceList()
	{
		global $gbl, $sgbl, $login, $ghtml;
		
	//	$val = lscandir_without_dot("{$sgbl->__path_real_etc_root}/init.d");
	/*
		$val = array_remove($val, $sgbl->__var_programname_web);
		$val = array_remove($val, $sgbl->__var_programname_dns);
		$val = array_remove($val, $sgbl->__var_programname_imap);
		$val = array_remove($val, $sgbl->__var_programname_mmail);
	*/
		// MR not use option '--type=sysv' because trouble in CentOS 5
		exec("chkconfig --list 2>/dev/null|awk '{print $1}'|grep -v ':'", $val1);

		$val2 = array();

	//	exec("command -v systemctl", $test);

	//	if (count($test) > 0) {
		if (getServiceType() === 'systemd') {
			exec("systemctl list-unit-files --type=service|awk '{print $1}'|sed 's/\.service//g'", $val2);
		}

		$val = lx_array_merge($val1, $val2);

		$nval = self::getMainServiceList();
		$nval = lx_array_merge(array($nval, $val));

		array_unique($nval);
		
		return $nval;
	}

	static function getMainServiceList()
	{
		global $gbl, $sgbl, $login, $ghtml;

		$nval['httpd'] = 'httpd';
		$nval['apache2'] = 'apache2';
		$nval['lighttpd'] = 'lighttpd';
		$nval['nginx'] = 'nginx';
		$nval['hiawatha'] = 'hiawatha';
	//	$nval['openlitespeed'] = 'lsws';
	//	$nval['monkey'] = 'monkey';

		$nval['varnish'] = 'varnish';
		$nval['squid'] = 'squid';
		$nval['trafficserver'] = 'trafficserver';

		$nval['php-fpm'] = 'php-fpm';
		$nval['mariadb'] = 'mariadb';
		$nval['dovecot'] = 'dovecot';
		$nval['rspamd'] = 'rspamd';

		$nval['named'] = 'named';
		$nval['bind9'] = 'bind9';
		$nval['djbdns'] = "tinydns";
	//	$nval['maradns'] = "maradns";
	//	$nval['powerdns'] = "powerdns";
		$nval['pdns'] = "pdns";
		$nval['pdns-server'] = "pdns";
		$nval['nsd'] = "nsd";
	//	$nval['mydns'] = "mydns";
		$nval['yadifad'] = "yadifad";

		$nval['exim4'] = 'exim4';
		$nval['exim'] = 'exim';
		$nval['postfix'] = 'postfix';
	//	$nval['courier-imap'] = 'courier';
	//	$nval['spamassassin'] = 'spamassassin';
	//	$nval['dovecot'] = 'dovecot';

		$nval['pure-ftpd'] = 'pure-ftpd';
		$nval['kloxo-php'] = 'kloxo-php';

		$nval['iptables'] = "iptables";
		$nval['firewalld'] = "firewalld";

		return $nval;
	}

	static function isSafeUnit($name)
	{
		return is_string($name) && $name !== '' && preg_match('/^[A-Za-z0-9_.:@+-]+$/', $name) === 1;
	}

	static function hasSystemd()
	{
		return is_dir('/run/systemd/system');
	}

	static function unitName($servicename)
	{
		$servicename = (string) $servicename;
		$map = self::getMainServiceList();
		if (isset($map[$servicename]) && is_string($map[$servicename]) && $map[$servicename] !== '') {
			return $map[$servicename];
		}

		return $servicename;
	}

	static function phpFpmUnitNames()
	{
		$out = array();
		foreach (array('/lib/systemd/system', '/usr/lib/systemd/system', '/etc/systemd/system') as $dir) {
			$found = @glob($dir . '/php*-fpm.service');
			if (!is_array($found)) {
				continue;
			}
			foreach ($found as $f) {
				$base = basename((string)$f, '.service');
				if (self::isSafeUnit($base) && !in_array($base, $out, true)) {
					$out[] = $base;
				}
			}
		}
		sort($out, SORT_NATURAL);

		return $out;
	}

	static function unitCandidates($servicename)
	{
		$name = self::unitName($servicename);
		$map = array(
			'php-fpm' => array('php-fpm'),
			'bind9' => array('bind9', 'named'),
			'named' => array('named', 'bind9'),
			'httpd' => array('httpd', 'apache2'),
			'apache2' => array('apache2', 'httpd'),
			'mariadb' => array('mariadb', 'mysql', 'mysqld'),
			'mysql' => array('mysql', 'mariadb', 'mysqld'),
			'mysqld' => array('mysqld', 'mariadb', 'mysql'),
			'pdns' => array('pdns', 'pdns-server'),
			'pdns-server' => array('pdns', 'pdns-server'),
			'exim4' => array('exim4', 'exim'),
			'exim' => array('exim', 'exim4'),
			'postfix' => array('postfix'),
			'pure-ftpd' => array('pure-ftpd', 'pure-ftpd-mysql'),
		);
		$cands = isset($map[$name]) ? $map[$name] : array($name);
		if ($name === 'php-fpm' || preg_match('/^php[0-9.]+-fpm$/', $name) === 1) {
			$cands = array_merge($cands, self::phpFpmUnitNames());
		}

		$out = array();
		foreach ($cands as $u) {
			if (self::isSafeUnit($u) && !in_array($u, $out, true)) {
				$out[] = $u;
			}
		}

		return $out;
	}

	static function systemdShow($unit, $prop)
	{
		if (!self::hasSystemd() || !self::isSafeUnit($unit) || !self::isSafeUnit($prop)) {
			return '';
		}
		try {
			$out = lxshell_output('systemctl', 'show', '-p', $prop, '--value', $unit);
		} catch (Throwable $e) {
			return '';
		}

		return is_string($out) ? trim($out) : '';
	}

	static function unitIsInstalled($unit)
	{
		if (!self::isSafeUnit($unit)) {
			return false;
		}
		if (function_exists('isServiceExists') && isServiceExists($unit)) {
			return true;
		}
		$load = self::systemdShow($unit, 'LoadState');

		return $load === 'loaded' || $load === 'masked';
	}

	static function resolveInstalledUnit($servicename)
	{
		$installed = array();
		foreach (self::unitCandidates($servicename) as $u) {
			if (self::unitIsInstalled($u)) {
				$installed[] = $u;
			}
		}
		if ($installed === array()) {
			return '';
		}
		foreach ($installed as $u) {
			if (self::systemdShow($u, 'ActiveState') === 'active') {
				return $u;
			}
		}

		return $installed[count($installed) - 1];
	}

	static function bootStateOn($unit)
	{
		$en = self::systemdShow($unit, 'UnitFileState');

		return in_array($en, array(
			'enabled', 'enabled-runtime', 'static', 'indirect', 'generated', 'alias',
		), true);
	}

	static function checkService($name)
	{
		$unit = self::resolveInstalledUnit($name);
		if ($unit === '') {
			$unit = self::isSafeUnit((string)$name) ? (string)$name : '';
		}
		if ($unit === '') {
			return 'off';
		}
		if (self::hasSystemd()) {
			return self::systemdShow($unit, 'ActiveState') === 'active' ? 'on' : 'off';
		}

		return self::processIsRunning($unit) ? 'on' : 'off';
	}

	static function processIsRunning($name)
	{
		if (!self::isSafeUnit($name)) {
			return false;
		}
		$names = array($name);
		$alias = array(
			'mariadb' => array('mariadbd', 'mysqld'),
			'mysql' => array('mysqld', 'mariadbd'),
			'mysqld' => array('mysqld', 'mariadbd'),
			'bind9' => array('named'),
			'named' => array('named'),
			'pdns' => array('pdns_server'),
			'apache2' => array('apache2', 'httpd'),
			'exim4' => array('exim4', 'exim'),
		);
		if (isset($alias[$name])) {
			$names = array_merge($names, $alias[$name]);
		}
		foreach ($names as $n) {
			if (!self::isSafeUnit($n)) {
				continue;
			}
			try {
				$out = lxshell_output('pgrep', '-x', $n);
			} catch (Throwable $e) {
				$out = '';
			}
			if (is_string($out) && trim($out) !== '') {
				return true;
			}
		}

		return false;
	}

	static function getRunLevel()
	{
		$v = trim((string) lxshell_output("runlevel"));
		$v = explode(" ", $v);
		
		return isset($v[1]) ? $v[1] : '3';
	}

	static function checkServiceOn($service)
	{
		$unit = self::resolveInstalledUnit($service);
		if ($unit === '') {
			$unit = self::isSafeUnit((string)$service) ? (string)$service : '';
		}
		if ($unit === '') {
			return false;
		}
		if (self::hasSystemd()) {
			return self::bootStateOn($unit);
		}

		try {
			$sysv = lxshell_output('chkconfig', '--list', $unit);
		} catch (Throwable $e) {
			$sysv = '';
		}
		if (is_string($sysv) && $sysv !== '' && strpos($sysv, ':on') !== false) {
			return true;
		}

		return false;
	}

	static function getServiceDetails($list)
	{
		if (!is_array($list)) {
			return array();
		}

		foreach ($list as &$__l) {
			if (!is_array($__l)) {
				continue;
			}
			$__l['install_state'] = 'dull';
			$__l['state'] = 'off';
			$__l['boot_state'] = 'off';

			$svc = isset($__l['servicename']) ? (string)$__l['servicename'] : '';
			$unit = self::resolveInstalledUnit($svc);
			if ($unit === '') {
				continue;
			}

			$__l['install_state'] = 'on';
			if (self::hasSystemd()) {
				$__l['state'] = self::systemdShow($unit, 'ActiveState') === 'active' ? 'on' : 'off';
				$__l['boot_state'] = self::bootStateOn($unit) ? 'on' : 'off';
			} else {
				$__l['boot_state'] = self::checkServiceOn($unit) ? 'on' : 'off';
				$grep = isset($__l['grepstring']) ? (string)$__l['grepstring'] : $unit;
				$__l['state'] = (self::processIsRunning($unit) || self::processIsRunning($grep)) ? 'on' : 'off';
			}
		}
		unset($__l);

		return $list;
	}
}
