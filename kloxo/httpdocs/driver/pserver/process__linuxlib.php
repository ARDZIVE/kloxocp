<?php

class Process__Linux extends lxDriverClass 
{
	static function readProcessList()
	{
		$return = array();
		$list = lscandir("/proc");
		if (!is_array($list)) {
			return $return;
		}

		foreach ($list as $pid) {
			if (!is_numeric($pid)) {
				continue;
			}

			$cmdlinearr = lfile("/proc/" . $pid . "/cmdline");
			if (!is_array($cmdlinearr) || !isset($cmdlinearr[0]) || $cmdlinearr[0] === '') {
				continue;
			}

			$cmdline = preg_replace('+\0+i', " ", $cmdlinearr[0]);
			$command = substr($cmdline, 0, 100);

			if (csa($cmdline, "display.php") && csa($cmdline, "kloxo")) {
				continue;
			}

			$row = array(
				'nname' => (string) $pid,
				'command' => $command,
				'state' => '',
				'username' => '',
				'memory' => 0,
			);

			$arr = lfile("/proc/" . $pid . "/status");
			if (!is_array($arr)) {
				$return[$pid] = $row;
				continue;
			}

			foreach ($arr as $a) {
				if (csb($a, "State:")) {
					$a = trim($a);
					$a = strtil($a, "(");
					$a = strfrom($a, "State:");
					$a = trim($a);
					$row["state"] = ($a === "S") ? "ZZ" : $a;
				}

				if (csa($a, "Uid")) {
					$uidarr = explode(":", $a);
					$value = isset($uidarr[1]) ? trimSpaces($uidarr[1]) : '';
					$uidarr2 = explode(" ", $value);
					$uid = isset($uidarr2[1]) ? (int) trim($uidarr2[1]) : 0;
					$pwd = posix_getpwuid($uid);
					$row["username"] = (is_array($pwd) && isset($pwd['name'])) ? $pwd['name'] : (string) $uid;
				}

				if (csa($a, "VmSize")) {
					$uidarr = explode(":", $a);
					$uidarr = isset($uidarr[1]) ? trimSpaces($uidarr[1]) : '0';
					$uidarr = strtilfirst($uidarr, " ");
					$row['memory'] = round(((float) $uidarr) / 1024, 2);
				}
			}

			$return[$pid] = $row;
		}

		return $return;
	}

	function dbactionUpdate($subaction)
	{
		lxshell_return("kill", "-" . $this->main->signal, $this->main->nname);
	}
}
