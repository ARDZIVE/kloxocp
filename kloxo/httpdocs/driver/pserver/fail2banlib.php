<?php

include_once dirname(__DIR__, 2) . '/lib/php/kloxo_fail2ban.php';

/**
 * Security > Fail2Ban. One list page (jails, banned addresses, recent activity)
 * plus Ban IP / Settings / Edit Jail forms on separate updateform pages.
 * Everything privileged runs as root through kloxo-backend (KloxoFail2ban).
 */
class fail2ban extends lxclass
{
	static $__desc = array("", "", "Fail2Ban");
	static $__desc_nname = array("", "", "Fail2Ban");
	static $__acdesc_show = array("", "", "Fail2Ban");
	static $__acdesc_update_ban = array("", "", "Ban IP");
	static $__acdesc_update_unban = array("", "", "Unban IP");
	static $__acdesc_update_unbanall = array("", "", "Unban All");
	static $__acdesc_update_settings = array("", "", "Settings");
	static $__acdesc_update_editjail = array("", "", "Edit Jail");
	static $__acdesc_update_enablejail = array("", "", "Enable Jail");
	static $__acdesc_update_disablejail = array("", "", "Disable Jail");
	static $__acdesc_update_reload = array("", "", "Reload");
	static $__desc_f2b_jail = array("s", "", "jail");
	static $__desc_f2b_ip = array("", "", "ip_address_f");
	static $__desc_f2b_info = array("M", "", "Fail2Ban");
	static $__desc_f2b_enabled = array("s", "", "jail_status");
	static $__desc_f2b_maxretry = array("", "", "max_retry");
	static $__desc_f2b_findtime = array("", "", "find_time");
	static $__desc_f2b_bantime = array("", "", "ban_time");
	static $__desc_f2b_ignoreip = array("t", "", "whitelist_ips");

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

	static function getTextAreaProperties($var)
	{
		return array("height" => 5, "width" => "60%");
	}

	/** Beige tabs: the list, then the forms. Edit Jail only while editing one. */
	static function fail2banPropertyTabs()
	{
		$tabs = array('a=show', 'a=updateform&sa=ban', 'a=updateform&sa=settings');
		$sa = '';
		if (isset($_REQUEST['frm_subaction']) && is_scalar($_REQUEST['frm_subaction'])) {
			$sa = strtolower(trim((string) $_REQUEST['frm_subaction']));
		}
		if ($sa === 'editjail') {
			$tabs[] = 'a=updateform&sa=editjail';
		}

		return $tabs;
	}

	function createShowPropertyList(&$alist)
	{
		$alist['property'] = self::fail2banPropertyTabs();

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

	/** Inline Font Awesome Free solid SVG (theme/fontawesome, CC BY 4.0). */
	static function faIcon($name, $color = '#333', $title = '', $height = 16)
	{
		static $cache = array();

		$name = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $name));
		if (!isset($cache[$name])) {
			$cache[$name] = null;
			$file = dirname(__DIR__, 2) . '/theme/fontawesome/svgs/solid/' . $name . '.svg';
			$raw = is_file($file) ? @file_get_contents($file) : false;
			if (is_string($raw)
				&& preg_match('/viewBox="0 0 (\d+) (\d+)"/', $raw, $vb) === 1
				&& preg_match('/<path[^>]*\sd="([^"]+)"/', $raw, $pd) === 1) {
				$cache[$name] = array((int) $vb[1], (int) $vb[2], $pd[1]);
			}
		}
		if ($cache[$name] === null) {
			return '';
		}
		list($vw, $vh, $d) = $cache[$name];
		$h = (int) $height;
		$w = (int) round($h * $vw / max(1, $vh));
		$t = htmlspecialchars((string) $title, ENT_QUOTES);
		$c = preg_match('/^#[0-9a-fA-F]{3,6}$/', (string) $color) === 1 ? $color : '#333';

		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $vw . ' ' . $vh . '"'
			. ' width="' . $w . '" height="' . $h . '" role="img" aria-label="' . $t . '"'
			. ' style="fill:' . $c . ';vertical-align:middle"><title>' . $t . '</title>'
			. '<path d="' . htmlspecialchars($d, ENT_QUOTES) . '"/></svg>';
	}

	/** True on the Ban IP / Settings / Edit Jail pages, which must not render the list. */
	static function isFormPage()
	{
		global $ghtml;

		$sa = '';
		if (is_object($ghtml)) {
			$v = $ghtml->frm_subaction;
			$sa = is_string($v) ? $v : '';
		}
		if ($sa === '' && isset($_REQUEST['frm_subaction']) && is_string($_REQUEST['frm_subaction'])) {
			$sa = $_REQUEST['frm_subaction'];
		}

		return in_array(strtolower($sa), array('ban', 'settings', 'editjail'), true);
	}

	// ------------------------------------------------------------------ show

	function showRawPrint($subaction = null)
	{
		if (self::isFormPage()) {
			return;
		}

		try {
			$this->assertAdmin();
		} catch (Throwable $e) {
			echo '<div class="kloxo-fail2ban"><p class="messagetext">Only admin can access Fail2Ban.</p></div>';

			return;
		}

		$ov = $this->execF2b('overview', array());
		$st = (isset($ov['status']) && is_array($ov['status'])) ? $ov['status'] : array();
		$rows = (isset($ov['rows']) && is_array($ov['rows'])) ? $ov['rows'] : array();
		$banned = (isset($ov['banned']) && is_array($ov['banned'])) ? $ov['banned'] : array();
		$log = (isset($ov['log']) && is_array($ov['log'])) ? $ov['log'] : array();
		$err = isset($st['error']) ? (string) $st['error'] : '';
		$host = $this->syncServerName();
		$skin = $this->skinColor();
		$h = static function ($v) {
			return htmlspecialchars((string) $v, ENT_QUOTES);
		};

		echo '<div class="kloxo-fail2ban">';

		if (empty($st['installed'])) {
			echo '<p class="messagetext" style="text-align:center;margin:12px 25px">'
				. 'Install the <code>fail2ban</code> package on this Ubuntu host '
				. '(the installer does this), then reopen this page.</p></div>';

			return;
		}
		if ($err !== '') {
			echo '<p class="messagetext formtablerow2">Error: ' . $h($this->errorText($err)) . '</p>';
			if (str_contains($err, 'fail2ban_backend_failed') || str_contains($err, 'must be root')) {
				echo '<p class="messagetext" style="color:#a00;margin:8px 25px">'
					. 'Fail2Ban control needs root via <code>kloxo-backend</code> '
					. '(Services → start <code>kloxo-backend</code>), then Refresh.</p>';
			}
		}
		if (empty($st['active'])) {
			echo '<p class="messagetext" style="color:#a00;margin:8px 25px">'
				. 'Fail2Ban is not running on this host. Use Reload to apply the settings and start it, '
				. 'or start <code>fail2ban</code> from Services.</p>';
		}

		$nRunning = 0;
		foreach ($rows as $r) {
			if (!empty($r['running'])) {
				$nRunning++;
			}
		}
		$version = isset($st['version']) && $st['version'] !== '' ? ' ' . $st['version'] : '';

		// Toolbar
		echo '<div class="div_showhide"><fieldset style="padding:0;text-align:center;margin:0;border:0;border-top:1px solid #ddd">'
			. '<legend><span style="font-weight:bold">Fail2Ban' . $h($version) . ' under ' . $h($host)
			. ' (' . ($nRunning) . ' jails running, ' . count($banned) . ' addresses banned)</span></legend></fieldset></div>';
		echo '<div class="div_showhide"><table width="100%" cellpadding="0" cellspacing="0" border="0"'
			. ' style="background:#fee;border:1px solid #' . $h($skin) . ';padding:10px"><tr><td valign="middle">';
		$tb = 'text-decoration:none;margin-right:14px;display:inline-block;text-align:center';
		echo '<a href="' . $h($this->urlFor('a=show')) . '" title="Refresh" style="' . $tb . '">'
			. self::faIcon('arrows-rotate', '#333', 'Refresh') . '<br><span class="small">Refresh</span></a>';
		echo '<a href="' . $h($this->urlFor('a=updateform&sa=ban')) . '" title="Ban IP" style="' . $tb . '">'
			. self::faIcon('plus', '#333', 'Ban IP') . '<br><span class="small">Ban IP</span></a>';
		$this->printInlineAction('reload', array(), self::faIcon('play', '#333', 'Reload'), 'Reload',
			'Apply the saved settings and reload Fail2Ban?', 'Reload', $tb);
		$this->printInlineAction('unbanall', array(), self::faIcon('trash', '#333', 'Unban All'), 'Unban All',
			'Remove every ban in every jail?', 'Unban All', $tb);
		echo '</td></tr></table></div>';

		// Jails
		echo '<div class="div_showhide" style="margin-top:6px"><fieldset style="padding:0;text-align:center;margin:0;border:0;border-top:1px solid #ddd">'
			. '<legend><span style="font-weight:bold">Jails (what Fail2Ban watches) under ' . $h($host)
			. ' (' . count($rows) . ')</span></legend></fieldset></div>';
		echo '<table cellspacing="1" cellpadding="3" width="100%" align="center" border="0" id="f2b_jails_table">';
		echo '<tr height="25" valign="middle">';
		foreach (array('Jail', 'Group', 'Protects', 'State', 'Max Retry', 'Find Time', 'Ban Time', 'Failed', 'Banned') as $c) {
			echo '<td class="collist" nowrap style="background:#' . $h($skin) . '"><b>' . $h($c) . '</b></td>';
		}
		echo '<td class="collist" nowrap align="center" style="background:#' . $h($skin)
			. ';width:72px;max-width:72px">&nbsp;</td></tr>';
		$i = 0;
		foreach ($rows as $r) {
			if (!is_array($r) || !isset($r['name'])) {
				continue;
			}
			$i++;
			$name = (string) $r['name'];
			$available = !empty($r['available']);
			$enabled = !empty($r['enabled']);
			$running = !empty($r['running']);
			if (!$available) {
				$state = '<span style="color:#888">Not available</span><br><span class="small" style="color:#888">'
					. $h($r['reason'] ?? '') . '</span>';
			} elseif ($running) {
				$state = '<span style="color:#2a2;font-weight:bold">Running</span>';
			} elseif ($enabled) {
				$state = '<span style="color:#c60">On, not running</span>';
			} else {
				$state = '<span style="color:#888">Off</span>';
			}
			echo '<tr height="22" class="tablerow' . ($i % 2) . '">';
			echo '<td class="col" nowrap><b>' . $h($name) . '</b></td>';
			echo '<td class="col" nowrap>' . $h($r['group'] ?? '') . '</td>';
			echo '<td class="col"><b>' . $h($r['title'] ?? '') . '</b><br><span class="small">' . $h($r['desc'] ?? '') . '</span></td>';
			echo '<td class="col">' . $state . '</td>';
			echo '<td class="col">' . $h($r['maxretry'] ?? '') . '</td>';
			echo '<td class="col" nowrap>' . $h($r['findtime'] ?? '') . '</td>';
			echo '<td class="col" nowrap>' . $h($r['bantime'] ?? '') . '</td>';
			echo '<td class="col" nowrap>' . $h(($r['failed_now'] ?? 0) . ' / ' . ($r['failed_total'] ?? 0)) . '</td>';
			echo '<td class="col" nowrap>' . $h(($r['banned_now'] ?? 0) . ' / ' . ($r['banned_total'] ?? 0)) . '</td>';
			echo '<td class="col" align="center" style="white-space:nowrap;width:72px;max-width:72px;padding:2px 4px">';
			echo '<a href="' . $h($this->editJailUrl($name)) . '" title="Edit" style="text-decoration:none;margin:0 2px;vertical-align:middle">'
				. self::faIcon('pen-to-square', '#333', 'Edit') . '</a>';
			if ($enabled) {
				$this->printInlineAction('disablejail', array('f2b_jail' => $name), self::faIcon('pause', '#c60', 'Turn off'),
					'Turn off', 'Turn off the ' . $name . ' jail?');
			} elseif ($available) {
				$this->printInlineAction('enablejail', array('f2b_jail' => $name), self::faIcon('play', '#2a2', 'Turn on'),
					'Turn on', 'Turn on the ' . $name . ' jail?');
			} else {
				echo '<span style="margin:0 2px;vertical-align:middle" title="' . $h($r['reason'] ?? '') . '">'
					. self::faIcon('play', '#ccc', 'Not available') . '</span>';
			}
			echo '</td></tr>';
		}
		echo '</table>';

		// Banned addresses
		echo '<div class="div_showhide" style="margin-top:10px"><fieldset style="padding:0;text-align:center;margin:0;border:0;border-top:1px solid #ddd">'
			. '<legend><span style="font-weight:bold">Banned IPs under ' . $h($host) . ' (' . count($banned)
			. ')</span></legend></fieldset></div>';
		echo '<table cellspacing="1" cellpadding="3" width="100%" align="center" border="0" id="f2b_banned_table">';
		echo '<tr height="25" valign="middle">';
		foreach (array('IP Address', 'Jail') as $c) {
			echo '<td class="collist" nowrap style="background:#' . $h($skin) . '"><b>' . $h($c) . '</b></td>';
		}
		echo '<td class="collist" nowrap align="center" style="background:#' . $h($skin) . ';width:72px">&nbsp;</td></tr>';
		if ($banned === array()) {
			echo '<tr class="tablerow0"><td class="col" colspan="3" style="text-align:center;padding:12px"><b>No banned IPs under '
				. $h($host) . '</b></td></tr>';
		}
		$i = 0;
		foreach ($banned as $b) {
			if (!is_array($b) || !isset($b['ip'], $b['jail'])) {
				continue;
			}
			$i++;
			echo '<tr height="22" class="tablerow' . ($i % 2) . '"><td class="col">' . $h($b['ip']) . '</td>'
				. '<td class="col">' . $h($b['jail']) . '</td><td class="col" align="center">';
			$this->printInlineAction('unban', array('f2b_jail' => (string) $b['jail'], 'f2b_ip' => (string) $b['ip']),
				self::faIcon('trash', '#333', 'Unban'), 'Unban', 'Unban ' . $b['ip'] . ' in ' . $b['jail'] . '?');
			echo '</td></tr>';
		}
		echo '</table>';

		// Recent activity
		echo '<div class="div_showhide" style="margin-top:10px"><fieldset style="padding:0;text-align:center;margin:0;border:0;border-top:1px solid #ddd">'
			. '<legend><span style="font-weight:bold">Recent Activity under ' . $h($host) . ' (' . count($log)
			. ')</span></legend></fieldset></div>';
		echo '<table cellspacing="1" cellpadding="3" width="100%" align="center" border="0" id="f2b_log_table">';
		echo '<tr height="25" valign="middle">';
		foreach (array('Time', 'Event', 'Jail', 'IP Address / Message') as $c) {
			echo '<td class="collist" nowrap style="background:#' . $h($skin) . '"><b>' . $h($c) . '</b></td>';
		}
		echo '</tr>';
		if ($log === array()) {
			echo '<tr class="tablerow0"><td class="col" colspan="4" style="text-align:center;padding:12px"><b>No activity recorded yet</b></td></tr>';
		}
		$i = 0;
		foreach ($log as $e) {
			if (!is_array($e)) {
				continue;
			}
			$i++;
			$ev = (string) ($e['event'] ?? '');
			$color = ($ev === 'Ban' || $ev === 'Restore Ban') ? '#c00' : (($ev === 'Unban') ? '#2a2' : '#c60');
			echo '<tr height="22" class="tablerow' . ($i % 2) . '"><td class="col" nowrap>' . $h($e['time'] ?? '') . '</td>'
				. '<td class="col" nowrap><span style="color:' . $color . ';font-weight:bold">' . $h($ev) . '</span></td>'
				. '<td class="col">' . $h($e['jail'] ?? '') . '</td>'
				. '<td class="col">' . $h(($e['ip'] ?? '') !== '' ? $e['ip'] : ($e['message'] ?? '')) . '</td></tr>';
		}
		echo '</table>';

		echo '</div>';
	}

	/**
	 * Tiny POST form for one action icon.
	 *
	 * @param array<string,string> $fields name => value, sent as frm_fail2ban_c_<name>
	 */
	function printInlineAction($subaction, $fields, $iconHtml, $title, $confirm, $label = '', $style = '')
	{
		$token = function_exists('getCSRFToken') ? getCSRFToken() : '';
		echo '<form method="post" action="/display.php" accept-charset="utf-8"'
			. ' style="display:inline;margin:0;padding:0">';
		echo '<input type="hidden" name="frm_token" value="' . htmlspecialchars((string) $token, ENT_QUOTES) . '">';
		echo '<input type="hidden" name="frm_action" value="update">';
		echo '<input type="hidden" name="frm_subaction" value="' . htmlspecialchars((string) $subaction, ENT_QUOTES) . '">';
		$this->printObjectPathInputs();
		foreach ((array) $fields as $k => $v) {
			echo '<input type="hidden" name="frm_fail2ban_c_' . htmlspecialchars((string) $k, ENT_QUOTES) . '" value="'
				. htmlspecialchars((string) $v, ENT_QUOTES) . '">';
		}
		$btnStyle = $label !== ''
			? 'border:0;background:transparent;cursor:pointer;padding:0;margin:0 14px 0 0;text-align:center;line-height:1.2'
			: 'border:0;background:transparent;cursor:pointer;padding:0;margin:0 2px;vertical-align:middle;line-height:1';
		echo '<button type="submit" title="' . htmlspecialchars((string) $title, ENT_QUOTES) . '"'
			. ' style="' . $btnStyle . '"'
			. ' onclick="return confirm(' . htmlspecialchars(json_encode((string) $confirm), ENT_QUOTES) . ')">'
			. $iconHtml . ($label !== '' ? '<br><span class="small">' . htmlspecialchars((string) $label, ENT_QUOTES) . '</span>' : '')
			. '</button>';
		echo '</form>';
	}

	function printObjectPathInputs()
	{
		global $ghtml;

		if (is_object($ghtml) && isset($ghtml->__http_vars['frm_o_o'])
			&& is_array($ghtml->__http_vars['frm_o_o'])
			&& method_exists($ghtml, 'print_input_vars')) {
			$ghtml->print_input_vars(array('frm_o_o' => $ghtml->__http_vars['frm_o_o']));

			return;
		}
		$host = $this->syncServerName();
		echo '<input type="hidden" name="frm_o_o[0][class]" value="pserver">';
		echo '<input type="hidden" name="frm_o_o[0][nname]" value="' . htmlspecialchars($host, ENT_QUOTES) . '">';
		echo '<input type="hidden" name="frm_o_o[1][class]" value="fail2ban">';
	}

	function skinColor()
	{
		global $login;

		try {
			if (is_object($login) && method_exists($login, 'getSkinColor')) {
				$c = $login->getSkinColor();
				if (is_string($c) && preg_match('/^[0-9a-fA-F]{3,6}$/', $c) === 1) {
					return $c;
				}
			}
		} catch (Throwable $e) {
		}

		return 'edc';
	}

	function urlFor($act)
	{
		global $ghtml;

		if (is_object($ghtml) && method_exists($ghtml, 'getFullUrl')) {
			try {
				$url = $ghtml->getFullUrl($act);
				if (is_string($url) && $url !== '') {
					return $url;
				}
			} catch (Throwable $e) {
			}
		}
		$sa = '';
		if (preg_match('/sa=([a-z]+)/', (string) $act, $m) === 1) {
			$sa = $m[1];
		}

		return '/display.php?frm_action=' . ($sa !== '' ? 'updateform&frm_subaction=' . $sa : 'show')
			. '&frm_o_o[0][class]=pserver'
			. '&frm_o_o[0][nname]=' . rawurlencode($this->syncServerName())
			. '&frm_o_o[1][class]=fail2ban';
	}

	function editJailUrl($jail)
	{
		return $this->urlFor('a=updateform&sa=editjail') . '&frm_fail2ban_c_f2b_jail=' . rawurlencode((string) $jail);
	}

	// ----------------------------------------------------------------- forms

	function updateform($subaction, $param)
	{
		try {
			$this->assertAdmin();
		} catch (Throwable $e) {
		}

		$vlist = array();
		switch ($subaction) {
			case 'ban':
				$ov = $this->execF2b('overview', array());
				$choices = array();
				foreach ((array) ($ov['rows'] ?? array()) as $r) {
					if (is_array($r) && !empty($r['running'])) {
						$choices[(string) $r['name']] = (string) $r['name'] . ' - ' . (string) $r['title'];
					}
				}
				$this->f2b_ip = '';
				if ($choices === array()) {
					$vlist['f2b_info'] = array('M', 'No jail is running. Turn a jail on (and Reload) before banning an address.');
					$vlist['__v_button'] = array();

					return $vlist;
				}
				$vlist['f2b_jail'] = array('A', $choices);
				$vlist['f2b_ip'] = null;
				$vlist['f2b_info'] = array('M', 'Ban one IPv4 or IPv6 address in the chosen jail until its ban time runs out. '
					. 'Addresses on the whitelist, loopback and the address you are browsing from are refused.');
				$vlist['__v_button'] = 'Ban IP';

				return $vlist;
			case 'settings':
				$cfg = $this->execF2b('config', array());
				$d = (isset($cfg['defaults']) && is_array($cfg['defaults'])) ? $cfg['defaults'] : array();
				$this->f2b_bantime = (string) ($d['bantime'] ?? '1h');
				$this->f2b_findtime = (string) ($d['findtime'] ?? '10m');
				$this->f2b_maxretry = (string) ($d['maxretry'] ?? '5');
				$this->f2b_ignoreip = implode("\n", (array) ($d['ignoreip'] ?? array()));
				$vlist['f2b_info'] = array('M', 'Defaults for every jail that has no value of its own. Times are seconds or a number with '
					. 's, m, h, d or w (10m, 1h, 1d, 1w); -1 as Ban Time bans for ever. '
					. 'Whitelisted addresses and networks (one per line) are never banned.');
				$vlist['f2b_bantime'] = null;
				$vlist['f2b_findtime'] = null;
				$vlist['f2b_maxretry'] = null;
				$vlist['f2b_ignoreip'] = array('t', $this->f2b_ignoreip);
				$vlist['__v_button'] = 'Update';

				return $vlist;
			case 'editjail':
				$jail = $this->requestedJail($param);
				$row = null;
				$ov = $this->execF2b('overview', array());
				foreach ((array) ($ov['rows'] ?? array()) as $r) {
					if (is_array($r) && ($r['name'] ?? '') === $jail) {
						$row = $r;
					}
				}
				if ($row === null) {
					$vlist['f2b_info'] = array('M', 'Unknown jail.');
					$vlist['__v_button'] = array();

					return $vlist;
				}
				$cfg = $this->execF2b('config', array());
				$own = (isset($cfg['jails'][$jail]) && is_array($cfg['jails'][$jail])) ? $cfg['jails'][$jail] : array();
				$this->f2b_enabled = !empty($row['enabled']) ? 'on' : 'off';
				$this->f2b_maxretry = isset($own['maxretry']) ? (string) $own['maxretry'] : '';
				$this->f2b_findtime = isset($own['findtime']) ? (string) $own['findtime'] : '';
				$this->f2b_bantime = isset($own['bantime']) ? (string) $own['bantime'] : '';
				$vlist['f2b_info'] = array('M', $jail . ' - ' . (string) $row['title'] . '. ' . (string) $row['desc']
					. ' Leave a value empty to use the default (now: ' . (int) $row['maxretry'] . ' tries in '
					. (string) $row['findtime'] . ', banned for ' . (string) $row['bantime'] . ').');
				$vlist['f2b_enabled'] = array('A', array('on' => 'On', 'off' => 'Off'));
				$vlist['f2b_maxretry'] = null;
				$vlist['f2b_findtime'] = null;
				$vlist['f2b_bantime'] = null;
				$vlist['__v_param'] = array('f2b_jail' => $jail);
				$vlist['__v_button'] = 'Update';

				return $vlist;
		}

		return $vlist;
	}

	function requestedJail($param = null)
	{
		$raw = '';
		if (is_array($param) && isset($param['f2b_jail']) && is_scalar($param['f2b_jail'])) {
			$raw = (string) $param['f2b_jail'];
		} elseif (isset($_REQUEST['frm_fail2ban_c_f2b_jail']) && is_scalar($_REQUEST['frm_fail2ban_c_f2b_jail'])) {
			$raw = (string) $_REQUEST['frm_fail2ban_c_f2b_jail'];
		}
		$raw = trim($raw);

		return preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $raw) === 1 ? $raw : '';
	}

	/** A posted field: $param first, then the raw request (inline action forms). */
	function field($param, $name)
	{
		if (is_array($param) && isset($param[$name]) && is_scalar($param[$name])) {
			return trim((string) $param[$name]);
		}
		$k = 'frm_fail2ban_c_' . $name;
		if (isset($_REQUEST[$k]) && is_scalar($_REQUEST[$k])) {
			return trim((string) $_REQUEST[$k]);
		}

		return '';
	}

	function redirectToShow($msgKey = 'updated_successfully', $data = '')
	{
		global $gbl;

		if (!is_object($gbl)) {
			return;
		}

		$msgKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $msgKey));
		$tail = '&frm_smessage=' . $msgKey;
		if ((string) $data !== '') {
			// The value is placed into HTML by the message printer: escape it first.
			$tail .= '&frm_m_smessage_data=' . rawurlencode(htmlspecialchars((string) $data, ENT_QUOTES));
		}
		$gbl->__this_redirect = $this->urlFor('a=show') . $tail;
	}

	// --------------------------------------------------------------- updates

	function updateBan($param)
	{
		$this->assertAdmin();
		$jail = $this->field($param, 'f2b_jail');
		$ip = $this->field($param, 'f2b_ip');
		$mine = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
		$ret = $this->execF2b('ban', array($jail, $ip, $mine));
		$this->throwIfFailed($ret);
		$this->redirectToShow('f2b_banned', $ip . ' in ' . $jail);

		return null;
	}

	function updateUnban($param)
	{
		$this->assertAdmin();
		$jail = $this->field($param, 'f2b_jail');
		$ip = $this->field($param, 'f2b_ip');
		$ret = $this->execF2b('unban', array($jail, $ip));
		$this->throwIfFailed($ret);
		$this->redirectToShow('f2b_unbanned', $ip . ' in ' . $jail);

		return null;
	}

	function updateUnbanall($param)
	{
		$this->assertAdmin();
		$ret = $this->execF2b('unbanAll', array());
		$this->throwIfFailed($ret);
		$this->redirectToShow('f2b_unbanned_all');

		return null;
	}

	function updateEnablejail($param)
	{
		return $this->switchJail($param, true);
	}

	function updateDisablejail($param)
	{
		return $this->switchJail($param, false);
	}

	function switchJail($param, $on)
	{
		$this->assertAdmin();
		$jail = $this->requestedJail($param);
		$ret = $this->execF2b('setJail', array($jail, array('enabled' => (bool) $on)));
		$this->throwIfFailed($ret);
		$this->redirectToShow($on ? 'f2b_jail_enabled' : 'f2b_jail_disabled', $jail);

		return null;
	}

	function updateEditjail($param)
	{
		$this->assertAdmin();
		$jail = $this->requestedJail($param);
		$fields = array(
			'enabled' => $this->field($param, 'f2b_enabled') === 'on',
			'maxretry' => $this->field($param, 'f2b_maxretry'),
			'findtime' => $this->field($param, 'f2b_findtime'),
			'bantime' => $this->field($param, 'f2b_bantime'),
		);
		$ret = $this->execF2b('setJail', array($jail, $fields));
		$this->throwIfFailed($ret);
		$this->redirectToShow('f2b_jail_updated', $jail);

		return null;
	}

	function updateSettings($param)
	{
		$this->assertAdmin();
		$fields = array(
			'bantime' => $this->field($param, 'f2b_bantime'),
			'findtime' => $this->field($param, 'f2b_findtime'),
			'maxretry' => $this->field($param, 'f2b_maxretry'),
			'ignoreip' => $this->field($param, 'f2b_ignoreip'),
		);
		$ret = $this->execF2b('setDefaults', array($fields));
		$this->throwIfFailed($ret);
		$this->redirectToShow('f2b_settings_saved');

		return null;
	}

	function updateReload($param)
	{
		$this->assertAdmin();
		$ret = $this->execF2b('apply', array());
		$this->throwIfFailed($ret);
		$skipped = (isset($ret['skipped']) && is_array($ret['skipped'])) ? array_keys($ret['skipped']) : array();
		$this->redirectToShow('f2b_reloaded', count((array) ($ret['running'] ?? array())) . ' jails running'
			. ($skipped !== array() ? ', skipped: ' . implode(', ', $skipped) : ''));

		return null;
	}

	// ---------------------------------------------------------------- helpers

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

	/**
	 * Run a KloxoFail2ban method as root (kloxo-backend) when the panel is not root.
	 * Never fall back to in-process as lxlabs: fail2ban-client needs the root
	 * socket (/var/run/fail2ban/fail2ban.sock) or it prints "you must be root".
	 * php-cli (tests / operator scripts) still runs in-process.
	 */
	function execF2b($method, $args)
	{
		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_fail2ban.php';
		$args = is_array($args) ? $args : array();
		$fail = array('ok' => false, 'error' => 'fail2ban_backend_failed',
			'status' => array('installed' => true, 'active' => false, 'error' => 'fail2ban_backend_failed', 'jails' => array()),
			'rows' => array(), 'banned' => array(), 'log' => array(), 'running' => array(), 'skipped' => array());
		$euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : 0;
		$panel = function_exists('php_sapi_name') ? php_sapi_name() : 'cli';
		$useBackend = $euid !== 0 && $panel !== 'cli' && function_exists('rl_exec_get');
		if (!$useBackend) {
			return $method === 'config' ? KloxoFail2ban::loadConfig() : call_user_func_array(array('KloxoFail2ban', $method), $args);
		}
		try {
			// 5th arg false: no in-process fallback as the panel user.
			$got = $method === 'config'
				? rl_exec_get(null, $this->syncServerName(), array('KloxoFail2ban', 'loadConfig'), array(), false)
				: rl_exec_get(null, $this->syncServerName(), array('KloxoFail2ban', $method), $args, false);
			if (is_array($got)) {
				return $got;
			}
		} catch (Throwable $e) {
			$msg = trim((string) $e->getMessage());
			if ($msg !== '') {
				$fail['error'] = 'fail2ban_backend_failed: ' . $msg;
				$fail['status']['error'] = $fail['error'];
			}
		}

		return $fail;
	}

	function throwIfFailed($ret)
	{
		if (is_array($ret) && !empty($ret['ok'])) {
			return;
		}
		$err = (is_array($ret) && isset($ret['error'])) ? (string) $ret['error'] : 'fail2ban_backend_failed';
		$this->throwFail($err !== '' ? $err : 'fail2ban_backend_failed');
	}

	/** "key: detail" from the backend becomes a translated message plus the detail. */
	function throwFail($err)
	{
		global $login;

		$key = $err;
		$val = '';
		$pos = strpos($err, ': ');
		if ($pos !== false) {
			$key = substr($err, 0, $pos);
			$val = substr($err, $pos + 2);
		}
		$key = preg_replace('/[^a-z0-9_]/', '', strtolower($key));
		$msg = (is_object($login) && method_exists($login, 'getThrow')) ? $login->getThrow($key) : $key;
		throw new lxException($msg, '', $val);
	}

	/** Readable form of a backend error key for the list page. */
	function errorText($err)
	{
		global $login;

		$key = $err;
		$val = '';
		$pos = strpos($err, ': ');
		if ($pos !== false) {
			$key = substr($err, 0, $pos);
			$val = substr($err, $pos + 2);
		}
		$key = preg_replace('/[^a-z0-9_]/', '', strtolower($key));
		$msg = (is_object($login) && method_exists($login, 'getThrow')) ? $login->getThrow($key) : $key;

		return $val !== '' ? $msg . ': ' . $val : $msg;
	}
}
