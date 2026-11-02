<?php

include_once "driver/pserver/service__linuxlib.php";

class Service__Debian extends lxDriverClass
{
	function dbactionAdd()
	{
		$this->systemctl('enable');
	}

	function unitName()
	{
		$svc = isset($this->main->servicename) ? (string) $this->main->servicename : '';

		return Service__Linux::unitName($svc);
	}

	function systemctl($act)
	{
		$unit = $this->unitName();
		if ($unit === '' || !preg_match('/^[A-Za-z0-9_.:@+-]+$/', $unit)) {
			return;
		}
		if ($unit === 'hiawatha' && ($act === 'stop' || $act === 'disable')) {
			return;
		}
		if (!in_array($act, array('start', 'stop', 'restart', 'enable', 'disable'), true)) {
			return;
		}

		lxshell_return('systemctl', $act, $unit);
	}

	function dbactionUpdate($subaction)
	{
		switch ($subaction) {
			case 'start':
				$this->systemctl('start');
				break;
			case 'stop':
				$this->systemctl('stop');
				break;
			case 'restart':
				$this->systemctl('restart');
				break;
			case 'toggle_status':
			case 'toggle_boot_state':
				if ($this->main->isOn('boot_state') || $this->main->isOn('status')) {
					$this->systemctl('enable');
				} else {
					$this->systemctl('disable');
				}
				break;
			case 'toggle_state':
				if ($this->main->isOn('state')) {
					$this->systemctl('start');
				} else {
					$this->systemctl('stop');
				}
				break;
		}
	}

	static function getServiceList()
	{
		return Service__Linux::getServiceList();
	}

	static function checkService($name)
	{
		return Service__Linux::checkService($name);
	}

	static function getServiceDetails($list)
	{
		return Service__Linux::getServiceDetails($list);
	}

	static function checkServiceOn($service)
	{
		return Service__Linux::checkServiceOn($service);
	}
}
