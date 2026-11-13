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
	static $__acdesc_update_emaillast = array("", "", "Email last report");

	static $__desc_lw_info = array("M", "", "LogWatch");
	static $__desc_lw_enabled = array("f", "", "enable_logwatch");
	static $__desc_lw_mailto = array("", "", "to_email");
	static $__desc_lw_mailfrom = array("", "", "from_email");
	static $__desc_lw_detail = array("", "", "detail_level");
	static $__desc_lw_range = array("", "", "report_range");
	static $__desc_lw_format = array("", "", "report_format");
	static $__desc_lw_archives = array("f", "", "include_archives");
	static $__desc_lw_frequency = array("", "", "frequency");
	static $__desc_lw_hour = array("", "", "hour");
	static $__desc_lw_minute = array("", "", "minute");
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
		return array('update' => null, 'emaillast' => null);
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
		global $gbl;

		// Keep failure/success redirects on this page (not Home).
		$this->rememberShowReferer();
		// A prior failed Update left "--Select One--" in the session restore map;
		// that re-selects the invalid value and blocks the next save.
		if (is_object($gbl) && method_exists($gbl, 'getSessionV') && method_exists($gbl, 'setSessionV')) {
			$prev = $gbl->getSessionV('__tmp_redirect_var');
			if (is_array($prev)) {
				$changed = false;
				foreach ($prev as $k => $v) {
					if (!is_string($k) || !str_contains($k, '_c_lw_')) {
						continue;
					}
					if ($v === '--Select One--' || $v === '') {
						unset($prev[$k]);
						$changed = true;
					}
				}
				if ($changed) {
					$gbl->setSessionV('__tmp_redirect_var', $prev);
				}
			}
		}
	}

	function updateform($subaction, $param)
	{
		try {
			$this->assertAdmin();
		} catch (Throwable $e) {
		}

		if ($subaction === 'emaillast') {
			$vlist = array();
			$vlist['lw_info'] = array('M',
				'Send the newest saved report from ' . KloxoLogwatch::REPORT_DIR
				. ' to the To Email address above. Works even when Mail Reports is off.');
			$vlist['__v_button'] = 'Email last report';

			return $vlist;
		}

		$st = $this->execLw('status', array());
		$cfg = (isset($st['config']) && is_array($st['config'])) ? $st['config'] : KloxoLogwatch::defaults();
		if (class_exists('KloxoLogwatch') && is_callable(array('KloxoLogwatch', 'normalizeConfig'))) {
			try {
				$cfg = KloxoLogwatch::normalizeConfig($cfg);
			} catch (Throwable $e) {
				$cfg = KloxoLogwatch::defaults();
			}
		}

		$this->lw_enabled = !empty($cfg['enabled']) ? 'on' : 'off';
		$this->lw_mailto = (string) ($cfg['mailto'] ?? 'root');
		$this->lw_mailfrom = (string) ($cfg['mailfrom'] ?? 'logwatch');
		$this->lw_detail = (string) ($cfg['detail'] ?? 'Low');
		if (!in_array($this->lw_detail, array('Low', 'Med', 'High'), true)) {
			$this->lw_detail = 'Low';
		}
		$this->lw_range = (string) ($cfg['range'] ?? 'Yesterday');
		$rangeOk = array('Yesterday', 'Today', 'All', 'between -7 days and -1 days');
		if (!in_array($this->lw_range, $rangeOk, true)) {
			$this->lw_range = 'Yesterday';
		}
		$this->lw_format = strtolower((string) ($cfg['format'] ?? 'text'));
		if ($this->lw_format !== 'html') {
			$this->lw_format = 'text';
		}
		$this->lw_archives = !empty($cfg['archives']) ? 'on' : 'off';
		$this->lw_frequency = (string) ($cfg['frequency'] ?? 'daily');
		if (!in_array($this->lw_frequency, array('daily', 'weekly', 'monthly', 'none'), true)) {
			$this->lw_frequency = 'daily';
		}
		$this->lw_hour = (string) (int) ($cfg['hour'] ?? 6);
		$this->lw_minute = (string) (int) ($cfg['minute'] ?? 0);
		$this->lw_keep_days = (string) (int) ($cfg['keep_days'] ?? 14);
		$this->lw_save_reports = !empty($cfg['save_reports']) ? 'on' : 'off';
		$this->lw_mail_reports = !empty($cfg['mail_reports']) ? 'on' : 'off';

		// Non-assoc 's' lists: option value === list entry (never "--Select One--").
		// Assoc 'A' only when the submitted key must differ from the visible label.
		$hours = array();
		for ($i = 0; $i < 24; $i++) {
			$hours[] = (string) $i;
		}
		$mins = array('0', '15', '30', '45');
		if (!in_array($this->lw_minute, $mins, true)) {
			$mins[] = $this->lw_minute;
		}

		$vlist = array();
		$vlist['lw_enabled'] = null;
		$vlist['lw_mail_reports'] = null;
		$vlist['lw_mailto'] = null;
		$vlist['lw_mailfrom'] = null;
		$vlist['lw_detail'] = array('s', array('Low', 'Med', 'High'));
		$vlist['lw_range'] = array('A', array(
			'Yesterday' => 'Yesterday',
			'Today' => 'Today',
			'All' => 'All',
			'between -7 days and -1 days' => 'Last 7 days',
		));
		$vlist['lw_format'] = array('s', array('text', 'html'));
		$vlist['lw_archives'] = null;
		$vlist['lw_frequency'] = array('s', array('daily', 'weekly', 'monthly', 'none'));
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
		$this->rememberShowReferer();
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
		if (!is_array($ret) || empty($ret['ok'])) {
			$err = is_array($ret) && isset($ret['error']) ? (string) $ret['error'] : 'logwatch_failed';
			$this->redirectToShow($err, $err, true);

			return null;
		}
		$this->redirectToShow('logwatch_settings_saved');

		return null;
	}

	function updateEmaillast($param)
	{
		$this->assertAdmin();
		$this->rememberShowReferer();
		$ret = $this->execLw('mailLastReport', array());
		if (!is_array($ret) || empty($ret['ok'])) {
			$err = is_array($ret) && isset($ret['error']) ? (string) $ret['error'] : 'logwatch_mail_failed';
			$detail = '';
			if (isset($ret['report']) && is_string($ret['report']) && $ret['report'] !== '') {
				$detail = basename($ret['report']);
			}
			// Stay on LogWatch show with an error toast (never throw → Home).
			$this->redirectToShow($err, $detail !== '' ? $detail : $err, true);

			return null;
		}
		$name = '';
		if (isset($ret['report']) && is_string($ret['report']) && $ret['report'] !== '') {
			$name = basename($ret['report']);
		}
		$to = '';
		if (isset($ret['mailto']) && is_string($ret['mailto']) && $ret['mailto'] !== '') {
			$to = trim($ret['mailto']);
		}
		$detail = $name !== '' ? $name : 'report';
		if ($to !== '') {
			$detail .= ' → ' . $to;
		}
		$this->redirectToShow('logwatch_report_emailed', $detail);

		return null;
	}

	function field($param, $name)
	{
		if (is_array($param) && isset($param[$name]) && is_scalar($param[$name])) {
			$v = trim((string) $param[$name]);
		} else {
			$k = 'frm_logwatch_c_' . $name;
			$v = (isset($_REQUEST[$k]) && is_scalar($_REQUEST[$k]))
				? trim((string) $_REQUEST[$k]) : '';
		}
		if ($v === '--Select One--') {
			return '';
		}

		return $v;
	}

	function rememberShowReferer()
	{
		global $gbl;

		if (!is_object($gbl) || !method_exists($gbl, 'setSessionV')) {
			return;
		}
		$url = $this->urlFor('a=show');
		if (is_string($url) && $url !== '') {
			$gbl->setSessionV('lx_http_referer', $url);
		}
	}

	function redirectToShow($msgKey = 'updated_successfully', $data = '', $error = false)
	{
		global $gbl;

		if (!is_object($gbl)) {
			return;
		}
		$this->rememberShowReferer();
		$msgKey = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $msgKey));
		if ($msgKey === '') {
			$msgKey = $error ? 'logwatch_failed' : 'updated_successfully';
		}
		$flag = $error ? 'frm_emessage' : 'frm_smessage';
		$tail = '&' . $flag . '=' . rawurlencode($msgKey);
		if ((string) $data !== '') {
			$dataKey = $error ? 'frm_m_emessage_data' : 'frm_m_smessage_data';
			$tail .= '&' . $dataKey . '=' . rawurlencode(htmlspecialchars((string) $data, ENT_QUOTES));
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
			try {
				$url = $ghtml->getFullUrl($action);
				if (is_string($url) && $url !== '' && str_contains($url, 'class]=logwatch')) {
					return $url;
				}
				// getFullUrl often drops frm_o_o on POST → bare Home show.
				if (is_string($url) && $url !== '' && str_contains($url, 'frm_o_o')) {
					return $url;
				}
			} catch (Throwable $e) {
			}
		}
		$sa = '';
		$act = 'show';
		if (preg_match('/sa=([a-z]+)/', (string) $action, $m) === 1) {
			$sa = $m[1];
			$act = 'updateform';
		}
		$q = 'frm_action=' . $act
			. ($sa !== '' ? '&frm_subaction=' . rawurlencode($sa) : '')
			. '&frm_o_o[0][class]=pserver'
			. '&frm_o_o[0][nname]=' . rawurlencode($this->syncServerName())
			. '&frm_o_o[1][class]=logwatch'
			. '&frm_o_o[1][nname]=' . rawurlencode($this->syncServerName());

		return '/display.php?' . $q;
	}

	function throwIfFailed($ret)
	{
		global $login;

		if (is_array($ret) && !empty($ret['ok'])) {
			return;
		}
		$this->rememberShowReferer();
		$err = is_array($ret) && isset($ret['error']) ? (string) $ret['error'] : 'logwatch_failed';
		$msg = (is_object($login) && method_exists($login, 'getThrow'))
			? $login->getThrow($err)
			: $err;
		// Point print_redirect_back at this page (not Home).
		throw new lxException(is_string($msg) ? $msg : $err, '', $err);
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
