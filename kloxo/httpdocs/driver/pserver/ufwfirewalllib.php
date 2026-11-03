<?php

include_once dirname(__DIR__, 2) . '/lib/php/kloxo_ufw.php';

/**
 * Single-tab UFW page (Hestia-inspired): status toolbar, defaults,
 * rules table with per-row delete, inline Add Rule form.
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

	/** All controls render in showRawPrint (Hestia-style single page). */
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

		$badgeBg = !$installed ? '#999' : ($active ? '#2e7d32' : '#c62828');
		$badgeLabel = !$installed ? 'NOT INSTALLED' : ($active ? 'ACTIVE' : 'INACTIVE');
		$nRules = count($rules);

		echo '<div class="kloxo-ufw" style="padding:12px 16px 28px;text-align:left;max-width:960px">';

		// Toolbar (Hestia-like)
		echo '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:0 0 14px;'
			. 'padding:10px 12px;background:#f5f5f5;border:1px solid #ddd;border-radius:3px">';
		echo '<span style="display:inline-block;padding:4px 10px;border-radius:3px;color:#fff;font-weight:bold;'
			. 'font-size:12px;letter-spacing:.04em;background:' . $badgeBg . '">'
			. htmlspecialchars($badgeLabel, ENT_QUOTES) . '</span>';
		echo '<span style="color:#555;font-size:13px">UFW Firewall</span>';
		echo '<span style="flex:1 1 auto"></span>';
		if ($installed) {
			if (!$active) {
				$this->printActionForm('ufwenable', 'Enable Firewall',
					'Enable UFW? Panel ports 7777/7778 and common services are allowed first.',
					'#2e7d32');
			} else {
				$this->printActionForm('ufwdisable', 'Disable Firewall',
					'Disable UFW on this server? Existing rules stay on disk.',
					'#c62828');
			}
		}
		echo '</div>';

		if ($err !== '' && $err !== 'ufw_not_installed') {
			echo '<p style="margin:0 0 12px;color:#c62828">Last error: <code>'
				. htmlspecialchars($err, ENT_QUOTES) . '</code></p>';
		}
		if (!$installed) {
			echo '<p style="margin:0 0 12px">Install the <code>ufw</code> package on this Ubuntu host, then reopen this page.</p>';
			echo '</div>';

			return;
		}

		// Default policies
		echo '<div style="margin:0 0 18px;padding:12px;border:1px solid #ddd;border-radius:3px;background:#fff">';
		echo '<div style="font-weight:bold;margin:0 0 8px">Default Policies</div>';
		$this->printFormOpen('ufwdefaults');
		echo '<table cellpadding="4" cellspacing="0" style="border-collapse:collapse"><tr>';
		echo '<td style="padding-right:8px">Incoming<br><select name="frm_ufwfirewall_c_ufw_default_in">';
		foreach ($policies as $p) {
			$sel = ($p === $defIn) ? ' selected' : '';
			echo '<option value="' . $p . '"' . $sel . '>' . $p . '</option>';
		}
		echo '</select></td>';
		echo '<td style="padding-right:8px">Outgoing<br><select name="frm_ufwfirewall_c_ufw_default_out">';
		foreach ($policies as $p) {
			$sel = ($p === $defOut) ? ' selected' : '';
			echo '<option value="' . $p . '"' . $sel . '>' . $p . '</option>';
		}
		echo '</select></td>';
		echo '<td style="vertical-align:bottom"><input type="submit" value="Save Defaults" class="submitbutton"></td>';
		echo '</tr></table>';
		echo '</form></div>';

		// Rules table — only when there is at least one rule (no empty status dump).
		if ($rules !== array()) {
			echo '<div style="margin:0 0 18px;padding:12px;border:1px solid #ddd;border-radius:3px;background:#fff">';
			echo '<div style="display:flex;align-items:baseline;justify-content:space-between;margin:0 0 8px">';
			echo '<div style="font-weight:bold">Firewall Rules</div>';
			echo '<div style="font-size:12px;color:#666">' . (int) $nRules
				. ' rule' . ($nRules === 1 ? '' : 's') . '</div></div>';
			echo '<table cellpadding="6" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:13px">';
			echo '<tr style="background:#eee;border-bottom:1px solid #ccc">';
			echo '<th align="left" style="width:40px">#</th>';
			echo '<th align="left">Action</th>';
			echo '<th align="left">Protocol</th>';
			echo '<th align="left">Port</th>';
			echo '<th align="left">IP Address</th>';
			echo '<th align="center" style="width:70px"></th></tr>';
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
				$proto = $parsed['proto'] !== '' ? strtoupper($parsed['proto']) : '—';
				$actUp = strtoupper($actRaw);
				$isAllow = str_contains($actUp, 'ALLOW');
				$actColor = $isAllow ? '#2e7d32' : '#c62828';
				$actLabel = $isAllow ? 'ALLOW' : (str_contains($actUp, 'DENY') || str_contains($actUp, 'REJECT')
					? (str_contains($actUp, 'REJECT') ? 'REJECT' : 'DENY') : $actUp);
				$canDel = true;
				try {
					$canDel = KloxoUfw::canDeleteRule($rules, (int) $n);
				} catch (Throwable $e) {
					$canDel = true;
				}
				echo '<tr style="background:' . $bg . ';border-bottom:1px solid #eee">';
				echo '<td>' . htmlspecialchars($n, ENT_QUOTES) . '</td>';
				echo '<td><span style="color:' . $actColor . ';font-weight:bold">'
					. htmlspecialchars($actLabel, ENT_QUOTES) . '</span></td>';
				echo '<td>' . htmlspecialchars($proto, ENT_QUOTES) . '</td>';
				echo '<td>' . htmlspecialchars($port, ENT_QUOTES) . '</td>';
				echo '<td>' . htmlspecialchars($from !== '' ? $from : 'Anywhere', ENT_QUOTES) . '</td>';
				echo '<td align="center">';
				if ($canDel) {
					$this->printFormOpen('deleterule');
					echo '<input type="hidden" name="frm_ufwfirewall_c_ufw_rule" value="'
						. htmlspecialchars($n, ENT_QUOTES) . '">';
					echo '<input type="submit" value="Delete" class="submitbutton" style="font-size:11px"'
						. ' onclick="return confirm(' . htmlspecialchars(
							json_encode('Delete rule #' . $n . '?'),
							ENT_QUOTES
						) . ')">';
					echo '</form>';
				} else {
					echo '<span style="color:#999;font-size:11px" title="Last allow for panel port">Protected</span>';
				}
				echo '</td></tr>';
			}
			echo '</table></div>';
		}

		// Add Rule (Hestia-like fields)
		echo '<div style="padding:12px;border:1px solid #ddd;border-radius:3px;background:#fff">';
		echo '<div style="font-weight:bold;margin:0 0 8px">Add Rule</div>';
		$this->printFormOpen('addrule');
		echo '<table cellpadding="4" cellspacing="0" style="border-collapse:collapse;width:100%">';
		echo '<tr><td style="width:25%">Action<br>';
		echo '<select name="frm_ufwfirewall_c_ufw_action" style="width:95%">';
		echo '<option value="allow">ALLOW</option><option value="deny">DENY</option></select></td>';
		echo '<td style="width:25%">Protocol<br>';
		echo '<select name="frm_ufwfirewall_c_ufw_proto" style="width:95%">';
		echo '<option value="tcp">TCP</option><option value="udp">UDP</option></select></td>';
		echo '<td style="width:25%">Port<br>';
		echo '<input type="text" name="frm_ufwfirewall_c_ufw_port" style="width:95%"'
			. ' placeholder="80 or 80-82 or 80,443" required></td>';
		echo '<td style="width:25%">IP Address<br>';
		echo '<input type="text" name="frm_ufwfirewall_c_ufw_from" style="width:95%"'
			. ' placeholder="Anywhere (0.0.0.0/0)"></td></tr>';
		echo '<tr><td colspan="4" style="padding-top:10px;text-align:right">';
		echo '<span style="float:left;color:#666;font-size:12px">Ranges: 80-82 · Lists: 80,443 · Blank IP = anywhere. '
			. 'Deny of panel ports 7777/7778 is refused.</span>';
		echo '<input type="submit" value="Add Rule" class="submitbutton"></td></tr>';
		echo '</table></form></div>';

		echo '</div>';
	}

	function printActionForm($subaction, $label, $confirm, $color)
	{
		$this->printFormOpen($subaction);
		echo '<input type="hidden" name="frm_ufwfirewall_c_ufw_confirm" value="on">';
		echo '<input type="submit" value="' . htmlspecialchars($label, ENT_QUOTES) . '" class="submitbutton"'
			. ' style="background:' . htmlspecialchars($color, ENT_QUOTES) . ';color:#fff;border-color:'
			. htmlspecialchars($color, ENT_QUOTES) . '"'
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
				$vlist['ufw_action'] = array('s', array('allow' => 'ALLOW', 'deny' => 'DENY'));
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
