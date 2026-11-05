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
	static $__acdesc_update_editrule = array("", "", "Edit Rule");
	static $__acdesc_update_deleterule = array("", "", "Delete Rule");
	static $__desc_ufw_action = array("s", "", "action");
	static $__desc_ufw_proto = array("s", "", "protocol");
	static $__desc_ufw_port = array("", "", "port");
	static $__desc_ufw_from = array("", "", "from_address");
	static $__desc_ufw_rule = array("s", "", "rule_number");
	static $__desc_ufw_family = array("s", "", "family");
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
		global $login;

		try {
			$this->assertAdmin();
		} catch (Throwable $e) {
			echo '<div class="kloxo-ufw"><p class="messagetext">Only admin can access UFW Firewall.</p></div>';

			return;
		}

		try {
			$st = $this->ufwStatus();
		} catch (Throwable $e) {
			echo '<div class="kloxo-ufw"><p class="messagetext">Could not read UFW status: '
				. htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p></div>';

			return;
		}

		$host = $this->syncServerName();
		$skin = $this->ufwSkinColor();
		$installed = !empty($st['installed']);
		$active = !empty($st['active']);
		$rules = (isset($st['rules']) && is_array($st['rules'])) ? $st['rules'] : array();
		$err = isset($st['error']) ? (string) $st['error'] : '';
		$conflictNote = (isset($st['conflict_note']) && is_string($st['conflict_note']))
			? $st['conflict_note'] : '';
		$iconBase = $this->ufwIconBase();
		$editIcon = $iconBase . '/updateform_edit.gif';
		$delIcon = $iconBase . '/delete.gif';
		$filter = $this->ruleFilterFromRequest();
		$displayRules = $this->filterRules($rules, $filter);
		$nTotal = count($rules);
		$nShown = count($displayRules);
		$filterActive = $this->ruleFilterIsActive($filter);
		$clickAdd = $this->ufwKeyword('clickheretoadd', 'Click Here to Add');
		$showHide = $this->ufwKeyword('showhide', 'Show/Hide');
		$showAll = $this->ufwKeyword('showall', 'Show All');
		$under = $this->ufwKeyword('under', 'Under');
		$formId = 'listaddform_ufwfirewall';
		$advId = 'ufw_advsearch';
		$formVisible = 'visibility:visible;display:block';
		try {
			if (is_object($login)
				&& method_exists($login, 'getSpecialObject')
				&& is_object($login->getSpecialObject('sp_specialplay'))
				&& method_exists($login->getSpecialObject('sp_specialplay'), 'isOn')
				&& $login->getSpecialObject('sp_specialplay')->isOn('close_add_form')) {
				$formVisible = 'visibility:hidden;display:none';
			}
		} catch (Throwable $e) {
		}
		$advVisible = $filterActive && ($filter['q'] === '' || $this->ruleFilterHasColumnFields($filter))
			? 'visibility:visible;display:block' : 'visibility:hidden;display:none';
		$lower = $nShown > 0 ? 1 : 0;
		$upper = (int) $nShown;
		$perpageof = $lower . ' to ' . $upper . ' of ';
		$qVal = isset($filter['q']) ? (string) $filter['q'] : '';
		$searchIcon = '<span title="Search" class="if16">&#xf0c5;</span>';
		$showallIcon = '<span title="' . htmlspecialchars($showAll, ENT_QUOTES)
			. '" class="if16">&#xf480;</span>';
		$refreshIcon = '<span title="Refresh" class="if16">&#xf021;</span>';
		// Mail-account add form look: pale green / lavender rows, grey footer.
		$rowBg0 = '#f4faf4';
		$rowBg1 = '#f3f3f8';
		$footerBg = '#e8e8e8';

		echo '<div class="kloxo-ufw">';

		if ($err !== '' && $err !== 'ufw_not_installed') {
			echo '<p class="messagetext formtablerow2">Error: '
				. htmlspecialchars($err, ENT_QUOTES) . '</p>';
		}
		if (!$installed) {
			echo '<p class="messagetext" style="text-align:center;margin:12px 25px">'
				. 'Install the <code>ufw</code> package on this Ubuntu host '
				. '(install enables UFW by default), then reopen this page.</p>';
			echo '</div>';

			return;
		}
		if (!$active) {
			echo '<p class="messagetext" style="color:#a00;margin:8px 25px">'
				. 'UFW is inactive on this host (install normally enables it). '
				. 'As root run the panel installer again, or '
				. '<code>php -r \'require "…/kloxo_ufw.php"; KloxoUfw::enable();\'</code>.'
				. '</p>';
		}
		if ($conflictNote !== '') {
			echo '<p class="messagetext" style="color:#a00;margin:8px 25px;text-align:center">'
				. htmlspecialchars($conflictNote, ENT_QUOTES) . '</p>';
		}

		$toggleLabel = 'Port Rule for ' . $host;
		echo '<div style="background:#' . htmlspecialchars($skin, ENT_QUOTES)
			. ';padding:4px;margin:0 25px;text-align:center">&nbsp;>>>> '
			. '<a href="javascript:toggleVisibilityById(\'' . $formId . '\');">'
			. htmlspecialchars($clickAdd . ' ' . $toggleLabel . ' (' . $showHide . ')', ENT_QUOTES)
			. '</a> <<<<&nbsp;</div>';
		echo '<br/>';

		echo '<div id="' . $formId . '" style="' . $formVisible . ';text-align:center" class="div_showhide">';
		$this->printFormOpen('addrule', false);
		echo '<table cellpadding="8" cellspacing="0" border="0"'
			. ' style="margin:0 auto;width:70%;max-width:640px;min-width:360px;text-align:left;'
			. 'border:1px solid #ccc;border-collapse:collapse">';
		$fields = array(
			array(
				'label' => 'Action',
				'req' => false,
				'html' => $this->ufwSelectHtml(
					'frm_ufwfirewall_c_ufw_action',
					array('allow' => 'ALLOW', 'deny' => 'DENY', 'reject' => 'REJECT'),
					'allow'
				),
			),
			array(
				'label' => 'Protocol',
				'req' => false,
				'html' => $this->ufwSelectHtml(
					'frm_ufwfirewall_c_ufw_proto',
					array('tcp' => 'TCP', 'udp' => 'UDP'),
					'tcp'
				),
			),
			array(
				'label' => 'Port',
				'req' => true,
				'html' => '<input type="text" class="textbox" name="frm_ufwfirewall_c_ufw_port"'
					. ' value="" size="30" placeholder="80 or 80-82 or 80,443" required>',
			),
			array(
				'label' => 'From IP',
				'req' => false,
				'html' => '<input type="text" class="textbox" name="frm_ufwfirewall_c_ufw_from"'
					. ' value="" size="30" placeholder="blank = anywhere">',
			),
		);
		foreach ($fields as $i => $f) {
			$bg = ($i % 2 === 0) ? $rowBg0 : $rowBg1;
			$lab = htmlspecialchars($f['label'], ENT_QUOTES);
			if (!empty($f['req'])) {
				$lab .= ' <span style="color:#c00">*</span>';
			}
			echo '<tr style="background:' . $bg . '"><td style="padding:10px 12px;border-bottom:1px solid #ddd">'
				. '<div style="font-weight:bold;margin-bottom:4px">' . $lab . '</div>'
				. $f['html'] . '</td></tr>';
		}
		echo '<tr style="background:' . $footerBg . '"><td align="right" style="padding:10px 12px">'
			. '<input type="submit" class="submitbutton" value="&nbsp;&nbsp;Add&nbsp;&nbsp;">'
			. '</td></tr>';
		echo '</table>';
		echo '<div class="messagetext" style="margin:6px auto;max-width:640px;text-align:left">'
			. 'Port: single (<code>443</code>), range (<code>80-82</code>), or list (<code>80,443</code>). '
			. 'Blank From = anywhere. Deny/reject of panel ports <b>7777</b>/<b>7778</b> is refused.'
			. '</div>';
		echo '</form></div>';

		// List section — same chrome as Domains / Auxiliary Login lists.
		echo '<br/>';
		echo '<div class="div_showhide">';
		echo '<fieldset style="padding:0;text-align:center;margin:0;border:0;border-top:1px solid #ddd">';
		echo '<legend><span style="font-weight:bold">Port Rules ' . htmlspecialchars($under, ENT_QUOTES)
			. ' ' . htmlspecialchars($host, ENT_QUOTES);
		if ($filterActive) {
			echo ' <span style="color:red">(filtered)</span>';
		}
		echo ' (' . htmlspecialchars($perpageof . (string) $nTotal, ENT_QUOTES) . ')</span></legend>';
		echo '</fieldset></div>';

		echo '<div class="div_showhide">';
		echo '<table width="100%" cellpadding="0" cellspacing="0" border="0"'
			. ' style="background:#fee;border:1px solid #' . htmlspecialchars($skin, ENT_QUOTES)
			. ';padding:10px">';
		echo '<tr><td valign="middle">';
		echo '<a href="' . htmlspecialchars($this->showUrl(), ENT_QUOTES)
			. '" title="Refresh" style="text-decoration:none;margin-right:12px">'
			. $refreshIcon . '<br><span class="small">Refresh</span></a>';
		echo '</td><td align="right" valign="middle">';
		echo '<form name="ufw_lpform_search" method="get" action="/display.php" accept-charset="utf-8"'
			. ' style="display:inline" onsubmit="return true;">';
		$this->printShowHiddenGets();
		foreach (array('number', 'action', 'proto', 'port', 'family', 'from') as $fk) {
			if (isset($filter[$fk]) && $filter[$fk] !== '') {
				echo '<input type="hidden" name="frm_ufw_filter[' . $fk . ']" value="'
					. htmlspecialchars((string) $filter[$fk], ENT_QUOTES) . '">';
			}
		}
		echo '<input type="text" class="searchbox" name="frm_ufw_filter[q]" size="18" value="'
			. htmlspecialchars($qVal, ENT_QUOTES) . '"> ';
		echo '<a href="javascript:document.ufw_lpform_search.submit();">' . $searchIcon . '</a>';
		echo '</form>&nbsp;&nbsp;';
		echo '<a href="' . htmlspecialchars($this->showUrl(), ENT_QUOTES) . '" title="'
			. htmlspecialchars($showAll, ENT_QUOTES) . '" style="text-decoration:none">'
			. $showallIcon . '<br><span class="small">' . htmlspecialchars($showAll, ENT_QUOTES)
			. '</span></a>';
		echo '</td></tr></table></div>';

		echo '<div class="div_showhide" style="margin-top:6px">';
		echo '<fieldset style="padding:0;text-align:center;margin:0;border:0;border-top:1px solid #ddd">';
		echo '<legend><span style="font-weight:bold">Advanced Search '
			. '<a href="javascript:toggleVisibilityById(\'' . $advId . '\');">'
			. htmlspecialchars($showHide, ENT_QUOTES) . '</a></span></legend></fieldset></div>';
		echo '<div id="' . $advId . '" style="' . $advVisible . ';" class="div_showhide">';
		echo '<form method="get" action="/display.php" accept-charset="utf-8">';
		$this->printShowHiddenGets();
		if ($qVal !== '') {
			echo '<input type="hidden" name="frm_ufw_filter[q]" value="'
				. htmlspecialchars($qVal, ENT_QUOTES) . '">';
		}
		echo '<table width="100%" cellpadding="4" cellspacing="0" border="0" style="background:#fee;border:1px solid #'
			. htmlspecialchars($skin, ENT_QUOTES) . '"><tr>';
		$cols = array(
			'number' => array('label' => '#', 'type' => 'text'),
			'action' => array('label' => 'Action', 'type' => 'action'),
			'proto' => array('label' => 'Protocol', 'type' => 'proto'),
			'port' => array('label' => 'Port', 'type' => 'text'),
			'family' => array('label' => 'Family', 'type' => 'family'),
			'from' => array('label' => 'From', 'type' => 'text'),
		);
		foreach ($cols as $key => $meta) {
			$val = isset($filter[$key]) ? (string) $filter[$key] : '';
			echo '<td nowrap><span style="font-weight:bold">'
				. htmlspecialchars($meta['label'], ENT_QUOTES) . '</span><br>';
			if ($meta['type'] === 'action') {
				echo '<select class="searchbox" name="frm_ufw_filter[action]"><option value="">—</option>';
				foreach (array('ALLOW', 'DENY', 'REJECT', 'LIMIT') as $a) {
					$sel = (strcasecmp($val, $a) === 0) ? ' selected' : '';
					echo '<option value="' . $a . '"' . $sel . '>' . $a . '</option>';
				}
				echo '</select>';
			} elseif ($meta['type'] === 'proto') {
				echo '<select class="searchbox" name="frm_ufw_filter[proto]"><option value="">—</option>';
				foreach (array('TCP', 'UDP', 'any') as $a) {
					$sel = (strcasecmp($val, $a) === 0) ? ' selected' : '';
					echo '<option value="' . $a . '"' . $sel . '>' . $a . '</option>';
				}
				echo '</select>';
			} elseif ($meta['type'] === 'family') {
				echo '<select class="searchbox" name="frm_ufw_filter[family]"><option value="">—</option>';
				foreach (array('IPv4', 'IPv6') as $a) {
					$sel = (strcasecmp($val, $a) === 0) ? ' selected' : '';
					echo '<option value="' . $a . '"' . $sel . '>' . $a . '</option>';
				}
				echo '</select>';
			} else {
				echo '<input type="text" class="searchbox" size="11" name="frm_ufw_filter['
					. htmlspecialchars($key, ENT_QUOTES) . ']" value="'
					. htmlspecialchars($val, ENT_QUOTES) . '">';
			}
			echo '</td>';
		}
		echo '<td valign="bottom"><input type="submit" class="submitbutton" value="&nbsp;&nbsp;Search&nbsp;&nbsp;"></td>';
		echo '</tr></table></form></div>';

		echo '<br/>';
		echo '<table cellspacing="1" cellpadding="3" width="100%" align="center" border="0">';
		echo '<tr height="25" valign="middle">';
		foreach (array('#', 'Action', 'Protocol', 'Port', 'Family', 'From', 'Actions') as $h) {
			echo '<td class="collist" nowrap style="background:#'
				. htmlspecialchars($skin, ENT_QUOTES) . '"><b>'
				. htmlspecialchars($h, ENT_QUOTES) . '</b></td>';
		}
		echo '</tr>';

		if ($displayRules === array()) {
			$emptyMsg = $filterActive
				? 'No matches found'
				: ('No Port Rules ' . $under . ' ' . $host);
			echo '<tr class="tablerow0"><td class="col" colspan="7" style="text-align:center;padding:12px">'
				. '<b>' . htmlspecialchars($emptyMsg, ENT_QUOTES) . '</b></td></tr>';
		} else {
			$i = 0;
			foreach ($displayRules as $r) {
				if (!is_array($r) || !isset($r['number'])) {
					continue;
				}
				$rowN = $this->normalizeRuleRow($r);
				$i++;
				$count = ($i % 2 === 0) ? 1 : 0;
				$n = (string) $rowN['number'];
				$canDel = true;
				try {
					$canDel = KloxoUfw::canDeleteRule($rules, (int) $n);
				} catch (Throwable $e) {
					$canDel = true;
				}
				$editUrl = $this->editRuleUrl((int) $n);
				echo '<tr height="22" class="tablerow' . $count . '">';
				echo '<td class="col">' . htmlspecialchars($n, ENT_QUOTES) . '</td>';
				echo '<td class="col">' . htmlspecialchars($rowN['action_label'], ENT_QUOTES) . '</td>';
				echo '<td class="col">' . htmlspecialchars($rowN['proto'], ENT_QUOTES) . '</td>';
				echo '<td class="col">' . htmlspecialchars($rowN['port'], ENT_QUOTES) . '</td>';
				echo '<td class="col">' . htmlspecialchars($rowN['family'], ENT_QUOTES) . '</td>';
				echo '<td class="col">' . htmlspecialchars($rowN['from_label'], ENT_QUOTES) . '</td>';
				echo '<td class="col" align="center" style="white-space:nowrap">';
				echo '<a href="' . htmlspecialchars($editUrl, ENT_QUOTES) . '" title="Edit rule on a new page">'
					. '<img src="' . htmlspecialchars($editIcon, ENT_QUOTES)
					. '" alt="Edit" width="16" height="16" border="0" style="vertical-align:middle"></a>';
				echo '&nbsp;';
				if ($canDel) {
					$this->printFormOpen('deleterule');
					echo '<input type="hidden" name="frm_ufwfirewall_c_ufw_rule" value="'
						. htmlspecialchars($n, ENT_QUOTES) . '">';
					echo '<button type="submit" title="Delete"'
						. ' style="border:0;background:transparent;cursor:pointer;padding:0;margin:0;vertical-align:middle"'
						. ' onclick="return confirm(' . htmlspecialchars(
							json_encode('Delete rule #' . $n . '?'),
							ENT_QUOTES
						) . ')">'
						. '<img src="' . htmlspecialchars($delIcon, ENT_QUOTES)
						. '" alt="Delete" width="16" height="16" border="0"></button>';
					echo '</form>';
				} else {
					echo '<img src="' . htmlspecialchars($delIcon, ENT_QUOTES)
						. '" alt="Protected" width="16" height="16" border="0"'
						. ' style="opacity:0.35;vertical-align:middle"'
						. ' title="Last allow for panel port 7777/7778">';
				}
				echo '</td></tr>';
			}
		}
		echo '</table>';

		echo '</div>';
	}

	function ufwSkinColor()
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

	function ufwKeyword($key, $fallback)
	{
		global $login;

		try {
			if (is_object($login) && method_exists($login, 'getKeywordUc')) {
				$got = $login->getKeywordUc($key);
				if (is_string($got) && $got !== '') {
					return $got;
				}
			}
		} catch (Throwable $e) {
		}

		return $fallback;
	}

	/**
	 * @param array<string,string> $opts
	 */
	function ufwSelectHtml($name, $opts, $selected)
	{
		$html = '<select class="textbox" name="' . htmlspecialchars((string) $name, ENT_QUOTES) . '">';
		foreach ($opts as $v => $lab) {
			$sel = ((string) $v === (string) $selected) ? ' selected' : '';
			$html .= '<option value="' . htmlspecialchars((string) $v, ENT_QUOTES) . '"' . $sel . '>'
				. htmlspecialchars((string) $lab, ENT_QUOTES) . '</option>';
		}
		$html .= '</select>';

		return $html;
	}

	/**
	 * @param array{number?:int,to?:string,action?:string,from?:string,ipv6?:bool} $r
	 * @return array{number:int,action_label:string,proto:string,port:string,family:string,from:string,from_label:string}
	 */
	function normalizeRuleRow($r)
	{
		$to = isset($r['to']) ? (string) $r['to'] : '';
		$actRaw = isset($r['action']) ? (string) $r['action'] : '';
		$from = isset($r['from']) ? (string) $r['from'] : '';
		$ipv6 = !empty($r['ipv6']);
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

		return array(
			'number' => isset($r['number']) ? (int) $r['number'] : 0,
			'action_label' => $actLabel,
			'proto' => $proto,
			'port' => $port,
			'family' => $ipv6 ? 'IPv6' : 'IPv4',
			'from' => $from,
			'from_label' => $from !== '' ? $from : 'Anywhere',
		);
	}

	/**
	 * @return array{q:string,number:string,action:string,proto:string,port:string,family:string,from:string}
	 */
	function ruleFilterFromRequest()
	{
		$keys = array('q', 'number', 'action', 'proto', 'port', 'family', 'from');
		$out = array();
		$src = array();
		if (isset($_GET['frm_ufw_filter']) && is_array($_GET['frm_ufw_filter'])) {
			$src = $_GET['frm_ufw_filter'];
		} elseif (isset($_REQUEST['frm_ufw_filter']) && is_array($_REQUEST['frm_ufw_filter'])) {
			$src = $_REQUEST['frm_ufw_filter'];
		}
		foreach ($keys as $k) {
			$out[$k] = (isset($src[$k]) && is_scalar($src[$k])) ? trim((string) $src[$k]) : '';
		}

		return $out;
	}

	/**
	 * @param array{q?:string,number?:string,action?:string,proto?:string,port?:string,family?:string,from?:string} $filter
	 */
	function ruleFilterIsActive($filter)
	{
		foreach ($filter as $v) {
			if (is_string($v) && $v !== '') {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array{q?:string,number?:string,action?:string,proto?:string,port?:string,family?:string,from?:string} $filter
	 */
	function ruleFilterHasColumnFields($filter)
	{
		foreach (array('number', 'action', 'proto', 'port', 'family', 'from') as $k) {
			if (isset($filter[$k]) && is_string($filter[$k]) && $filter[$k] !== '') {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<array{number:int,to:string,action:string,from:string,ipv6?:bool}> $rules
	 * @param array{q?:string,number?:string,action?:string,proto?:string,port?:string,family?:string,from?:string} $filter
	 * @return list<array{number:int,to:string,action:string,from:string,ipv6?:bool}>
	 */
	function filterRules($rules, $filter)
	{
		if (!$this->ruleFilterIsActive($filter)) {
			return $rules;
		}
		$q = isset($filter['q']) ? (string) $filter['q'] : '';
		$out = array();
		foreach ($rules as $r) {
			if (!is_array($r) || !isset($r['number'])) {
				continue;
			}
			$row = $this->normalizeRuleRow($r);
			if ($q !== '') {
				$hay = implode(' ', array(
					(string) $row['number'],
					$row['action_label'],
					$row['proto'],
					$row['port'],
					$row['family'],
					$row['from_label'],
				));
				if (stripos($hay, $q) === false) {
					continue;
				}
			}
			if (isset($filter['number']) && $filter['number'] !== ''
				&& !str_contains((string) $row['number'], (string) $filter['number'])) {
				continue;
			}
			if (isset($filter['action']) && $filter['action'] !== ''
				&& strcasecmp($row['action_label'], (string) $filter['action']) !== 0) {
				continue;
			}
			if (isset($filter['proto']) && $filter['proto'] !== ''
				&& strcasecmp($row['proto'], (string) $filter['proto']) !== 0) {
				continue;
			}
			if (isset($filter['port']) && $filter['port'] !== ''
				&& stripos($row['port'], (string) $filter['port']) === false) {
				continue;
			}
			if (isset($filter['family']) && $filter['family'] !== ''
				&& strcasecmp($row['family'], (string) $filter['family']) !== 0) {
				continue;
			}
			if (isset($filter['from']) && $filter['from'] !== ''
				&& stripos($row['from_label'], (string) $filter['from']) === false) {
				continue;
			}
			$out[] = $r;
		}

		return $out;
	}

	function ufwIconBase()
	{
		$base = '/theme/icon/collage';
		if (function_exists('get_image_path')) {
			try {
				$got = get_image_path();
				if (is_string($got) && $got !== '') {
					$base = rtrim($got, '/');
				}
			} catch (Throwable $e) {
			}
		}

		return $base;
	}

	function showUrl()
	{
		global $ghtml;

		if (is_object($ghtml) && method_exists($ghtml, 'getFullUrl')) {
			try {
				$url = $ghtml->getFullUrl('a=show');
				if (is_string($url) && $url !== '') {
					return $url;
				}
			} catch (Throwable $e) {
			}
		}
		$host = $this->syncServerName();

		return '/display.php?frm_action=show'
			. '&frm_o_o[0][class]=pserver'
			. '&frm_o_o[0][nname]=' . rawurlencode($host)
			. '&frm_o_o[1][class]=ufwfirewall';
	}

	function editRuleUrl($number)
	{
		global $ghtml;

		$n = (int) $number;
		if (is_object($ghtml) && method_exists($ghtml, 'getFullUrl')) {
			try {
				$url = $ghtml->getFullUrl('a=updateform&sa=editrule');
				if (is_string($url) && $url !== '') {
					return $url . '&frm_ufwfirewall_c_ufw_rule=' . rawurlencode((string) $n);
				}
			} catch (Throwable $e) {
			}
		}
		$host = $this->syncServerName();

		return '/display.php?frm_action=updateform&frm_subaction=editrule'
			. '&frm_o_o[0][class]=pserver'
			. '&frm_o_o[0][nname]=' . rawurlencode($host)
			. '&frm_o_o[1][class]=ufwfirewall'
			. '&frm_ufwfirewall_c_ufw_rule=' . rawurlencode((string) $n);
	}

	function printShowHiddenGets()
	{
		global $ghtml;

		echo '<input type="hidden" name="frm_action" value="show">';
		if (is_object($ghtml) && isset($ghtml->__http_vars['frm_o_o'])
			&& is_array($ghtml->__http_vars['frm_o_o'])
			&& method_exists($ghtml, 'print_input_vars')) {
			$ghtml->print_input_vars(array('frm_o_o' => $ghtml->__http_vars['frm_o_o']));

			return;
		}
		$host = $this->syncServerName();
		echo '<input type="hidden" name="frm_o_o[0][class]" value="pserver">';
		echo '<input type="hidden" name="frm_o_o[0][nname]" value="'
			. htmlspecialchars($host, ENT_QUOTES) . '">';
		echo '<input type="hidden" name="frm_o_o[1][class]" value="ufwfirewall">';
	}

	function printActionForm($subaction, $label, $confirm)
	{
		$this->printFormOpen($subaction);
		echo '<input type="hidden" name="frm_ufwfirewall_c_ufw_confirm" value="on">';
		echo '<input type="submit" class="submitbutton" value="&nbsp;&nbsp;'
			. htmlspecialchars((string) $label, ENT_QUOTES) . '&nbsp;&nbsp;"'
			. ' onclick="return confirm(' . htmlspecialchars(json_encode($confirm), ENT_QUOTES) . ')">';
		echo '</form>';
	}

	function printFormOpen($subaction, $inline = true)
	{
		global $ghtml;

		$token = function_exists('getCSRFToken') ? getCSRFToken() : '';
		$style = $inline ? 'display:inline;margin:0' : 'display:block;margin:0';
		echo '<form method="post" action="/display.php" accept-charset="utf-8" style="' . $style . '">';
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
			case 'editrule':
				$ruleNo = 0;
				if (is_array($param) && isset($param['ufw_rule'])) {
					$ruleNo = (int) $param['ufw_rule'];
				} elseif (isset($_GET['frm_ufwfirewall_c_ufw_rule'])) {
					$ruleNo = (int) $_GET['frm_ufwfirewall_c_ufw_rule'];
				} elseif (isset($_REQUEST['frm_ufwfirewall_c_ufw_rule'])) {
					$ruleNo = (int) $_REQUEST['frm_ufwfirewall_c_ufw_rule'];
				}
				$preAction = 'allow';
				$preProto = 'tcp';
				$prePort = '';
				$preFrom = '';
				if ($ruleNo > 0) {
					try {
						$stEdit = $this->ufwStatus();
						$rulesEdit = (isset($stEdit['rules']) && is_array($stEdit['rules']))
							? $stEdit['rules'] : array();
						foreach ($rulesEdit as $rr) {
							if (!is_array($rr) || !isset($rr['number']) || (int) $rr['number'] !== $ruleNo) {
								continue;
							}
							$rowE = $this->normalizeRuleRow($rr);
							$preAction = strtolower((string) $rowE['action_label']);
							if (!in_array($preAction, array('allow', 'deny', 'reject'), true)) {
								$preAction = 'allow';
							}
							$preProto = strtolower((string) $rowE['proto']);
							if ($preProto !== 'udp') {
								$preProto = 'tcp';
							}
							$prePort = (string) $rowE['port'];
							$preFrom = (string) $rowE['from'];
							if (strcasecmp($preFrom, 'Anywhere') === 0) {
								$preFrom = '';
							}
							break;
						}
					} catch (Throwable $e) {
					}
				}
				$this->ufw_rule = $ruleNo > 0 ? (string) $ruleNo : '';
				$this->ufw_action = $preAction;
				$this->ufw_proto = $preProto;
				$this->ufw_port = $prePort;
				$this->ufw_from = $preFrom;
				// Keep rule number in the posted form (separate page from the list).
				$vlist['ufw_rule'] = null;
				$vlist['ufw_action'] = array('s', array(
					'allow' => 'ALLOW', 'deny' => 'DENY', 'reject' => 'REJECT',
				));
				$vlist['ufw_proto'] = array('s', array('tcp' => 'TCP', 'udp' => 'UDP'));
				$vlist['ufw_port'] = null;
				$vlist['ufw_from'] = null;
				$vlist['__v_button'] = 'Update Rule';
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

	function updateEditrule($param)
	{
		$this->assertAdmin();
		$p = is_array($param) ? $param : array();
		$number = isset($p['ufw_rule']) ? (string) $p['ufw_rule'] : '';
		if ($number === '' && isset($_REQUEST['frm_ufwfirewall_c_ufw_rule'])) {
			$number = (string) $_REQUEST['frm_ufwfirewall_c_ufw_rule'];
		}
		$action = isset($p['ufw_action']) ? (string) $p['ufw_action'] : '';
		$proto = isset($p['ufw_proto']) ? (string) $p['ufw_proto'] : 'tcp';
		$portField = isset($p['ufw_port']) ? (string) $p['ufw_port'] : '';
		$from = isset($p['ufw_from']) ? (string) $p['ufw_from'] : '';
		try {
			// Edit replaces one numbered rule — a single port/range only.
			$port = KloxoUfw::validatePort($portField);
			$ret = $this->execUfw('editRule', array($number, $action, $proto, $port, $from));
			$this->throwIfFailed($ret, 'could_not_edit_ufw_rule');
		} catch (InvalidArgumentException $e) {
			$this->throwUfw($e->getMessage(), $portField !== '' ? $portField : $number);
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
			$fam = !empty($r['ipv6']) ? 'IPv6' : 'IPv4';
			$out[$n] = trim($n . ' ' . $to . ' ' . $act . ' ' . $from . ' ' . $fam);
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
