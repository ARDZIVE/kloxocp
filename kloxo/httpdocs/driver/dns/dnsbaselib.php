<?php

class LxDnsClass extends Lxaclass
{
}

class dns_record_a extends LxDnsClass
{
	static $__desc = array("", "", "dns_record");
	static $__desc_nname = array("n", "", "dns_record");
	static $__desc_param = array("n", "", "value");
	static $__desc_hostname = array("n", "", "hostname", "a=updateform&sa=edit");
	static $__desc_flag = array("n", "", "flag");
	static $__desc_tag = array("n", "", "tag");
	static $__desc_ttype = array("", "", "type");
	static $__desc_ttype_v_mx = array("", "", "MX");
	static $__desc_ttype_v_ns = array("", "", "NS");
	static $__desc_ttype_v_a = array("", "", "A");
	static $__desc_ttype_v_aaaa = array("", "", "AAAA");
	static $__desc_ttype_v_txt = array("", "", "TXT");
	static $__desc_ttype_v_cname = array("", "", "CNAME");
	static $__desc_ttype_v_fcname = array("", "", "FCNAME");
	static $__desc_priority = array("", "", "priority");
	static $__desc_ttype_v_caa = array("", "", "CAA");
	static $__desc_ttype_v_srv = array("", "", "SRV");
	static $__desc_weight = array("n", "", "weight");
	static $__desc_port = array("n", "", "port");

	function isSelect()
	{
		if ($this->nname === 'a___base__') {
		//	return false;
		}

		if ($this->nname === 'a_mail') {
			return true;
		}

		return true;
	}

	function updateform($subaction, $param)
	{
		$vlist['hostname'] = array('M', null);

		if ($this->ttype === 'txt') {
			$vlist['param'] = array("t", "");
		} else {
			if (($this->ttype === 'fcname') || ($this->ttype === 'ns') || ($this->ttype === 'mx')) {
				$vlist['param'] = array('m', array('posttext' => "."));
			} else {
				$vlist['param'] = null;
			}
		}

		return $vlist;
	}

	function isAction($var)
	{
	/*
		if ($this->ttype === 'ns') {
			return false;
		}
	*/
		return true;
	}

	static function createListNlist($parent, $view)
	{
	//	$nlist['nname'] = '10%';
		$nlist['hostname'] = '20%';
		$nlist['ttype'] = '5%';
		$nlist['priority'] = '5%';
		$nlist['param'] = '70%';

		return $nlist;
	}

	static function perPage()
	{
		return 6000;
	}

	function display($var)
	{
		if (!isset($this->$var)) {
			if ($var == 'hostname') {
				$this->$var = '__base__';
			} else {
				return '-';
			}
		}

		if ($var === 'ttype') {
			return strtoupper($this->$var);
		}

		if ($var === 'param') {
			if ($this->ttype === 'txt') {
				if (strlen($this->$var) > 75) {
					return substr($this->$var, 0, 75) . "...";
				}
			}

			if ($this->ttype === 'caa') {
				$flag = isset($this->flag) ? (string)$this->flag : '';
				$tag = isset($this->tag) ? (string)$this->tag : '';
				$pval = isset($this->param) ? (string)$this->param : '';

				return "{$flag} {$tag} \"{$pval}\"";
			}

			if ($this->ttype === 'srv') {
				$weight = isset($this->weight) ? (string)$this->weight : '';
				$port = isset($this->port) ? (string)$this->port : '';
				$pval = isset($this->param) ? (string)$this->param : '';

				return "{$weight} {$port} \"{$pval}\"";
			}

		/*
		//	if (strpos($this->getParentO()->nname, '.dnst') !== false) {
				// MR -- for template
			//	$this->$var = str_replace($this->getParentO()->nname, "__base__", $this->$var);
		//	} else {
				// MR -- for dns setting
				if ($this->ttype !== 'cn') {
					$this->$var = str_replace("__base__", $this->getParentO()->nname, $this->$var);
				} else {
					if (($this->$var === '__base__') || ($this->$var === $this->getParentO()->nname)) {
						$this->$var = $this->getParentO()->nname . '.';
					} else {
					//	$this->$var .= '.' . $this->getParentO()->nname . '.';
					}
				}

				if (($this->ttype === 'fcname') || ($this->ttype === 'ns') || ($this->ttype === 'mx')) {
					$this->$var .= '.';
				}
		//	}
		*/
		}

		// MR -- fix appear in 'old' data
		if ($var === 'hostname') {
			if (($this->hostname === $this->param) || ($this->hostname === '')) {
				$this->$var = '__base__';
			}

			$parent = $this->getParentO();
			$pname = (is_object($parent) && isset($parent->nname)) ? (string)$parent->nname : '';
			if ($pname !== '') {
				$this->$var = str_replace($pname, "__base__", $this->$var);
			}
			$this->$var = str_replace(".__base__", "", $this->$var);

			if ($this->$var !== '__base__') {
				// MR -- TODO: change domain.dnst to __base__; unfinish
			//	$this->$var = $this->$var . '.__base__';
			}
		}

		return $this->$var;
	}

	static function add($parent, $class, $param)
	{
		global $login;

		if (!is_array($param)) {
			$param = array();
		}
		$ttype = (string)($param['ttype'] ?? '');
		$param['hostname'] = (string)($param['hostname'] ?? '');
		if ($ttype === 'mx') {
			// Validates domain
			validate_domain_name((string)($param['param'] ?? ''));

			$param['nname'] = "{$ttype}_{$param['priority']}";
			$param['hostname'] = (is_object($parent) && isset($parent->nname))
				? (string)$parent->nname : '';
		} elseif ($ttype === 'ns') {
			// MR -- make possible to 'delegate' subdomain to other server!

			// MR -- need remove __base__
			$x = str_replace('.__base__', '', (string)($param['hostname'] ?? ''));

			validate_hostname_name($x);

			$a_record_match = false;

			$records = (is_object($parent) && isset($parent->dns_record_a))
				? $parent->dns_record_a : array();
			foreach ((array)$records as $d) {
				if (!is_object($d)) {
					continue;
				}
				if (($d->ttype === 'a') || ($d->ttype === 'aaa') || ($d->ttype === 'aaaa')) {
					if ($d->hostname === $x) {
						$a_record_match = true;
						break;
					}
				}
			}

			if (!$a_record_match) {
				throw new lxException($login->getThrow('need_a_or_aaa_record'), '', $x);
			}

			// Validates domain
			validate_domain_name($param['param']);

			$param['nname'] = "{$ttype}_{$param['param']}";
		} elseif ($ttype === 'a' || $ttype === 'aaaa') {
			// Validates subdomain
			validate_hostname_name($param['hostname']);
			
			// MR -- make IPv6 always full format
			if (strpos($param['param'], ':') !== false) {
				$param['param'] = ipv6_expand($param['param']);
			}

			// Validates both ipv4 and ipv6
			validate_ipaddress($param['param']);

			// MR -- back to use old model (importance for round-robin 'A record'
			$param['nname'] = "{$ttype}_{$param['hostname']}_{$param['param']}";
		} elseif ($ttype === 'cname') {
			// Validates hostname subdomain
			validate_hostname_name($param['hostname']);
			validate_hostname_name($param['param']);

			$param['nname'] = "{$ttype}_{$param['hostname']}";
		} elseif ($ttype === 'fcname') {
			// Validates hostname subdomain
			validate_hostname_name($param['hostname']);

			// Validates value domain
			validate_domain_name($param['param']);

			$param['nname'] = "{$ttype}_{$param['hostname']}";
		} elseif ($ttype === 'txt') {
			// Validates hostname subdomain

			$val = (string)($param['hostname'] ?? '');

			// MR -- handle hostname like _domainkey and _dmarc
			if ((strpos($val, '_', 0) !== false)) {
				$val = str_replace('_', '', $val);
			}

			validate_hostname_name($val);

			$param['nname'] = "{$ttype}_{$val}";
		} elseif ($ttype === 'caa') {
			// Validates hostname subdomain

			$val =  str_replace('.__base__', '', (string)($param['hostname'] ?? ''));

			validate_hostname_name($val);

			validate_domain_name($param['param']);

			$param['nname'] = "{$ttype}_{$param['param']}";
		} elseif ($ttype === 'srv') {
			$val = (string)($param['hostname'] ?? '');

			// MR -- handle hostname like _domainkey and _dmarc
			if ((strpos($val, '_', 0) !== false)) {
				$val = str_replace('_', '', $val);
			}

			validate_hostname_name($val);

			$param['nname'] = "{$ttype}_{$val}";
		} else {
			$param['nname'] = "{$ttype}_{$param['hostname']}";
		}

		return $param;
	}

	static function addform($parent, $class, $typetd = null)
	{
		$typetdVal = (is_array($typetd) && isset($typetd['val'])) ? (string)$typetd['val'] : '';
		$base = (is_object($parent) && isset($parent->nname)) ? (string)$parent->nname : '';
		if ($typetdVal === 'ns') {
			// MR -- add hostname entry to make possible to 'delegate' to other server!
			$vlist['hostname'] = array('m', array('value'=> '__base__'));
			$vlist['param'] = array('m', array('posttext' => "."));
		} elseif ($typetdVal === 'mx') {
			$vlist['priority'] = array('s', array('5', '10', '20', '30', '40', '50', '60', '70', '80', '90', '100'));
			$vlist['param'] = array('m', array('posttext' => "."));
		} elseif ($typetdVal === 'cname') {
			$vlist['hostname'] = array('m', array('posttext' => ".$base."));
			$vlist['param'] = array('m', array('posttext' => ".$base."));
		} elseif ($typetdVal === 'fcname') {
			$vlist['hostname'] = array('m', array('posttext' => ".$base."));
			$vlist['param'] = array('m', array('posttext' => "."));
		} elseif ($typetdVal === 'txt') {
			$vlist['hostname'] = array('m', array('posttext' => ".$base."));
			$vlist['param'] = array('t', "");
		} elseif ($typetdVal === 'caa') {
			$vlist['hostname'] = array('m', array('value'=> '__base__'));
			$vlist['flag'] = array('s', array('0', '1', '128'));
			$vlist['tag'] = array('s', array('issue', 'issuewild', 'iodef'));
			$vlist['param'] = array('m', array('value'=> 'letsencrypt.org'));
		} elseif ($typetdVal === 'srv') {
			$vlist['hostname'] = array('m', array('posttext' => ".$base."));
			$vlist['priority'] = array('s', array('0', '5', '10', '20', '30', '40', '50', '60', '70', '80', '90', '100'));
			$vlist['weight'] = array('s', array('0', '5', '10', '20', '30', '40', '50', '60', '70', '80', '90', '100'));
			$vlist['port'] = null;
			$vlist['param'] = null;
		} else {
		//	$vlist['hostname'] = array('m', array('posttext' => ".$base."));
			$vlist['hostname'] = null;
			$vlist['param'] = null;
		}

		$ret['variable'] = $vlist;
		$ret['action'] = 'Add';

		return $ret;
	}
}

class Mx_rec_a extends LxDnsclass
{
}

class Ns_rec_a extends Lxdnsclass
{
}

class Txt_rec_a extends Lxdnsclass
{
}

class A_rec_a extends Lxdnsclass
{
}

class Cn_rec_a extends Lxdnsclass
{
}

abstract class Dnsbase extends Lxdb
{
	// Mysql
	static $__desc_ttl = array("", "", "ttl");
	static $__desc_syncserver = array("sd", "", "primary_dns");
	static $__desc_ns_rec_a = array("", "", "ns_record");
	static $__desc_a_rec_a = array("", "", "a_record");
	static $__desc_mx_rec_a = array("", "", "mx_record");
	static $__desc_cn_rec_a = array("", "", "cn_record");
	static $__desc_fcname_rec_a = array("", "", "fcname_record");
	static $__desc_dns_record_a = array("", "", "dns_record");
	static $__desc_zone_type = array("", "", "type_of_dns_zone_file");
	static $__desc_nameserver_f = array("n", "", "primary_DNS");
	static $__desc_newdnstemplate_f = array("n", "", "new_dns_template");
	static $__desc_secnameserver_f = array("", "", "secondary_DNS");
	static $__desc_soanameserver = array("", "", "SOA_nameserver");
	static $__desc_hostmaster = array("", "", "hostmaster_email");
	static $__acdesc_update_parameter = array("", "", "General Settings");
	static $__acdesc_update_switchdnsserver = array("", "", "switch_server");
	static $__acdesc_update_rebuild = array("", "", "Rebuild");

	static function normalizeNsName($name)
	{
		$name = strtolower(trim((string)$name));
		$name = rtrim($name, '.');

		return $name;
	}

	static function assertNsName($name)
	{
		global $login;

		$name = self::normalizeNsName($name);
		if ($name === '') {
			throw new lxException($login->getThrow('invalid_domain_name'), '', $name);
		}
		if (strpos($name, '.') !== false) {
			validate_domain_name($name);
		} else {
			validate_hostname_name($name);
		}

		return $name;
	}

	static function qualifyTemplateNs($name, $templateNname, $fallbackHost = 'ns1')
	{
		$name = self::normalizeNsName($name);
		$base = self::normalizeNsName($templateNname);
		if ($name === '') {
			$name = self::normalizeNsName($fallbackHost);
		}
		if ($name === '') {
			$name = 'ns1';
		}
		if (strpos($name, '.') === false && $base !== '') {
			$name .= '.' . $base;
		}

		return self::assertNsName($name);
	}

	function createDefaultTemplate($webipaddress, $mmailipaddress = "0.0.0.0", $nameserver = "", $secnamserver = null)
	{
		global $login;

		$this->ttl = "86400"; // in seconds; 1 day

		$webipaddress = trim((string)$webipaddress);
		$mmailipaddress = trim((string)$mmailipaddress);
		if ($webipaddress === '') {
			throw new lxException($login->getThrow('no_ip_address'));
		}
		if ($mmailipaddress === '') {
			$mmailipaddress = $webipaddress;
		}

		$nameserver = self::qualifyTemplateNs($nameserver, (string)($this->nname ?? ''), 'ns1');
		$this->soanameserver = $nameserver;
		if (!isset($this->zone_type) || $this->zone_type === '' || $this->zone_type === null) {
			$this->zone_type = 'master';
		}

		$this->addRec('ns', $nameserver, $nameserver);

		$secnamserver = self::normalizeNsName($secnamserver);
		if ($secnamserver !== '') {
			$secnamserver = self::qualifyTemplateNs($secnamserver, (string)($this->nname ?? ''), 'ns2');
			if ($secnamserver !== $nameserver) {
				$this->addRec('ns', $secnamserver, $secnamserver);
			}
		}

		$cpip = $webipaddress;
		try {
			$got = getOneIPForServer("localhost");
			if (is_string($got) && $got !== '') {
				$cpip = $got;
			}
		} catch (Throwable $e) {
		}

		$base = (string)($this->nname ?? '');

		$this->addRec("a", "cp", $cpip);
		$this->addRec("a", "__base__", $webipaddress);
		$this->addRec("a", "ns1", $webipaddress);
		$this->addRec("a", "ns2", $webipaddress);
		$this->addRec("a", "mail", $mmailipaddress);
		$this->addRec("cn", "www", "__base__");
		$this->addRec("a", "ftp", $webipaddress);
		$this->addRec("a", "stats", $webipaddress);
		$this->addRec("cn", "webmail", "mail");
		$this->addRec("cn", "lists", "mail");
		$this->addRec("fcname", "smtp", "mail.{$base}");
		$this->addRec("fcname", "pop", "mail.{$base}");
		$this->addRec("fcname", "imap", "mail.{$base}");
		$this->addRec("mx", "10", "mail.{$base}");
		$this->addRec("cn", "_acme-challenge", "myalias");
	}

	function addRec($ttype, $name, $param)
	{
		$rname = "{$ttype}_$name";
		$__temp = new dns_record_a(null, null, $rname);

		if ($ttype === 'mx') {
			$__temp->hostname = $this->nname;
			$__temp->priority = $name;
			$__temp->param = $param;
		} elseif ($ttype === 'ns') {
			// MR -- add 'hostname' and set '__base__' as default
			$__temp->hostname = "__base__";
			$__temp->param = $param;
		} else {
			$__temp->hostname = $name;
			$__temp->param = $param;
		}

		$__temp->ttype = $ttype;

		if (!isset($this->dns_record_a)) {
			$this->dns_record_a = array();
		}

		$this->dns_record_a[$rname] = $__temp;
		$this->setUpdateSubaction('subdomain');
	}

	function addDomainKey($key)
	{
		$this->addRec("txt", "_domainkey", "t=y; o=-; r=admin@{$this->nname}");
	//	$this->addRec("txt", "private._domainkey", "k=rsa; p=$key");
		$this->addRec("txt", "private._domainkey", "v=DKIM1; g=*; k=rsa; p=$key");
	}

	function RemoveDomainKey()
	{
		foreach ((array)($this->dns_record_a ?? array()) as $k => $v) {
			if ($v->ttype === 'txt' && ($v->hostname === "_domainkey" || $v->hostname === 'private._domainkey')) {
				dprint("removing domainkey for $this->nname\n");

				unset($this->dns_record_a[$k]);
			}
		}
	}

	function getIpForBaseDomain()
	{
		if (!isset($this->dns_record_a) || !is_array($this->dns_record_a)) {
			return '0.0.0.0';
		}

		foreach ($this->dns_record_a as $d) {
			if (is_object($d) && isset($d->ttype) && $d->ttype === 'a' && isset($d->hostname) && $d->hostname === '__base__') {
				return $d->param;
			}
		}

		return '0.0.0.0';
	}

	function copyObject($dns)
	{
	//	$this->ipaddress = $dns->ipaddress;
		$this->ttl = $dns->ttl;

		if ($dns->isClass('dns')) {
			$this->soanameserver = $dns->soanameserver;
		} else {
			$this->soanameserver = str_replace($dns->nname, $this->nname, $dns->soanameserver);
		}

		$this->zone_type = $dns->zone_type;
		$name = $dns->nname;

		if (!isset($dns->dns_record_a) || !is_array($dns->dns_record_a)) {
			return;
		}

		foreach ($dns->dns_record_a as $k => $o) {
			if ($dns->isClass('dns') && $o->ttype === 'ns') {
				// MR -- add 'hostname'
				$hostname = $o->hostname;
				$param = $o->param;
				$nname = $o->nname;
			} else {
				$hostname = str_replace($dns->nname, $this->nname, $o->hostname);
				$param = str_replace($dns->nname, $this->nname, $o->param);
				$nname = str_replace($dns->nname, $this->nname, $o->nname);
			}

			$this->dns_record_a[$nname] = new dns_record_a(null, null, $nname);
			$this->dns_record_a[$nname]->hostname = $hostname;
			$this->dns_record_a[$nname]->ttype = $o->ttype;

			if (isset($o->priority)) {
				$this->dns_record_a[$nname]->priority = $o->priority;
			}

			$this->dns_record_a[$nname]->param = $param;
		}
	}

	function copyObjectWithSave($dnstemplate)
	{
		$saved = null;

		foreach ((array)($this->dns_record_a ?? array()) as $k => $v) {
			if ($v->ttype === 'txt') {
				$saved[$k] = $v;
			}
		}

		$this->dns_record_a = null;
		$this->copyObject($dnstemplate);

		foreach ($saved as $k => $v) {
			if (!isset($this->dns_record_a[$k])) {
				$this->dns_record_a[$k] = $v;
			}
		}
	}

	function updateRebuild($param)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$dnstemplatename = $param['newdnstemplate_f'];
		$dnstemplate = new Dnstemplate($this->__masterserver, $this->__readserver, $dnstemplatename);

		// If template get the ip from the template.
		$dnstemplate->get();
		$this->copyObjectWithSave($dnstemplate);
		$gbl->__ajax_refresh = true;
		$this->rootpassword_changed = 'on';

		return $param;
	}

	function postAdd()
	{
		$web = trim((string)($this->webipaddress ?? ''));
		$mail = trim((string)($this->mmailipaddress ?? ''));
		if ($web === '' || $mail === '') {
			try {
				$ips = self::getIpaddressList(is_object($this->getParentO()) ? $this->getParentO() : $this);
			} catch (Throwable $e) {
				$ips = array();
			}
			$first = '';
			if (is_array($ips) && $ips !== array()) {
				$first = (string)reset($ips);
			}
			if ($web === '') {
				$web = $first;
			}
			if ($mail === '') {
				$mail = $first !== '' ? $first : $web;
			}
			$this->webipaddress = $web;
			$this->mmailipaddress = $mail;
		}
		$ns = trim((string)($this->nameserver_f ?? ''));
		$sec = trim((string)($this->secnameserver_f ?? ''));
		if ($ns === '') {
			$ns = 'ns1';
		}
		if ($sec === '') {
			$sec = 'ns2';
		}
		$this->nameserver_f = $ns;
		$this->secnameserver_f = $sec;
		$this->createDefaultTemplate($web, $mail, $ns, $sec);
	}

	function createShowClist($subaction)
	{
		$clist["dns_record_a"] = null;

		return $clist;
	}

	function isRightParent()
	{
		try {
			$parent = $this->getParentO();
		} catch (Throwable $e) {
			return true;
		}
		if (!is_object($parent) || !method_exists($parent, 'getClName')) {
			return true;
		}

		return ($parent->getClName() === $this->parent_clname);
	}

	static function dnsPropertyTabs($withRebuild = true)
	{
		$tabs = array('a=show');
		if ($withRebuild) {
			$tabs[] = 'a=updateform&sa=rebuild';
		}
		$tabs[] = 'a=addform&c=dns_record_a&dta[var]=ttype&dta[val]=ns';
		$tabs[] = 'a=addform&c=dns_record_a&dta[var]=ttype&dta[val]=a';
		$tabs[] = 'a=addform&c=dns_record_a&dta[var]=ttype&dta[val]=aaaa';
		$tabs[] = 'a=addform&c=dns_record_a&dta[var]=ttype&dta[val]=cname';
		$tabs[] = 'a=addform&c=dns_record_a&dta[var]=ttype&dta[val]=fcname';
		$tabs[] = 'a=addform&c=dns_record_a&dta[var]=ttype&dta[val]=mx';
		$tabs[] = 'a=addform&c=dns_record_a&dta[var]=ttype&dta[val]=txt';
		$tabs[] = 'a=addform&c=dns_record_a&dta[var]=ttype&dta[val]=caa';
		$tabs[] = 'a=addform&c=dns_record_a&dta[var]=ttype&dta[val]=srv';
		$tabs[] = 'a=updateform&sa=parameter';

		return $tabs;
	}

	function createShowPropertyList(&$alist)
	{
		global $gbl, $sgbl, $login, $ghtml;

		if ($this->isRightParent()) {
			$rebuild = !cse($this->get__table(), "template")
				&& (!isset($sgbl) || !is_object($sgbl) || $sgbl->isKloxo());
			foreach (self::dnsPropertyTabs($rebuild) as $tab) {
				$alist['property'][] = $tab;
			}
		}
	}

	function fixParentClName()
	{
		if (!isset($this->dns_record_a) || !is_array($this->dns_record_a)) {
			return;
		}

		$clname = $this->getClName();
		foreach ($this->dns_record_a as $d) {
			if (!is_object($d)) {
				continue;
			}
			if (isset($d->parent_clname) && $d->parent_clname !== '') {
				continue;
			}

			$d->parent_clname = $clname;
		}
		// Show must not dirty the zone: setUpdateSubaction/write here made
		// login->was() sync DNS after every Manage DNS click.
	}

	function createShowAlist(&$alist, $subaction = null)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$this->fixParentClName();

		return $alist;
	}

	static function getIpaddressList($parent)
	{
		$out = array();
		$master = (is_object($parent) && isset($parent->__masterserver))
			? $parent->__masterserver : null;
		$res = array();
		global $sgbl;
		$passfile = (isset($sgbl) && is_object($sgbl) && isset($sgbl->__path_admin_pass))
			? (string)$sgbl->__path_admin_pass : '';
		if ($passfile !== '' && is_readable($passfile)
			&& trim((string)@file_get_contents($passfile)) !== '') {
			try {
				$db = new Sqlite($master, 'ipaddress');
				$got = $db->getTable(array('ipaddr'));
				if (is_array($got)) {
					$res = $got;
				}
			} catch (Throwable $e) {
				$res = array();
			}
		}

		if (is_array($res)) {
			foreach ($res as $row) {
				if (!is_array($row) || !isset($row['ipaddr'])) {
					continue;
				}
				$ip = trim((string)$row['ipaddr']);
				if ($ip === '' || strpos($ip, '127.') === 0) {
					continue;
				}
				$out[$ip] = $ip;
			}
		}

		if ($out) {
			return $out;
		}

		$ipfile = dirname(__DIR__, 2) . '/lib/php/kloxo_ipaddr.php';
		if (is_file($ipfile)) {
			include_once $ipfile;
		}
		if (class_exists('KloxoIpaddr', false)) {
			foreach (KloxoIpaddr::currentIpv4() as $row) {
				if (!is_array($row) || !isset($row['ipaddr'])) {
					continue;
				}
				$ip = trim((string)$row['ipaddr']);
				if ($ip === '') {
					continue;
				}
				$out[$ip] = $ip;
			}
		}

		return $out;
	}

	function updateform($subaction, $param)
	{
		global $gbl, $sgbl, $login, $ghtml;

		switch ($subaction) {

			// ONly from dnstemplate
			case "ipaddress":
				$res = self::getIpaddressList($this);
				$vlist['ipaddress'] = array('s', $res);
				return $vlist;

			case "parameter":
				$nslist = array();
				foreach ((array)($this->dns_record_a ?? array()) as $d) {
					if (is_object($d) && isset($d->ttype) && $d->ttype === 'ns') {
						$nslist[] = $d->param;
					}
				}

				$vlist['ttl'] = null;
				$vlist['soanameserver'] = array('s', $nslist);
				$this->setDefaultValue('hostmaster', "admin@{$this->nname}");
				$vlist['hostmaster'] = null;
				return $vlist;

			case "switchdnsserver":
				$vlist['syncserver'] = array('s', $login->getServerList('syncserver'));
				return $vlist;


			case "rebuild":
				$vlist['newdnstemplate_f'] = array('s', domainbase::getDnsTemplateList($login));
				$vlist['__v_updateall_button'] = array();
				return $vlist;

		}

		return parent::updateform($subaction, $param);
	}

	function updateSwitchDnsServer($param)
	{
		global $gbl, $sgbl, $login, $ghtml;

		// Not much checking is needed now. You just add the files. Don't delete it from the old place.
		// After all it is just one single dns file. We will come up with a better logic later.

		$this->syncserver = $param['syncserver'];

		$domain = $this->getParentO();
		$domain->dnspserver = $this->syncserver;
		$domain->setUpdateSubaction();
		$domain->write();

		$this->dbaction = 'syncadd';
		$this->was();

		$ghtml->print_redirect_back_success('dns_switched_successfuly', null);

		exit;
	}
}
