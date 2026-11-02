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
	//	$nval['courier-imap'] = 'courier';
	//	$nval['spamassassin'] = 'spamassassin';
	//	$nval['dovecot'] = 'dovecot';

		$nval['iptables'] = "iptables";
		$nval['firewalld'] = "firewalld";

		return $nval;
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

	static function checkService($name)
	{
		global $gbl, $sgbl, $login, $ghtml;
	/*
		if ($name === 'qmail') {
			$ret = lxshell_return("qmailctl", "stat");
		} else {
			$ret = lxshell_return("service". $name, "status");
		}
	*/
		exec("pgrep ^{$name}", $out);

	//	$state = ($ret) ? "off" : "on";
		$state = (count($out) > 0) ? "off" : "on";

		return $state;
	}

	static function getRunLevel()
	{
		$v = trim(lxshell_output("runlevel"));
		$v = explode(" ", $v);
		
		return isset($v[1]) ? $v[1] : '3';
	}

	static function checkServiceOn($service)
	{
		exec("ps --no-headers -o comm 1", $servicetype);
		if (isset($servicetype[0]) && $servicetype[0] === 'systemd') {
			exec("systemctl list-unit-files --type=service", $units);
			if (is_array($units)) {
				foreach ($units as $line) {
					if (strpos($line, $service) === 0 && strpos($line, 'enabled') !== false) {
						return true;
					}
				}
			}
		}

		$sysv = array();
		exec("chkconfig --list 2>/dev/null", $sysv);
		if (is_array($sysv)) {
			foreach ($sysv as $line) {
				if (strpos($line, $service) === 0 && strpos($line, ':on') !== false) {
					return true;
				}
			}
		}

		return false;
	}

	static function getServiceDetails($list)
	{
		if (!is_array($list)) {
			return array();
		}

		$ps = lxshell_output("ps", "ax");
		if (!is_string($ps)) {
			$ps = '';
		}

		foreach ($list as &$__l) {
			$__l['install_state'] = 'dull';
			$__l['state'] = 'off';
			$__l['boot_state'] = 'off';

			if (!isset($__l['servicename']) || !isServiceExists($__l['servicename'])) {
				continue;
			}

			$__l['install_state'] = 'on';

			if (self::checkServiceOn($__l['servicename'])) {
				$__l['boot_state'] = 'on';
			}

			if (!empty($__l['grepstring']) && preg_match("/[\/ ]{$__l['grepstring']}/i", $ps)) {
				$__l['state'] = 'on';
			}

			if ($__l['state'] === 'off') {
				$out = array();
				exec("pgrep ^{$__l['servicename']}", $out);
				$__l['state'] = (is_array($out) && count($out) > 0) ? 'on' : 'off';
			}
		}
		unset($__l);

		return $list;
	}
}
