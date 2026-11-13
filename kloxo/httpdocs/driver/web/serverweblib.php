<?php

class serverweb extends lxdb
{
	static $__desc = array("", "", "webserver_config");
	static $__desc_nname = array("", "", "webserver_config");
	static $__desc_php_type = array("", "", "php_type");
	static $__desc_secondary_php = array("", "", "secondary_php");

	static $__acdesc_update_edit = array("", "", "config");
	static $__acdesc_show = array("", "", "webserver_config");

	static $__desc_apache_optimize = array("", "", "apache_optimize");
	static $__desc_enable_keepalive = array("f", "", "enable_keepalive");

	static $__desc_mysql_convert = array("", "", "mysql_convert");
	static $__desc_mysql_charset = array("", "", "mysql_charset");

	static $__desc_fix_chownchmod = array("", "", "fix_chownchmod");
	static $__desc_fix_chownchmod_user = array("", "", "fix_chownchmod");

	static $__desc_php_branch = array("", "", "php_branch");
	static $__desc_php_used = array("", "", "php_used");

	static $__desc_multiple_php_flag = array("f", "", "multiple_php_enable");

	static $__desc_multiple_php_install = array("", "", "multiple_php_install");
	static $__desc_multiple_php_install_note = array("", "", "multiple_php_install_note");
	static $__desc_multiple_php_already_installed = array("", "", "multiple_php_already_installed");
	static $__desc_multiple_php_remove = array("", "", "multiple_php_remove");

	static $__desc_pagespeed_cache = array("", "", "pagespeed_cache");

	static $__desc_enable_php52m_fpm = array("", "", "enable_php52m_fpm");

	/** @var null|string Test hook: force nginx|apache without reading slavedb. */
	public static $webDriverOverride = null;

	/**
	 * Switch Program web driver for this server (nginx or apache).
	 *
	 * Same order as Switch Program: slavedb/driver via kloxo-backend first
	 * (written on every switch), then the panel driver table, then a local
	 * absolute slavedb read. Falling back to getSyncClass(null, …) first was
	 * wrong — for some logins it returns null and we always showed Nginx.
	 *
	 * @return string nginx|apache
	 */
	function selectedWebDriver()
	{
		if (is_string(self::$webDriverOverride) && self::$webDriverOverride !== '') {
			return self::normalizeWebDriverName(self::$webDriverOverride);
		}

		$server = (isset($this->syncserver) && is_string($this->syncserver) && $this->syncserver !== '')
			? $this->syncserver : 'localhost';
		if ($server === '' || $server === 'pserver-localhost') {
			$server = 'localhost';
		}

		// Parent pserver may already hold web_driver from Switch Program.
		try {
			$parent = $this->getParentO();
			if (is_object($parent) && isset($parent->web_driver)
				&& is_string($parent->web_driver) && $parent->web_driver !== '') {
				return self::normalizeWebDriverName($parent->web_driver);
			}
		} catch (Throwable $e) {
		}

		$got = '';

		// 1) Live slavedb on the server (authoritative after Switch Program).
		if (function_exists('rl_exec_get')) {
			try {
				$raw = rl_exec_get(null, $server, 'slave_get_driver', array('web'), false);
				if (is_string($raw) && $raw !== '') {
					$got = $raw;
				}
			} catch (Throwable $e) {
				$got = '';
			}
		}

		// 2) Panel driver table (pg_web).
		if ($got === '') {
			global $gbl;
			try {
				if (is_object($gbl) && method_exists($gbl, 'getSyncClass')) {
					$master = (isset($this->__masterserver) && is_string($this->__masterserver)
						&& $this->__masterserver !== '')
						? $this->__masterserver : 'localhost';
					$raw = $gbl->getSyncClass($master, $server, 'web');
					if (is_string($raw) && $raw !== '') {
						$got = $raw;
					}
				}
			} catch (Throwable $e) {
				$got = '';
			}
		}

		// 3) Local slavedb with an absolute path (panel FPM cwd is not always httpdocs).
		if ($got === '') {
			$got = self::readLocalSlaveWebDriver();
		}

		return self::normalizeWebDriverName($got);
	}

	/** @return string nginx|apache */
	static function normalizeWebDriverName($name)
	{
		$got = strtolower(trim((string) $name));
		if ($got === 'apache' || $got === 'apache2' || $got === 'httpd') {
			return 'apache';
		}

		return 'nginx';
	}

	/** Read etc/slavedb/driver web entry from the program root. */
	static function readLocalSlaveWebDriver()
	{
		global $sgbl;

		$paths = array();
		if (is_object($sgbl) && isset($sgbl->__path_program_root)
			&& is_string($sgbl->__path_program_root) && $sgbl->__path_program_root !== '') {
			$paths[] = rtrim($sgbl->__path_program_root, '/') . '/etc/slavedb/driver';
		}
		$paths[] = dirname(__DIR__, 3) . '/etc/slavedb/driver';
		$paths[] = '../etc/slavedb/driver';

		foreach ($paths as $f) {
			if (!is_string($f) || $f === '' || !is_readable($f)) {
				continue;
			}
			$raw = @file_get_contents($f);
			if (!is_string($raw) || $raw === '') {
				continue;
			}
			// allowed_classes=false yields __PHP_Incomplete_Class; cast to array for ->data.
			$rmt = @unserialize($raw, array('allowed_classes' => false));
			if ($rmt === false) {
				$rmt = @unserialize($raw);
			}
			$data = null;
			if (is_object($rmt)) {
				$arr = (array) $rmt;
				if (isset($arr['data']) && is_array($arr['data'])) {
					$data = $arr['data'];
				} elseif (isset($rmt->data) && is_array($rmt->data)) {
					$data = $rmt->data;
				}
			} elseif (is_array($rmt) && isset($rmt['data']) && is_array($rmt['data'])) {
				$data = $rmt['data'];
			}
			if (is_array($data) && isset($data['web']) && is_string($data['web']) && $data['web'] !== '') {
				return $data['web'];
			}
		}

		if (function_exists('slave_get_driver')) {
			try {
				$raw = slave_get_driver('web');
				if (is_string($raw) && $raw !== '') {
					return $raw;
				}
			} catch (Throwable $e) {
			}
		}

		return '';
	}

	/** Point field labels at Apache or Nginx desclib keys for this request. */
	function applyDriverFieldLabels()
	{
		if ($this->selectedWebDriver() === 'apache') {
			self::$__desc_php_type = array('', '', 'php_type_apache');
			self::$__desc_secondary_php = array('', '', 'secondary_php_apache');
			self::$__desc_apache_optimize = array('', '', 'apache_optimize');
		} else {
			self::$__desc_php_type = array('', '', 'php_type_nginx');
			self::$__desc_secondary_php = array('', '', 'secondary_php_nginx');
			self::$__desc_apache_optimize = array('', '', 'nginx_optimize');
		}
	}

	function createShowUpdateform()
	{
		$parent = $this->getParentO();
		if (!is_object($parent) || $parent->getClass() !== 'pserver') {
			$uflist['fix_chownchmod_user'] = null;

			return $uflist;
		}

		if ((!isset($this->syncserver) || $this->syncserver === '')
			&& isset($parent->nname) && is_string($parent->nname) && $parent->nname !== '') {
			$this->syncserver = $parent->nname;
		}

		$this->applyDriverFieldLabels();

		$uflist['php_used'] = null;
		$uflist['php_branch'] = null;
		$uflist['multiple_php_activate'] = null;
		$uflist['multiple_php_install'] = null;
		$uflist['multiple_php_remove'] = null;
		$uflist['php_type'] = null;
		$uflist['apache_optimize'] = null;
		$uflist['mysql_convert'] = null;
		$uflist['fix_chownchmod'] = null;
		$uflist['pagespeed_clear_cache'] = null;

		return $uflist;
	}

	function updateform($subaction, $param)
	{
		$vlist = array();
		$this->applyDriverFieldLabels();
		$web = $this->selectedWebDriver();

		switch($subaction) {
			case "apache_optimize":
				$this->apache_optimize = null;
				$txt = '';
				if ($web === 'nginx') {
					foreach (array('/etc/nginx/nginx.conf', '/etc/nginx/conf/nginx.conf') as $conf) {
						if (is_file($conf)) {
							$txt = (string) @file_get_contents($conf);
							break;
						}
					}
				} else {
					foreach (array(
						'/etc/apache2/conf-available/kloxo-optimize.conf',
						'/etc/apache2/conf-available/~kloxo.conf',
						'/etc/httpd/conf.d/~kloxo.conf',
					) as $conf) {
						if (is_file($conf)) {
							$txt = (string) @file_get_contents($conf);
							break;
						}
					}
				}

				$a = array('default', 'low', 'medium', 'high');
				if ($web !== 'nginx' && stripos($txt, 'customize') !== false) {
					$a[] = 'customize';
				}
				$vlist['apache_optimize'] = array('s', $a);

				$b = 'default';
				foreach ($a as $v) {
					if (stripos($txt, 'selected: ' . $v) !== false
						|| stripos($txt, 'kloxo-optimize: ' . $v) !== false) {
						$b = $v;
						break;
					}
				}
				$this->setDefaultValue('apache_optimize', $b);

				$this->enable_keepalive = null;
				$vlist['enable_keepalive'] = null;
				if ($web === 'nginx') {
					$s = (preg_match('/^\s*keepalive_timeout\s+0\s*;/mi', $txt) === 1) ? 'off' : 'on';
				} else {
					$s = (preg_match('/^keepalive on/mi', $txt) === 1) ? 'on' : 'off';
				}
				$this->setDefaultValue('enable_keepalive', $s);

				break;
			case "mysql_convert":
				$this->mysql_convert = null;
				$this->mysql_charset = null;
				$vlist['mysql_convert'] = array('s', array('to-myisam', 'to-innodb', 'to-aria'));
				$vlist['mysql_charset'] = array('s', array('utf8'));

				break;
			case "fix_chownchmod":
				$this->fix_chownchmod = null;

				$vlist['fix_chownchmod'] = array('s', array('fix-ownership', 'fix-permissions', 'fix-ALL'));

				break;

			case "fix_chownchmod_user":
				$this->fix_chownchmod_user = null;

				$vlist['fix_chownchmod_user'] = array('s', array('fix-ownership', 'fix-permissions', 'fix-ALL'));

				break;
			case "php_type":
				$existing = isset($this->php_type) ? (string)$this->php_type : '';
				$this->php_type = null;
				$this->secondary_php = null;

				if ($web === 'nginx') {
					$vlist['php_type'] = array('M',
						'Nginx is the selected web server: sites always use PHP-FPM '
						. '(per-domain pools). Apache MPM / proxy_fcgi choices apply only when '
						. 'Switch Program Web is Apache.');
					$this->php_type = 'php-fpm';
				} else {
					$a = array('php-fpm_event', 'php-fpm_worker', 'proxy_fcgi_event', 'proxy_fcgi_worker');
					$vlist['php_type'] = array('s', $a);

					$d = '';
					$key = isset($this->nname) && $this->nname !== ''
						? (string)$this->nname
						: 'pserver-' . (isset($this->syncserver) && $this->syncserver !== ''
							? $this->syncserver : 'localhost');
					$pass = lfile_get_contents("__path_admin_pass");
					if (is_string($pass) && trim($pass) !== '') {
						try {
							$d = (string) db_get_value('serverweb', $key, 'php_type');
						} catch (Throwable $e) {
							$d = '';
						}
					}
					if ($d === '' && $existing !== '') {
						$d = $existing;
					}
					if ($d === '' || strpos($d, 'mod_php') !== false || strpos($d, 'suphp') !== false
						|| strpos($d, 'fcgid') !== false) {
						$d = 'proxy_fcgi_event';
						if (is_string($pass) && trim($pass) !== '') {
							try {
								db_set_default('serverweb', 'php_type', $d, "nname = '" . $key . "'");
							} catch (Throwable $e) {
							}
						}
					}
					$this->setDefaultValue('php_type', $d);
				}

				$vlist['secondary_php'] = array('f', array('on', 'off'));
				include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpver.php';
				if (file_exists(KloxoPhpver::flagDirectory() . '/enablemultiplephp.flg')) {
					$this->setDefaultValue('secondary_php', 'on');
				}

				break;
			case "php_branch":
				$this->php_branch = null;
				include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpver.php';
				$a = KloxoPhpver::phpUsedChoices();
				$vlist['php_branch'] = array('s', $a);
				$this->setDefaultValue('php_branch', KloxoPhpver::activeId());

				break;
			case "multiple_php_install":
				$this->multiple_php_install = null;
				include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpver.php';
				$avail = KloxoPhpver::asSelectList(KloxoPhpver::availableToInstall());
				$vlist['multiple_php_install'] = array('U', $avail);
				$note = KloxoPhpver::installNote();
				if ($note !== '' && KloxoPhpver::availableToInstall() === array()) {
					$vlist['multiple_php_install_note'] = array('M', $note);
				}

				break;

			case "multiple_php_remove":
				$this->multiple_php_remove = null;
				include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpver.php';
				$have = KloxoPhpver::asSelectList(KloxoPhpver::removableIds());
				$vlist['multiple_php_remove'] = array('U', $have);

				break;

			case "multiple_php_activate":
				include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpver.php';
				$h = implode(' ', KloxoPhpver::installedIds());
				if ($h === '') {
					$h = '(none yet)';
				}
				$vlist['multiple_php_already_installed'] = array('M', $h);
				$vlist['multiple_php_flag'] = array('f', array('on', 'off'));
				$this->multiple_php_flag = null;
				$s = file_exists(KloxoPhpver::flagDirectory() . '/enablemultiplephp.flg') ? 'on' : 'off';
				$this->setDefaultValue('multiple_php_flag', $s);

				break;

			case "php_used":
				$this->php_used = null;
				include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpver.php';
				$d = KloxoPhpver::phpUsedChoices();
				$j = KloxoPhpver::activeId();
				$this->setDefaultValue('php_used', $j);
				$vlist['php_used'] = array('s', $d);

				break;

			case "enable_php52m_fpm":

				$this->enable_php52m_fpm = null;

				$vlist['enable_php52m_fpm'] = array("f", array('on', 'off'));

				include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpver.php';
				if (file_exists(KloxoPhpver::flagDirectory() . '/enable_php52m-fpm.flg')) {
					$this->setDefaultValue('enable_php52m_fpm', 'on');
				}

				break;

			case "pagespeed_clear_cache":
				$this->pagespeed_cache = null;

				$vlist['pagespeed_cache'] = array('s', array('clear'));

				break;

			default:
				$vlist['__v_button'] = array();

				break;
		}

		return $vlist;
	}

	function update($subaction, $param)
	{
		global $login;

		if (!is_array($param)) {
			$param = array();
		}
		if ($subaction === 'multiple_php_install' || $subaction === 'multiple_php_remove') {
			include_once dirname(__DIR__, 2) . '/lib/php/kloxo_phpver.php';
			$ids = KloxoPhpver::parseRequestedIds($param[$subaction] ?? '');
			if ($ids === array()) {
				throw new lxException($login->getThrow('no_options_selected'), '', 'blank');
			}
			$param[$subaction] = implode(',', $ids);
		}

		return parent::update($subaction, $param);
	}

	static function initThisObjectRule($parent, $class, $name = null)
	{
		return $parent->getClName();
	}
}
