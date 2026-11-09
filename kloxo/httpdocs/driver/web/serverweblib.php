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

	function createShowUpdateform()
	{
		$parent = $this->getParentO();
		if (!is_object($parent) || $parent->getClass() !== 'pserver') {
			$uflist['fix_chownchmod_user'] = null;

			return $uflist;
		}

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

		switch($subaction) {
			case "apache_optimize":
				$this->apache_optimize = null;
				$txt = '';
				foreach (array(
					'/etc/apache2/conf-available/kloxo-optimize.conf',
					'/etc/apache2/conf-available/~kloxo.conf',
					'/etc/httpd/conf.d/~kloxo.conf',
				) as $conf) {
					if (is_file($conf)) {
						$txt = (string)@file_get_contents($conf);
						break;
					}
				}

				$a = array('default', 'low', 'medium', 'high');
				if (stripos($txt, 'customize') !== false) {
					$a[] = 'customize';
				}
				$vlist['apache_optimize'] = array('s', $a);

				$b = 'default';
				foreach ($a as $v) {
					if (stripos($txt, 'selected: ' . $v) !== false) {
						$b = $v;
						break;
					}
				}
				$this->setDefaultValue('apache_optimize', $b);

				$this->enable_keepalive = null;
				$vlist['enable_keepalive'] = null;
				$s = (preg_match('/^keepalive on/mi', $txt) === 1) ? 'on' : 'off';
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
						$d = (string)db_get_value("serverweb", $key, "php_type");
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
							db_set_default("serverweb", "php_type", $d, "nname = '" . $key . "'");
						} catch (Throwable $e) {
						}
					}
				}
				$this->setDefaultValue('php_type', $d);

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
