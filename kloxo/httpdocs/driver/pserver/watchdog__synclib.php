<?php

class watchdog__sync extends Lxdriverclass
{

	// Service checks and restarts are done by the kloxo-watchdog systemd service
	// (bin/kloxo-watchdog, lib/php/kloxo_watchdog.php). It does not need the
	// panel or its database, restarts only the unit that is down (the old
	// /script/restart-* lists restarted every enabled unit and knew nothing of
	// Exim or Dovecot), and honours units stopped on purpose.
	static function watchRun()
	{
		return;
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
