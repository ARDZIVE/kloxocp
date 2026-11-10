<?php 

class pserver__Linux extends lxDriverClass
{
	static function powerCommandArgs($action)
	{
		$act = ($action === 'poweroff') ? 'poweroff' : 'reboot';
		$cmds = array(
			array('/bin/systemctl', '--no-block', $act),
			array('/usr/bin/systemctl', '--no-block', $act),
			array('/sbin/' . $act),
			array('/usr/sbin/' . $act),
		);
		foreach ($cmds as $args) {
			if (is_string($args[0]) && is_executable($args[0])) {
				return $args;
			}
		}

		return null;
	}

	function runPowerCommand($action)
	{
		global $login;

		$act = ($action === 'poweroff') ? 'poweroff' : 'reboot';
		$args = self::powerCommandArgs($act);
		if (!is_array($args) || !isset($args[0])) {
			$msg = (is_object($login) && method_exists($login, 'getThrow'))
				? $login->getThrow('could_not_execute_reboot')
				: 'Could not execute reboot/poweroff (systemctl not found)';
			throw new lxException($msg, '', $act);
		}

		if (function_exists('os_set_path')) {
			os_set_path();
		}

		try {
			$p = @proc_open($args, array(
				0 => array('file', '/dev/null', 'r'),
				1 => array('pipe', 'w'),
				2 => array('pipe', 'w'),
			), $pipes);
		} catch (Throwable $e) {
			log_log('error', "power {$act}: " . $e->getMessage());
			$msg = (is_object($login) && method_exists($login, 'getThrow'))
				? $login->getThrow('could_not_execute_reboot')
				: $e->getMessage();
			throw new lxException($msg, '', $act);
		}

		if ($p === false) {
			$msg = (is_object($login) && method_exists($login, 'getThrow'))
				? $login->getThrow('could_not_execute_reboot')
				: 'Could not execute reboot/poweroff';
			throw new lxException($msg, '', $act);
		}

		if (isset($pipes[1])) {
			stream_get_contents($pipes[1]);
			fclose($pipes[1]);
		}
		$err = '';
		if (isset($pipes[2])) {
			$err = stream_get_contents($pipes[2]);
			fclose($pipes[2]);
		}
		$code = proc_close($p);
		if ($code !== 0 && is_string($err) && $err !== '') {
			log_log('error', "power {$act}: {$err}");
		}
	}

	function poweroff()
	{
		$this->runPowerCommand('poweroff');
	}

	function reboot()
	{
		$this->runPowerCommand('reboot');
	}

	static function mysqlPasswordReset($pass)
	{
	//	lxshell_return("lxphp.exe", "../bin/common/misc/reset-mysql-root-password.php", $pass);
		exec("sh /script/reset-mysql-root-password {$pass}");
	}

	function setTimeZone()
	{
		$tz = isset($this->main->timezone) ? (string)$this->main->timezone : '';
		$list = timezone_identifiers_list();
		if ($tz === '' || !is_array($list) || !in_array($tz, $list, true)) {
			return;
		}

		$src = '/usr/share/zoneinfo/' . $tz;
		$real = realpath($src);
		$base = realpath('/usr/share/zoneinfo');
		if ($real === false || $base === false || strpos($real, $base . '/') !== 0) {
			return;
		}

		$t = '/etc/localtime';
		if (is_link($t) || is_file($t)) {
			@unlink($t);
		}
		@symlink($real, $t);
	}

	static function execCommand($iid, $command)
	{
		global $login, $global_shell_error, $global_shell_ret;
		$global_shell_error = null;

		$out = shell_exec("$command 2>&1");

		return array('output' => $out, 'error' => $global_shell_error);
	}

	function information()
	{
		$rmt = new Remote();

		$rmt->load_threshold = $this->main->load_threshold;
		lxfile_mkdir("../etc/data");

		lfile_put_serialize("../etc/data/loadmonitor", $rmt);
	}

	function dbactionUpdate($subaction)
	{
		switch ($subaction) {
			case "mysqlpasswordreset":
				$this->mysqlPasswordReset();
				break;

			case "reboot":
				$this->reboot();
				break;

			case "poweroff":
				$this->poweroff();
				break;
			case "information":
				$this->information();
				break;

			case "password":
				$this->main->syncPasswordCommon();
				break;

			case "timezone":
				$this->setTimeZone();
				break;

			case "importvps":
				return $this->importVps();
				break;

			case "importhypervmvps":
				return $this->importHypervmVPS();
				break;

			case "savevpsdata":
				$this->savevpsdata();
				break;

			case "graph_vpstraffic":
				return rrd_graph_server("traffic", $this->main->__var_graph_list, $this->main->rrdtime);
				break;

			case "graph_vpscpuusage":
				return rrd_graph_server("cpu", $this->main->__var_graph_list, $this->main->rrdtime);
				break;

			case "graph_vpsmemoryusage":
				return rrd_graph_server("memory", $this->main->__var_graph_list, $this->main->rrdtime);
				break;
		}
	}

	function saveVpsData()
	{
		lxfile_mkdir("/home/hypervm/data/");
		lfile_put_serialize("/home/hypervm/data/complete", $this->main->__var_vpsdata);
	}

	function importHypervmVPS()
	{
		$vpsdata = lfile_get_unserialize("/home/hypervm/data/complete");
		
		foreach ($vpsdata as $k => $v) {
			$v->syncserver = $this->main->nname;
		}
		
		return $vpsdata;
	}

	function createShowAlist(&$alist, $subaction = null)
	{

	}

	static function parse_data($data)
	{
		$res = array();
		if (!is_array($data)) {
			return $res;
		}

		foreach ($data as $d) {
			$d = trimSpaces($d);
			$d = str_replace(":", "", $d);
			$rval = explode(" ", $d);
			if (!isset($rval[0]) || $rval[0] === '') {
				continue;
			}
			$res[strtolower($rval[0])] = isset($rval[1]) ? $rval[1] : '0';
		}
		
		return $res;
	}

	static function getTotalMemory()
	{
		$path = "/proc/meminfo";
		$data = lfile($path);
		$res = self::parse_data($data);
		
		return $res['memtotal'] / (1024);
	}

	static function pserverInfo()
	{
		global $gbl, $sgbl, $login, $ghtml;
		
		$osdet = findOperatingSystem();

		$path = "/proc/meminfo";

		$data = lfile($path);

		$res = self::parse_data($data);

		$unit = 1024;
		$memtotal = isset($res['memtotal']) ? (float) $res['memtotal'] : 0;
		$memfree = isset($res['memfree']) ? (float) $res['memfree'] : 0;
		$swaptotal = isset($res['swaptotal']) ? (float) $res['swaptotal'] : 0;
		$swapfree = isset($res['swapfree']) ? (float) $res['swapfree'] : 0;
		$buffers = isset($res['buffers']) ? (float) $res['buffers'] : 0;
		$cached = isset($res['cached']) ? (float) $res['cached'] : 0;

		$ret['priv_s_memory'] = $memtotal / $unit;
		$ret['used_s_memory'] = ($memtotal - $memfree) / $unit;
		$ret['priv_s_swap'] = $swaptotal / $unit;
		$ret['used_s_swap'] = ($swaptotal - $swapfree) / $unit;

		$ret['used_s_membuffers'] = $buffers / $unit;

		$ret['used_s_memcached'] = $cached / $unit;

		$ret['used_s_realused'] = ($memtotal - $memfree - $buffers - $cached) / $unit;

		// This is a hack to show the actual non-kloxo memory on openvz.
		if ($sgbl->isKloxo()) {
		//	if (lxfile_exists("/proc/user_beancounters")) {
		//	if ((!lxfile_exists("/boot/grub/grub.conf")) && (!lxfile_exists("/boot/grub2/grub.conf"))) {
		//	exec("grep envID /proc/self/status", $out);
			exec("sh /script/virtual-info -t", $out);

			if (isset($out[0]) && $out[0] == 'container') {
				$ret['used_s_memory'] -= 20;
			}
		}

		foreach ($ret as &$vvv) {
			$vvv = round($vvv);
		}
		unset($vvv);

		$path = "/proc/cpuinfo";

		$data = lfile($path);

		$processornum = 0;
		$cpu = array();

		if (!is_array($data)) {
			$data = array();
		}

		foreach ($data as $v) {
			if (!trim($v)) {
				continue;
			}
			
			$d = explode(':', $v);
			$d[0] = trim($d[0]);
			$d[1] = isset($d[1]) ? trim($d[1]) : '';
			
			if ($d[0] === 'processor') {
				$processornum = $d[1];
				continue;
			}

			if ($d[0] === 'model name') {
				$cpu[$processornum]['used_s_cpumodel'] = $d[1];
			}
			
			if ($d[0] === 'cpu MHz') {
				$cpu[$processornum]['used_s_cpuspeed'] = round($d[1] / 100) / 10 . "GHz";
			}
			
			if ($d[0] === 'cache size') {
				$cpu[$processornum]['used_s_cpucache'] = $d[1];
			}
		}

		$ret['disk'] = diskusage__linux::getDiskUsage();
		$ret['lvm'] = vg_complete();
		$ret['cpu'] = $cpu;
		$ret['osdet'] = $osdet;
		
		return $ret;
	}

	function importVps()
	{
		global $gbl, $sgbl, $login, $ghtml;

		$objlist = null;
		
		if ($this->main->__var_vps_driver === 'openvz') {
			if (!lxfile_exists("/etc/vz/conf")) {
				throw new lxException($login->getThrow("no_vz_conf_directory"), '', '/etc/vz/conf');
			}

			$list = lscandir_without_dot_or_underscore("/etc/vz/conf");
			
			foreach ($list as $l) {
				if (!cse($l, ".conf")) {
					continue;
				}
				
				if ($l === '0.conf') {
					continue;
				}
				
				$object = vps__openvz::createVpsObject($this->main->nname, $l);
				$objlist[$object->nname] = $object;
			}
		} else {

			if (is_executable("/bin/systemctl") || is_executable("/usr/bin/systemctl")) { lxshell_return("systemctl", "enable", "xendomains"); }
			//lxshell_return("service", "xendomains", "restart");

			$imdriver = $this->main->__var_xenimportdriver;
			$importdriverfile = "{$sgbl->__path_program_htmlbase}/lib/xenimport/xenimport__$imdriver.php";
			
			if (!lxfile_exists($importdriverfile)) {
				throw new lxException($login->getThrow("could_not_find_xen_import_driver_file"), '', $importdriverfile);
			}

			include_once $importdriverfile;

			if (!function_exists("__xenimport_get_data")) {
				throw new lxException($login->getThrow("no_xenimport_function"));
			}

			$data = __xenimport_get_data();

			foreach ((array)$data as $input) {
				$object = vps__xen::createVpsObject($this->main->nname, $input);
				$objlist[$object->nname] = $object;
			}
		}
		
		return $objlist;
	}
}
