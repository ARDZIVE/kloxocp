<?php

class mail_graylist_wlist_a extends lxaclass {

	static $__desc = array("", "",  "whitelist_ips");
	static $__acdesc_list = array("", "",  "whitelist_ips");

	// Data — nname is the whitelisted IP (Kloxo-MR Whitelist IPs list).
	static $__desc_nname = array("n", "",  "whitelist_ip");

	static function createListAddForm($parent, $class)
	{
		return true;
	}

	static function AddListForm($parent, $class)
	{
		return self::addform($parent, $class);
	}

	static function addform($parent, $class, $typetd = null)
	{
		$vlist = array();
		$vlist['nname'] = null;
		$ret = array();
		$ret['variable'] = $vlist;
		$ret['action'] = 'add';

		return $ret;
	}

	static function add($parent, $class, $param)
	{
		if (!is_array($param)) {
			$param = array();
		}
		$ip = isset($param['nname']) ? trim((string) $param['nname']) : '';
		// IPv4/IPv6 address or CIDR; stored in canonical form
		try {
			$list = KloxoMailPolicy::parseWhitelist(array($ip));
		} catch (InvalidArgumentException $e) {
			throw new lxException($e->getMessage(), 'nname', $ip);
		}
		if (count($list) !== 1) {
			throw new lxException('Whitelist IP is required', 'nname', $ip);
		}
		$param['nname'] = $list[0];

		return $param;
	}

	static function createListNlist($parent, $view)
	{
		$nlist = array();
		$nlist['nname'] = '100%';

		return $nlist;
	}

	static function createListAlist($object, $class)
	{
		if (class_exists('ServerMail') && is_callable(array('ServerMail', 'servermailPropertyTabs'))) {
			$tabs = ServerMail::servermailPropertyTabs();
			$out = array();
			foreach ($tabs as $t) {
				if (is_string($t) && $t !== '' && !str_starts_with($t, 'goback=')) {
					$out[] = 'goback=1&' . $t;
				} else {
					$out[] = $t;
				}
			}

			return $out;
		}

		return array(
			'goback=1&a=updateform&sa=update',
			'goback=1&a=updateform&sa=spamdyke',
			'a=list&c=mail_graylist_wlist_a',
		);
	}
}

class ServerMail extends lxdb
{
	static $__desc = array("", "",  "server_mail_settings");
	static $__desc_nname = array("", "",  "server_mail_settings");
	static $__desc_queuelifetime = array("", "",  "queue_life_time");
	static $__desc_myname = array("", "",  "my_name");
	static $__desc_queuelifetime_v_604800 = array("", "",  "queue_life_time");
	static $__desc_concurrencyremote = array("", "",  "no_of_mail_send");
	static $__desc_enable_maps = array("f", "",  "enable_maps_protection");
	static $__desc_spamdyke_flag = array("f", "",  "enable_spamdyke");
	static $__desc_domainkey_flag = array("f", "",  "enable_domainkey");

	static $__desc_smtp_instance = array("", "",  "max_smtp_instances");
	static $__desc_smtp_relay = array("t", "",  "smtp_relay");

	static $__desc_send_limit = array("", "",  "send_limit");

	static $__desc_max_size = array("", "",  "max_mail_attachment_size");
	static $__desc_virus_scan_flag = array("f", "",  "enable_virus_scan");
	static $__acdesc_update_update = array("", "",  "server_mail_settings");
	static $__acdesc_update_spamdyke = array("", "",  "spamdyke");
	static $__acdesc_show = array("", "",  "server_mail_settings");
	static $__desc_greet_delay = array("", "",  "greet_delay");
	static $__desc_max_rcpnts = array("", "",  "maximum_recipients");
	static $__desc_graylist_flag = array("f", "",  "enable_graylisting");
	static $__desc_graylist_min_secs = array("", "",  "graylist_min_secs");
	static $__desc_graylist_max_secs = array("", "",  "graylist_max_secs");
	static $__desc_reject_empty_rdns_flag = array("f", "",  "reject_empty_rdns");
	static $__desc_reject_ip_in_cc_rdns_flag = array("f", "",  "reject_ip_in_cc_rdns");
	static $__desc_reject_missing_sender_mx_flag = array("f", "",  "reject_missing_sender_mx");
	static $__desc_reject_unresolvable_rdns_flag = array("f", "",  "reject_unresolvable_rdns");
	static $__desc_dns_blacklists = array("t", "",  "dns_blacklists");

	static $__desc_default_dns_blacklists_flag = array("f", "",  "enable_default_dns_blacklists");

	static $__desc_blacklist_headers = array("t", "",  "blacklist_headers");
	static $__desc_default_blacklist_headers_flag = array("f", "",  "enable_default_blacklist_headers");
	static $__desc_mail_graylist_wlist_a = array("", "",  "whitelist_ips");

	static function initThisObjectRule($parent, $class, $name = null)
	{
		return is_object($parent) && isset($parent->nname) ? $parent->nname : 'localhost';
	}

	static function servermailPropertyTabs()
	{
		// Exactly three beige tabs (Kloxo-MR Mail Server Settings page).
		return array(
			'a=updateform&sa=update',
			'a=updateform&sa=spamdyke',
			'a=list&c=mail_graylist_wlist_a',
		);
	}

	function getSelfList()
	{
		$parent = null;
		try {
			$parent = $this->getParentO();
		} catch (Throwable $e) {
			$parent = null;
		}

		$current = (is_object($parent) && method_exists($parent, 'getClass')
			&& $parent->getClass() === 'pserver')
			? $parent : $this;

		$canDb = function_exists('lfile_exists') && lfile_exists('__path_admin_pass')
			&& trim((string) lfile_get_contents('__path_admin_pass')) !== '';

		$out = array();
		if ($canDb && class_exists('pserver') && is_callable(array('pserver', 'adminServerSwitchList'))) {
			try {
				$got = pserver::adminServerSwitchList($current);
				if (is_array($got)) {
					foreach ($got as $k => $p) {
						if (is_object($p) && method_exists($p, 'getId')) {
							$out[(string) $k] = $p;
						}
					}
				}
			} catch (Throwable $e) {
				$out = array();
			}
		}

		if ($out === array() && is_object($current) && method_exists($current, 'getId')) {
			$n = (isset($current->nname) && is_string($current->nname) && $current->nname !== '')
				? $current->nname : 'localhost';
			$out[$n] = $current;
		}

		return $out !== array() ? $out : null;
	}

	function createExtraVariables()
	{
		$this->applySpamdykeDefaults();
	}

	function applySpamdykeDefaults()
	{
		// Kloxo-MR defaults of the (former Spamdyke) SMTP protection form.
		$this->setDefaultValue('greet_delay', '1');
		$this->setDefaultValue('max_rcpnts', '150');
		$this->setDefaultValue('graylist_flag', 'on');
		$this->setDefaultValue('graylist_min_secs', '300');
		$this->setDefaultValue('graylist_max_secs', '1814400');
		$this->setDefaultValue('reject_empty_rdns_flag', 'off');
		$this->setDefaultValue('reject_ip_in_cc_rdns_flag', 'off');
		$this->setDefaultValue('reject_missing_sender_mx_flag', 'on');
		$this->setDefaultValue('reject_unresolvable_rdns_flag', 'off');
	}

	function createShowPropertyList(&$alist)
	{
		$alist['property'] = self::servermailPropertyTabs();

		return $alist;
	}

	function postUpdate($subaction = null)
	{
		// We need to write because reads everything from the database.
		$this->write();

		if ($subaction === 'update') {
			if (is_file('/script/fixdomainkey') || is_file('/opt/kloxo/bin/fix/fixdomainkey')) {
				try {
					$script = is_file('/script/fixdomainkey')
						? '/script/fixdomainkey' : '/opt/kloxo/bin/fix/fixdomainkey';
					if (function_exists('lxshell_return')) {
						lxshell_return('sh', $script);
					}
				} catch (Throwable $e) {
					log_log('error', 'servermail fixdomainkey: ' . $e->getMessage());
				}
			}
		}
	}

	/**
	 * SMTP protection tab (key `spamdyke` kept for the URLs and the database columns):
	 * validate before anything is saved; the driver then installs it for Exim or Postfix.
	 */
	function updatespamdyke($param)
	{
		if (($param['default_dns_blacklists_flag'] ?? '') === 'on') {
			$param['dns_blacklists'] = KloxoMailPolicy::defaultFile('default_rbl.txt');
		}
		if (($param['default_blacklist_headers_flag'] ?? '') === 'on') {
			$param['blacklist_headers'] = KloxoMailPolicy::defaultFile('default_blacklist_headers.txt');
		}

		try {
			$p = KloxoMailPolicy::normalize(array(
				'enabled' => 'on',
				'greet_delay' => $param['greet_delay'] ?? '',
				'max_rcpnts' => $param['max_rcpnts'] ?? '',
				'graylist' => $param['graylist_flag'] ?? '',
				'graylist_min_secs' => $param['graylist_min_secs'] ?? '',
				'graylist_max_secs' => $param['graylist_max_secs'] ?? '',
				'dns_blacklists' => $param['dns_blacklists'] ?? '',
				'blacklist_headers' => $param['blacklist_headers'] ?? '',
			));
		} catch (InvalidArgumentException $e) {
			throw new lxException($e->getMessage(), '', '');
		}

		// the columns are TEXT; keep the lists in the canonical form that will be installed
		$param['dns_blacklists'] = implode(' ', $p['dns_blacklists']);
		$param['blacklist_headers'] = trim((string) ($param['blacklist_headers'] ?? ''));

		return $param;
	}

	function updateform($subaction, $param)
	{
		$vlist = array();

		switch ($subaction) {
			case "update":
				// Kloxo-MR Mail Server Settings fields (screenshot).
				$vlist['myname'] = null;
				$vlist['spamdyke_flag'] = null;
				$vlist['domainkey_flag'] = null;

				if (!isset($this->virus_scan_flag) || $this->virus_scan_flag === '' || $this->virus_scan_flag === null) {
					$this->virus_scan_flag = 'off';
				}
				$vlist['virus_scan_flag'] = null;

				if (!isset($this->max_size) || $this->max_size === '' || $this->max_size === null) {
					$this->setDefaultValue('max_size', '41943040');
				}
				$vlist['max_size'] = null;

				if (!isset($this->queuelifetime) || $this->queuelifetime === '' || $this->queuelifetime === null) {
					$this->setDefaultValue('queuelifetime', '604800');
				}
				$vlist['queuelifetime'] = null;

				if (!isset($this->smtp_instance) || $this->smtp_instance === '' || $this->smtp_instance === null) {
					$this->smtp_instance = '50';
				}
				$vlist['smtp_instance'] = null;

				if (!isset($this->smtp_relay) || $this->smtp_relay === null) {
					$this->smtp_relay = '';
				}
				$vlist['smtp_relay'] = null;

				if (!isset($this->send_limit) || $this->send_limit === '' || $this->send_limit === null) {
					$this->setDefaultValue('send_limit', '30000');
				}
				$vlist['send_limit'] = null;

				break;

			case "spamdyke":
				// SMTP protection tab fields (the Kloxo-MR Spamdyke form, same fields).
				$this->applySpamdykeDefaults();

				$vlist['greet_delay'] = null;
				$vlist['max_rcpnts'] = null;
				$vlist['graylist_flag'] = null;
				$vlist['graylist_min_secs'] = null;
				$vlist['graylist_max_secs'] = null;
				$vlist['reject_empty_rdns_flag'] = null;
				$vlist['reject_ip_in_cc_rdns_flag'] = null;
				$vlist['reject_missing_sender_mx_flag'] = null;
				$vlist['reject_unresolvable_rdns_flag'] = null;

				// The saved list, or the shipped default until one was saved (an empty box is a saved choice).
				$rbl = (isset($this->dns_blacklists) && is_string($this->dns_blacklists))
					? $this->dns_blacklists : KloxoMailPolicy::defaultFile('default_rbl.txt');
				$vlist['dns_blacklists'] = array('t', trim($rbl));
				$vlist['default_dns_blacklists_flag'] = null;

				$hdr = (isset($this->blacklist_headers) && is_string($this->blacklist_headers))
					? $this->blacklist_headers : KloxoMailPolicy::defaultFile('default_blacklist_headers.txt');
				$vlist['blacklist_headers'] = array('t', trim($hdr));
				$vlist['default_blacklist_headers_flag'] = null;

				break;
		}

		return $vlist;
	}
}
