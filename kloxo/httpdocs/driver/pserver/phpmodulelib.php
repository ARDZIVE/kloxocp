<?php

class PhpModule extends lxclass
{
	// Data

	static $__desc = array("", "",  "phpmodule");

	static $__desc_nname = array("", "",  "phpmodule_name");
	static $__desc_type = array("", "",  "phpmodule_type");
	static $__desc_target = array("", "",  "phpmodule_target");
	static $__desc_modulename = array("", "",  "phpmodule_name");
	static $__desc_fullfile = array("", "",  "phpmodule_fullfile");

	static $__desc_status = array("eS", "",  "s:status");
	static $__desc_status_v_on = array("eS", "",  "is_installed");
	static $__desc_status_v_off = array("eS", "",  "is_not_installed");

//	static $__rewrite_nname_const = array("modulename", "syncserver");

	static $__desc_button_enable_f = array("b", "", "", "a=update&sa=enable");
	static $__desc_button_disable_f = array("b", "", "", "a=update&sa=disable");
	static $__desc_button_restart_f = array("b", "", "", "a=update&sa=restart");

	static $__acdesc_update_enable = array("", "", "enable");
	static $__acdesc_update_disable = array("", "", "disable");
	static $__acdesc_update_restart = array("", "", "restart");

	static $__acdesc_list = array("", "",  "phpmodule_status");

//	static $__acdesc_update_restart = array("", "",  "phpmodule_restart");

	function get() { }

	function write() { }

	function isSelect()	{ return false; }

	static function createListAlist($parent, $class)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$alist[] = "a=list&c=$class";

		return $alist;
	}

	static function createListBlist($parent, $class)
	{
		return null;
	}

	static function createListNlist($parent, $view)
	{
		$nlist['status'] = '3%';
	//	$nlist['nname'] = '25%';
		$nlist['modulename'] = '15%';
		$nlist['target'] = '10%';
		$nlist['type'] = '10%';
		$nlist['fullfile'] = '100%';

		$nlist["button_enable_f"] = '5%';
		$nlist["button_disable_f"] = '5%';
		$nlist["button_restart_f"] = '5%';

		return $nlist;
	}

	static function perPage()
	{
		return 80;
	}

	function createShowUpdateform()
	{
		$uform['update'] = null;

		return $uform;
	}

	static function searchVar()
	{
		return "nname";
	}

	static function initThisObject($parent, $class, $name = null)
	{
		global $gbl, $sgbl, $login, $ghtml;

		$o = new PhpModule($parent->__masterserver, $parent->__readserver, $name);

		return $o;
	}

	static function canGetSingle() { return false; }

	static function initThisListRule($parent, $class) { return null; }

	static function initThisList($parent, $class)
	{
		global $gbl, $login;

		$sync = (is_object($parent) && isset($parent->syncserver) && is_string($parent->syncserver)
			&& $parent->syncserver !== '')
			? $parent->syncserver : 'localhost';
		$master = (is_object($parent) && isset($parent->__masterserver)) ? $parent->__masterserver : null;
		$read = (is_object($parent) && isset($parent->__readserver) && is_string($parent->__readserver)
			&& $parent->__readserver !== '')
			? $parent->__readserver : $sync;

		$driverapp = null;
		if (is_object($gbl) && method_exists($gbl, 'getSyncClass')) {
			try {
				$driverapp = $gbl->getSyncClass($master, $read, 'phpmodule');
			} catch (Throwable $e) {
				$driverapp = null;
			}
		}
		if (!is_string($driverapp) || $driverapp === '') {
			$driverapp = 'linux';
		}
		$dclass = function_exists('kloxo_driver_class')
			? kloxo_driver_class('phpmodule', $driverapp)
			: null;
		if (!is_string($dclass) || $dclass === '') {
			$dclass = 'PhpModule__linux';
		}

		$res = null;
		try {
			if (function_exists('rl_exec_get')) {
				// List is read-only; allow in-process fallback when backend is down
				// so the page is not blank (kloxo_rl_driver used to return []).
				$res = rl_exec_get($master, $read, array($dclass, 'getListDetail'), array($sync), true);
			}
		} catch (Throwable $e) {
			log_log('error', 'phpmodule getListDetail: ' . $e->getMessage());
			$res = null;
		}

		if ((!is_array($res) || $res === array())
			&& function_exists('isLocalhost') && isLocalhost($sync)
			&& class_exists('PhpModule__linux', false)) {
			try {
				$res = PhpModule__linux::getListDetail($sync);
			} catch (Throwable $e) {
				log_log('error', 'phpmodule local getListDetail: ' . $e->getMessage());
				$res = array();
			}
		}

		return is_array($res) ? $res : array();
	}

	function updateform($subaction, $param)
	{
		return array('non_f' => array('h', null));
	}

	function update($subaction, $param)
	{
		global $gbl, $login;

		$sub = is_string($subaction) ? strtolower($subaction) : '';
		if (!in_array($sub, array('enable', 'disable', 'restart'), true)) {
			return $param;
		}

		$fullfile = isset($this->fullfile) ? (string) $this->fullfile : '';
		$status = isset($this->status) ? (string) $this->status : '';
		$sync = (isset($this->syncserver) && is_string($this->syncserver) && $this->syncserver !== '')
			? $this->syncserver : 'localhost';
		$master = isset($this->__masterserver) ? $this->__masterserver : null;

		$driverapp = 'linux';
		if (is_object($gbl) && method_exists($gbl, 'getSyncClass')) {
			try {
				$got = $gbl->getSyncClass($master, $sync, 'phpmodule');
				if (is_string($got) && $got !== '') {
					$driverapp = $got;
				}
			} catch (Throwable $e) {
				// keep linux
			}
		}
		$dclass = function_exists('kloxo_driver_class')
			? kloxo_driver_class('phpmodule', $driverapp)
			: 'PhpModule__linux';
		if (!is_string($dclass) || $dclass === '') {
			$dclass = 'PhpModule__linux';
		}

		try {
			if (!function_exists('rl_exec_get')) {
				throw new lxException(
					is_object($login) && method_exists($login, 'getThrow')
						? $login->getThrow('could_not_run_command')
						: 'could_not_run_command'
				);
			}
			// phpenmod / restart need root — no panel-user fallback.
			$res = rl_exec_get($master, $sync, array($dclass, 'applyAction'),
				array($sub, $fullfile, $status), false);
		} catch (Throwable $e) {
			$msg = trim((string) $e->getMessage());
			if ($msg === '') {
				$msg = 'Could not change PHP module (kloxo-backend did not answer).';
			}
			throw new lxException($msg);
		}

		if (is_array($res) && isset($res['error']) && is_string($res['error']) && $res['error'] !== '') {
			throw new lxException($res['error']);
		}

		return $param;
	}

	static function toggleDistroMod($fullfile, $enable)
	{
		if (!is_string($fullfile) || $fullfile === '') {
			return false;
		}
		if (preg_match('#^/etc/php/(\d+\.\d+)/mods-available/([A-Za-z0-9._-]+)\.ini$#', $fullfile, $m) !== 1) {
			return false;
		}
		$bin = $enable ? 'phpenmod' : 'phpdismod';
		$path = '/usr/sbin/' . $bin;
		if (!is_executable($path)) {
			$path = $bin;
		}
		$p = @proc_open(array($path, '-v', $m[1], '-s', 'ALL', $m[2]),
			array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		if (!is_resource($p)) {
			return false;
		}
		$err = (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($p);
		if ($code !== 0) {
			log_log('error', "phpmodule {$bin} {$m[2]}: " . trim($err));

			return false;
		}

		return true;
	}

}