<?php

class serverweb__ extends lxDriverClass
{
	function __construct()
	{
	}

	function dosyncToSystemPre()
	{
	}

	function dbactionUpdate($subaction)
	{
		// We need to write because reads everything from the database.
		$this->main->write();

		switch ($subaction) {
			case "apache_optimize":
				$this->set_apache_optimize();

				break;
			case "fix_chownchmod":
				$this->set_fix_chownchmod();

				break;

			case "fix_chownchmod_user":
				$this->set_fix_chownchmod_user();

				break;
			case "mysql_convert":
				$this->set_mysql_convert();

				break;
			case "php_type":
				$this->set_php_type();

				break;

			case "php_branch":
				$this->set_php_branch();

				break;

			case "multiple_php_install":
				$this->set_multiple_php_install();

				break;

			case "multiple_php_remove":
				$this->set_multiple_php_remove();

				break;

			case "multiple_php_activate":
				$this->set_multiple_php_activate();

				break;

			case "php_used":
				$this->set_php_used();

				break;

			case "enable_php52m_fpm":

				$this->set_php52m_fpm();

				break;

			case "pagespeed_clear_cache":
				$this->set_pagespeed_clear_cache();

				break;
		}
	}

	/** @return string nginx|apache */
	function selectedWebDriver()
	{
		if (is_object($this->main) && method_exists($this->main, 'selectedWebDriver')) {
			return $this->main->selectedWebDriver();
		}
		if (function_exists('slave_get_driver')) {
			$got = strtolower(trim((string) slave_get_driver('web')));
			if ($got === 'apache' || $got === 'apache2' || $got === 'httpd') {
				return 'apache';
			}
		}

		return 'nginx';
	}

	function set_apache_optimize()
	{
		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_ipaddr.php';

		$sel = (string)($this->main->apache_optimize ?? 'default');
		if (!in_array($sel, array('default', 'low', 'medium', 'high', 'customize'), true)) {
			$sel = 'default';
		}
		$ka = ((string)($this->main->enable_keepalive ?? '') === 'on') ? 'on' : 'off';

		if (KloxoIpaddr::isUbuntu()) {
			if ($this->selectedWebDriver() === 'nginx') {
				$this->writeUbuntuNginxOptimize($sel, $ka);
			} else {
				$this->writeUbuntuApacheOptimize($sel, $ka);
			}
			createRestartFile('restart-web');
			return;
		}

		if ($this->selectedWebDriver() === 'nginx') {
			return;
		}

		$scripting = '/script/apache-optimize';
		if (is_file($scripting)) {
			lxshell_return('sh', $scripting, '--select=' . $sel, '--keepalive=' . $ka, '--nolog');
		}
	}

	/** @var null|string Test hook: rewrite this nginx.conf path instead of /etc/nginx. */
	public static $nginxConfOverride = null;

	/**
	 * Tune worker_processes / worker_connections / keepalive_timeout in nginx.conf.
	 *
	 * @param string $sel default|low|medium|high
	 * @param string $ka on|off
	 */
	function writeUbuntuNginxOptimize($sel, $ka)
	{
		$file = '';
		if (is_string(self::$nginxConfOverride) && self::$nginxConfOverride !== '') {
			$file = self::$nginxConfOverride;
		} else {
			foreach (array('/etc/nginx/nginx.conf', '/etc/nginx/conf/nginx.conf') as $cand) {
				if (is_file($cand) && is_writable($cand)) {
					$file = $cand;
					break;
				}
				if ($file === '' && is_file($cand)) {
					$file = $cand;
				}
			}
		}
		if ($file === '' || !is_readable($file)) {
			return;
		}

		$workers = array(
			'low' => array('1', '1024'),
			'medium' => array('auto', '4096'),
			'high' => array('auto', '20480'),
			'default' => array('auto', '20480'),
			'customize' => array('auto', '20480'),
		);
		$W = isset($workers[$sel]) ? $workers[$sel] : $workers['default'];
		$keep = ($ka === 'on') ? '65' : '0';

		$prev = (string) @file_get_contents($file);
		$body = $prev;
		if (preg_match('/^\s*worker_processes\s+/m', $body) === 1) {
			$body = preg_replace(
				'/^\s*worker_processes\s+[^;]+;/m',
				'worker_processes ' . $W[0] . ';',
				$body,
				1
			);
		} else {
			$body = "worker_processes {$W[0]};\n" . $body;
		}
		if (preg_match('/^\s*worker_connections\s+/m', $body) === 1) {
			$body = preg_replace(
				'/^\s*worker_connections\s+[^;]+;/m',
				"\tworker_connections {$W[1]};",
				$body,
				1
			);
		}
		if (preg_match('/^\s*keepalive_timeout\s+/m', $body) === 1) {
			$body = preg_replace(
				'/^\s*keepalive_timeout\s+[^;]+;/m',
				"\tkeepalive_timeout {$keep};",
				$body,
				1
			);
		} elseif (preg_match('/^\s*http\s*\{/m', $body) === 1) {
			$body = preg_replace(
				'/^(\s*http\s*\{)/m',
				"$1\n    keepalive_timeout {$keep};",
				$body,
				1
			);
		}
		// Marker for the form's current selection.
		if (str_contains($body, '# kloxo-optimize:')) {
			$body = preg_replace('/# kloxo-optimize:\s*\S+/', '# kloxo-optimize: ' . $sel, $body, 1);
		} else {
			$body = "# kloxo-optimize: {$sel}\n" . $body;
		}

		$tmp = $file . '.tmp.' . getmypid();
		if (@file_put_contents($tmp, $body) === false) {
			return;
		}
		@chmod($tmp, 0644);
		if (!@rename($tmp, $file)) {
			@unlink($tmp);
			return;
		}
		if (is_string(self::$nginxConfOverride) && self::$nginxConfOverride !== '') {
			return;
		}
		$check = $this->runArgv(array('nginx', '-t'));
		if ($check !== 0 && $check !== 127) {
			@file_put_contents($file, $prev);
		}
	}

	function writeUbuntuApacheOptimize($sel, $ka)
	{
		$dir = '/etc/apache2/conf-available';
		if (!is_dir($dir)) {
			return;
		}

		$limits = array(
			'low' => array(1, 5, 15, 15, 50),
			'medium' => array(2, 25, 75, 25, 150),
			'high' => array(3, 50, 150, 50, 400),
			'default' => array(2, 25, 75, 25, 150),
			'customize' => array(2, 25, 75, 25, 150),
		);
		$L = isset($limits[$sel]) ? $limits[$sel] : $limits['default'];
		$keep = ($ka === 'on') ? 'On' : 'Off';
		$body = "# Generated by Kloxo CP Webserver Configure. selected: {$sel}\n"
			. "KeepAlive {$keep}\n"
			. "MaxKeepAliveRequests 100\n"
			. "KeepAliveTimeout 5\n"
			. "<IfModule mpm_event_module>\n"
			. "\tStartServers {$L[0]}\n"
			. "\tMinSpareThreads {$L[1]}\n"
			. "\tMaxSpareThreads {$L[2]}\n"
			. "\tThreadLimit 64\n"
			. "\tThreadsPerChild {$L[3]}\n"
			. "\tMaxRequestWorkers {$L[4]}\n"
			. "\tMaxConnectionsPerChild 0\n"
			. "</IfModule>\n"
			. "<IfModule mpm_worker_module>\n"
			. "\tStartServers {$L[0]}\n"
			. "\tMinSpareThreads {$L[1]}\n"
			. "\tMaxSpareThreads {$L[2]}\n"
			. "\tThreadsPerChild {$L[3]}\n"
			. "\tMaxRequestWorkers {$L[4]}\n"
			. "\tMaxConnectionsPerChild 0\n"
			. "</IfModule>\n";

		$file = $dir . '/kloxo-optimize.conf';
		$prev = is_file($file) ? (string)@file_get_contents($file) : null;
		$tmp = $file . '.tmp.' . getmypid();
		if (@file_put_contents($tmp, $body) === false) {
			return;
		}
		@chmod($tmp, 0644);
		if (!@rename($tmp, $file)) {
			@unlink($tmp);
			return;
		}
		$this->runArgv(array('a2enconf', '-q', 'kloxo-optimize'));
		$check = $this->runArgv(array('apache2ctl', '-t'));
		if ($check !== 0 && $prev !== null) {
			@file_put_contents($file, $prev);
		}
	}

	function runArgv($cmd)
	{
		if (!is_array($cmd) || $cmd === array() || !is_string($cmd[0]) || $cmd[0] === '') {
			return 127;
		}
		$bin = $cmd[0];
		if ($bin[0] !== '/') {
			$found = '';
			foreach (explode(':', (string)getenv('PATH') . ':/usr/sbin:/usr/bin:/bin') as $d) {
				if ($d !== '' && is_executable($d . '/' . $bin)) {
					$found = $d . '/' . $bin;
					break;
				}
			}
			if ($found === '') {
				return 127;
			}
			$cmd[0] = $found;
		} elseif (!is_executable($bin)) {
			return 127;
		}
		$p = @proc_open($cmd, array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		if (!$this->procStarted($p)) {
			return 127;
		}
		stream_get_contents($pipes[1]);
		stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		return proc_close($p);
	}

	function procStarted($p)
	{
		return is_resource($p) || is_object($p);
	}

	function set_fix_chownchmod()
	{
		$scripting = '/script/fix-chownchmod';

		switch ($this->main->fix_chownchmod) {
			case 'fix-ownership':
				lxshell_return("sh", $scripting, "--select=chown", '--nolog');

				break;
			case 'fix-permissions':
				lxshell_return("sh", $scripting, "--select=chmod", '--nolog');

				break;
			case 'fix-ALL':
				lxshell_return("sh", $scripting, "--select=all", '--nolog');

				break;
		}
	}

	function set_fix_chownchmod_user()
	{
		$scripting = '/script/fix-chownchmod';

		$client = 'all';
		try {
			$p = $this->main->getParentO();
			if (is_object($p) && isset($p->nname) && $p->nname !== '') {
				$client = (string)$p->nname;
			}
		} catch (Throwable $e) {
		}
		$user = '--client=' . $client;

		switch ($this->main->fix_chownchmod_user) {
			case 'fix-ownership':
				lxshell_return("sh", $scripting, "--select=chown", $user, '--nolog');

				break;
			case 'fix-permissions':
				lxshell_return("sh", $scripting, "--select=chmod", $user, '--nolog');

				break;
			case 'fix-ALL':
				lxshell_return("sh", $scripting, "--select=all", $user, '--nolog');

				break;
		}
	}

	function set_mysql_convert()
	{
		$scripting = '/script/mysql-convert';

		$charset = ($this->main->mysql_charset === 'utf8') ? '--utf8=yes' : '';
		$engine = '';
		switch ($this->main->mysql_convert) {
			case 'to-myisam':
				$engine = 'myisam';
				break;
			case 'to-innodb':
				$engine = 'innodb';
				break;
			case 'to-aria':
				$engine = 'aria';
				break;
		}
		if ($engine === '') {
			return;
		}
		$args = array('sh', $scripting, '--engine=' . $engine);
		if ($charset !== '') {
			$args[] = $charset;
		}
		$args[] = '--nolog';
		call_user_func_array('lxshell_return', $args);
	}

	function set_php_type()
	{
		global $login;

		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_ipaddr.php';
		$t = (string)($this->main->php_type ?? '');
		if (KloxoIpaddr::isUbuntu()) {
			if ($this->selectedWebDriver() === 'nginx') {
				if (!isset($this->main->php_type) || trim((string) $this->main->php_type) === '') {
					$this->main->php_type = 'php-fpm';
				}
				$this->set_secondary_php();
				createRestartFile('restart-web');
				return;
			}
			$this->set_php_type_ubuntu($t);
			createRestartFile('restart-web');
			return;
		}

		if (((stripos($t, '_ruid2') !== false)) || (stripos($t, '_itk') !== false)) {
			if ($this->main->secondary_php === 'on') {
				throw new lxException($login->getThrow("secondary_php_not_work_for"), '', $t);
			}
		}

		$ullkfapath = '../file/apache';

		$ehcpath = '/etc/httpd/conf';
		$ehcdpath = '/etc/httpd/conf.d';

		$haecpath = '/opt/configs/apache/etc/conf';
		$haecdpath = '/opt/configs/apache/etc/conf.d';

		if (isWebProxyOrApache()) {
			//--- some vps include /etc/httpd/conf.d/swtune.conf
			exec("'rm' -rf {$ehcdpath}/swtune.conf");

			lxshell_return("'cp' -rf {$ullkfapath} /opt/configs");

			if (!lfile_exists("{$ehcdpath}/~kloxo.conf")) {
				lxfile_cp(getLinkCustomfile($haecdpath, "~kloxo.conf"), $ehcdpath . "/~kloxo.conf");
				lxfile_cp(getLinkCustomfile($haecpath, "httpd.conf"), $ehcpath . "/httpd.conf");
			}

			if (!lfile_exists("{$ehcdpath}/__version.conf")) {
				lxfile_cp(getLinkCustomfile($haecdpath, "__version.conf"), $ehcdpath . "/__version.conf");
			}

			//--- don't use '=== true' but '!== false'
			if (stripos($t, 'mod_php') !== false) {
				$this->set_modphp($t);
			} elseif (stripos($t, 'suphp') !== false) {
				$this->set_suphp();
			} elseif (stripos($t, 'php-fpm') !== false) {
				$this->set_phpfpm();
			} elseif (stripos($t, 'fcgid') !== false) {
				$this->set_fcgid();
			} elseif (stripos($t, 'proxy_fcgi') !== false) {
				$this->set_proxyfcgi();
			}

			if (stripos($t, 'php-fpm') !== false) {
				if (is_executable("/bin/systemctl") || is_executable("/usr/bin/systemctl")) { @exec("systemctl enable php-fpm >/dev/null 2>&1"); }
			} else {
				if (is_executable("/bin/systemctl") || is_executable("/usr/bin/systemctl")) { @exec("systemctl disable php-fpm >/dev/null 2>&1"); }
			}

		//	$this->set_mpm($t);
		}

		$this->set_secondary_php();

		createRestartFile("restart-web");
	}

	function set_php_type_ubuntu($t)
	{
		if (!is_dir('/etc/apache2')) {
			return;
		}
		$t = is_string($t) ? $t : '';
		$mpm = (stripos($t, '_worker') !== false) ? 'mpm_worker' : 'mpm_event';
		foreach (array('mpm_prefork', 'mpm_worker', 'mpm_event') as $mod) {
			if ($mod !== $mpm) {
				$this->runArgv(array('a2dismod', '-q', $mod));
			}
		}
		$this->runArgv(array('a2enmod', '-q', $mpm));
		foreach (array('proxy', 'proxy_fcgi') as $mod) {
			$this->runArgv(array('a2enmod', '-q', $mod));
		}
		foreach (array('php8.5', 'php8.4', 'php8.3', 'php8.2', 'php7.4', 'php5.6') as $mod) {
			$this->runArgv(array('a2dismod', '-q', $mod));
		}
		$check = $this->runArgv(array('apache2ctl', '-t'));
		if ($check !== 0 && $check !== 127) {
			log_log('error', 'apache2ctl -t failed after php_type ' . $t);
		}
	}

	function set_modphp($type)
	{
		$ehcdpath = '/etc/httpd/conf.d';
		$haecdpath = '/opt/configs/apache/etc/conf.d';

		$this->rename_to_nonconf();

		$this->set_mpm('prefork');

		if ($type === 'mod_php') {
			// no action here
		} elseif ($type === 'mod_php_ruid2') {
			lxfile_cp(getLinkCustomfile($haecdpath, "ruid2.conf"), $ehcdpath . "/ruid2.conf");
			lxfile_rm("{$ehcdpath}/ruid2.nonconf");
		} elseif ($type === 'mod_php_itk') {
			lxfile_cp(getLinkCustomfile($haecdpath, "itk.conf"), $ehcdpath . "/itk.conf");
			exec("echo 'HTTPD=/usr/sbin/httpd.itk' >/etc/sysconfig/httpd");
		}

		lxfile_cp(getLinkCustomfile($haecdpath, "php.conf"), $ehcdpath . "/php.conf");
		lxfile_rm("{$ehcdpath}/php.nonconf");

		$this->remove_phpfpm();
	}

	function set_suphp()
	{
		$ehcdpath = '/etc/httpd/conf.d';

		$epath = '/etc';
		$haepath = '/opt/configs/apache/etc';

		$this->rename_to_nonconf();

		lxfile_cp(getLinkCustomfile($haepath, "suphp.conf"), $epath . "/suphp.conf");

		$this->remove_phpfpm();

		lxshell_return("sh", "/script/fixphp", "--nolog");

		lxfile_rm("{$ehcdpath}/suphp.nonconf");
	}

	function set_phpfpm()
	{
		$ehcdpath = '/etc/httpd/conf.d';
		$haecdpath = '/opt/configs/apache/etc/conf.d';

		$this->rename_to_nonconf();

	//	lxfile_rm("{$ehcdpath}/proxy_fcgi.nonconf");
	//	lxfile_rm("{$ehcdpath}/proxy_fcgi.conf");
		lxfile_rm("{$ehcdpath}/fastcgi.nonconf");

		lxfile_cp(getLinkCustomfile($haecdpath, "_inactive_.conf"), $ehcdpath . "/php.conf");
		lxfile_cp(getLinkCustomfile($haecdpath, "fastcgi.conf"), $ehcdpath . "/fastcgi.conf");

	//	lxshell_return("chkconfig", "php-fpm", "on");
		if (is_executable("/bin/systemctl") || is_executable("/usr/bin/systemctl")) { @exec("systemctl enable php-fpm >/dev/null 2>&1"); }
	}

	function set_fcgid()
	{
		$ehcdpath = '/etc/httpd/conf.d';
		$haecdpath = '/opt/configs/apache/etc/conf.d';

		$this->remove_phpfpm();

		$this->rename_to_nonconf();

		lxfile_cp(getLinkCustomfile($haecdpath, "fcgid.conf"), $ehcdpath . "/fcgid.conf");
		lxfile_rm("{$ehcdpath}/fcgid.nonconf");
	}

	function set_proxyfcgi()
	{
		$ehcmdpath = '/etc/httpd/conf.modules.d';
		$haecmdpath = '/opt/configs/apache/etc/conf.modules.d';

		$this->remove_phpfpm();

		$this->rename_to_nonconf();

		lxfile_cp(getLinkCustomfile($haecmdpath, "00-proxy.conf"), $ehcmdpath . "/00-proxy.conf");
		lxfile_rm("{$ehcmdpath}/00-proxy.nonconf");
	}

	function remove_phpfpm()
	{
		// MR -- no remove and just off/disable
		if (is_executable("/bin/systemctl") || is_executable("/usr/bin/systemctl")) { @exec("systemctl disable --now php-fpm >/dev/null 2>&1"); }
	}

	function rename_to_nonconf()
	{
		// MR -- use overwrite with 'inactive' content instead rename
		// minimize effect when apt upgrades apache modules
		$list = array('php', 'fastcgi', 'fcgid', 'ruid2', 'suphp', 'proxy_fcgi', 'itk');

		foreach ($list as &$l) {
			if ($l === 'proxy_fcgi') {
				$tpath = '/etc/httpd/conf.modules.d';
				$spath = '/opt/configs/apache/etc/conf.modules.d';
			} else {
				$tpath = '/etc/httpd/conf.d';
				$spath = '/opt/configs/apache/etc/conf.d';
			}

			$source = getLinkCustomfile($spath, "_inactive_.conf");
			if ($l === 'proxy_fcgi') {
				lxfile_cp($source, "{$tpath}/00-proxy.conf");
				lxfile_rm("{$tpath}/00-proxy.nonconf");
			} else {
				lxfile_cp($source, "{$tpath}/{$l}.conf");
				lxfile_rm("{$tpath}/{$l}.nonconf");
			}
		}
	}

	function set_mpm($type)
	{
		if (stripos($type, '_worker') !== false) {
			$a = '.worker';
		} elseif (stripos($type, '_event') !== false) {
			$a = '.event';
		} elseif (stripos($type, '_itk') !== false) {
			$a = '.itk';
		} else {
			$a = '';
		}

		if (file_exists("../etc/use_apache24.flg")) {
			$a = str_replace('.', '', $a);

			if ($a === '') { $a = 'prefork'; }

		//	exec("echo 'LoadModule mpm_{$a}_module modules/mod_mpm_{$a}.so' >/etc/httpd/conf.modules.d/00-mpm.conf");
			file_put_contents("/etc/httpd/conf.modules.d/00-mpm.conf",
				"LoadModule mpm_{$a}_module modules/mod_mpm_{$a}.so");
		} else {
		//	exec("echo 'HTTPD=/usr/sbin/httpd{$a}' >/etc/sysconfig/httpd");
			file_put_contents("/etc/sysconfig/httpd.conf", "HTTPD=/usr/sbin/httpd{$a}");
		}

		$scripting = '/script/fixweb';

		lxshell_return("sh", $scripting, "--target=defaults", "--nolog");

		setRpmInstalled("httpd");
	}

	function set_secondary_php()
	{
		$ehcdpath = '/etc/httpd/conf.d';
		$haecdpath = '/opt/configs/apache/etc/conf.d';

		$epath = '/etc';
		$haepath = '/opt/configs/apache/etc';

		// MR -- remove old files
		lxfile_rm("{$ehcdpath}/suphp52.nonconf");
		lxfile_rm("{$ehcdpath}/suphp52.conf");

		if ($this->main->secondary_php === 'on') {
			if (stripos($this->main->php_type, 'suphp') !== false) {
				lxfile_cp(getLinkCustomfile($haecdpath, "suphp2.conf"), $ehcdpath . "/suphp.conf");
				lxfile_cp(getLinkCustomfile($haecdpath, "_inactive_.conf"), $ehcdpath . "/suphp2.conf");
			} else {
				lxfile_cp(getLinkCustomfile($haecdpath, "_inactive_.conf"), $ehcdpath . "/suphp.conf");
				lxfile_cp(getLinkCustomfile($haecdpath, "suphp2.conf"), $ehcdpath . "/suphp2.conf");
			}

			if (isWebProxy()) {
				if (stripos($this->main->php_type, 'fcgid') !== false) {
					lxfile_cp(getLinkCustomfile($haecdpath, "fcgid2.conf"), $ehcdpath . "/fcgid.conf");
					lxfile_cp(getLinkCustomfile($haecdpath, "_inactive_.conf"), $ehcdpath . "/fcgid2.conf");
				} else {
					lxfile_cp(getLinkCustomfile($haecdpath, "_inactive_.conf"), $ehcdpath . "/fcgid.conf");
					// MR -- only enable under Apache 2.2 (because trouble if enable together with proxy_fcgi
					lxfile_cp(getLinkCustomfile($haecdpath, "fcgid2.conf"), $ehcdpath . "/fcgid2.conf");
				}
			}
		} else {
			lxfile_rm("{$ehcdpath}/suphp2.conf");
			lxfile_rm("{$ehcdpath}/fcgid2.conf");

			if (stripos($this->main->php_type, 'suphp') !== false) {
				lxfile_cp(getLinkCustomfile($haepath, "suphp.conf"), $epath . "/suphp.conf");
				lxfile_cp(getLinkCustomfile($haecdpath, "suphp.conf"), $ehcdpath . "/suphp.conf");
			} else {
				lxfile_cp(getLinkCustomfile($haecdpath, "_inactive_.conf"), $ehcdpath . "/suphp.conf");
			}

			if (stripos($this->main->php_type, 'fcgid') !== false) {
				lxfile_cp(getLinkCustomfile($haecdpath, "fcgid.conf"), $ehcdpath . "/fcgid.conf");
			} else {
				lxfile_cp(getLinkCustomfile($haecdpath, "_inactive_.conf"), $ehcdpath . "/fcgid.conf");
			}
		}

		lxfile_cp(getLinkCustomfile($haepath, "suphp.conf"), $epath . "/suphp.conf");
	}

	function set_php_branch($branch = null)
	{
		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpver.php';
		$sel = $branch ? $branch : $this->main->php_branch;
		$sel = preg_replace('/(.*)\_\(as\_(.*)\)/', '$1', (string)$sel);
		if (KloxoPhpver::isDistroId($sel)) {
			KloxoPhpver::setActiveId($sel);
			KloxoPhpver::setDefaultCli($sel);
			createRestartFile("restart-php-fpm");
			createRestartFile("restart-web");
			return;
		}

		$ehcdpath = '/etc/httpd/conf.d';
		$haecdpath = '/opt/configs/apache/etc/conf.d';
	/*
		$installed = isRpmInstalled('yum-plugin-replace');

		if (!$installed) {
			setRpmInstalled("yum-plugin-replace");
		}
	*/
		$scripting = '/script/set-php-branch';

		if ($branch) {
			$branchselect = $branch;
		} else {
			$branchselect = $this->main->php_branch;
		}

		$branchselect = preg_replace('/(.*)\_\(as\_(.*)\)/', '$1', $branchselect);

		lxshell_return("sh", $scripting, $branchselect);

		$scripting = '/script/fixweb';

		lxshell_return("sh", $scripting, '--nolog');
	/*
		$installed = isRpmInstalled("{$branchselect}-fpm");

		if ($installed) {
		//	lxshell_return("chkconfig", "php-fpm", "on");
			createRestartFile("restart-web");
		}
	*/
		createRestartFile("restart-web");

		if (stripos('mod_php', $this->main->php_type) === false) {
			lxfile_mv(getLinkCustomfile($haecdpath, "_inactive_.conf"), $ehcdpath . "/php.conf");

		}
	}

	function set_php_used()
	{
		global $login;

		$v = $this->main->php_used;

		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpver.php';
		if (KloxoPhpver::setActiveId((string)$v)) {
			KloxoPhpver::setDefaultCli((string)$v);
			createRestartFile("restart-php-fpm");
			createRestartFile("restart-web");
			return;
		}

		if (isWebProxyOrApache()) {
			$p = $this->main->php_type;

			if ($v !== '--PHP Branch--') {

				if ((strpos($p, 'php-fpm') !== false) || (strpos($p, 'proxy_fcgi') !== false)) {
					// no action
				} else {
					throw new lxException($login->getThrow("only_work_for_php-type_for_php-fpm_or_proxy_fcgi"), '', $p);
				}
			}
		}

		switch ($v) {
			case "--PHP Branch--":
				lxshell_return("sh", "/script/set-php-fpm", "php");
				break;
			default:
				lxshell_return("sh", "/script/set-php-fpm", $v);
				break;
		}
	}

	function set_multiple_php_install()
	{
		global $login;

		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpver.php';
		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_ipaddr.php';
		$ids = KloxoPhpver::parseRequestedIds($this->main->multiple_php_install ?? '');
		$allow = array_fill_keys(KloxoPhpver::installableSeries(), true);
		$vers = array();
		foreach ($ids as $id) {
			$s = KloxoPhpver::idToSeries((string)$id);
			if ($s !== '' && isset($allow[$s])) {
				$vers[] = $s;
			}
		}
		$vers = array_values(array_unique($vers));
		if ($vers === array()) {
			throw new lxException($login->getThrow('no_options_selected'), '', 'blank');
		}
		if (KloxoIpaddr::isUbuntu()) {
			$this->startSitePhpInstall($vers);
			return;
		}

		$c = '/tmp/phpm-install-process.sh';

		if (file_exists($c)) {
			throw new lxException($login->getThrow('other_install_process_still_running'), '', $this->main->syncserver);
		}

		$b = '';
		foreach ($ids as $v) {
			if (preg_match('/^[A-Za-z0-9._-]+$/', (string)$v) !== 1) {
				continue;
			}
			$b .= "sh /script/phpm-installer {$v} -y\n";
		}

		$b .= "sh /script/fixphp\n";
		$b .= "'rm' -f {$c}\n";

		file_put_contents($c, $b);

		lxshell_background("sh", $c);

		throw new lxInfoException($login->getThrow('install_process_running_in_background'), '', $this->main->syncserver);
	}

	/**
	 * Paths for a queued install-site-php job. Delegates to KloxoPhpver.
	 *
	 * @return array{root:string,script:string,logDir:string,job:string,lock:string,log:string}
	 */
	static function sitePhpInstallPaths($root)
	{
		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpver.php';

		return KloxoPhpver::sitePhpInstallPaths((string)$root);
	}

	/**
	 * Build the wrapper script body. Delegates to KloxoPhpver.
	 */
	static function sitePhpInstallWrap($script, $root, $versArg, $log, $lock, $job)
	{
		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpver.php';

		return KloxoPhpver::sitePhpInstallWrap(
			(string)$script,
			(string)$root,
			(string)$versArg,
			(string)$log,
			(string)$lock,
			(string)$job
		);
	}

	/**
	 * Panel PHP-FPM is lxlabs. Write a validated request file, then ask
	 * kloxo-backend (root) to run it. If 7776 does not answer, the file stays
	 * for the backend 30s loop / kloxo-watchdog.
	 *
	 * @param string $method queueInstall|queueRemove
	 * @param mixed $arg
	 * @return array{0:mixed,1:string} [result, server]
	 */
	function phpverBackend($method, $arg)
	{
		global $login;

		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpver.php';
		$server = 'localhost';
		if (isset($this->main->syncserver) && is_string($this->main->syncserver) && $this->main->syncserver !== '') {
			$server = $this->main->syncserver;
		}
		$op = ($method === 'queueRemove') ? 'remove' : 'install';
		$drop = KloxoPhpver::dropRequest($op, $arg);
		if (!is_array($drop) || empty($drop['ok'])) {
			$err = (is_array($drop) && isset($drop['error'])) ? (string) $drop['error'] : 'could not queue';
			if ($err === 'no versions' || $err === 'no packages') {
				throw new lxException($login->getThrow('no_options_selected'), '', 'blank');
			}
			if ($err === 'other install running') {
				throw new lxException($login->getThrow('other_install_process_still_running'), '', $server);
			}
			throw new lxException($login->getThrow('could_not_execute'), '', $method . ': ' . $err);
		}
		$euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : 0;
		if ($euid === 0) {
			return array(KloxoPhpver::runDroppedRequest(), $server);
		}
		if (function_exists('rl_exec_get')) {
			$calls = array(
				array('KloxoPhpver', 'runDroppedRequest', array()),
				array('KloxoPhpver', $method, array($arg)),
			);
			foreach ($calls as $c) {
				try {
					$got = rl_exec_get(null, $server, array($c[0], $c[1]), $c[2], false);
				} catch (Throwable $e) {
					$got = null;
				}
				if (is_array($got) && !empty($got['ok'])) {
					return array($got, $server);
				}
			}
		}

		return array(array('ok' => true, 'queued' => true), $server);
	}

	function startSitePhpInstall($vers)
	{
		global $login;

		list($got, $server) = $this->phpverBackend('queueInstall', $vers);
		if (!is_array($got) || empty($got['ok'])) {
			$err = (is_array($got) && isset($got['error'])) ? (string)$got['error'] : '';
			if ($err === 'no versions') {
				throw new lxException($login->getThrow('no_options_selected'), '', 'blank');
			}
			if ($err === 'other install running') {
				throw new lxException($login->getThrow('other_install_process_still_running'), '', $server);
			}
			if ($err === 'file_not_found' || strncmp($err, 'file_not_found:', 15) === 0) {
				$path = ($err === 'file_not_found') ? '' : substr($err, 15);
				throw new lxException($login->getThrow('file_not_found'), '', $path);
			}
			$why = ($err !== '' && $err !== 'not root')
				? $err
				: 'kloxo-backend (127.0.0.1:7776) did not handle the request';
			throw new lxException($login->getThrow('could_not_execute'), '', 'install-site-php: ' . $why);
		}

		throw new lxInfoException($login->getThrow('install_process_running_in_background'), '', $server);
	}

	function set_multiple_php_remove()
	{
		global $login;

		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpver.php';
		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_ipaddr.php';

		$list = KloxoPhpver::parseRequestedIds($this->main->multiple_php_remove ?? '');
		if ($list === array()) {
			throw new lxException($login->getThrow('no_options_selected'), '', 'blank');
		}

		$skip = array_fill_keys(KloxoPhpver::protectedRemoveIds(), true);
		if (isset($this->main->php_used) && is_string($this->main->php_used) && $this->main->php_used !== '') {
			$skip[$this->main->php_used] = true;
		}
		$keep = array();
		foreach ($list as $v) {
			if (!isset($skip[$v])) {
				$keep[] = $v;
			}
		}
		if ($keep === array()) {
			throw new lxException($login->getThrow('php_already_in_used'), '', implode(',', $list));
		}
		$list = $keep;

		if (KloxoIpaddr::isUbuntu()) {
			list($got, $server) = $this->phpverBackend('queueRemove', $list);
			if (!is_array($got) || empty($got['ok'])) {
				$err = (is_array($got) && isset($got['error'])) ? (string)$got['error'] : '';
				if ($err === 'php already in use') {
					throw new lxException($login->getThrow('php_already_in_used'), '', implode(',', $list));
				}
				if ($err === 'no packages' || $err === 'no versions') {
					throw new lxException($login->getThrow('no_options_selected'), '', 'blank');
				}
				if ($err === 'other install running') {
					throw new lxException($login->getThrow('other_install_process_still_running'), '', $server);
				}
				$why = ($err !== '' && $err !== 'not root')
					? $err
					: 'kloxo-backend (127.0.0.1:7776) did not handle the request as root';
				throw new lxException($login->getThrow('could_not_execute'), '', 'php remove: ' . $why);
			}
			throw new lxInfoException($login->getThrow('remove_process_running_in_background'), '', $server);
		}

		foreach ($list as $v) {
			lxshell_return('sh', '/script/phpm-remover', $v);
		}
	}

	function set_multiple_php_activate()
	{
		global $login;

		$e = $this->main->multiple_php_flag;

		if ($e === 'on') {
			@touch('../etc/flag/enablemultiplephp.flg');
		} else {
			@unlink('../etc/flag/enablemultiplephp.flg');
		}

		exec("sh /script/enable-php-fpm; sh /script/add-start-queue restart-php-fpm");
	}

	function set_php52m_fpm()
	{
		global $login;

		$e = $this->main->enable_php52m_fpm;

		if ($e === 'on') {
			@touch('../etc/flag/enable_php52m-fpm.flg');
		} else {
			@unlink('../etc/flag/enable_php52m-fpm.flg');
		}

		exec("sh /script/enable-php-fpm; sh /script/add-start-queue restart-php-fpm");
	}

	function set_pagespeed_clear_cache()
	{
		global $login;

		$rem = $this->main->pagespeed_cache;

		if ($rem === '') {
			throw new lxException($login->getThrow('no_options_selected'), '', 'blank');
		} else {
			exec("sh /script/clearcache-pagespeed");
		}
	}
}
