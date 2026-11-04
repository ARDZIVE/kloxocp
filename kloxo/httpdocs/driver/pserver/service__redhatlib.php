<?php

include_once __DIR__ . '/service__linuxlib.php';

class Service__Redhat extends lxDriverClass
{
	function dbactionAdd()
	{
		$this->systemctlOrChkconfig('enable');
	}

	function unitName()
	{
		$svc = isset($this->main->servicename) ? (string) $this->main->servicename : '';
		$resolved = Service__Linux::resolveInstalledUnit($svc);
		if ($resolved !== '') {
			return $resolved;
		}

		return Service__Linux::unitName($svc);
	}

	function systemctlOrChkconfig($act)
	{
		$unit = $this->unitName();
		if ($unit === '' || !Service__Linux::isSafeUnit($unit)) {
			return;
		}
		if (!Service__Linux::mayControlUnit($unit, (string) $act)) {
			return;
		}
		if (Service__Linux::hasSystemd() && Service__Linux::systemctlBin() !== '') {
			Service__Linux::systemctlRun($act, $unit);

			return;
		}
		// Legacy SysV only when systemd is absent.
		if (in_array($act, array('enable', 'on'), true)) {
			@exec('chkconfig ' . escapeshellarg($unit) . ' on >/dev/null 2>&1');
		} elseif (in_array($act, array('disable', 'off'), true)) {
			@exec('chkconfig ' . escapeshellarg($unit) . ' off >/dev/null 2>&1');
		}
	}

	function startStopService($act)
	{
		$act = (string) $act;
		if (!in_array($act, array('start', 'stop', 'restart'), true)) {
			return;
		}
		$unit = $this->unitName();
		if ($unit === '' || !Service__Linux::mayControlUnit($unit, $act)) {
			return;
		}
		if (Service__Linux::hasSystemd() && Service__Linux::systemctlBin() !== '') {
			Service__Linux::systemctlRun($act, $unit);

			return;
		}
		// Avoid hanging shell strings when possible; SysV fallback only.
		$svcBin = is_executable('/sbin/service') ? '/sbin/service'
			: (is_executable('/usr/sbin/service') ? '/usr/sbin/service' : '');
		if ($svcBin === '') {
			return;
		}
		try {
			$p = @proc_open(array($svcBin, $unit, $act), array(
				0 => array('file', '/dev/null', 'r'),
				1 => array('pipe', 'w'),
				2 => array('pipe', 'w'),
			), $pipes);
		} catch (Throwable $e) {
			return;
		}
		if ($p === false || $p === null) {
			return;
		}
		if (isset($pipes[1])) {
			Service__Linux::readPipeLimited($pipes[1], 5);
			fclose($pipes[1]);
		}
		if (isset($pipes[2])) {
			Service__Linux::readPipeLimited($pipes[2], 5);
			fclose($pipes[2]);
		}
		$status = @proc_get_status($p);
		if (is_array($status) && !empty($status['running'])) {
			@proc_terminate($p);
		}
		proc_close($p);
	}

	function dbactionUpdate($subaction)
	{
		switch ($subaction) {
			case 'start':
				$this->startStopService('start');
				break;
			case 'stop':
				$this->startStopService('stop');
				break;
			case 'restart':
				$this->startStopService('restart');
				break;
			case 'toggle_boot_state':
				if ($this->main->isOn('boot_state')) {
					$this->systemctlOrChkconfig('enable');
				} else {
					$this->systemctlOrChkconfig('disable');
				}
				break;
			case 'toggle_state':
				if ($this->main->isOn('state')) {
					$this->startStopService('start');
				} else {
					$this->startStopService('stop');
				}
				break;
		}
	}

	static function checkServiceInRc($rc, $service)
	{
		foreach ($rc as $r) {
			if (preg_match("/^S.*{$service}/i", $r)) {
				return true;
			}
		}

		return false;
	}

	static function checkServiceOn($service)
	{
		return Service__Linux::checkServiceOn($service);
	}

	static function getServiceDetails($list)
	{
		return Service__Linux::getServiceDetails($list);
	}

	static function getServiceList()
	{
		return Service__Linux::getServiceList();
	}
}
