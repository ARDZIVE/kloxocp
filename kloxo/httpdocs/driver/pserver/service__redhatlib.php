<?php

include_once "driver/pserver/service__linuxlib.php";

class Service__Redhat extends lxDriverClass
{
	// We need to properly port this system to debian. I tried using the chkconfig directly on debian, 
	// but it seems the individual scripts themselves have to support chkconfig if it has to work, 
	// and thus chkconfig fails to run. Now the only way is to use update-rc.d program on debain.

	function dbactionAdd()
	{
	//	lxshell_return("chkconfig", $this->main->servicename, 'on');
		exec("chkconfig {$this->main->servicename} on >/dev/null 2>&1");
	}

	function startStopService($act)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$cmd = $this->main->servicename;

		if (strpos($cmd, 'proxy') !== false) {
			$t = str_replace('proxy', '', $cmd);
			$cmdlist = array($t, 'httpd');
		} else {
			$cmdlist = array($cmd);
		}

		foreach ($cmdlist as $key => $cmd) {
			exec_with_all_closed("service {$cmd} {$act}");
		}
	}

	function dbactionUpdate($subaction)
	{
		switch ($subaction) {
			case "start":
				$this->startStopService("start");

				break;
			case "stop":
				$this->startStopService("stop");

				break;
			case "restart":
				$this->startStopService("stop");
				sleep(2);
				$this->startStopService("start");

				break;

			case "toggle_boot_state":
				if ($this->main->servicename !== 'hiawatha') {
					if ($this->main->isOn('boot_state')) {
					//	lxshell_return("chkconfig", $this->main->servicename, 'on');
						exec("chkconfig {$this->main->servicename} on >/dev/null 2>&1");
					} else {
					//	lxshell_return("chkconfig", $this->main->servicename, 'off');
						exec("chkconfig {$this->main->servicename} off >/dev/null 2>&1");
					}
				}
				
				break;
			case "toggle_state":
				if ($this->main->isOn('state')) {
					$this->startStopService("start");
				} else {
					$this->startStopService("stop");
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
