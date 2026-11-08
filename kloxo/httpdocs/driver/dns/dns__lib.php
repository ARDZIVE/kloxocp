<?php

class dns__ extends lxDriverClass
{
	function __construct()
	{
	}

	static function unInstallMeTrue($drivertype = null)
	{
		setAllInactivateDnsServer();
	}

	static function installMeTrue($drivertype = null)
	{
		if ($drivertype === 'none') {
			return;
		}

		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_dns.php';
		kloxo_dns_switch((string)$drivertype);

		createRestartFile("restart-dns");
	}

	static function getActiveDriver()
	{
		return slave_get_driver('dns');
	}

	function createConfFile()
	{
		global $sgbl;

		$input = array();

		$domains[] = $this->main->nname;

		foreach ((array)$this->main->__var_addonlist as $d) {
			$domains[] = $d->nname;
		}

		$input['ttl'] = $this->main->ttl;
		$input['nameduser'] = $sgbl->__var_programuser_dns;
		$input['soanameserver'] = $this->main->soanameserver;
	//	$input['email'] = $this->main->__var_email;
		$input['email'] = ($this->main->hostmaster) ? $this->main->hostmaster : "admin@{$this->main->nname}";
		$input['serial'] = $this->main->__var_ddate;
		$input['dns_records'] = $this->main->dns_record_a;

		// MR -- not work and not implementing yet!
	//	$input['account'] = $this->parent->getRealClientParentO()->getPathFromName();

		$dnsdrvlist = getAllDnsDriverList();

		foreach ($dnsdrvlist as $v) {
			if ($v === 'none') { continue; }

			// Knot is handled by dns__knot (zone files + knot.conf), not by templates.
			if (($v === 'bind') || ($v === 'yadifa') || ($v === 'knot')) { continue; }

			$tplsource = getLinkCustomfile("/opt/configs/{$v}/tpl", "domains.conf.tpl");
			$tpl = file_get_contents($tplsource);

			foreach ($domains as $d) {
				$input['domainname'] = $d;

				$tpltarget = "/opt/configs/{$v}/conf/master/{$d}";

				$tplparse = getParseInlinePhp($tpl, $input);

				file_put_contents($tpltarget, $tplparse);
			}
		}
	}

	function syncCreateConf()
	{
		$input = array();

		// MR -- need to make sure for dnsnotify
		$input['driver'] = self::getActiveDriver();

		$ip_dns = $this->getIps();
	//	$ip_hostname = array(gethostbyname(php_uname('n')));
		// MR -- IP list without hostname IP
	//	$input['ips'] = array_diff($ip_dns, $ip_hostname);
		$input['ips'] = $ip_dns;

		$input['serverips'] = $this->getServerIps();

		$dnsdrvlist = getAllDnsDriverList();

		$mlist = $this->getMasterList();
		$slist = $this->getSlaveList();
		$rlist = $this->getReverseList();

		foreach ($dnsdrvlist as $v) {
			if (($v === 'none') || ($v === 'knot')) { continue; }

			$input['domains'] = $mlist;
			$tplsource = getLinkCustomfile("/opt/configs/{$v}/tpl", "list.master.conf.tpl");
			$tpl = file_get_contents($tplsource);
			getParseInlinePhp($tpl, $input);

			$input['domains'] = $slist;
			$tplsource = getLinkCustomfile("/opt/configs/{$v}/tpl", "list.slave.conf.tpl");
			$tpl = file_get_contents($tplsource);
			getParseInlinePhp($tpl, $input);

			$input['arpas'] = $rlist;
			$tplsource = getLinkCustomfile("/opt/configs/{$v}/tpl", "list.reverse.conf.tpl");
			$tpl = file_get_contents($tplsource);
			getParseInlinePhp($tpl, $input);
		}
	}

	function createAllowTransferIps()
	{
		$input = array();
	/*
		$ip_dns = $this->getIps();
		$ip_hostname = array(gethostbyname(php_uname('n')));
		// MR -- IP list without hostname IP
		$input['ips'] = array_diff($ip_dns, $ip_hostname);
	*/
		// MR -- need to make sure for dnsnotify
		$input['driver'] = self::getActiveDriver();

		$input['ips'] = $this->getIps();
		$input['serverips'] = $this->getServerIps();

		$dnsdrvlist = getAllDnsDriverList();

		foreach ($dnsdrvlist as $v) {
			if (($v === 'none') || ($v === 'knot')) { continue; }

			$tplsource = getLinkCustomfile("/opt/configs/{$v}/tpl", "list.transfered.conf.tpl");

			$tpl = file_get_contents($tplsource);

			getParseInlinePhp($tpl, $input);
		}
	}

	function getIps()
	{
		$nobase = true;

		$ret = rl_exec_get('localhost', 'localhost', 'getIpfromARecord', array($this->main->syncserver, $nobase));

		return $ret;
	}

	function getServerIps()
	{
		return os_get_allips();
	}

	function getMasterList()
	{
		$ret = rl_exec_get('localhost', 'localhost', 'getDnsMasters', array($this->main->syncserver));

		return $ret;
	}

	function getSlaveList()
	{
		$ret = rl_exec_get('localhost', 'localhost', 'getDnsSlaves', array($this->main->syncserver));

		return $ret;
	}

	function getReverseList()
	{
		$ret = rl_exec_get('localhost', 'localhost', 'getDnsReverses', array($this->main->syncserver));

		return $ret;
	}

	function dbactionAdd()
	{
		$this->main->write();

		$this->createConfFile();
		$this->createAllowTransferIps();
		$this->syncCreateConf();
	}

	function dbactionDelete()
	{
		$this->main->write();

		$this->createAllowTransferIps();
		$this->syncCreateConf();
	}

	function dbactionUpdate($subaction)
	{
		switch ($subaction) {
			case "allowed_transfer":
				$this->createAllowTransferIps();

				break;
			case "synchronize":
				$this->syncCreateConf();

				break;
			case "synchronize_fix":
				$this->syncCreateConf();

				break;
			case "domain":
				$this->createConfFile();

				break;
			case "full_update":
			default:
				$this->createConfFile();
				$this->createAllowTransferIps();
				$this->syncCreateConf();

				break;
		}
	}

	function dosyncToSystemPost()
	{
		$driver = self::getActiveDriver();

		// Knot reloads itself from applyConf(); no restart file for it.
		if ($driver !== 'knot') {
			createRestartFile("restart-dns");
		}
	}
}