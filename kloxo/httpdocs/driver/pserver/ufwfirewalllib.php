<?php

include_once dirname(__DIR__, 2) . '/lib/php/kloxo_ufw.php';

/**
 * UFW Port Rules list (show) plus Add/Edit on separate updateform pages.
 * Privileged ufw runs via kloxo-backend.
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
	static $__acdesc_update_deleterules = array("", "", "Delete");
	static $__acdesc_update_suspendrule = array("", "", "Suspend Rule");
	static $__acdesc_update_unsuspendrule = array("", "", "Unsuspend Rule");
	static $__desc_ufw_action = array("s", "", "action");
	static $__desc_ufw_proto = array("s", "", "protocol");
	static $__desc_ufw_port = array("", "", "port");
	static $__desc_ufw_from = array("", "", "from_address");
	static $__desc_ufw_comment = array("", "", "comment");
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
		$tabs = array('a=show');
		$sa = '';
		if (isset($_REQUEST['frm_subaction']) && is_scalar($_REQUEST['frm_subaction'])) {
			$sa = strtolower(trim((string) $_REQUEST['frm_subaction']));
		} elseif (isset($_GET['frm_subaction']) && is_scalar($_GET['frm_subaction'])) {
			$sa = strtolower(trim((string) $_GET['frm_subaction']));
		}
		if ($sa === 'addrule') {
			$tabs[] = 'a=updateform&sa=addrule';
		} elseif ($sa === 'editrule') {
			$tabs[] = 'a=updateform&sa=editrule';
		}

		return $tabs;
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

	/** List chrome is showRawPrint; add/edit use updateform pages. */
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

	/**
	 * Inline Font Awesome Free solid SVG (theme/fontawesome/svgs/solid/NAME.svg).
	 */
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

	/** UFW allow/deny/reject shown as ACCEPT/DROP/REJECT. */
	static function actionDisplay($label)
	{
		$u = strtoupper((string) $label);
		static $map = array('ALLOW' => 'ACCEPT', 'DENY' => 'DROP');

		return isset($map[$u]) ? $map[$u] : $u;
	}

	/** True on the Add Rule / Edit pages, which must not render the rule list. */
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

		return in_array(strtolower($sa), array('addrule', 'editrule'), true);
	}

	function showRawPrint($subaction = null)
	{
		global $login;

		if (self::isFormPage()) {
			return;
		}

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
		$rawRules = (isset($st['rules']) && is_array($st['rules'])) ? $st['rules'] : array();
		$logical = KloxoUfw::collapseDualStack($rawRules);
		$suspended = $this->suspendedList();
		$rules = array_merge($logical, $suspended);
		$err = isset($st['error']) ? (string) $st['error'] : '';
		$conflictNote = (isset($st['conflict_note']) && is_string($st['conflict_note']))
			? $st['conflict_note'] : '';
		// Font Awesome Free SVG icons (theme/fontawesome, CC BY 4.0).
		$editIconHtml = self::faIcon('pen-to-square', '#333', 'Edit');
		$delIconHtml = self::faIcon('trash', '#333', 'Delete');
		$suspendIconHtml = self::faIcon('pause', '#c60', 'Suspend (disable in firewall)');
		$unsuspendIconHtml = self::faIcon('play', '#2a2', 'Unsuspend (enable in firewall)');
		$suspendIconMuted = self::faIcon('pause', '#ccc', 'Last allow for panel port 7777/7778');
		$delIconMuted = self::faIcon('trash', '#ccc', 'Last allow for panel port 7777/7778');
		$filter = $this->ruleFilterFromRequest();
		$displayRules = $this->filterRules($rules, $filter);
		$nTotal = count($rules);
		$nShown = count($displayRules);
		$filterActive = $this->ruleFilterIsActive($filter);
		$showHide = $this->ufwKeyword('showhide', 'Show/Hide');
		$showAll = $this->ufwKeyword('showall', 'Show All');
		$under = $this->ufwKeyword('under', 'Under');
		$advId = 'ufw_advsearch';
		$advVisible = $filterActive && ($filter['q'] === '' || $this->ruleFilterHasColumnFields($filter))
			? 'visibility:visible;display:block' : 'visibility:hidden;display:none';
		$lower = $nShown > 0 ? 1 : 0;
		$upper = (int) $nShown;
		$perpageof = $lower . ' to ' . $upper . ' of ';
		$qVal = isset($filter['q']) ? (string) $filter['q'] : '';
		$searchIcon = self::faIcon('magnifying-glass', '#333', 'Search');
		$showallIcon = self::faIcon('list', '#333', $showAll);
		$refreshIcon = self::faIcon('arrows-rotate', '#333', 'Refresh');
		$addIcon = self::faIcon('plus', '#333', 'Add Rule');
		$deleteTbIcon = self::faIcon('trash', '#333', 'Delete');
		$addUrl = $this->addRuleUrl();
		$token = function_exists('getCSRFToken') ? getCSRFToken() : '';

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
			$enableIcon = self::faIcon('shield-halved', '#a00', 'Enable UFW');
			echo '<div class="messagetext" style="color:#a00;margin:8px 25px;text-align:center">'
				. '<p style="margin:0 0 10px">UFW is inactive on this host '
				. '(install normally enables it). Enable now to apply the default '
				. 'panel ports (SSH, HTTP/S, FTP, DNS, mail, 7777/7778) and turn the firewall on.</p>';
			echo '<form method="post" action="/display.php" accept-charset="utf-8"'
				. ' style="display:inline;margin:0" onsubmit="return confirm('
				. htmlspecialchars(json_encode(
					'Enable UFW? Panel ports 7777/7778 plus SSH, HTTP/S, FTP, DNS and mail are allowed first.'
				), ENT_QUOTES) . ')">';
			echo '<input type="hidden" name="frm_token" value="'
				. htmlspecialchars((string) $token, ENT_QUOTES) . '">';
			echo '<input type="hidden" name="frm_action" value="update">';
			echo '<input type="hidden" name="frm_subaction" value="ufwenable">';
			$this->printObjectPathInputs();
			echo '<button type="submit" class="submitbutton" title="Enable UFW"'
				. ' style="cursor:pointer;padding:6px 14px;vertical-align:middle">'
				. ($enableIcon !== '' ? $enableIcon . ' ' : '')
				. 'Enable UFW</button>';
			echo '</form></div>';
		}
		if ($conflictNote !== '') {
			echo '<p class="messagetext" style="color:#a00;margin:8px 25px;text-align:center">'
				. htmlspecialchars($conflictNote, ENT_QUOTES) . '</p>';
		}

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
			. '" title="Refresh" style="text-decoration:none;margin-right:14px;display:inline-block;text-align:center">'
			. $refreshIcon . '<br><span class="small">Refresh</span></a>';
		echo '<a href="' . htmlspecialchars($addUrl, ENT_QUOTES)
			. '" title="Add Rule" style="text-decoration:none;margin-right:14px;display:inline-block;text-align:center">'
			. $addIcon . '<br><span class="small">Add Rule</span></a>';
		echo '<a href="javascript:void(0)" title="Delete" style="text-decoration:none;display:inline-block;text-align:center"'
			. ' onclick="return kloxoUfwDeleteSelected();">'
			. $deleteTbIcon . '<br><span class="small">Delete</span></a>';
		echo '<script type="text/javascript">function kloxoUfwDeleteSelected(){'
			. 'var c=document.querySelectorAll("input.ufw-row-check:checked");'
			. 'if(!c.length){alert("Select one or more rules to delete.");return false;}'
			. 'if(!confirm("Delete selected rules?")){return false;}'
			. 'var f=document.getElementById("ufw_multi_delete_form");'
			. 'if(!f){return false;}'
			. 'var old=f.querySelectorAll("input[name=\'frm_ufwfirewall_c_ufw_rules[]\']");'
			. 'for(var i=0;i<old.length;i++){old[i].parentNode.removeChild(old[i]);}'
			. 'for(var j=0;j<c.length;j++){var h=document.createElement("input");'
			. 'h.type="hidden";h.name="frm_ufwfirewall_c_ufw_rules[]";h.value=c[j].value;f.appendChild(h);}'
			. 'f.submit();return false;}</script>';
		echo '<form id="ufw_multi_delete_form" method="post" action="/display.php" accept-charset="utf-8" style="display:none">';
		echo '<input type="hidden" name="frm_token" value="' . htmlspecialchars((string) $token, ENT_QUOTES) . '">';
		echo '<input type="hidden" name="frm_action" value="update">';
		echo '<input type="hidden" name="frm_subaction" value="deleterules">';
		$this->printObjectPathInputs();
		echo '</form>';
		echo '</td><td align="right" valign="middle">';
		echo '<form name="ufw_lpform_search" method="get" action="/display.php" accept-charset="utf-8"'
			. ' style="display:inline" onsubmit="return true;">';
		$this->printShowHiddenGets();
		foreach (array('number', 'action', 'proto', 'port', 'from', 'comment') as $fk) {
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
			'from' => array('label' => 'From', 'type' => 'text'),
			'comment' => array('label' => 'Comment', 'type' => 'text'),
		);
		foreach ($cols as $key => $meta) {
			$val = isset($filter[$key]) ? (string) $filter[$key] : '';
			echo '<td nowrap><span style="font-weight:bold">'
				. htmlspecialchars($meta['label'], ENT_QUOTES) . '</span><br>';
			if ($meta['type'] === 'action') {
				echo '<select class="searchbox" name="frm_ufw_filter[action]"><option value="">—</option>';
				foreach (array('ALLOW', 'DENY', 'REJECT', 'LIMIT') as $a) {
					$sel = (strcasecmp($val, $a) === 0) ? ' selected' : '';
					echo '<option value="' . $a . '"' . $sel . '>' . self::actionDisplay($a) . '</option>';
				}
				echo '</select>';
			} elseif ($meta['type'] === 'proto') {
				echo '<select class="searchbox" name="frm_ufw_filter[proto]"><option value="">—</option>';
				foreach (array('TCP', 'UDP', 'ICMP', 'any') as $a) {
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
		echo '<table cellspacing="1" cellpadding="3" width="100%" align="center" border="0" id="ufw_rules_table">';
		echo '<tr height="25" valign="middle">';
		echo '<td class="collist" nowrap align="center" style="background:#'
			. htmlspecialchars($skin, ENT_QUOTES)
			. ';text-align:center;width:28px"><input type="checkbox" title="Select all"'
			. ' onclick="var b=this.checked;'
			. 'var n=document.querySelectorAll(\'#ufw_rules_table input.ufw-row-check\');'
			. 'for(var i=0;i<n.length;i++){n[i].checked=b;}"></td>';
		foreach (array('#', 'Action', 'Protocol', 'Port', 'From', 'Comment') as $h) {
			echo '<td class="collist" nowrap style="background:#'
				. htmlspecialchars($skin, ENT_QUOTES) . '"><b>'
				. htmlspecialchars($h, ENT_QUOTES) . '</b></td>';
		}
		// Last column: icons only — no header title, narrow width.
		echo '<td class="collist" nowrap align="center" style="background:#'
			. htmlspecialchars($skin, ENT_QUOTES)
			. ';text-align:center;width:72px;max-width:72px;padding:2px 4px">&nbsp;</td>';
		echo '</tr>';

		if ($displayRules === array()) {
			$emptyMsg = $filterActive
				? 'No matches found'
				: ('No Port Rules ' . $under . ' ' . $host);
			echo '<tr class="tablerow0"><td class="col" colspan="8" style="text-align:center;padding:12px">'
				. '<b>' . htmlspecialchars($emptyMsg, ENT_QUOTES) . '</b></td></tr>';
		} else {
			$i = 0;
			foreach ($displayRules as $r) {
				if (!is_array($r)) {
					continue;
				}
				$rowN = $this->normalizeRuleRow($r);
				$i++;
				$count = ($i % 2 === 0) ? 1 : 0;
				$isSusp = !empty($rowN['suspended']);
				$selId = (string) $rowN['id'];
				$canDel = true;
				if (!$isSusp) {
					try {
						$nums = isset($r['numbers']) && is_array($r['numbers'])
							? $r['numbers'] : array((int) $rowN['number']);
						$canDel = KloxoUfw::canDeleteNumbers($rawRules, $nums);
					} catch (Throwable $e) {
						$canDel = true;
					}
				}
				$editUrl = $this->editRuleUrl($isSusp ? $selId : (int) $rowN['number']);
				$numLabel = $isSusp ? '—' : (string) $rowN['number'];
				$actHtml = '';
				if ($isSusp) {
					$actHtml .= '<span style="color:#c00;font-weight:bold;margin-right:4px" title="Suspended">&#9679;</span>';
				}
				$actHtml .= htmlspecialchars(self::actionDisplay($rowN['action_label']), ENT_QUOTES);

				echo '<tr height="22" class="tablerow' . $count . '">';
				echo '<td class="col" align="center"><input type="checkbox" class="ufw-row-check" value="'
					. htmlspecialchars($selId, ENT_QUOTES) . '"'
					. ($canDel || $isSusp ? '' : ' disabled title="Protected panel port"') . '></td>';
				echo '<td class="col">' . htmlspecialchars($numLabel, ENT_QUOTES) . '</td>';
				echo '<td class="col">' . $actHtml . '</td>';
				echo '<td class="col">' . htmlspecialchars($rowN['proto'], ENT_QUOTES) . '</td>';
				echo '<td class="col">' . htmlspecialchars($rowN['port'] !== '' ? $rowN['port'] : '—', ENT_QUOTES) . '</td>';
				echo '<td class="col">' . htmlspecialchars($rowN['from_label'], ENT_QUOTES) . '</td>';
				echo '<td class="col">' . htmlspecialchars($rowN['comment'], ENT_QUOTES) . '</td>';
				echo '<td class="col" align="center" style="white-space:nowrap;width:72px;max-width:72px;padding:2px 4px">';

				echo '<a href="' . htmlspecialchars($editUrl, ENT_QUOTES)
					. '" title="Edit" style="text-decoration:none;margin:0 2px;vertical-align:middle">'
					. $editIconHtml . '</a>';

				if ($isSusp) {
					$this->printInlineAction('unsuspendrule', $selId, $unsuspendIconHtml, 'Unsuspend',
						'Unsuspend this rule?');
				} elseif ($canDel) {
					$this->printInlineAction('suspendrule', (string) $rowN['number'], $suspendIconHtml, 'Suspend',
						'Suspend rule #' . $rowN['number'] . '?');
				} else {
					echo '<span style="margin:0 2px;vertical-align:middle">' . $suspendIconMuted . '</span>';
				}

				if ($canDel || $isSusp) {
					$this->printInlineAction(
						'deleterule',
						$isSusp ? $selId : (string) $rowN['number'],
						$delIconHtml,
						'Delete',
						$isSusp ? 'Delete suspended rule?' : ('Delete rule #' . $rowN['number'] . '?')
					);
				} else {
					echo '<span style="margin:0 2px;vertical-align:middle">' . $delIconMuted . '</span>';
				}
				echo '</td></tr>';
			}
		}
		echo '</table>';

		echo '</div>';
	}

	/**
	 * Tiny POST form for a single-row action icon (if16 HTML glyph).
	 */
	function printInlineAction($subaction, $ruleId, $iconHtml, $title, $confirm)
	{
		$token = function_exists('getCSRFToken') ? getCSRFToken() : '';
		echo '<form method="post" action="/display.php" accept-charset="utf-8"'
			. ' style="display:inline;margin:0;padding:0">';
		echo '<input type="hidden" name="frm_token" value="' . htmlspecialchars((string) $token, ENT_QUOTES) . '">';
		echo '<input type="hidden" name="frm_action" value="update">';
		echo '<input type="hidden" name="frm_subaction" value="'
			. htmlspecialchars((string) $subaction, ENT_QUOTES) . '">';
		$this->printObjectPathInputs();
		echo '<input type="hidden" name="frm_ufwfirewall_c_ufw_rule" value="'
			. htmlspecialchars((string) $ruleId, ENT_QUOTES) . '">';
		echo '<button type="submit" title="' . htmlspecialchars((string) $title, ENT_QUOTES) . '"'
			. ' style="border:0;background:transparent;cursor:pointer;padding:0;margin:0 2px;vertical-align:middle;line-height:1"'
			. ' onclick="return confirm(' . htmlspecialchars(json_encode((string) $confirm), ENT_QUOTES) . ')">'
			. $iconHtml . '</button>';
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
		echo '<input type="hidden" name="frm_o_o[0][nname]" value="'
			. htmlspecialchars($host, ENT_QUOTES) . '">';
		echo '<input type="hidden" name="frm_o_o[1][class]" value="ufwfirewall">';
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
	 * @param array $r
	 * @return array{number:int,id:string,action_label:string,proto:string,port:string,from:string,from_label:string,comment:string,suspended:bool}
	 */
	function normalizeRuleRow($r)
	{
		$suspended = !empty($r['suspended']);
		$to = isset($r['to']) ? (string) $r['to'] : '';
		$actRaw = isset($r['action']) ? (string) $r['action'] : '';
		$from = isset($r['from']) ? (string) $r['from'] : '';
		$comment = isset($r['comment']) ? (string) $r['comment'] : '';
		$parsed = KloxoUfw::parseRuleTo($to);
		$port = $parsed['port'];
		$proto = $parsed['proto'] !== '' ? strtoupper($parsed['proto']) : 'any';
		if ($suspended) {
			if (isset($r['proto']) && is_string($r['proto']) && $r['proto'] !== '') {
				$proto = strtoupper((string) $r['proto']);
			}
			if (isset($r['port'])) {
				$port = (string) $r['port'];
			}
		}
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
		// Default install rules created before comments existed: show their purpose.
		if (trim($comment) === '' && $actLabel === 'ALLOW'
			&& ($from === '' || strcasecmp($from, 'Anywhere') === 0)
			&& preg_match('/^\d+$/', (string) $port) === 1) {
			$comment = KloxoUfw::defaultComment((string) $port, $proto);
		}
		$id = isset($r['id']) && is_string($r['id']) && $r['id'] !== ''
			? $r['id']
			: ('n:' . (isset($r['number']) ? (string) (int) $r['number'] : '0'));

		return array(
			'number' => isset($r['number']) ? (int) $r['number'] : 0,
			'id' => $id,
			'action_label' => $actLabel,
			'proto' => $proto,
			'port' => $port,
			'from' => $from,
			'from_label' => ($from !== '' && strcasecmp($from, 'Anywhere') !== 0) ? $from : 'Anywhere',
			'comment' => $comment,
			'suspended' => $suspended,
		);
	}

	/**
	 * @return array{q:string,number:string,action:string,proto:string,port:string,from:string,comment:string}
	 */
	function ruleFilterFromRequest()
	{
		$keys = array('q', 'number', 'action', 'proto', 'port', 'from', 'comment');
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

	function ruleFilterIsActive($filter)
	{
		foreach ($filter as $v) {
			if (is_string($v) && $v !== '') {
				return true;
			}
		}

		return false;
	}

	function ruleFilterHasColumnFields($filter)
	{
		foreach (array('number', 'action', 'proto', 'port', 'from', 'comment') as $k) {
			if (isset($filter[$k]) && is_string($filter[$k]) && $filter[$k] !== '') {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<array> $rules
	 * @param array $filter
	 * @return list<array>
	 */
	function filterRules($rules, $filter)
	{
		if (!$this->ruleFilterIsActive($filter)) {
			return $rules;
		}
		$q = isset($filter['q']) ? (string) $filter['q'] : '';
		$out = array();
		foreach ($rules as $r) {
			if (!is_array($r)) {
				continue;
			}
			$row = $this->normalizeRuleRow($r);
			if ($q !== '') {
				$hay = implode(' ', array(
					(string) $row['number'],
					$row['action_label'],
					$row['proto'],
					$row['port'],
					$row['from_label'],
					$row['comment'],
					!empty($row['suspended']) ? 'suspended' : '',
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
			if (isset($filter['from']) && $filter['from'] !== ''
				&& stripos($row['from_label'], (string) $filter['from']) === false) {
				continue;
			}
			if (isset($filter['comment']) && $filter['comment'] !== ''
				&& stripos($row['comment'], (string) $filter['comment']) === false) {
				continue;
			}
			$out[] = $r;
		}

		return $out;
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

	function addRuleUrl()
	{
		global $ghtml;

		if (is_object($ghtml) && method_exists($ghtml, 'getFullUrl')) {
			try {
				$url = $ghtml->getFullUrl('a=updateform&sa=addrule');
				if (is_string($url) && $url !== '') {
					return $url;
				}
			} catch (Throwable $e) {
			}
		}
		$host = $this->syncServerName();

		return '/display.php?frm_action=updateform&frm_subaction=addrule'
			. '&frm_o_o[0][class]=pserver'
			. '&frm_o_o[0][nname]=' . rawurlencode($host)
			. '&frm_o_o[1][class]=ufwfirewall';
	}

	function editRuleUrl($number)
	{
		global $ghtml;

		$n = (is_string($number) && preg_match('/^s:[0-9a-f.]+$/i', $number) === 1)
			? $number : (string) (int) $number;
		if (is_object($ghtml) && method_exists($ghtml, 'getFullUrl')) {
			try {
				$url = $ghtml->getFullUrl('a=updateform&sa=editrule');
				if (is_string($url) && $url !== '') {
					return $url . '&frm_ufwfirewall_c_ufw_rule=' . rawurlencode($n);
				}
			} catch (Throwable $e) {
			}
		}
		$host = $this->syncServerName();

		return '/display.php?frm_action=updateform&frm_subaction=editrule'
			. '&frm_o_o[0][class]=pserver'
			. '&frm_o_o[0][nname]=' . rawurlencode($host)
			. '&frm_o_o[1][class]=ufwfirewall'
			. '&frm_ufwfirewall_c_ufw_rule=' . rawurlencode($n);
	}

	function printShowHiddenGets()
	{
		echo '<input type="hidden" name="frm_action" value="show">';
		$this->printObjectPathInputs();
	}

	function printFormOpen($subaction, $inline = true)
	{
		$token = function_exists('getCSRFToken') ? getCSRFToken() : '';
		$style = $inline ? 'display:inline;margin:0' : 'display:block;margin:0';
		echo '<form method="post" action="/display.php" accept-charset="utf-8" style="' . $style . '">';
		echo '<input type="hidden" name="frm_token" value="' . htmlspecialchars((string) $token, ENT_QUOTES) . '">';
		echo '<input type="hidden" name="frm_action" value="update">';
		echo '<input type="hidden" name="frm_subaction" value="'
			. htmlspecialchars((string) $subaction, ENT_QUOTES) . '">';
		$this->printObjectPathInputs();
	}

	function updateform($subaction, $param)
	{
		try {
			$this->assertAdmin();
		} catch (Throwable $e) {
		}

		$policies = array('allow' => 'allow', 'deny' => 'deny', 'reject' => 'reject');
		// First entry is the default (DROP = ufw deny).
		$actions = array('deny' => 'DROP', 'allow' => 'ACCEPT', 'reject' => 'REJECT');
		$protos = array('tcp' => 'TCP', 'udp' => 'UDP', 'icmp' => 'ICMP');
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
				$this->ufw_action = 'deny';
				$this->ufw_proto = 'tcp';
				$this->ufw_port = '';
				$this->ufw_from = '';
				$this->ufw_comment = '';
				$vlist['ufw_action'] = array('A', $actions);
				$vlist['ufw_proto'] = array('A', $protos);
				$vlist['ufw_port'] = null;
				$vlist['ufw_from'] = null;
				$vlist['ufw_comment'] = null;
				$vlist['__v_button'] = 'Add Rule';
				return $vlist;
			case 'editrule':
				$ruleId = $this->requestedRuleId($param);
				$preAction = 'deny';
				$preProto = 'tcp';
				$prePort = '';
				$preFrom = '';
				$preComment = '';
				if ($ruleId !== '') {
					try {
						$row = $this->findEditableRule($ruleId);
						if (is_array($row)) {
							$rowE = $this->normalizeRuleRow($row);
							$preAction = strtolower((string) $rowE['action_label']);
							if (!in_array($preAction, array('allow', 'deny', 'reject'), true)) {
								$preAction = 'deny';
							}
							$preProto = strtolower((string) $rowE['proto']);
							if (!in_array($preProto, array('tcp', 'udp', 'icmp'), true)) {
								$preProto = 'tcp';
							}
							$prePort = (string) $rowE['port'];
							if (!preg_match('/^\d[\d:,]*$/', $prePort)) {
								$prePort = '';
							}
							$preFrom = (string) $rowE['from'];
							if (strcasecmp($preFrom, 'Anywhere') === 0) {
								$preFrom = '';
							}
							$preComment = (string) $rowE['comment'];
						}
					} catch (Throwable $e) {
					}
				}
				$this->ufw_action = $preAction;
				$this->ufw_proto = $preProto;
				$this->ufw_port = $prePort;
				$this->ufw_from = $preFrom;
				$this->ufw_comment = $preComment;
				// Rule number is carried as a hidden field, not shown.
				if ($ruleId !== '') {
					$vlist['__v_param'] = array('ufw_rule' => $ruleId);
				}
				$vlist['ufw_action'] = array('A', $actions);
				$vlist['ufw_proto'] = array('A', $protos);
				$vlist['ufw_port'] = null;
				$vlist['ufw_from'] = null;
				$vlist['ufw_comment'] = null;
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
					: array('A', $choices);
				$vlist['__v_button'] = $choices === array() ? array() : 'Delete Rule';
				return $vlist;
		}

		return $vlist;
	}

	/** Rule id (live number or s:… suspended id) from param/GET/REQUEST. */
	function requestedRuleId($param = null)
	{
		$raw = '';
		if (is_array($param) && isset($param['ufw_rule']) && is_scalar($param['ufw_rule'])) {
			$raw = (string) $param['ufw_rule'];
		} elseif (isset($_REQUEST['frm_ufwfirewall_c_ufw_rule']) && is_scalar($_REQUEST['frm_ufwfirewall_c_ufw_rule'])) {
			$raw = (string) $_REQUEST['frm_ufwfirewall_c_ufw_rule'];
		}
		$raw = trim($raw);
		if (preg_match('/^[1-9]\d{0,3}$/', $raw) === 1 || preg_match('/^s:[0-9a-f.]+$/i', $raw) === 1) {
			return $raw;
		}

		return '';
	}

	/** Logical live rule (by number) or suspended record (by s:id). */
	function findEditableRule($ruleId)
	{
		if (str_starts_with($ruleId, 's:')) {
			foreach ($this->suspendedList() as $s) {
				if ($s['id'] === $ruleId) {
					return $s;
				}
			}

			return null;
		}
		$st = $this->ufwStatus();
		$rules = (isset($st['rules']) && is_array($st['rules'])) ? $st['rules'] : array();

		return KloxoUfw::findLogicalRule($rules, (int) $ruleId);
	}

	/** Human text for a rule (ACCEPT 443/tcp from Anywhere, comment: web). */
	function ruleSummary($action, $proto, $port, $from, $comment = '')
	{
		$a = self::actionDisplay($action);
		$proto = strtolower((string) $proto);
		$to = ($proto === 'icmp') ? 'ICMP' : (((string) $port !== '' ? $port : 'any port') . ($proto !== '' ? '/' . $proto : ''));
		$f = trim((string) $from);
		if ($f === '' || strcasecmp($f, 'any') === 0) {
			$f = 'Anywhere';
		}
		$out = $a . ' ' . $to . ' from ' . $f;
		if (trim((string) $comment) !== '') {
			$out .= ', comment: ' . trim((string) $comment);
		}

		return $out;
	}

	/** Summary of an existing live rule number or suspended id, '' if unknown. */
	function describeRuleId($ruleId)
	{
		try {
			$row = $this->findEditableRule((string) $ruleId);
			if (!is_array($row)) {
				return '';
			}
			$n = $this->normalizeRuleRow($row);
			$port = (string) $n['port'];

			return $this->ruleSummary($n['action_label'], $n['proto'], $port, $n['from'], $n['comment']);
		} catch (Throwable $e) {
			return '';
		}
	}

	function redirectToShow($msgKey = 'updated_successfully', $data = '')
	{
		global $gbl, $ghtml;

		if (!is_object($gbl)) {
			return;
		}

		$msgKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $msgKey));
		$tail = '&frm_smessage=' . $msgKey;
		if ((string) $data !== '') {
			// The value is placed into HTML by the message printer: escape it first.
			$tail .= '&frm_m_smessage_data=' . rawurlencode(htmlspecialchars((string) $data, ENT_QUOTES));
		}
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
		$this->redirectToShow('ufw_enabled');

		return null;
	}

	function updateUfwdisable($param)
	{
		$this->assertAdmin();
		$ret = $this->execUfw('disable', array());
		$this->throwIfFailed($ret, 'could_not_disable_ufw');
		$this->redirectToShow('ufw_disabled');

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
		$this->redirectToShow('ufw_defaults_set', 'incoming ' . self::actionDisplay($incoming)
			. ', outgoing ' . self::actionDisplay($outgoing));

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
		$comment = isset($p['ufw_comment']) ? (string) $p['ufw_comment'] : '';
		try {
			if (strtolower($proto) === 'icmp') {
				$ret = $this->execUfw('addRule', array($action, 'icmp', '', $from, $comment));
				$this->throwIfFailed($ret, 'could_not_add_ufw_rule');
			} else {
				$ports = KloxoUfw::splitPorts($portField);
				foreach ($ports as $port) {
					$last = $this->execUfw('addRule', array($action, $proto, $port, $from, $comment));
					$this->throwIfFailed($last, 'could_not_add_ufw_rule');
				}
			}
		} catch (InvalidArgumentException $e) {
			$this->throwUfw($e->getMessage(), $portField);
		}
		$shownPort = strtolower($proto) === 'icmp' ? '' : implode(',', KloxoUfw::splitPorts($portField));
		$this->redirectToShow('ufw_rule_added',
			$this->ruleSummary($action, $proto, $shownPort, $from, $comment));

		return null;
	}

	function updateEditrule($param)
	{
		$this->assertAdmin();
		$p = is_array($param) ? $param : array();
		$number = $this->requestedRuleId($p);
		$action = isset($p['ufw_action']) ? (string) $p['ufw_action'] : '';
		$proto = isset($p['ufw_proto']) ? (string) $p['ufw_proto'] : 'tcp';
		$portField = isset($p['ufw_port']) ? (string) $p['ufw_port'] : '';
		$from = isset($p['ufw_from']) ? (string) $p['ufw_from'] : '';
		$comment = isset($p['ufw_comment']) ? (string) $p['ufw_comment'] : '';
		try {
			if ($number === '') {
				throw new InvalidArgumentException('invalid_ufw_rule');
			}
			// Applied to the firewall immediately (suspended rules only update the store).
			if (str_starts_with($number, 's:')) {
				$ret = $this->execUfw('editSuspended', array($number, $action, $proto, $portField, $from, $comment));
			} else {
				$ret = $this->execUfw('editRule', array($number, $action, $proto, $portField, $from, $comment));
			}
			$this->throwIfFailed($ret, 'could_not_edit_ufw_rule');
		} catch (InvalidArgumentException $e) {
			$this->throwUfw($e->getMessage(), $portField !== '' ? $portField : $number);
		}
		$shownPort = strtolower($proto) === 'icmp' ? '' : implode(',', KloxoUfw::splitPorts($portField));
		$this->redirectToShow(str_starts_with($number, 's:') ? 'ufw_rule_updated_suspended' : 'ufw_rule_updated',
			$this->ruleSummary($action, $proto, $shownPort, $from, $comment));

		return null;
	}

	function updateDeleterule($param)
	{
		$this->assertAdmin();
		$p = is_array($param) ? $param : array();
		$number = isset($p['ufw_rule']) ? (string) $p['ufw_rule'] : '';
		if ($number === '' && isset($_REQUEST['frm_ufwfirewall_c_ufw_rule'])) {
			$number = (string) $_REQUEST['frm_ufwfirewall_c_ufw_rule'];
		}
		$what = $this->describeRuleId($number);
		try {
			if (str_starts_with($number, 's:')) {
				$ret = $this->execUfw('deleteSuspended', array($number));
			} else {
				$ret = $this->execUfw('deleteRule', array($number));
			}
		} catch (InvalidArgumentException $e) {
			$this->throwUfw($e->getMessage(), $number);
		}
		$this->throwIfFailed($ret, 'could_not_delete_ufw_rule');
		$this->redirectToShow('ufw_rule_deleted', $what !== '' ? $what : ('#' . $number));

		return null;
	}

	function updateDeleterules($param)
	{
		$this->assertAdmin();
		$ids = array();
		if (isset($_REQUEST['frm_ufwfirewall_c_ufw_rules']) && is_array($_REQUEST['frm_ufwfirewall_c_ufw_rules'])) {
			$ids = $_REQUEST['frm_ufwfirewall_c_ufw_rules'];
		} elseif (is_array($param) && isset($param['ufw_rules']) && is_array($param['ufw_rules'])) {
			$ids = $param['ufw_rules'];
		}
		$liveNums = array();
		$suspIds = array();
		foreach ($ids as $id) {
			if (!is_scalar($id)) {
				continue;
			}
			$id = trim((string) $id);
			if ($id === '') {
				continue;
			}
			if (str_starts_with($id, 's:')) {
				$suspIds[] = $id;
			} elseif (str_starts_with($id, 'n:')) {
				$parts = explode(',', substr($id, 2));
				foreach ($parts as $p) {
					if (preg_match('/^\d+$/', trim($p)) === 1) {
						$liveNums[] = (int) trim($p);
					}
				}
			} elseif (preg_match('/^\d+$/', $id) === 1) {
				$liveNums[] = (int) $id;
			}
		}
		$whats = array();
		foreach ($ids as $rawId) {
			if (!is_scalar($rawId)) {
				continue;
			}
			$rawId = trim((string) $rawId);
			$lookup = $rawId;
			if (str_starts_with($rawId, 'n:')) {
				$lookup = explode(',', substr($rawId, 2))[0];
			}
			$d = $this->describeRuleId($lookup);
			if ($d !== '') {
				$whats[] = $d;
			}
		}
		try {
			if ($liveNums !== array()) {
				$ret = $this->execUfw('deleteRules', array($liveNums));
				$this->throwIfFailed($ret, 'could_not_delete_ufw_rule');
			}
			foreach ($suspIds as $sid) {
				$ret = $this->execUfw('deleteSuspended', array($sid));
				$this->throwIfFailed($ret, 'could_not_delete_ufw_rule');
			}
		} catch (InvalidArgumentException $e) {
			$this->throwUfw($e->getMessage());
		}
		if ($liveNums === array() && $suspIds === array()) {
			$this->throwUfw('invalid_ufw_rule');
		}
		$shown = array_slice($whats, 0, 5);
		$txt = count($whats) . ' deleted' . ($shown !== array() ? ': ' . implode('; ', $shown) : '');
		if (count($whats) > 5) {
			$txt .= '; and ' . (count($whats) - 5) . ' more';
		}
		$this->redirectToShow('ufw_rules_deleted', $txt);

		return null;
	}

	function updateSuspendrule($param)
	{
		$this->assertAdmin();
		$p = is_array($param) ? $param : array();
		$number = isset($p['ufw_rule']) ? (string) $p['ufw_rule'] : '';
		if ($number === '' && isset($_REQUEST['frm_ufwfirewall_c_ufw_rule'])) {
			$number = (string) $_REQUEST['frm_ufwfirewall_c_ufw_rule'];
		}
		$what = $this->describeRuleId($number);
		try {
			$ret = $this->execUfw('suspendRule', array($number));
		} catch (InvalidArgumentException $e) {
			$this->throwUfw($e->getMessage(), $number);
		}
		$this->throwIfFailed($ret, 'could_not_suspend_ufw_rule');
		$this->redirectToShow('ufw_rule_suspended', $what !== '' ? $what : ('#' . $number));

		return null;
	}

	function updateUnsuspendrule($param)
	{
		$this->assertAdmin();
		$p = is_array($param) ? $param : array();
		$id = isset($p['ufw_rule']) ? (string) $p['ufw_rule'] : '';
		if ($id === '' && isset($_REQUEST['frm_ufwfirewall_c_ufw_rule'])) {
			$id = (string) $_REQUEST['frm_ufwfirewall_c_ufw_rule'];
		}
		$what = $this->describeRuleId($id);
		try {
			$ret = $this->execUfw('unsuspendRule', array($id));
		} catch (InvalidArgumentException $e) {
			$this->throwUfw($e->getMessage(), $id);
		}
		$this->throwIfFailed($ret, 'could_not_unsuspend_ufw_rule');
		$this->redirectToShow('ufw_rule_unsuspended', $what !== '' ? $what : $id);

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

	/**
	 * Suspended (disabled) rules. The store is written by the root backend, so
	 * it is read there too: the panel user (lxlabs) cannot read a root-owned
	 * file, and the rows would silently vanish from the list.
	 */
	function suspendedList()
	{
		try {
			$got = $this->execUfw('listSuspended', array());
		} catch (Throwable $e) {
			return array();
		}
		$out = array();
		if (is_array($got)) {
			foreach ($got as $row) {
				if (is_array($row) && isset($row['id']) && is_string($row['id']) && str_starts_with($row['id'], 's:')) {
					$out[] = $row;
				}
			}
		}

		return $out;
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
		foreach (KloxoUfw::collapseDualStack($st['rules']) as $r) {
			if (!is_array($r) || !isset($r['number'])) {
				continue;
			}
			$n = (string) $r['number'];
			$to = isset($r['to']) ? (string) $r['to'] : '';
			$act = isset($r['action']) ? (string) $r['action'] : '';
			$from = isset($r['from']) ? (string) $r['from'] : '';
			$c = isset($r['comment']) ? (string) $r['comment'] : '';
			$out[$n] = trim($n . ' ' . $to . ' ' . $act . ' ' . $from . ($c !== '' ? ' # ' . $c : ''));
		}

		return $out;
	}

	/**
	 * Run a KloxoUfw method as root (kloxo-backend) when the panel is not root.
	 * Never fall back to in-process as lxlabs: ufw needs root. php-cli
	 * (tests / operator scripts) still runs in-process.
	 */
	function execUfw($method, $args)
	{
		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_ufw.php';
		$args = is_array($args) ? $args : array();
		$euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : 0;
		$panel = function_exists('php_sapi_name') ? php_sapi_name() : 'cli';
		$useBackend = $euid !== 0 && $panel !== 'cli' && function_exists('rl_exec_get');
		if ($useBackend) {
			$fail = array(
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
			try {
				// 5th arg false: no in-process fallback as the panel user.
				$got = rl_exec_get(null, $this->syncServerName(), array('KloxoUfw', $method), $args, false);
				if (is_array($got)) {
					return $got;
				}
			} catch (Throwable $e) {
				$msg = trim((string) $e->getMessage());
				if ($msg !== '') {
					$fail['error'] = 'ufw_backend_failed: ' . $msg;
				}
			}

			return $fail;
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
