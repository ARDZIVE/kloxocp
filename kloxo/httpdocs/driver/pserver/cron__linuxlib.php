<?php

class cron__Linux extends lxDriverClass
{
	function dbactionAdd()
	{
		$this->syncCreateConf();
		$ret = array("__syncv_jobid" => $this->main->nname);

		return $ret;
	}

	function dbactionDelete()
	{
		$this->syncCreateConf();
	}

	function dbactionUpdate($subaction)
	{
		$this->syncCreateConf();
	}

	function getCronString($list)
	{
		if (!is_array($list)) {
			return $list;
		}
		if (isset($list[0]) && $list[0] === '--all--') {
			return "*";
		} else {
			return implode(",", $list);
		}
	}

	function syncCreateConf()
	{
		global $sgbl;

		$username = isset($this->main->username) ? (string)$this->main->username : '';
		if ($username === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
			return;
		}

		$cron_root = (is_object($sgbl) && isset($sgbl->__path_cron_root))
			? $sgbl->__path_cron_root : '/var/spool/cron/crontabs';
		$conf_file = "{$cron_root}/{$username}";
		$list = array('minute', 'hour', 'weekday', 'ddate', 'month');

		$tfile = lx_tmp_file($conf_file);
		if (!$tfile) {
			return;
		}

		$mailto = isset($this->main->__var_mailto) ? (string)$this->main->__var_mailto : '';
		$mailto = preg_replace('/[\r\n]/', '', $mailto);
		if ($mailto !== '' && !preg_match('/^[A-Za-z0-9._%+\-@]+$/', $mailto)) {
			$mailto = '';
		}

		$cmd = ($mailto === '') ? "MAILTO=\"\"\n" : "MAILTO={$mailto}\n";

		$result = $this->main->__var_cron_list;
		if (!is_array($result)) {
			$result = array();
		}

		foreach ($result as &$__r) {
			if (!is_array($__r)) {
				continue;
			}
			foreach ($list as $l) {
				$raw = isset($__r["ser_$l"]) ? $__r["ser_$l"] : '';
				$decoded = @unserialize(base64_decode($raw));
				$__r[$l] = ($decoded !== false) ? $decoded : (isset($__r[$l]) ? $__r[$l] : '*');
			}
		}
		unset($__r);

		$result = merge_array_object_not_deleted($result, $this->main);
		if (!is_array($result)) {
			$result = array();
		}

		foreach ($result as $v) {
			if (!is_array($v)) {
				continue;
			}

			$command = isset($v['command']) ? preg_replace('/[\r\n]/', ' ', (string)$v['command']) : '';
			if ($command === '') {
				continue;
			}
			$command = $this->pinCronPhp($command, $username);

			$ttype = isset($v['ttype']) ? $v['ttype'] : '';
			if ($ttype === 'simple') {
				$v['weekday'] = array('--all--');
				$v['month'] = array('--all--');
				$v['ddate'] = array('--all--');

				$simple = isset($v['simple_cron']) ? $v['simple_cron'] : '';
				if ($simple === 'every-day') {
					$v['hour'] = isset($v['cron_day_hour']) ? $v['cron_day_hour'] : 0;
					$v['minute'] = 0;
				}

				if ($simple === 'every-hour') {
					$v['hour'] = array('--all--');
					$v['minute'] = 0;
				}

				if ($simple === 'every-minute') {
					$v['hour'] = array('--all--');
					$v['minute'] = array('--all--');
				}
			} elseif (isset($v['weekday']) && is_array($v['weekday'])) {
				foreach ($v['weekday'] as &$___tq) {
					if (is_numeric($___tq)) {
						$___tq -= 1;
					}
				}
				unset($___tq);
			}

			foreach ($list as $l) {
				$v[$l] = $this->getCronString(isset($v[$l]) ? $v[$l] : '*');
			}

			if ($v['minute'] === '' || $v['minute'] === null) {
				$v['minute'] = 0;
			}

			$cmd .= implode("\t", array($v['minute'], $v['hour'], $v['ddate'], $v['month'], $v['weekday'], $command));
			$cmd .= "\n";
		}

		$cmd = str_replace('--all--', '*', $cmd);

		lfile_put_contents($tfile, $cmd);

		if (!@posix_getpwnam($username)) {
			lxfile_rm("/var/spool/cron/{$username}");
			lxfile_rm("/var/spool/cron/crontabs/{$username}");
			lunlink($tfile);
			return;
		}

		lxshell_return("/usr/bin/crontab", "-u", $username, $tfile);

		lunlink($tfile);
	}

	function pinCronPhp($command, $username)
	{
		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_ipaddr.php';
		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpver.php';
		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpfpm.php';
		if (!KloxoIpaddr::isUbuntu()) {
			return $command;
		}
		$cli = KloxoPhpver::cliBin(KloxoPhpver::activeId());
		foreach (glob('/etc/php/*/fpm/pool.d/kloxo-*.conf') ?: [] as $f) {
			if (!is_string($f) || !is_file($f)) {
				continue;
			}
			$txt = (string) @file_get_contents($f);
			if (!preg_match('/^user\\s*=\\s*' . preg_quote($username, '/') . '\\s*$/m', $txt)) {
				continue;
			}
			$series = basename(dirname($f, 3));
			if (preg_match('/^\\d+\\.\\d+$/', $series) === 1) {
				$cli = KloxoPhpver::cliBin('php' . $series);
				break;
			}
		}

		return KloxoPhpfpm::rewriteCronPhp((string) $command, $cli);
	}
}
