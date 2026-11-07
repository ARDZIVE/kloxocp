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

		$nval['kloxo-webcache'] = 'kloxo-webcache';
		$nval['kloxo-webproxy'] = 'kloxo-webproxy';
		$nval['webcache'] = 'kloxo-webcache';
		$nval['webproxy'] = 'kloxo-webproxy';
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

	/**
	 * Units the panel must not stop/disable/restart: killing them blanks every
	 * page (MariaDB socket "No such file or directory") or hangs the backend.
	 *
	 * @return list<string>
	 */
	static function protectedUnits()
	{
		return array(
			'mariadb', 'mysql', 'mysqld', 'mariadbd',
			'kloxo-php', 'kloxo-web', 'kloxo-backend', 'kloxo-wrap', 'hiawatha',
		);
	}

	static function isProtectedUnit($name)
	{
		$name = strtolower((string) $name);
		if ($name === '' || !self::isSafeUnit($name)) {
			return true;
		}
		if (in_array($name, self::protectedUnits(), true)) {
			return true;
		}
		$prefer = strtolower(self::preferredServiceName($name));

		return in_array($prefer, self::protectedUnits(), true);
	}

	/**
	 * Whether systemctl $act is allowed for $unit.
	 * start/enable always OK for installed units; stop/disable/restart blocked for protected.
	 */
	static function mayControlUnit($unit, $act)
	{
		$unit = (string) $unit;
		$act = (string) $act;
		if (!self::isSafeUnit($unit)
			|| !in_array($act, array('start', 'stop', 'restart', 'enable', 'disable'), true)) {
			return false;
		}
		if (in_array($act, array('start', 'enable'), true)) {
			return true;
		}
		// stop / disable / restart of panel-critical units take the UI down.
		return !self::isProtectedUnit($unit);
	}

	static function hasSystemd()
	{
		return is_dir('/run/systemd/system');
	}

	/** Absolute systemctl binary, or empty if missing. */
	static function systemctlBin()
	{
		foreach (array('/bin/systemctl', '/usr/bin/systemctl') as $bin) {
			if (is_executable($bin)) {
				return $bin;
			}
		}

		return '';
	}

	/**
	 * Drain a pipe with a short timeout so a stuck systemctl cannot hang the panel.
	 *
	 * @param resource $pipe
	 */
	static function readPipeLimited($pipe, $seconds = 5)
	{
		$out = '';
		if (!is_resource($pipe)) {
			return $out;
		}
		stream_set_blocking($pipe, false);
		$deadline = microtime(true) + (float) $seconds;
		while (!feof($pipe) && microtime(true) < $deadline) {
			$chunk = fread($pipe, 8192);
			if ($chunk === false || $chunk === '') {
				usleep(50000);
				continue;
			}
			$out .= $chunk;
			if (strlen($out) > 65536) {
				break;
			}
		}

		return $out;
	}

	/**
	 * Run systemctl with an argument array (no shell string).
	 * Uses --no-block for start/stop/restart so the request cannot hang waiting
	 * on a unit (or deadlock when controlling kloxo-backend itself).
	 *
	 * @return int process exit code, or -1 on failure / refused
	 */
	static function systemctlRun($act, $unit)
	{
		$d = self::systemctlRunDetail($act, $unit);

		return (int) $d['rc'];
	}

	/**
	 * Same as systemctlRun but returns what happened. Runs in whatever process
	 * calls it: only root can stop units, and `euid` says who that was.
	 *
	 * @return array{rc:int,out:string,err:string,euid:int}
	 */
	static function systemctlRunDetail($act, $unit)
	{
		$euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : -1;
		$res = array('rc' => -1, 'out' => '', 'err' => '', 'euid' => $euid);
		$bin = self::systemctlBin();
		$act = (string) $act;
		$unit = (string) $unit;
		if ($bin === '') {
			$res['err'] = 'systemctl not found';

			return $res;
		}
		if (!self::isSafeUnit($unit)
			|| !in_array($act, array('start', 'stop', 'restart', 'enable', 'disable'), true)
			|| !self::mayControlUnit($unit, $act)) {
			$res['err'] = 'refused';

			return $res;
		}
		$args = array($bin);
		if (in_array($act, array('start', 'stop', 'restart'), true)) {
			$args[] = '--no-block';
		}
		$args[] = $act;
		$args[] = $unit;
		try {
			$p = @proc_open($args, array(
				0 => array('file', '/dev/null', 'r'),
				1 => array('pipe', 'w'),
				2 => array('pipe', 'w'),
			), $pipes);
		} catch (Throwable $e) {
			$res['err'] = 'cannot run systemctl';

			return $res;
		}
		if ($p === false || $p === null) {
			$res['err'] = 'cannot run systemctl';

			return $res;
		}
		if (isset($pipes[1])) {
			$res['out'] = self::readPipeLimited($pipes[1], 5);
			fclose($pipes[1]);
		}
		if (isset($pipes[2])) {
			$res['err'] = self::readPipeLimited($pipes[2], 5);
			fclose($pipes[2]);
		}
		$rc = -1;
		$status = @proc_get_status($p);
		if (is_array($status)) {
			if (!empty($status['running'])) {
				@proc_terminate($p);
			} elseif (isset($status['exitcode']) && (int) $status['exitcode'] >= 0) {
				// proc_close() returns -1 once proc_get_status() has reaped the child.
				$rc = (int) $status['exitcode'];
			}
		}
		$closed = (int) proc_close($p);
		$res['rc'] = $rc >= 0 ? $rc : $closed;

		// Tell the watchdog: a unit stopped here on purpose stays stopped,
		// a unit started here is watched again. Only the root backend can write.
		if ($res['rc'] === 0 && $euid === 0 && class_exists('KloxoWatchdog', false)) {
			$id = (string) preg_replace('/\.service$/', '', (string) self::systemdShow($unit, 'Id'));
			$root = class_exists('KloxoRecovery', false) ? KloxoRecovery::rootDir() : '/opt/kloxo';
			foreach (array_unique(array_filter(array((string) preg_replace('/\.service$/', '', $unit), $id))) as $n) {
				if (in_array($act, array('stop', 'disable'), true)) {
					KloxoWatchdog::hold($n, $root);
				} elseif (in_array($act, array('start', 'restart'), true)) {
					KloxoWatchdog::release($n, $root);
				}
			}
		}

		return $res;
	}

	/**
	 * Run systemctl as root for the panel. Panel PHP-FPM is not root, so a
	 * request goes to kloxo-backend (loopback 7776) explicitly. remote_exec()
	 * falls back to running in-process when the backend fails; that would be a
	 * silent no-op as the panel user, so the caller sees `euid` and an error.
	 *
	 * @return array{ok:bool,rc:int,out:string,err:string,euid:int}
	 */
	static function controlUnit($act, $unit, $server = 'localhost')
	{
		$euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : -1;
		$fail = array('ok' => false, 'rc' => -1, 'out' => '', 'err' => '', 'euid' => $euid);
		if (!self::mayControlUnit((string) $unit, (string) $act)) {
			$fail['err'] = 'refused';

			return $fail;
		}
		if ($euid !== 0 && function_exists('rl_exec_get') && php_sapi_name() !== 'cli') {
			try {
				$got = rl_exec_get(null, $server, array('Service__Linux', 'systemctlRunDetail'), array($act, $unit));
			} catch (Throwable $e) {
				$fail['err'] = 'kloxo-backend: ' . $e->getMessage();

				return $fail;
			}
			if (!is_array($got) || !isset($got['rc'])) {
				$fail['err'] = 'kloxo-backend gave no answer';

				return $fail;
			}
		} else {
			$got = self::systemctlRunDetail($act, $unit);
		}
		$got['out'] = isset($got['out']) ? (string) $got['out'] : '';
		$got['err'] = isset($got['err']) ? (string) $got['err'] : '';
		$got['euid'] = isset($got['euid']) ? (int) $got['euid'] : -1;
		$got['ok'] = ((int) $got['rc'] === 0);
		if (!$got['ok'] && $got['euid'] > 0) {
			$got['err'] = 'systemctl ran as uid ' . $got['euid'] . ', not root: kloxo-backend '
				. '(127.0.0.1:7776) did not handle the request. ' . $got['err'];
		}

		return $got;
	}

	/**
	 * systemctl --no-block returns at once; wait until the unit reaches the
	 * state the page will show.
	 */
	static function waitForUnit($unit, $act, $seconds = 6)
	{
		if (!in_array((string) $act, array('start', 'stop', 'restart'), true)) {
			return;
		}
		$deadline = microtime(true) + (float) $seconds;
		// A restart may still read `active` before the stop begins.
		if ($act === 'restart') {
			usleep(300000);
		}
		do {
			$s = self::systemdShow($unit, 'ActiveState');
			if ($act === 'stop' && $s !== 'active' && $s !== 'deactivating') {
				return;
			}
			if ($act !== 'stop' && ($s === 'active' || $s === 'failed')) {
				return;
			}
			usleep(200000);
		} while (microtime(true) < $deadline);
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

	/**
	 * Preferred panel name for a systemd unit (Debian/Ubuntu names).
	 * Collapses RHEL aliases (httpd/named/mysqld) onto one list row.
	 */
	static function preferredServiceName($name)
	{
		$name = (string) $name;
		$map = array(
			'httpd' => 'apache2',
			'apache2' => 'apache2',
			'named' => 'bind9',
			'bind9' => 'bind9',
			'mysqld' => 'mariadb',
			'mysql' => 'mariadb',
			'mariadb' => 'mariadb',
			'pdns-server' => 'pdns',
			'pdns' => 'pdns',
			'exim' => 'exim4',
			'exim4' => 'exim4',
		);

		return isset($map[$name]) ? $map[$name] : $name;
	}

	/** True when two unit names share the same systemd unit file (alias). */
	static function unitsAreSameDaemon($a, $b)
	{
		$a = (string) $a;
		$b = (string) $b;
		if ($a === '' || $b === '') {
			return false;
		}
		if ($a === $b) {
			return true;
		}
		$fa = self::systemdShow($a, 'FragmentPath');
		$fb = self::systemdShow($b, 'FragmentPath');

		return $fa !== '' && $fa === $fb;
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
		$bin = self::systemctlBin();
		if ($bin === '') {
			return '';
		}
		try {
			$p = @proc_open(array($bin, 'show', '-p', $prop, '--value', $unit), array(
				0 => array('file', '/dev/null', 'r'),
				1 => array('pipe', 'w'),
				2 => array('pipe', 'w'),
			), $pipes);
		} catch (Throwable $e) {
			return '';
		}
		if ($p === false || $p === null) {
			return '';
		}
		$out = '';
		if (isset($pipes[1])) {
			$out = self::readPipeLimited($pipes[1], 3);
			fclose($pipes[1]);
		}
		if (isset($pipes[2])) {
			self::readPipeLimited($pipes[2], 3);
			fclose($pipes[2]);
		}
		$status = @proc_get_status($p);
		if (is_array($status) && !empty($status['running'])) {
			@proc_terminate($p);
		}
		proc_close($p);

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

		// Key by preferred family (bind9/named → bind9) so systemd aliases of the
		// same daemon do not produce two installed rows.
		$byFamily = array();

		foreach ($list as $key => &$__l) {
			if (!is_array($__l)) {
				continue;
			}
			$__l['install_state'] = 'dull';
			$__l['state'] = 'off';
			$__l['boot_state'] = 'off';

			$svc = isset($__l['servicename']) ? (string) $__l['servicename'] : '';
			$unit = self::resolveInstalledUnit($svc);
			if ($unit === '') {
				continue;
			}

			// Ubuntu default SQL is MariaDB. Hide mysqld/mysql only when they are
			// the same systemd unit/alias as mariadb. A distinct mysql-server
			// package (different FragmentPath) stays listed and can be started
			// from Services after install left it stopped.
			if (in_array($svc, array('mysql', 'mysqld'), true)) {
				$mariaUnit = self::resolveInstalledUnit('mariadb');
				if ($mariaUnit !== '' && self::unitsAreSameDaemon($unit, $mariaUnit)) {
					continue;
				}
			}

			// Family key: named+bind9 → bind9, httpd+apache2 → apache2, even when
			// systemd LoadState is true for both the real unit and its alias.
			$family = self::preferredServiceName($unit);
			if ($family === $unit) {
				$family = self::preferredServiceName($svc);
			}
			if (isset($byFamily[$family])) {
				$prevKey = $byFamily[$family];
				$prevSvc = isset($list[$prevKey]['servicename']) ? (string) $list[$prevKey]['servicename'] : '';
				// Prefer the Debian/Ubuntu catalog name (bind9, apache2, mariadb).
				if ($svc === $family && $prevSvc !== $family) {
					$list[$prevKey]['install_state'] = 'dull';
					$list[$prevKey]['state'] = 'off';
					$list[$prevKey]['boot_state'] = 'off';
					$byFamily[$family] = $key;
				} else {
					continue;
				}
			} else {
				$byFamily[$family] = $key;
			}

			$__l['install_state'] = 'on';
			if (self::hasSystemd()) {
				$__l['state'] = self::systemdShow($unit, 'ActiveState') === 'active' ? 'on' : 'off';
				$__l['boot_state'] = self::bootStateOn($unit) ? 'on' : 'off';
			} else {
				$__l['boot_state'] = self::checkServiceOn($unit) ? 'on' : 'off';
				$grep = isset($__l['grepstring']) ? (string) $__l['grepstring'] : $unit;
				$__l['state'] = (self::processIsRunning($unit) || self::processIsRunning($grep)) ? 'on' : 'off';
			}
		}
		unset($__l);

		return $list;
	}
}
