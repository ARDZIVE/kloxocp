<?php

class watchdog__sync extends Lxdriverclass
{

	static function watchRun()
	{
		if (lx_core_lock_check_only("scavenge.php", "scavenge.php.pid")) {
			log_log("watchdog", "scavenge is running");
			dprint("Savenge is running\n");
			return;
		}

		// Don't restart service while booting.
		$time = os_getUptime();

		if ($time < 600) { return; }

		$list = lfile_get_unserialize("../etc/watchdog.conf");

		foreach ((array) $list as $l) {
			if (!is_array($l)) {
				continue;
			}
			$status = isset($l['status']) ? $l['status'] : 'off';
			$name = isset($l['servicename']) ? (string) $l['servicename'] : '';
			$port = isset($l['port']) ? $l['port'] : '';
			$raw = isset($l['action']) ? (string) $l['action'] : '';
			if (!isOn($status)) {
				print("{$name} is disabled\n");
				continue;
			}

			if (check_if_port_on($port)) {
				continue;
			}

			$drv = '';
			if (csb($raw, "__driver_")) {
				$drv = substr($raw, strlen('__driver_'));
				if ($drv === 'qmail') {
					$drv = 'mail';
				}
			} elseif (csb($raw, "restart-")) {
				$drv = substr($raw, strlen('restart-'));
			} else {
				continue;
			}
			if (!preg_match('/^[A-Za-z0-9._-]+$/', $drv)) {
				continue;
			}
			$script = "/script/restart-{$drv}";
			if (!is_file($script) && !is_link($script)) {
				continue;
			}
			$action = "sh {$script} --force";
			lxshell_return('/bin/sh', $script, '--force');

			log_log("watchdog", "$action executed for port {$port}");
			send_system_monitor_message_to_admin("Port: {$port}\nAction: {$action}");
		}
	}

	function dbactionUpdate($subaction)
	{
		switch ($subaction) {
			case "ftp":
				// MR -- TODO
				break;
			case "full_update":
			default:
				$result = (isset($this->main->__var_watchlist) && is_array($this->main->__var_watchlist))
					? $this->main->__var_watchlist : array();
				unset($this->main->__var_watchlist);
				$result = merge_array_object_not_deleted($result, $this->main);
				if (!is_array($result)) {
					$result = array();
				}
				lfile_put_serialize("../etc/watchdog.conf", $result);
				break;
		}
	}

	function dbactionAdd()
	{
		$this->dbactionUpdate("");
	}
}
