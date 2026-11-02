<?php

class sshclient extends lxclass
{
	static $__desc = array("", "", "ssh_client");
	static $__desc_nname = array("", "", "ssh_client");

	static $__acdesc_show = array("", "",  "ssh_terminal");

	function get() {}
	function write() {}

	function createShowPropertyList(&$alist)
	{
		$alist['property'][] = 'a=show';
	}

	function getSelfList()
	{
		global $login;

		if (!is_object($login) || !method_exists($login, 'isAdmin') || !$login->isAdmin()) {
			return null;
		}

		$list = null;
		try {
			if (method_exists($login, 'getList')) {
				$list = $login->getList('pserver');
			}
		} catch (Throwable $e) {
			$list = null;
		}

		$out = array();
		if (is_array($list)) {
			foreach ($list as $k => $p) {
				if (is_object($p) && method_exists($p, 'getId')) {
					$out[(string)$k] = $p;
				}
			}
		}

		if ($out === array()) {
			$parent = $this->getParentO();
			if (is_object($parent) && method_exists($parent, 'getClass')
				&& $parent->getClass() === 'pserver' && method_exists($parent, 'getId')) {
				$n = isset($parent->nname) ? (string)$parent->nname : 'localhost';
				$out[$n] = $parent;
			}
		}

		return $out !== array() ? $out : null;
	}

	function showRawPrint($subaction = null)
	{
		global $gbl, $sgbl, $login, $ghtml;

		if (!is_object($login) || !method_exists($login, 'isAdmin') || !$login->isAdmin()) {
			$msg = (is_object($login) && method_exists($login, 'getThrow'))
				? $login->getThrow("only_admin_can_access")
				: "only_admin_can_access";
			throw new lxException($msg);
		}

		$command = '';
		if (isset($_POST['frm_sshclient_command'])) {
			$command = kloxo_string($_POST['frm_sshclient_command']);
		}

		$hist = self::loadHistory();
		if ($command !== '') {
			$server = 'localhost';
			$parent = $this->getParentO();
			if (is_object($parent) && isset($parent->nname) && is_string($parent->nname) && $parent->nname !== '') {
				$server = $parent->nname;
			}
			$output = self::runAdminCommand($command, false, $server);
			$hist[] = array('cmd' => $command, 'out' => $output);
			if (count($hist) > 40) {
				$hist = array_slice($hist, -40);
			}
			self::saveHistory($hist);
		}

		$user = self::promptUser();
		$host = self::promptHost();
		$psuf = self::promptSuffix();
		$euser = htmlspecialchars($user, ENT_QUOTES);
		$ehost = htmlspecialchars($host, ENT_QUOTES);
		$esuf = htmlspecialchars($psuf, ENT_QUOTES);
		$token = htmlspecialchars(function_exists('getCSRFToken') ? (string)getCSRFToken() : '', ENT_QUOTES);
?>
<div class="kloxo-ssh-terminal" style="padding:8px 16px 24px;">
	<div style="background:#000; color:#aaa; font-family:Consolas,'Courier New',monospace; font-size:14px; line-height:1.35; padding:18px 22px 28px; min-height:420px; overflow:auto;">
		<pre style="margin:0 0 8px; color:#ddd; font-size:13px; line-height:1.1;"> __        __   _      ____                      _
 \ \      / /__| |__  / ___|___  _ __  ___  ___ | | ___
  \ \ /\ / / _ \ '_ \| |   / _ \| '_ \/ __|/ _ \| |/ _ \
   \ V  V /  __/ |_) | |__| (_) | | | \__ \ (_) | |  __/
    \_/\_/ \___|_.__/ \____\___/|_| |_|___/\___/|_|\___|</pre>
		<div style="margin:0 0 18px;"><a href="http://web-console.org" target="_blank" rel="noopener noreferrer" style="color:#4ea3ff;">http://web-console.org</a></div>
<?php
		foreach ($hist as $row) {
			if (!is_array($row)) {
				continue;
			}
			$pcmd = htmlspecialchars(kloxo_string($row['cmd'] ?? ''), ENT_QUOTES);
			$pout = htmlspecialchars(kloxo_string($row['out'] ?? ''), ENT_QUOTES);
?>
		<div><span style="color:#e36;"> <?= $euser ?></span><span style="color:#ddd;">@</span><span style="color:#e36;"><?= $ehost ?></span><span style="color:#ddd;"> ~<?= $esuf ?> </span><span style="color:#ddd;"><?= $pcmd ?></span></div>
<?php
			if ($pout !== '') {
?>
		<pre style="margin:2px 0 10px; white-space:pre-wrap; word-break:break-word; color:#ccc; font-family:inherit; font-size:inherit;"><?= $pout ?></pre>
<?php
			}
		}
?>
		<form method="post" action="/display.php" accept-charset="utf-8" style="margin:0;">
			<input type="hidden" name="frm_token" value="<?= $token ?>">
			<input type="hidden" name="frm_action" value="show">
<?php
		if (is_object($ghtml) && method_exists($ghtml, 'print_input_vars')) {
			$post = array();
			if (isset($ghtml->__http_vars['frm_o_o'])) {
				$post['frm_o_o'] = $ghtml->__http_vars['frm_o_o'];
			}
			if (isset($ghtml->__http_vars['frm_consumedlogin'])) {
				$post['frm_consumedlogin'] = $ghtml->__http_vars['frm_consumedlogin'];
			}
			$ghtml->print_input_vars($post);
		}
?>
			<div style="display:flex; align-items:baseline; gap:0;">
				<span style="color:#e36; white-space:nowrap;"> <?= $euser ?></span><span style="color:#ddd;">@</span><span style="color:#e36;"><?= $ehost ?></span><span style="color:#ddd; white-space:nowrap;"> ~<?= $esuf ?>&nbsp;</span>
				<input id="kloxo-ssh-cmd" type="text" name="frm_sshclient_command" autocomplete="off" spellcheck="false" style="flex:1; min-width:0; background:#000; color:#ddd; border:0; outline:0; font:inherit; padding:0;">
			</div>
		</form>
	</div>
</div>
<script>
(function () {
	var i = document.getElementById('kloxo-ssh-cmd');
	if (i) { i.focus(); }
})();
</script>
<?php
	}

	static function promptUser()
	{
		return 'root';
	}

	static function promptSuffix()
	{
		return '#';
	}

	static function promptHost()
	{
		$host = function_exists('php_uname') ? php_uname('n') : '';
		if (is_string($host) && $host !== '') {
			return $host;
		}

		return 'localhost';
	}

	static function runAdminCommand($command, $forceLocal = false, $server = 'localhost')
	{
		$command = str_replace("\0", '', kloxo_string($command));
		$command = trim($command);
		if ($command === '') {
			return '';
		}
		if (strlen($command) > 4096) {
			$command = substr($command, 0, 4096);
		}

		$euid = function_exists('posix_geteuid') ? (int)posix_geteuid() : 0;
		$panel = function_exists('php_sapi_name') ? php_sapi_name() : 'cli';
		$useBackend = !$forceLocal && $euid !== 0 && $panel !== 'cli'
			&& function_exists('rl_exec_get');
		if ($useBackend) {
			$host = is_string($server) && $server !== '' ? $server : 'localhost';
			try {
				$out = rl_exec_get(null, $host, array('sshclient', 'runAdminCommand'),
					array($command, true, $host));
				if (is_string($out)) {
					return $out;
				}
			} catch (Throwable $e) {
			}
		}

		$cwd = ($euid === 0 && is_dir('/root') && is_readable('/root')) ? '/root' : '/home/kloxo';
		if (!is_dir($cwd) || !is_readable($cwd)) {
			$tmp = function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '/tmp';
			$cwd = is_dir($tmp) ? $tmp : '/tmp';
		}

		$spec = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$env = array(
			'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
			'HOME' => $cwd,
			'TERM' => 'dumb',
			'LANG' => 'C.UTF-8',
			'SHELL' => '/bin/bash',
			'USER' => ($euid === 0) ? 'root' : 'lxlabs',
		);

		$proc = @proc_open(array('/bin/bash', '-lc', $command), $spec, $pipes, $cwd, $env);
		if (!is_resource($proc)) {
			return "could not start shell\n";
		}

		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$out = '';
		$max = 65536;
		$deadline = microtime(true) + 15.0;
		while (microtime(true) < $deadline) {
			$status = proc_get_status($proc);
			$chunk = (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
			if ($chunk !== '') {
				$out .= $chunk;
				if (strlen($out) >= $max) {
					$out = substr($out, 0, $max) . "\n[output truncated]\n";
					break;
				}
			}
			if (empty($status['running'])) {
				$out .= (string)stream_get_contents($pipes[1]) . (string)stream_get_contents($pipes[2]);
				break;
			}
			usleep(50000);
		}

		$status = proc_get_status($proc);
		if (!empty($status['running'])) {
			if (isset($status['pid']) && function_exists('posix_kill')) {
				@posix_kill((int)$status['pid'], 9);
			}
			proc_terminate($proc, 9);
			$out .= "\n[timed out after 15s]\n";
		}

		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($proc);

		return $out;
	}

	static function hostForPserver($parent)
	{
		$fallback = 'localhost';
		if (!is_object($parent) || !isset($parent->nname)) {
			return $fallback;
		}
		$name = (string)$parent->nname;
		if ($name !== '' && $name !== 'localhost') {
			$fallback = $name;
		}
		try {
			$fqdn = getFQDNforServer($name);
		} catch (Throwable $e) {
			return $fallback;
		}
		if (is_string($fqdn) && $fqdn !== '') {
			return $fqdn;
		}

		return $fallback;
	}

	static function initThisObjectRule($parent, $class, $name = null) { return "sshclient"; }

	static function initThisObject($parent, $class, $name = null)
	{
		$obj = new sshclient($parent->__masterserver, $parent->__readserver, 'sshclient');
		$obj->get();

		return $obj;
	}

	static function loadHistory()
	{
		global $gbl;

		if (is_object($gbl) && isset($gbl->c_session) && is_object($gbl->c_session)
			&& isset($gbl->c_session->ssession_vars) && is_array($gbl->c_session->ssession_vars)
			&& isset($gbl->c_session->ssession_vars['sshclient_term_hist'])
			&& is_array($gbl->c_session->ssession_vars['sshclient_term_hist'])) {
			return $gbl->c_session->ssession_vars['sshclient_term_hist'];
		}
		if (isset($_SESSION['sshclient_term_hist']) && is_array($_SESSION['sshclient_term_hist'])) {
			return $_SESSION['sshclient_term_hist'];
		}

		return array();
	}

	static function saveHistory($hist)
	{
		global $gbl;

		if (!is_array($hist)) {
			$hist = array();
		}
		if (is_object($gbl) && method_exists($gbl, 'setSessionV')) {
			try {
				$gbl->setSessionV('sshclient_term_hist', $hist);
				if (isset($gbl->c_session) && is_object($gbl->c_session) && method_exists($gbl->c_session, 'write')) {
					$gbl->c_session->write();
				}
			} catch (Throwable $e) {
			}
		}
		$_SESSION['sshclient_term_hist'] = $hist;
	}
}
