<?php

include_once dirname(__DIR__, 2) . '/lib/php/kloxo_ufw.php';

class ufwfirewall extends lxclass
{
	static $__desc = array("", "", "UFW Firewall");
	static $__desc_nname = array("", "", "UFW Firewall");
	static $__acdesc_show = array("", "", "UFW Firewall");
	static $__acdesc_update_ufwenable = array("", "", "Enable");
	static $__acdesc_update_ufwdisable = array("", "", "Disable");
	static $__acdesc_update_addrule = array("", "", "Add Rule");
	static $__acdesc_update_deleterule = array("", "", "Delete Rule");
	static $__desc_ufw_action = array("s", "", "action");
	static $__desc_ufw_proto = array("s", "", "protocol");
	static $__desc_ufw_port = array("", "", "port");
	static $__desc_ufw_from = array("", "", "from_address");
	static $__desc_ufw_rule = array("s", "", "rule_number");
	static $__desc_ufw_confirm = array("M", "", "UFW Firewall");

	function get() {}
	function write() {}

	function isSync()
	{
		return false;
	}

	static function initThisObjectRule($parent, $class, $name = null)
	{
		return is_object($parent) && isset($parent->nname) && is_string($parent->nname) && $parent->nname !== ''
			? $parent->nname : 'localhost';
	}

	static function ufwPropertyTabs()
	{
		return array(
			'a=show',
			'a=updateform&sa=ufwenable',
			'a=updateform&sa=ufwdisable',
			'a=updateform&sa=addrule',
			'a=updateform&sa=deleterule',
		);
	}

	static function ufwPropertyTabsForPserver()
	{
		return array(
			'a=show&o=ufwfirewall',
			'a=updateform&sa=ufwenable&o=ufwfirewall',
			'a=updateform&sa=ufwdisable&o=ufwfirewall',
			'a=updateform&sa=addrule&o=ufwfirewall',
			'a=updateform&sa=deleterule&o=ufwfirewall',
		);
	}

	function createShowPropertyList(&$alist)
	{
		$alist['property'] = self::ufwPropertyTabs();

		return $alist;
	}

	function createShowUpdateform()
	{
		return array();
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

	function showRawPrint($subaction = null)
	{
		try {
			$this->assertAdmin();
		} catch (Throwable $e) {
			echo '<div class="kloxo-ufw" style="padding:12px 16px 24px;text-align:left">'
				. 'Only admin can access UFW Firewall.</div>';

			return;
		}

		try {
			$st = $this->ufwStatus();
		} catch (Throwable $e) {
			echo '<div class="kloxo-ufw" style="padding:12px 16px 24px;text-align:left">'
				. 'Could not read UFW status: '
				. htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</div>';

			return;
		}

		$installed = !empty($st['installed']);
		$active = !empty($st['active']);
		$state = !$installed ? 'not installed' : ($active ? 'active' : 'inactive');
		$defIn = isset($st['default_in']) && is_string($st['default_in']) && $st['default_in'] !== ''
			? $st['default_in'] : '—';
		$defOut = isset($st['default_out']) && is_string($st['default_out']) && $st['default_out'] !== ''
			? $st['default_out'] : '—';
		$rules = (isset($st['rules']) && is_array($st['rules'])) ? $st['rules'] : array();
		$raw = isset($st['raw']) ? (string) $st['raw'] : '';
		$err = isset($st['error']) ? (string) $st['error'] : '';
		$eState = htmlspecialchars($state, ENT_QUOTES);
		$eIn = htmlspecialchars($defIn, ENT_QUOTES);
		$eOut = htmlspecialchars($defOut, ENT_QUOTES);
		$eErr = htmlspecialchars($err, ENT_QUOTES);
		$eRaw = htmlspecialchars($raw, ENT_QUOTES);

		echo '<div class="kloxo-ufw" style="padding:12px 16px 24px;text-align:left">';
		echo '<p style="margin:0 0 8px"><b>UFW Firewall</b> is <code>' . $eState . '</code>.';
		echo ' Default incoming: <code>' . $eIn . '</code>. Default outgoing: <code>' . $eOut . '</code>.</p>';
		if ($err !== '' && $err !== 'ufw_not_installed') {
			echo '<p style="margin:0 0 8px">Last error: <code>' . $eErr . '</code></p>';
		}
		if (!$installed) {
			echo '<p style="margin:0 0 8px">Install the <code>ufw</code> package on this Ubuntu host, then reopen this page. Enabling UFW first allows panel ports 7777 and 7778.</p>';
		}
		echo '<table cellspacing="0" cellpadding="4" style="border-collapse:collapse;min-width:60%">';
		echo '<tr><th align="left">#</th><th align="left">To</th><th align="left">Action</th><th align="left">From</th></tr>';
		if ($rules === array()) {
			echo '<tr><td colspan="4">No numbered rules.</td></tr>';
		} else {
			foreach ($rules as $r) {
				if (!is_array($r)) {
					continue;
				}
				$n = isset($r['number']) ? (string) $r['number'] : '';
				$to = htmlspecialchars(isset($r['to']) ? (string) $r['to'] : '', ENT_QUOTES);
				$act = htmlspecialchars(isset($r['action']) ? (string) $r['action'] : '', ENT_QUOTES);
				$from = htmlspecialchars(isset($r['from']) ? (string) $r['from'] : '', ENT_QUOTES);
				echo '<tr><td>' . htmlspecialchars($n, ENT_QUOTES) . '</td><td>' . $to
					. '</td><td>' . $act . '</td><td>' . $from . '</td></tr>';
			}
		}
		echo '</table>';
		if ($raw !== '') {
			echo '<pre style="margin:12px 0 0;white-space:pre-wrap;font-size:12px;line-height:1.35">'
				. $eRaw . '</pre>';
		}
		echo '</div>';
	}

	function updateform($subaction, $param)
	{
		try {
			$this->assertAdmin();
		} catch (Throwable $e) {
		}

		$vlist = array();
		switch ($subaction) {
			case 'ufwenable':
				$vlist['ufw_confirm'] = array('M',
					'Enable UFW. Panel ports 7777 and 7778 plus SSH, HTTP/S, FTP, DNS and mail are allowed first.');
				$vlist['__v_button'] = 'Enable';
				return $vlist;
			case 'ufwdisable':
				$vlist['ufw_confirm'] = array('M',
					'Disable UFW on this server. Existing rules stay on disk and apply again when you re-enable.');
				$vlist['__v_button'] = 'Disable';
				return $vlist;
			case 'addrule':
				$vlist['ufw_action'] = array('s', array('allow' => 'allow', 'deny' => 'deny'));
				$vlist['ufw_proto'] = array('s', array('tcp' => 'tcp', 'udp' => 'udp'));
				$vlist['ufw_port'] = null;
				$vlist['ufw_from'] = null;
				$vlist['__v_button'] = 'Add Rule';
				return $vlist;
			case 'deleterule':
				$choices = array();
				try {
					$choices = $this->ruleChoices();
				} catch (Throwable $e) {
					$choices = array();
				}
				$vlist['ufw_rule'] = $choices === array()
					? array('M', 'No numbered rules.')
					: array('s', $choices);
				$vlist['__v_button'] = $choices === array() ? array() : 'Delete Rule';
				return $vlist;
		}

		return $vlist;
	}

	function redirectToShow()
	{
		global $gbl, $ghtml;

		if (is_object($ghtml) && method_exists($ghtml, 'getFullUrl')) {
			$gbl->__this_redirect = $ghtml->getFullUrl('a=show') . '&frm_smessage=updated_successfully';
		}
	}

	function updateUfwenable($param)
	{
		$this->assertAdmin();
		$ret = $this->execUfw('enable', array());
		$this->throwIfFailed($ret, 'could_not_enable_ufw');
		$this->redirectToShow();

		return null;
	}

	function updateUfwdisable($param)
	{
		$this->assertAdmin();
		$ret = $this->execUfw('disable', array());
		$this->throwIfFailed($ret, 'could_not_disable_ufw');
		$this->redirectToShow();

		return null;
	}

	function updateAddrule($param)
	{
		$this->assertAdmin();
		$p = is_array($param) ? $param : array();
		$action = isset($p['ufw_action']) ? (string) $p['ufw_action'] : '';
		$proto = isset($p['ufw_proto']) ? (string) $p['ufw_proto'] : 'tcp';
		$port = isset($p['ufw_port']) ? (string) $p['ufw_port'] : '';
		$from = isset($p['ufw_from']) ? (string) $p['ufw_from'] : '';
		try {
			$ret = $this->execUfw('addRule', array($action, $proto, $port, $from));
		} catch (InvalidArgumentException $e) {
			$this->throwUfw($e->getMessage(), $port);
		}
		$this->throwIfFailed($ret, 'could_not_add_ufw_rule');
		$this->redirectToShow();

		return null;
	}

	function updateDeleterule($param)
	{
		$this->assertAdmin();
		$p = is_array($param) ? $param : array();
		$number = isset($p['ufw_rule']) ? (string) $p['ufw_rule'] : '';
		try {
			$ret = $this->execUfw('deleteRule', array($number));
		} catch (InvalidArgumentException $e) {
			$this->throwUfw($e->getMessage(), $number);
		}
		$this->throwIfFailed($ret, 'could_not_delete_ufw_rule');
		$this->redirectToShow();

		return null;
	}

	function assertAdmin()
	{
		global $login;

		if (is_object($login) && method_exists($login, 'isAdmin') && $login->isAdmin()) {
			return;
		}
		$msg = (is_object($login) && method_exists($login, 'getThrow'))
			? $login->getThrow('only_admin_can_access')
			: 'only_admin_can_access';
		throw new lxException($msg);
	}

	function syncServerName()
	{
		if (isset($this->syncserver) && is_string($this->syncserver) && $this->syncserver !== '') {
			return $this->syncserver;
		}
		$parent = null;
		try {
			$parent = $this->getParentO();
		} catch (Throwable $e) {
			$parent = null;
		}
		if (is_object($parent) && isset($parent->nname) && is_string($parent->nname) && $parent->nname !== '') {
			return $parent->nname;
		}

		return (isset($this->nname) && is_string($this->nname) && $this->nname !== '')
			? $this->nname : 'localhost';
	}

	function ufwStatus()
	{
		$ret = $this->execUfw('status', array());
		if (is_array($ret)) {
			return $ret;
		}

		return array(
			'active' => false,
			'installed' => false,
			'default_in' => '',
			'default_out' => '',
			'raw' => '',
			'rules' => array(),
			'error' => 'ufw_status_failed',
		);
	}

	function ruleChoices()
	{
		$st = $this->ufwStatus();
		$out = array();
		if (!isset($st['rules']) || !is_array($st['rules'])) {
			return $out;
		}
		foreach ($st['rules'] as $r) {
			if (!is_array($r) || !isset($r['number'])) {
				continue;
			}
			$n = (string) $r['number'];
			$to = isset($r['to']) ? (string) $r['to'] : '';
			$act = isset($r['action']) ? (string) $r['action'] : '';
			$from = isset($r['from']) ? (string) $r['from'] : '';
			$out[$n] = trim($n . ' ' . $to . ' ' . $act . ' ' . $from);
		}

		return $out;
	}

	function execUfw($method, $args)
	{
		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_ufw.php';
		$args = is_array($args) ? $args : array();
		$euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : 0;
		$panel = function_exists('php_sapi_name') ? php_sapi_name() : 'cli';
		$useBackend = $euid !== 0 && $panel !== 'cli' && function_exists('rl_exec_get');
		if ($useBackend) {
			try {
				$got = rl_exec_get(null, $this->syncServerName(), array('KloxoUfw', $method), $args);
				if (is_array($got)) {
					return $got;
				}
			} catch (Throwable $e) {
			}
		}

		return call_user_func_array(array('KloxoUfw', $method), $args);
	}

	function throwIfFailed($ret, $fallback)
	{
		if (is_array($ret) && !empty($ret['ok'])) {
			return;
		}
		$err = is_array($ret) && isset($ret['error']) ? (string) $ret['error'] : $fallback;
		$this->throwUfw($err !== '' ? $err : $fallback);
	}

	function throwUfw($key, $val = '')
	{
		global $login;

		$msg = (is_object($login) && method_exists($login, 'getThrow'))
			? $login->getThrow($key) : $key;
		throw new lxException($msg, '', $val);
	}
}
