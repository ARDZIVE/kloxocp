<?php

include_once dirname(__DIR__, 2) . '/lib/php/kloxo_ufw.php';

/**
 * Single-tab UFW page: enable/disable, default policies, numbered
 * port rules (add/delete). Privileged ufw runs via kloxo-backend.
 */
class ufwfirewall extends lxclass
{
	static $__desc = array("", "", "UFW Firewall");
	static $__desc_nname = array("", "", "UFW Firewall");
	static $__acdesc_show = array("", "", "UFW Firewall");
	static $__acdesc_update_ufwenable = array("", "", "Enable");
	static $__acdesc_update_ufwdisable = array("", "", "Disable");
	static $__acdesc_update_ufwdefaults = array("", "", "Default Policies");
	static $__acdesc_update_addrule = array("", "", "Add Rule");
	static $__acdesc_update_deleterule = array("", "", "Delete Rule");
	static $__desc_ufw_action = array("s", "", "action");
	static $__desc_ufw_proto = array("s", "", "protocol");
	static $__desc_ufw_port = array("", "", "port");
	static $__desc_ufw_from = array("", "", "from_address");
	static $__desc_ufw_rule = array("s", "", "rule_number");
	static $__desc_ufw_default_in = array("s", "", "default_incoming");
	static $__desc_ufw_default_out = array("s", "", "default_outgoing");
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
		return array('a=show');
	}

	static function ufwPropertyTabsForPserver()
	{
		return array('a=show&o=ufwfirewall');
	}

	function createShowPropertyList(&$alist)
	{
		$alist['property'] = self::ufwPropertyTabs();

		return $alist;
	}

	/** All controls render in showRawPrint (single page). */
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
		$defIn = (isset($st['default_in']) && is_string($st['default_in']) && $st['default_in'] !== '')
			? $st['default_in'] : 'deny';
		$defOut = (isset($st['default_out']) && is_string($st['default_out']) && $st['default_out'] !== '')
			? $st['default_out'] : 'allow';
		$rules = (isset($st['rules']) && is_array($st['rules'])) ? $st['rules'] : array();
		$err = isset($st['error']) ? (string) $st['error'] : '';
		$policies = array('allow', 'deny', 'reject');
		if (!in_array($defIn, $policies, true)) {
			$defIn = 'deny';
		}
		if (!in_array($defOut, $policies, true)) {
			$defOut = 'allow';
		}
		$nRules = count($rules);
		$statusText = !$installed ? 'not installed' : ($active ? 'active' : 'inactive');

		echo '<div class="kloxo-ufw" style="padding:12px 16px 28px;text-align:left">';
		echo '<table cellpadding="6" cellspacing="0" width="100%" style="border-collapse:collapse;margin:0 0 14px">';
		echo '<tr style="background:#f0f0f0"><td colspan="2"><b>Firewall status:</b> '
			. htmlspecialchars($statusText, ENT_QUOTES) . '</td><td align="right">';
		if ($installed) {
			if (!$active) {
				$this->printActionForm('ufwenable', 'Enable',
					'Enable UFW? Panel ports 7777/7778 and common service ports are allowed first.');
			} else {
				$this->printActionForm('ufwdisable', 'Disable',
					'Disable UFW? Existing port rules stay on disk.');
			}
		}
		echo '</td></tr></table>';

		if ($err !== '' && $err !== 'ufw_not_installed') {
			echo '<p style="margin:0 0 12px;color:#c00">Error: '
				. htmlspecialchars($err, ENT_QUOTES) . '</p>';
		}
		if (!$installed) {
			echo '<p>Install the <code>ufw</code> package on this Ubuntu host, then reopen this page.</p>';
			echo '</div>';

			return;
		}

		echo '<table cellpadding="6" cellspacing="0" width="100%" style="border-collapse:collapse;margin:0 0 18px;'
			. 'border:1px solid #ccc">';
		echo '<tr style="background:#e8e8e8"><td colspan="3"><b>Default Policies</b></td></tr>';
		echo '<tr><td colspan="3">';
		$this->printFormOpen('ufwdefaults');
		echo 'Incoming: <select name="frm_ufwfirewall_c_ufw_default_in">';
		foreach ($policies as $p) {
			$sel = ($p === $defIn) ? ' selected' : '';
			echo '<option value="' . $p . '"' . $sel . '>' . $p . '</option>';
		}
		echo '</select> &nbsp; Outgoing: <select name="frm_ufwfirewall_c_ufw_default_out">';
		foreach ($policies as $p) {
			$sel = ($p === $defOut) ? ' selected' : '';
			echo '<option value="' . $p . '"' . $sel . '>' . $p . '</option>';
		}
		echo '</select> &nbsp; <input type="submit" value="Save Defaults" class="submitbutton">';
		echo '</form></td></tr></table>';

		echo '<table cellpadding="6" cellspacing="0" width="100%" style="border-collapse:collapse;margin:0 0 18px;'
			. 'border:1px solid #ccc">';
		echo '<tr style="background:#e8e8e8"><td colspan="6"><b>Port Rules</b>'
			. ' <span style="font-weight:normal;color:#666">(' . (int) $nRules . ')</span></td></tr>';
		echo '<tr style="background:#f5f5f5">'
			. '<th align="left">#</th><th align="left">Action</th><th align="left">Protocol</th>'
			. '<th align="left">Port</th><th align="left">From</th><th align="center">Delete</th></tr>';
		if ($rules === array()) {
			echo '<tr><td colspan="6" style="color:#666">No port rules yet. Add one below '
				. '(rules apply when the firewall is active).</td></tr>';
		} else {
			$i = 0;
			foreach ($rules as $r) {
				if (!is_array($r) || !isset($r['number'])) {
					continue;
				}
				$i++;
				$bg = ($i % 2 === 0) ? '#fafafa' : '#fff';
				$n = (string) $r['number'];
				$to = isset($r['to']) ? (string) $r['to'] : '';
				$actRaw = isset($r['action']) ? (string) $r['action'] : '';
				$from = isset($r['from']) ? (string) $r['from'] : '';
				$parsed = KloxoUfw::parseRuleTo($to);
				$port = $parsed['port'] !== '' ? $parsed['port'] : $to;
				$proto = $parsed['proto'] !== '' ? strtoupper($parsed['proto']) : 'any';
				$actUp = strtoupper($actRaw);
				$actLabel = 'ALLOW';
				if (str_contains($actUp, 'REJECT')) {
					$actLabel = 'REJECT';
				} elseif (str_contains($actUp, 'DENY')) {
					$actLabel = 'DENY';
				} elseif (str_contains($actUp, 'LIMIT')) {
					$actLabel = 'LIMIT';
				} elseif (!str_contains($actUp, 'ALLOW')) {
					$actLabel = $actUp !== '' ? $actUp : '—';
				}
				$canDel = true;
				try {
					$canDel = KloxoUfw::canDeleteRule($rules, (int) $n);
				} catch (Throwable $e) {
					$canDel = true;
				}
				echo '<tr style="background:' . $bg . '">';
				echo '<td>' . htmlspecialchars($n, ENT_QUOTES) . '</td>';
				echo '<td>' . htmlspecialchars($actLabel, ENT_QUOTES) . '</td>';
				echo '<td>' . htmlspecialchars($proto, ENT_QUOTES) . '</td>';
				echo '<td>' . htmlspecialchars($port, ENT_QUOTES) . '</td>';
				echo '<td>' . htmlspecialchars($from !== '' ? $from : 'Anywhere', ENT_QUOTES) . '</td>';
				echo '<td align="center">';
				if ($canDel) {
					$this->printFormOpen('deleterule');
					echo '<input type="hidden" name="frm_ufwfirewall_c_ufw_rule" value="'
						. htmlspecialchars($n, ENT_QUOTES) . '">';
					echo '<input type="submit" value="Delete" class="submitbutton"'
						. ' onclick="return confirm(' . htmlspecialchars(
							json_encode('Delete rule #' . $n . '?'),
							ENT_QUOTES
						) . ')">';
					echo '</form>';
				} else {
					echo '<span title="Last allow for panel port 7777/7778">Protected</span>';
				}
				echo '</td></tr>';
			}
		}
		echo '</table>';

		echo '<table cellpadding="6" cellspacing="0" width="100%" style="border-collapse:collapse;'
			. 'border:1px solid #ccc">';
		echo '<tr style="background:#e8e8e8"><td colspan="2"><b>Add Port Rule</b></td></tr>';
		echo '<tr><td colspan="2">';
		$this->printFormOpen('addrule');
		echo '<table cellpadding="4" cellspacing="0"><tr>';
		echo '<td>Action<br><select name="frm_ufwfirewall_c_ufw_action">'
			. '<option value="allow">ALLOW</option>'
			. '<option value="deny">DENY</option>'
			. '<option value="reject">REJECT</option></select></td>';
		echo '<td>Protocol<br><select name="frm_ufwfirewall_c_ufw_proto">'
			. '<option value="tcp">TCP</option>'
			. '<option value="udp">UDP</option></select></td>';
		echo '<td>Port<br><input type="text" name="frm_ufwfirewall_c_ufw_port" size="18"'
			. ' placeholder="80 or 80-82 or 80,443" required></td>';
		echo '<td>From IP<br><input type="text" name="frm_ufwfirewall_c_ufw_from" size="18"'
			. ' placeholder="blank = anywhere"></td>';
		echo '<td style="vertical-align:bottom"><input type="submit" value="Add" class="submitbutton"></td>';
		echo '</tr></table>';
		echo '<div style="margin-top:8px;color:#666;font-size:12px">'
			. 'Port: single (<code>443</code>), range (<code>80-82</code>), or list (<code>80,443</code>). '
			. 'Blank From = anywhere. Deny/reject of panel ports <b>7777</b>/<b>7778</b> is refused. '
			. 'Enable first allows panel, SSH, HTTP/S, FTP, DNS, mail and webcache/webproxy ports.'
			. '</div>';
		echo '</form></td></tr></table>';

		echo '</div>';
	}

	function printActionForm($subaction, $label, $confirm, $color = null)
	{
		$this->printFormOpen($subaction);
		echo '<input type="hidden" name="frm_ufwfirewall_c_ufw_confirm" value="on">';
		$style = '';
		if (is_string($color) && $color !== '') {
			$c = htmlspecialchars($color, ENT_QUOTES);
			$style = ' style="background:' . $c . ';color:#fff;border-color:' . $c . '"';
		}
		echo '<input type="submit" value="' . htmlspecialchars($label, ENT_QUOTES) . '" class="submitbutton"'
			. $style
			. ' onclick="return confirm(' . htmlspecialchars(json_encode($confirm), ENT_QUOTES) . ')">';
		echo '</form>';
	}

	function printFormOpen($subaction)
	{
		global $ghtml;

		$token = function_exists('getCSRFToken') ? getCSRFToken() : '';
		echo '<form method="post" action="/display.php" accept-charset="utf-8" style="display:inline;margin:0">';
		echo '<input type="hidden" name="frm_token" value="' . htmlspecialchars((string) $token, ENT_QUOTES) . '">';
		echo '<input type="hidden" name="frm_action" value="update">';
		echo '<input type="hidden" name="frm_subaction" value="'
			. htmlspecialchars((string) $subaction, ENT_QUOTES) . '">';
		if (is_object($ghtml) && isset($ghtml->__http_vars['frm_o_o'])
			&& is_array($ghtml->__http_vars['frm_o_o'])
			&& method_exists($ghtml, 'print_input_vars')) {
			$ghtml->print_input_vars(array('frm_o_o' => $ghtml->__http_vars['frm_o_o']));
		} else {
			$host = $this->syncServerName();
			echo '<input type="hidden" name="frm_o_o[0][class]" value="pserver">';
			echo '<input type="hidden" name="frm_o_o[0][nname]" value="'
				. htmlspecialchars($host, ENT_QUOTES) . '">';
			echo '<input type="hidden" name="frm_o_o[1][class]" value="ufwfirewall">';
		}
	}

	function updateform($subaction, $param)
	{
		// Kept for direct updateform URLs / tests; primary UI is showRawPrint.
		try {
			$this->assertAdmin();
		} catch (Throwable $e) {
		}

		$policies = array('allow' => 'allow', 'deny' => 'deny', 'reject' => 'reject');
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
			case 'ufwdefaults':
				$st = array();
				try {
					$st = $this->ufwStatus();
				} catch (Throwable $e) {
					$st = array();
				}
				$in = (isset($st['default_in']) && is_string($st['default_in']) && $st['default_in'] !== '')
					? $st['default_in'] : 'deny';
				$out = (isset($st['default_out']) && is_string($st['default_out']) && $st['default_out'] !== '')
					? $st['default_out'] : 'allow';
				if (!isset($policies[$in])) {
					$in = 'deny';
				}
				if (!isset($policies[$out])) {
					$out = 'allow';
				}
				$this->ufw_default_in = $in;
				$this->ufw_default_out = $out;
				$vlist['ufw_default_in'] = array('s', $policies);
				$vlist['ufw_default_out'] = array('s', $policies);
				$vlist['__v_button'] = 'Update Defaults';
				return $vlist;
			case 'addrule':
				$vlist['ufw_action'] = array('s', array(
					'allow' => 'ALLOW', 'deny' => 'DENY', 'reject' => 'REJECT',
				));
				$vlist['ufw_proto'] = array('s', array('tcp' => 'TCP', 'udp' => 'UDP'));
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
					? array('M', 'No rules to delete.')
					: array('s', $choices);
				$vlist['__v_button'] = $choices === array() ? array() : 'Delete Rule';
				return $vlist;
		}

		return $vlist;
	}

	function redirectToShow()
	{
		global $gbl, $ghtml;

		if (!is_object($gbl)) {
			return;
		}

		$tail = '&frm_smessage=updated_successfully';
		if (is_object($ghtml) && method_exists($ghtml, 'getFullUrl')) {
			try {
				$url = $ghtml->getFullUrl('a=show');
				if (is_string($url) && $url !== '') {
					$gbl->__this_redirect = $url . $tail;

					return;
				}
			} catch (Throwable $e) {
			}
		}

		$host = $this->syncServerName();
		$gbl->__this_redirect = '/display.php?frm_action=show'
			. '&frm_o_o[0][class]=pserver'
			. '&frm_o_o[0][nname]=' . rawurlencode($host)
			. '&frm_o_o[1][class]=ufwfirewall'
			. $tail;
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

	function updateUfwdefaults($param)
	{
		$this->assertAdmin();
		$p = is_array($param) ? $param : array();
		$incoming = isset($p['ufw_default_in']) ? (string) $p['ufw_default_in'] : '';
		$outgoing = isset($p['ufw_default_out']) ? (string) $p['ufw_default_out'] : '';
		try {
			$ret = $this->execUfw('setDefaults', array($incoming, $outgoing));
		} catch (InvalidArgumentException $e) {
			$this->throwUfw($e->getMessage());
		}
		$this->throwIfFailed($ret, 'could_not_set_ufw_defaults');
		$this->redirectToShow();

		return null;
	}

	function updateAddrule($param)
	{
		$this->assertAdmin();
		$p = is_array($param) ? $param : array();
		$action = isset($p['ufw_action']) ? (string) $p['ufw_action'] : '';
		$proto = isset($p['ufw_proto']) ? (string) $p['ufw_proto'] : 'tcp';
		$portField = isset($p['ufw_port']) ? (string) $p['ufw_port'] : '';
		$from = isset($p['ufw_from']) ? (string) $p['ufw_from'] : '';
		try {
			$ports = KloxoUfw::splitPorts($portField);
			$last = null;
			foreach ($ports as $port) {
				$last = $this->execUfw('addRule', array($action, $proto, $port, $from));
				$this->throwIfFailed($last, 'could_not_add_ufw_rule');
			}
		} catch (InvalidArgumentException $e) {
			$this->throwUfw($e->getMessage(), $portField);
		}
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
		// Panel FPM runs as lxlabs; ufw needs root via kloxo-backend.
		$useBackend = $euid !== 0 && $panel !== 'cli' && function_exists('rl_exec_get');
		if ($useBackend) {
			try {
				$got = rl_exec_get(null, $this->syncServerName(), array('KloxoUfw', $method), $args);
				if (is_array($got)) {
					return $got;
				}
			} catch (Throwable $e) {
				return array(
					'ok' => false,
					'out' => '',
					'error' => 'ufw_backend_failed',
					'active' => false,
					'installed' => false,
					'default_in' => '',
					'default_out' => '',
					'raw' => '',
					'rules' => array(),
				);
			}

			return array(
				'ok' => false,
				'out' => '',
				'error' => 'ufw_backend_failed',
				'active' => false,
				'installed' => false,
				'default_in' => '',
				'default_out' => '',
				'raw' => '',
				'rules' => array(),
			);
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
