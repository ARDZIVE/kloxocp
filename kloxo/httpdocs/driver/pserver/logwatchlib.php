<?php

include_once dirname(__DIR__, 2) . '/lib/php/kloxo_logwatch.php';

/**
 * Security > LogWatch. Configure schedule, mail, detail and report retention.
 * Privileged work runs as root through kloxo-backend (KloxoLogwatch).
 */
class logwatch extends lxclass
{
	static $__desc = array("", "", "LogWatch");
	static $__desc_nname = array("", "", "LogWatch");
	static $__acdesc_show = array("", "", "LogWatch");
	static $__acdesc_update_update = array("", "", "LogWatch");
	static $__acdesc_update_runnow = array("", "", "Run Now");

	static $__desc_lw_info = array("M", "", "LogWatch");
	static $__desc_lw_enabled = array("f", "", "enable_logwatch");
	static $__desc_lw_mailto = array("", "", "to_email");
	static $__desc_lw_mailfrom = array("", "", "from_email");
	static $__desc_lw_detail = array("s", "", "detail_level");
	static $__desc_lw_range = array("s", "", "report_range");
	static $__desc_lw_format = array("s", "", "report_format");
	static $__desc_lw_archives = array("f", "", "include_archives");
	static $__desc_lw_frequency = array("s", "", "frequency");
	static $__desc_lw_hour = array("s", "", "hour");
	static $__desc_lw_minute = array("s", "", "minute");
	static $__desc_lw_keep_days = array("", "", "keep_report_days");
	static $__desc_lw_save_reports = array("f", "", "save_reports_on_disk");
	static $__desc_lw_mail_reports = array("f", "", "mail_reports");

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

	static function logwatchPropertyTabs()
	{
		return array('a=show');
	}

	function createShowPropertyList(&$alist)
	{
		$alist['property'] = self::logwatchPropertyTabs();

		return $alist;
	}

	function createShowUpdateform()
	{
		return array('update' => null);
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
			echo '<div class="kloxo-logwatch"><p class="messagetext">Only admin can access LogWatch.</p></div>';

			return;
		}

		$st = $this->execLw('status', array());
		$cfg = (isset($st['config']) && is_array($st['config'])) ? $st['config'] : array();
		$reports = (isset($st['reports']) && is_array($st['reports'])) ? $st['reports'] : array();
		$host = $this->syncServerName();
		$h = static function ($v) {
			return htmlspecialchars((string) $v, ENT_QUOTES);
		};
		$dir = KloxoLogwatch::REPORT_DIR;

		echo '<div class="kloxo-logwatch" style="margin:8px 16px">';
		echo '<p class="messagetext">LogWatch summarises system logs on a schedule and can email the report. '
			. 'Saved reports are kept under <code>' . $h($dir) . '</code> and appear in '
			. '<strong>Log Manager → LogWatch</strong> (Server section). '
			. 'Open that page, choose application <em>LogWatch</em>, then pick a dated report file.</p>';

		if (empty($st['installed'])) {
			echo '<p class="messagetext" style="color:#a00">The <code>logwatch</code> package is not installed on '
				. $h($host) . '. Install it with apt, then reopen this page (or re-run the Kloxo installer).</p>';
		} else {
			$on = !empty($st['enabled']) ? 'enabled' : 'disabled';
			$freq = $h($cfg['frequency'] ?? 'daily');
			$when = sprintf('%02d:%02d', (int) ($cfg['hour'] ?? 6), (int) ($cfg['minute'] ?? 0));
			echo '<p class="messagetext">Status on ' . $h($host) . ': <strong>' . $on . '</strong>, '
				. 'frequency <strong>' . $freq . '</strong> at <strong>' . $when . '</strong>, '
				. 'keep <strong>' . (int) ($cfg['keep_days'] ?? 14) . '</strong> days of saved reports '
				. '(' . count($reports) . ' on disk).</p>';
		}

		if ($reports !== array()) {
			echo '<p class="messagetext" style="margin-top:8px"><strong>Recent reports</strong></p><ul>';
			$i = 0;
			foreach ($reports as $r) {
				if ($i++ >= 8) {
					break;
				}
				if (!is_array($r)) {
					continue;
				}
				echo '<li><code>' . $h($r['name'] ?? '') . '</code> '
					. '(' . (int) ($r['size'] ?? 0) . ' bytes)</li>';
			}
			echo '</ul>';
		}

		$runUrl = $this->urlFor('a=update&sa=runnow');
		echo '<p style="margin:12px 0"><a class="button" href="' . $h($runUrl) . '">Run Now</a></p>';
		echo '</div>';
	}

	function updateform($subaction, $param)
	{
		try {
			$this->assertAdmin();
		} catch (Throwable $e) {
		}

		$st = $this->execLw('status', array());
		$cfg = (isset($st['config']) && is_array($st['config'])) ? $st['config'] : KloxoLogwatch::defaults();

		$this->lw_enabled = !empty($cfg['enabled']) ? 'on' : 'off';
		$this->lw_mailto = (string) ($cfg['mailto'] ?? 'root');
		$this->lw_mailfrom = (string) ($cfg['mailfrom'] ?? 'logwatch');
		$this->lw_detail = (string) ($cfg['detail'] ?? 'Low');
		$this->lw_range = (string) ($cfg['range'] ?? 'Yesterday');
		$this->lw_format = (string) ($cfg['format'] ?? 'text');
		$this->lw_archives = !empty($cfg['archives']) ? 'on' : 'off';
		$this->lw_frequency = (string) ($cfg['frequency'] ?? 'daily');
		$this->lw_hour = (string) (int) ($cfg['hour'] ?? 6);
		$this->lw_minute = (string) (int) ($cfg['minute'] ?? 0);
		$this->lw_keep_days = (string) (int) ($cfg['keep_days'] ?? 14);
		$this->lw_save_reports = !empty($cfg['save_reports']) ? 'on' : 'off';
		$this->lw_mail_reports = !empty($cfg['mail_reports']) ? 'on' : 'off';

		$hours = array();
		for ($i = 0; $i < 24; $i++) {
			$hours[(string) $i] = sprintf('%02d', $i);
		}
		$mins = array();
		foreach (array(0, 15, 30, 45) as $m) {
			$mins[(string) $m] = sprintf('%02d', $m);
		}
		if (!isset($mins[$this->lw_minute])) {
			$mins[$this->lw_minute] = sprintf('%02d', (int) $this->lw_minute);
		}

		$vlist = array();
		$vlist['lw_info'] = array('M',
			'LogWatch settings for this server. Saved reports go to ' . KloxoLogwatch::REPORT_DIR
			. ' and are listed in Log Manager under the LogWatch application. '
			. 'Mail needs a working MTA (Exim or Postfix).');
		$vlist['lw_enabled'] = null;
		$vlist['lw_mail_reports'] = null;
		$vlist['lw_mailto'] = null;
		$vlist['lw_mailfrom'] = null;
		$vlist['lw_detail'] = array('s', array('Low' => 'Low', 'Med' => 'Medium', 'High' => 'High'));
		$vlist['lw_range'] = array('s', array(
			'Yesterday' => 'Yesterday',
			'Today' => 'Today',
			'All' => 'All',
			'between -7 days and -1 days' => 'Last 7 days',
		));
		$vlist['lw_format'] = array('s', array('text' => 'Text', 'html' => 'HTML'));
		$vlist['lw_archives'] = null;
		$vlist['lw_frequency'] = array('s', array(
			'daily' => 'Daily',
			'weekly' => 'Weekly (Sunday)',
			'monthly' => 'Monthly (1st)',
			'none' => 'Manual only (no schedule)',
		));
		$vlist['lw_hour'] = array('s', $hours);
		$vlist['lw_minute'] = array('s', $mins);
		$vlist['lw_save_reports'] = null;
		$vlist['lw_keep_days'] = null;
		$vlist['__v_button'] = 'Update';

		return $vlist;
	}

	function updateUpdate($param)
	{
		$this->assertAdmin();
		$fields = array(
			'enabled' => $this->field($param, 'lw_enabled'),
			'mailto' => $this->field($param, 'lw_mailto'),
			'mailfrom' => $this->field($param, 'lw_mailfrom'),
			'detail' => $this->field($param, 'lw_detail'),
			'range' => $this->field($param, 'lw_range'),
			'format' => $this->field($param, 'lw_format'),
			'archives' => $this->field($param, 'lw_archives'),
			'frequency' => $this->field($param, 'lw_frequency'),
			'hour' => $this->field($param, 'lw_hour'),
			'minute' => $this->field($param, 'lw_minute'),
			'keep_days' => $this->field($param, 'lw_keep_days'),
			'save_reports' => $this->field($param, 'lw_save_reports'),
			'mail_reports' => $this->field($param, 'lw_mail_reports'),
		);
		$ret = $this->execLw('setSettings', array($fields));
		$this->throwIfFailed($ret);
		$this->redirectToShow('updated_successfully');

		return null;
	}

	function updateRunnow($param)
	{
		$this->assertAdmin();
		$ret = $this->execLw('run', array());
		$this->throwIfFailed($ret);
		$name = '';
		if (isset($ret['report']) && is_string($ret['report']) && $ret['report'] !== '') {
			$name = basename($ret['report']);
		}
		$this->redirectToShow('updated_successfully', $name !== '' ? $name : 'LogWatch ran');

		return null;
	}

	function field($param, $name)
	{
		if (is_array($param) && isset($param[$name]) && is_scalar($param[$name])) {
			return trim((string) $param[$name]);
		}
		$k = 'frm_logwatch_c_' . $name;
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
			$tail .= '&frm_m_smessage_data=' . rawurlencode(htmlspecialchars((string) $data, ENT_QUOTES));
		}
		$gbl->__this_redirect = $this->urlFor('a=show') . $tail;
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

	function urlFor($action)
	{
		global $ghtml;

		if (is_object($ghtml) && method_exists($ghtml, 'getFullUrl')) {
			return $ghtml->getFullUrl($action);
		}

		return '/display.php?frm_action=show';
	}

	function throwIfFailed($ret)
	{
		global $login;

		if (is_array($ret) && !empty($ret['ok'])) {
			return;
		}
		$err = is_array($ret) && isset($ret['error']) ? (string) $ret['error'] : 'logwatch_failed';
		$msg = (is_object($login) && method_exists($login, 'getThrow'))
			? $login->getThrow($err)
			: $err;
		throw new lxException($msg);
	}

	function execLw($method, $args)
	{
		include_once dirname(__DIR__, 2) . '/lib/php/kloxo_logwatch.php';
		$args = is_array($args) ? $args : array();
		$fail = array('ok' => false, 'error' => 'logwatch_backend_failed', 'installed' => false,
			'enabled' => false, 'config' => KloxoLogwatch::defaults(), 'reports' => array(),
			'report_dir' => KloxoLogwatch::REPORT_DIR);
		$euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : 0;
		$panel = function_exists('php_sapi_name') ? php_sapi_name() : 'cli';
		$useBackend = $euid !== 0 && $panel !== 'cli' && function_exists('rl_exec_get');
		if (!$useBackend) {
			if ($method === 'status') {
				return KloxoLogwatch::status();
			}

			return call_user_func_array(array('KloxoLogwatch', $method), $args);
		}
		try {
			$got = rl_exec_get(null, $this->syncServerName(), array('KloxoLogwatch', $method), $args, false);
			if (is_array($got)) {
				return $got;
			}
		} catch (Throwable $e) {
			$fail['error'] = trim((string) $e->getMessage()) !== '' ? $e->getMessage() : 'logwatch_backend_failed';
		}

		return $fail;
	}
}
