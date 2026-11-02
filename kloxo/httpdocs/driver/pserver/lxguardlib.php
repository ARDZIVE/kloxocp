<?php

class lxguard extends lxdb 
{
	static $__desc = array("", "",  "lxguard");
	static $__desc_nname = array("", "",  "lxguard");
	static $__desc_disablehit = array("", "",  "disable_when_this_many_wrong_attempts");
	static $__desc_configure_flag = array("f", "",  "i_have_read_about_lxguard_and_understands_what_it_does");
	static $__acdesc_update_update = array("", "",  "update");
	static $__acdesc_show = array("", "",  "Lxguard");
	static $__acdesc_update_whitelist = array("", "",  "whitelist");
	static $__acdesc_update_remove = array("", "",  "remove");
	static $__desc_lxguardwhitelist_l = array("d", "",  "");
	static $__desc_rawlxguardhit_l = array("d", "",  "");
	static $__desc_lxguardhitdisplay_l = array("d", "",  "");

	static function initThisObjectRule($parent, $class, $name = null)
	{
		return is_object($parent) && isset($parent->nname) ? $parent->nname : 'localhost';
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

	function createShowPropertyList(&$alist)
	{
		$alist['property'] = array(
			'a=show',
			'a=list&c=lxguardhitdisplay',
			'a=list&c=rawlxguardhit',
			'a=list&c=lxguardwhitelist',
		);

		return $alist;
	}

	function createExtraVariables()
	{
		$this->setDefaultValue("disablehit", "20");
	}

	static function collect_lxguard()
	{
		$sq = new Sqlite(null, "lxguardhit");
		$ddate = time();
		// $ddate -= 24 * 3600 * 30 * 3;
		// $ddate -= 60 * 60 * 24 * 30 * 3;
		// MR -- change to 1 month
		$ddate -= 60 * 60 * 24 * 30 * 1;
		$sq->rawQuery("delete from lxguardhit where (ddate + 0) < $ddate");
		$list = get_all_pserver();

		foreach($list as $l) {
			try {
				lxguardhitdisplay::getDataFromServer($l);
				self::save_current_hitlist($l);
			} catch (Throwable $e) {
			}
		}
	}

	function createShowUpdateform()
	{
		$uform['update'] = null;

		return $uform;
	}

	function updateWhiteList($param)
	{
		if (!is_array($param) || !isset($param['_accountselect']) || !is_array($param['_accountselect'])) {
			return $param;
		}

		$s = null;
		foreach($param['_accountselect'] as $p) {
			$nname = "{$p}___$this->syncserver";
			$s = new lxguardwhitelist(null, null, $nname);
			$s->ipaddress = $p;
			$s->syncserver = $this->syncserver;
			$s->parent_clname = $this->getClName();
			$s->dbaction = 'add';
			$s->write();
		}

		// You need to sync at least one so that the whitelist is properly fixed 
		// on the actual server.

		if (is_object($s)) {
			$s->dbaction = 'add';
			$s->was();
		}

		$this->updateRemove($param);
	}

	function updateRemove($param)
	{
		if (!is_array($param) || !isset($param['_accountselect']) || !is_array($param['_accountselect'])) {
			return $param;
		}

		$server = $this->syncserver;
		$sq = new Sqlite(null, "lxguardhit");

		foreach($param['_accountselect'] as $ip) {
			// MR -- fix because 'ipaddress=' not work and change to 'ipaddress like %%'
			$sq->rawQuery("delete from lxguardhit where syncserver='{$server}' and ipaddress like '%{$ip}%'");
		}

		self::save_current_hitlist($server);
	}

	static function save_current_hitlist($server)
	{
		$list = lxguardhitdisplay::createHitList($server);
		$hl = array();

		if (is_array($list)) {
			foreach($list as $r) {
				if (!is_array($r) || !isset($r['ipaddress'])) {
					continue;
				}
				$hl[$r['ipaddress']] = isset($r['failcount']) ? $r['failcount'] : 0;
			}
		}

		rl_exec_get(null, $server, "lxguard_save_hitlist", array($hl));
	}

	function updateform($subaction, $param)
	{
		$this->setDefaultValue("disablehit", "20");
		$vlist = array();
		$vlist['disablehit'] = null;
		$vlist['configure_flag'] = null;

		return $vlist;
	}
}
