<?php

class pserver extends pservercore {

	static $__desc_mailqueue_l = array('', '', '', '');
	static $__desc_clientmail_l = array('', '', '', '');
	static $__desc_web_driver = array('', '', 'web_driver', '');
	static $__desc_webcache_driver = array('', '', 'webcache_driver', '');
	static $__desc_dns_driver = array('', '', 'dns_driver', '');
	static $__desc_pop3_driver = array('', '', 'pop3_driver', '');
//	static $__desc_imap4_driver = array('', '', 'pop3_driver', '');
	static $__desc_smtp_driver = array('', '', 'smtp_driver', '');
	static $__desc_spam_driver = array('', '', 'spam_driver', '');
	static $__acdesc_update_switchprogram = array('', '', 'Switch Program', '');
	static $__acdesc_update_mailqueuedelete = array('', '', 'delete', '');
	static $__acdesc_update_mailqueueflush = array('', '', 'flush', '');

	static $__desc_sshconfig_l = array('', '', '', '');
	static $__desc_phpini_o = array("db", "", "");
	static $__desc_serverweb_o = array("db", "", "");
	static $__desc_lxguard_o = array("db", "", "");
	static $__desc_ufwfirewall_o = array("d", "", "");
	static $__desc_fail2ban_o = array("d", "", "");
	static $__desc_logwatch_o = array("d", "", "");
	static $__desc_phpmodule_l = array("", "", "");

	Function display($var)
	{
		if ($var === "rolelist") {
			if(is_array($this->rolelist))
				return implode(",", $this->rolelist);
			else
				return $this->rolelist;
		}

		if ($var === 'used_f') {
			return $this->createUsed();
		}

		if ($var === 'ostype') {
			$v = parent::display($var);
			return ($v === null || $v === '') ? 'debian' : $v;
		}

		if ($var === 'nname') {
			$v = parent::display($var);
			return ($v === null || $v === '') ? 'localhost' : $v;
		}

		return parent::display($var);
	}


	// Programs the Switch Program form offers, per class (same lists as the form).
	function switchProgramChoices()
	{
		$os = (isset($this->ostype) && $this->ostype !== '') ? $this->ostype : 'debian';
		$inc = ($os === 'debian' || $os === 'ubuntu') ? 'debian.inc' : 'rhel.inc';
		$driver = array();
		$incpath = "../file/driver/{$inc}";

		if (is_file($incpath)) {
			include $incpath;
		}

		if (!is_array($driver)) {
			$driver = array();
		}

		$defaults = array(
			'web' => array('none', 'nginx', 'apache'),
			'webcache' => array('none'),
			'dns' => array('none', 'bind', 'knot'),
			'spam' => array('none', 'rspamd'),
			'pop3' => array('none', 'dovecot'),
			'smtp' => array('none', 'exim', 'postfix'),
		);
		$out = array();

		foreach ($defaults as $c => $list) {
			$out[$c] = (isset($driver[$c]) && is_array($driver[$c])) ? array_values($driver[$c]) : $list;
		}

		return $out;
	}

	/**
	 * Older installs stored spamassassin / bogofilter; both are gone. Map them to rspamd
	 * so the form and switch logic see a valid current program.
	 */
	static function normalizeSpamProgram($name)
	{
		$name = is_string($name) ? $name : '';
		if ($name === 'spamassassin' || $name === 'bogofilter' || $name === 'spamassassin-toaster') {
			return 'rspamd';
		}

		return $name !== '' ? $name : 'none';
	}

	// Program now in use for a class: the stored value, else the slave db, else none.
	function switchProgramCurrent($class)
	{
		$key = "{$class}_driver";

		if (isset($this->$key) && is_string($this->$key) && $this->$key !== '') {
			$val = $this->$key;
		} else {
			try {
				$val = rl_exec_get(null, $this->nname, 'slave_get_driver', array($class));
			} catch (Throwable $e) {
				$val = null;
			}
		}

		$val = (is_string($val) && $val !== '') ? $val : 'none';

		return ($class === 'spam') ? self::normalizeSpamProgram($val) : $val;
	}

	/**
	 * Rewrite a leftover spamassassin/bogofilter slave + object driver to rspamd and
	 * stop SpamAssassin when the stored value was still the old name.
	 */
	function migrateLegacySpamDriver()
	{
		$raw = null;
		try {
			$raw = rl_exec_get(null, $this->nname, 'slave_get_driver', array('spam'));
		} catch (Throwable $e) {
			$raw = isset($this->spam_driver) ? $this->spam_driver : null;
		}
		if (!is_string($raw) || !in_array($raw, array('spamassassin', 'bogofilter', 'spamassassin-toaster'), true)) {
			return;
		}
		$this->spam_driver = 'rspamd';
		try {
			rl_exec_get(null, $this->nname, array('spam', 'switchDriver'), array('spam', $raw, 'rspamd'));
		} catch (Throwable $e) {
			// install/enable rspamd even if the old uninstall raised
			try {
				rl_exec_get(null, $this->nname, array('Spam__rspamd', 'installMe'), array());
			} catch (Throwable $e2) {
			}
		}
		changeDriver($this->nname, 'spam', 'rspamd');
		$a = array();
		foreach (array('web', 'webcache', 'dns', 'spam', 'pop3', 'smtp') as $c) {
			$a[$c] = $this->switchProgramCurrent($c);
		}
		$a['spam'] = 'rspamd';
		try {
			rl_exec_get(null, $this->nname, 'slave_save_db', array('driver', $a));
		} catch (Throwable $e) {
		}
	}

	function updateSwitchProgram($param)
	{
		global $gbl, $sgbl, $login, $ghtml;

		// MR -- change and add nofixconfig
	/*
		// MR -- trick if using setdriver script
		if ($param['imap4_driver']) {
			$param['pop3_driver'] = $param['imap4_driver'];
			unset($param['imap4_driver']);
		}
	*/
		$this->migrateLegacySpamDriver();

		// Only these six keys are drivers. Anything else in $param (use_apache24,
		// __v_updateall_button...) must never reach switchDriver.
		$driverClasses = array('web', 'webcache', 'dns', 'spam', 'pop3', 'smtp');
		$allowed = $this->switchProgramChoices();
		$a = array();

		foreach ($driverClasses as $c) {
			$key = "{$c}_driver";
			$cur = $this->switchProgramCurrent($c);
			$want = isset($param[$key]) ? (string) $param[$key] : $cur;
			if ($c === 'spam') {
				$want = self::normalizeSpamProgram($want);
			}

			if (!isset($allowed[$c]) || !in_array($want, $allowed[$c], true)) {
				throw new lxException("Unknown {$c} program '{$want}'", '', $key);
			}

			$a[$c] = $want;
		}

		// Rspamd may be switched to none only when POP3/IMAP and SMTP are both none
		// in the same submit (email security: do not run a mail stack without the filter).
		$curSpam = $this->switchProgramCurrent('spam');
		if ($a['spam'] === 'none' && $curSpam !== 'none'
			&& ($a['pop3'] !== 'none' || $a['smtp'] !== 'none')) {
			$msg = (is_object($login) && method_exists($login, 'getThrow'))
				? $login->getThrow('cannot_disable_rspamd_while_mail_enabled')
				: 'Rspamd cannot be disabled while POP3/IMAP or SMTP is still enabled. This action is not possible: keep Rspamd for email server security, or disable both POP3/IMAP and SMTP together first.';
			throw new lxException($msg, '', 'spam_driver');
		}

	//	$nofixconfig = $param['no_fix_config'];

		$httpd24flag = "../etc/flag/use_apache24.flg";

		if (file_exists($httpd24flag)) {
			$current_useapache24 = 'on';
		} else {
			$current_useapache24 = 'off';
		}

		// MR -- get httpd24u info
		$out = array();
		if (is_file('../etc/list/httpd.lst')) {
			$lst = (string)@file_get_contents('../etc/list/httpd.lst');
			if (str_contains($lst, 'httpd24')) {
				$out[] = 'httpd24';
			}
		}

		if ($out !== []) {
			$next_useapache24 = (isset($param['use_apache24']) && $param['use_apache24'] === 'on') ? 'on' : 'off';

			if ($next_useapache24 === 'on') {
				exec("echo '' > {$httpd24flag}");
			} else {
				exec("'rm' -f {$httpd24flag}");
			}
		} else {
			$next_useapache24 = 'off';

			if (file_exists($httpd24flag)) {
				exec("'rm' -f {$httpd24flag}");
			}
		}

		// MR -- add 'pserver' on slavedb - read current server enough from slave_get_db
	//	$a['pserver'] = $this->nname;
	//	rl_exec_get(null, $this->nname, 'slave_save_db', array('driver', $a));

		$policyErrors = array();

		foreach ($driverClasses as $class) {
			$k = "{$class}_driver";
			$v = $a[$class];
			$drstring = $k;
			$current = $this->switchProgramCurrent($class);

			if ($class === 'web') {
				if ($current_useapache24 !== $next_useapache24) {
					dprint("Change 'Use Apache24' from '{$current_useapache24}' to '{$next_useapache24}'\n");
					rl_exec_get(null, $this->nname, array($class, 'switchDriver'), array($class, $v, $v));
				}
			}

			if ($current === $v) {
				dprint("No change for $k: $v\n");
			} else {
				dprint("Change for $k: $v\n");

				rl_exec_get(null, $this->nname, array($class, 'switchDriver'), array($class, $current, $v));

				changeDriver($this->nname, $class, $v);

				$fixc = $class;

			//	if (($class === 'spam') || ($class === 'pop3') || ($class === 'imap4') || ($class === 'smtp')) {
				if (($class === 'spam') || ($class === 'pop3') || ($class === 'smtp')) {
					// the script is /script/fixmail (it runs bin/fix/fixmmail.php);
					// there is no /script/fixmmail
					$fixc = "mail";
				}

				$a[$class] = $v;
				rl_exec_get(null, $this->nname, 'slave_save_db', array('driver', $a));

				if ($class === 'smtp') {
					// SMTP protection (the Spamdyke replacement) follows the MTA: install the
					// saved Mail Server Settings for the one that was just selected.
					$pres = rl_exec_get(null, $this->nname, array('KloxoMailPolicy', 'reapply'), array($v));
					if (!is_array($pres) || empty($pres['ok'])) {
						$why = (is_array($pres) && isset($pres['error']) && $pres['error'] !== '') ? $pres['error'] : 'no answer from the backend';
						$policyErrors[] = "SMTP is now {$v}, but the SMTP protection settings could not be applied ({$why})";
					}
				}

			//	if ($nofixconfig === 'on') { continue; }

				if ($class === 'webcache') {
					// Web cache in front: regenerate every site config with the new
					// ports first, then let install-webcache move the cache onto 80/443.
					// On failure put the old driver back and regenerate, so sites keep serving.
					$oldcache = $current;
					exec("sh /script/fixweb --server={$this->nname} --nolog");
					$res = rl_exec_get(null, $this->nname, array('KloxoWebcache', 'apply'), array($v));
					if (!is_array($res) || empty($res['ok'])) {
						$why = (is_array($res) && isset($res['error']) && $res['error'] !== '') ? $res['error'] : 'no answer from the backend';
						changeDriver($this->nname, $class, $oldcache);
						$a[$class] = $oldcache;
						rl_exec_get(null, $this->nname, 'slave_save_db', array('driver', $a));
						exec("sh /script/fixweb --server={$this->nname} --nolog");
						$back = rl_exec_get(null, $this->nname, array('KloxoWebcache', 'apply'), array($oldcache));
						$backok = (is_array($back) && !empty($back['ok'])) ? 'the previous web cache setting was restored' : 'RESTORING the previous setting also failed, check the web server';
						throw new lxException("Web cache switch to {$v} failed ({$why}); {$backok}", '', 'webcache');
					}

					continue;
				}

			//	lxshell_return("sh", "/script/fix{$fixc}", "--target=defaults", "--server={$this->nname}", "--nolog");
				exec("sh /script/fix{$fixc} --target=defaults --server={$this->nname} --nolog");
			}
		}

		if ($policyErrors !== array()) {
			throw new lxException(implode('; ', $policyErrors), '', 'smtp');
		}
	}

	function updatemailQueueFlush($param)
	{
		$app = mailqueue::queueDriverApp($this);
		kloxo_rl_driver(null, $this->syncserver, 'mailqueue', $app, 'QueueFlush', array());
		return null;
	}

	function updatemailQueueDelete($param)
	{
		$this->updateAccountSel($param, "mailqueuedelete");
		$app = mailqueue::queueDriverApp($this);
		kloxo_rl_driver(null, $this->syncserver, 'mailqueue', $app, 'QueueDelete',
			array($this->mailqueuedelete_list));

		return null;
	}

	function createUsed()
	{
		if (isset($this->used_f)) {
			return $this->used_f;
		}
		$res = $this->getUsed();
		if ($res) {
			$this->used_f = 'on';
		} else {
			$this->used_f = 'dull';
		}

		return $this->used_f;
	}

	function getUsed()
	{
	//	$vlist = array("mmail" => "mmail", "dns" => "dns",  "web" => "web", "mysqldb" => 'mysqldb', 'mssqldb' => 'mssqldb');

		$vlist = array("mmail" => "mmail", "dns" => "dns",  "web" => "web", "mysqldb" => 'mysqldb', "webcache" => 'webcache');

		$ret = null;

		foreach($vlist as $k => $v) {
			if (!is_array($v)) {
				$db = $v;
				$vname = "syncserver";
			} else {
				$db = $v[0];
				$vname = $v[1];
			}

			$db = new Sqlite($this->__masterserver, $db);
			$str = "$vname = '$this->nname'";
			$res = $db->getRowsWhere($str, array('nname'));

			if ($res) {
				$tmp = null;
				foreach($res as $r) {
					$tmp[] = $r['nname'];
				}
				$ret[$k] = implode(", ", $tmp);
			}
		}

		return $ret;
	}

	function createUsedDomainList()
	{
		$res = $this->getUsed();

		foreach($res as $k => $v) {
			$var = "used_domainlist_{$k}_f";
			$this->$var = $v;
		}

	//	$serlist = array("mmail" => "mmail", "dns" => "dns", "web" => "web", "mysqldb" => 'mysqldb', 'mssqldb' => 'mssqldb');
		$serlist = array("mmail" => "mmail", "dns" => "dns", "web" => "web", "mysqldb" => 'mysqldb', "webcache" => 'webcache');

		return $serlist;

	}

	function getSelfList()
	{
		try {
			$list = parent::getSelfList();
			if (is_array($list) && $list !== array()) {
				$out = array();
				foreach ($list as $k => $p) {
					if (is_object($p) && method_exists($p, 'getId')) {
						$out[(string)$k] = $p;
					}
				}
				if ($out !== array()) {
					return $out;
				}
			}
		} catch (Throwable $e) {
			log_log("error", "pserver getSelfList parent: " . $e->getMessage());
		}

		return self::adminServerSwitchList($this);
	}

	static function adminServerSwitchList($current = null)
	{
		global $login;

		$out = array();
		if (is_object($login) && method_exists($login, 'isAdmin') && $login->isAdmin()
			&& method_exists($login, 'getList')) {
			try {
				$ps = $login->getList('pserver');
				if (is_array($ps)) {
					foreach ($ps as $k => $p) {
						if (is_object($p) && method_exists($p, 'getId')) {
							$out[(string)$k] = $p;
						}
					}
				}
			} catch (Throwable $e) {
			}
		}

		if ($out === array() && is_object($current) && method_exists($current, 'getId')) {
			$n = (isset($current->nname) && is_string($current->nname) && $current->nname !== '')
				? $current->nname : 'localhost';
			$out[$n] = $current;
		}

		return $out !== array() ? $out : null;
	}

	function getMysqlDbAdmin(&$alist)
	{
		if (!function_exists('kloxo_pma_action_object')) {
			include_once dirname(__DIR__, 2) . '/lib/php/kloxo_pma.php';
		}
		$local = method_exists($this, 'isLocalhost') && $this->isLocalhost('nname');
		$server = (isset($this->nname) && is_string($this->nname)) ? $this->nname : 'localhost';
		$alist[] = kloxo_pma_action_object($local, $server);
	}


	static function rebootPropertyTabs()
	{
		return array(
			'a=updateform&sa=reboot',
			'a=updateform&sa=poweroff',
		);
	}

	static function switchProgramPropertyTabs()
	{
		return array(
			'a=updateform&sa=switchprogram',
		);
	}

	function createShowPropertyList(&$alist)
	{
		global $ghtml;

		$sa = '';
		if (is_object($ghtml)) {
			$raw = $ghtml->frm_subaction;
			$sa = is_string($raw) ? $raw : '';
		}

		if ($sa === 'commandcenter') {
			$alist['property'][] = "a=updateform&sa=commandcenter";
		} elseif ($sa === 'extraedit') {
			$alist['property'] = class_exists('phpini')
				&& is_callable(array('phpini', 'phpiniPropertyTabsForParent'))
				? phpini::phpiniPropertyTabsForParent()
				: array(
					'a=show&o=phpini',
					'a=updateform&sa=extraedit&o=phpini',
				);
		} elseif ($sa === 'ufwenable' || $sa === 'ufwdisable' || $sa === 'ufwdefaults'
			|| $sa === 'addrule' || $sa === 'deleterule') {
			$alist['property'] = class_exists('ufwfirewall')
				&& is_callable(array('ufwfirewall', 'ufwPropertyTabsForPserver'))
				? ufwfirewall::ufwPropertyTabsForPserver()
				: array('a=show&o=ufwfirewall');
		} elseif ($sa === 'timezone') {
			$alist['property'][] = "a=updateform&sa=timezone";
		} elseif ($sa === 'hostname') {
			$alist['property'][] = "a=updateform&sa=hostname";
	//	} elseif ($sa === 'update') {
	//		$alist['property'][] = "a=updateform&sa=update&n=driver";
		} elseif ($sa === 'reboot' || $sa === 'poweroff') {
			$alist['property'] = self::rebootPropertyTabs();
		} elseif ($sa === 'mysqlpasswordreset') {
			$alist['property'][] = "a=updateform&sa=mysqlpasswordreset";
		} elseif ($sa === 'switchprogram') {
			$alist['property'] = self::switchProgramPropertyTabs();
		}  else {
			$alist['property'][] = 'a=show';

		//	$alist['property'][] = "o=sp_specialplay&a=updateform&sa=skin";
			$alist['property'][] = "a=updateform&sa=information";

		//	if ($this->nname !== 'localhost') {
				$alist['property'][] = "a=updateform&sa=password";
		//	}

			if (check_if_many_server()) {
				$alist['property'][] = "a=list&c=psrole_a";
			}
		}
	}

	function createShowAlist(&$alist, $subaction = null)
	{
		global $login, $ghtml;

		// LxCenter:
		// No menu structures for Domain and Advanced here?

		$alist['__title_main_pserver'] = $this->getTitleWithSync();
		$alist[] = "a=list&c=service";
		$alist[] = "a=list&c=cron";
		$alist[] = "a=list&c=process";
		$alist[] = "a=list&c=component";
		$alist[] = "a=list&c=ipaddress";
		$alist[] = "a=updateform&sa=commandcenter";
		$alist[] = "a=updateform&sa=switchprogram";
		$alist[] = "a=updateform&sa=timezone";
		$alist[] = "a=updateform&sa=hostname";
		$alist[] = "a=show&o=sshclient";
		$alist[] = "a=show&o=llog";
		$alist[] = "a=show&l[class]=ffile&l[nname]=";
	//	$alist['__v_dialog_driver'] = "a=updateform&sa=update&o=driver";
		$alist[] = "a=show&o=driver";
		$alist[] = "a=updateform&sa=reboot";
		$alist[] = "a=updateform&sa=poweroff";

		$alist['__title_security'] = "Security";
		$alist[] = "a=show&o=sshconfig";
		$alist[] = "a=list&c=watchdog";
		$alist[] = "a=show&o=lxguard";
		$alist[] = "a=show&o=ufwfirewall";
		$alist[] = "a=show&o=fail2ban";
		$alist[] = "a=show&o=logwatch";
		$alist[] = "a=list&c=hostdeny";
		$alist[] = "a=list&c=sshauthorizedkey";

	//	$alist[] = "a=show&o=jailed";

		$alist['__title_webmailanddb'] = $login->getKeywordUc('webmailanddb');
		$alist[] = "o=servermail&a=updateform&sa=update";
		$alist[] = 'a=list&c=mailqueue';
		$alist[] = 'a=list&c=clientmail';
		$alist[] = "a=list&c=ftpsession";

		if (isset($ghtml->frm_o_o[0]['class']) && $ghtml->frm_o_o[0]['class'] === 'pserver') {
			$alist[] = "a=show&o=phpini";
			$alist[] = "a=updateform&sa=extraedit&o=phpini";
		}

		$alist[] = "a=show&o=serverweb";
		$alist[] = "a=show&o=serverftp";

		if ($login->isAdmin()) {
			$alist[] = "a=list&c=phpmodule";
		}

		$this->getMysqlDbAdmin($alist);
		$alist[] = "a=updateform&sa=mysqlpasswordreset";
		$alist[] = "a=list&c=dbadmin";
	/*
		
	//	$alist['__title_nnn'] = 'Machine';
		// MR -- move to under pserver
		$alist['__v_dialog_driver'] = "a=updateform&sa=update&o=driver";
		$alist[] = "a=updateform&sa=reboot";
		$alist[] = "a=updateform&sa=poweroff";
	*/

		return $alist;
	}
}
