<?php
/**
 * Backend protocol: authenticate before any unserialize of network data (audit C1).
 *
 * Envelope (same as the old remote/slave form; now required for localhost too):
 *
 *   <remotechar>\n
 *   base64(username)\n
 *   base64(password)\n
 *   base64(payload)\n
 *
 * The payload is PHP-serialized today. This file never unserializes it. The caller
 * may unserialize only after kloxo_remote_verify_network_pass() (or a test double)
 * has accepted the username and password.
 *
 * JSON replacement (not built): replace the payload with a versioned JSON object
 * validated against a schema, sent over a Unix socket (or mutual TLS for
 * multi-server). Do not add further unserialize() of network input. See
 * docs/AUDIT_REPORT.md C1 and docs/HANDOFF_BRIEFING.md P0.5.
 *
 * PHP 5-compatible on purpose: included by the legacy backend until the PHP 8.3 port.
 */

function kloxo_remote_hash_equals($a, $b)
{
	if (!is_string($a) || !is_string($b)) {
		return false;
	}
	if (function_exists('hash_equals')) {
		return hash_equals($a, $b);
	}
	if (strlen($a) !== strlen($b)) {
		return false;
	}
	$r = 0;
	$n = strlen($a);
	for ($i = 0; $i < $n; $i++) {
		$r |= ord($a[$i]) ^ ord($b[$i]);
	}
	return $r === 0;
}

/**
 * Split the envelope. Never unserializes. $total is raw network bytes.
 *
 * @return array{ok:bool,reason?:string,user?:string,pass?:string,payload_b64?:string}
 */
function kloxo_remote_parse_envelope($total, $remotechar)
{
	if (!is_string($total) || $total === '' || !is_string($remotechar) || $remotechar === '') {
		return array('ok' => false, 'reason' => 'authentication_required');
	}
	if (strpos($total, $remotechar) !== 0) {
		return array('ok' => false, 'reason' => 'authentication_required');
	}

	$list = explode("\n", $total);
	if (count($list) < 4) {
		return array('ok' => false, 'reason' => 'authentication_required');
	}

	$user = base64_decode($list[1], true);
	$pass = base64_decode($list[2], true);
	if ($user === false || $pass === false) {
		return array('ok' => false, 'reason' => 'authentication_required');
	}
	if ($user === '' || $pass === '') {
		return array('ok' => false, 'reason' => 'authentication_required');
	}

	$payload_b64 = $list[3];
	if ($payload_b64 === '') {
		return array('ok' => false, 'reason' => 'authentication_required');
	}

	return array(
		'ok' => true,
		'user' => $user,
		'pass' => $pass,
		'payload_b64' => $payload_b64,
	);
}

/**
 * Parse the envelope and run $verify_pass(user, pass) before returning the payload.
 * The callback must not unserialize the payload. This function does not unserialize.
 *
 * @param callable $verify_pass
 * @return array{ok:bool,reason?:string,user?:string,pass?:string,payload_b64?:string}
 */
function kloxo_remote_authenticated_payload($total, $remotechar, $verify_pass)
{
	$env = kloxo_remote_parse_envelope($total, $remotechar);
	if (empty($env['ok'])) {
		return $env;
	}
	if (!is_callable($verify_pass) || !call_user_func($verify_pass, $env['user'], $env['pass'])) {
		return array('ok' => false, 'reason' => 'remote_authentication_failed');
	}
	return $env;
}

/**
 * Production password check. Uses $login when the PHP lxserver has set it;
 * otherwise the admin-pass file and the local slave_db (not network data).
 */
function kloxo_remote_verify_network_pass($user, $pass)
{
	global $login;

	if (!is_string($user) || !is_string($pass) || $user === '' || $pass === '') {
		return false;
	}

	if (is_object($login) && isset($login->password) && $login->password !== '' && function_exists('check_remote_pass')) {
		if (check_remote_pass($user, $pass)) {
			return true;
		}
	}

	if (function_exists('getAdminDbPass')) {
		try {
			$admin = getAdminDbPass();
			if (is_string($admin) && $admin !== '') {
				$admin = trim($admin);
				if (kloxo_remote_hash_equals($admin, $pass)) {
					return true;
				}
				if (function_exists('check_password') && check_password($pass, $admin)) {
					return true;
				}
			}
		} catch (Throwable $e) {
		}
	}

	if (function_exists('kloxo_load_slave_db')) {
		$rmt = kloxo_load_slave_db();
		if (is_object($rmt) && isset($rmt->password) && is_string($rmt->password) && $rmt->password !== '') {
			if (kloxo_remote_hash_equals($rmt->password, $pass)) {
				return true;
			}
			if (function_exists('check_password') && check_password($pass, $rmt->password)) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Load slave-db.db with gadget filtering (security S6).
 *
 * @return object|false
 */
function kloxo_load_slave_db()
{
	if (!function_exists('lxfile_exists') || !lxfile_exists('__path_slave_db')) {
		return false;
	}
	$raw = function_exists('lfile_get_contents') ? lfile_get_contents('__path_slave_db') : false;
	if (!is_string($raw) || $raw === '') {
		return false;
	}
	$rmt = kloxo_unserialize_deny_gadgets($raw);
	return is_object($rmt) ? $rmt : false;
}

/**
 * Whether a backend action=get callable is allowed (security R3).
 * Blocks shell/RCE builtins and non-identifier names; panel Class::method stays.
 *
 * @param mixed $func
 */
function kloxo_backend_callable_allowed($func)
{
	static $denyFn = array(
		'system', 'exec', 'passthru', 'shell_exec', 'proc_open', 'popen',
		'pcntl_exec', 'pcntl_fork', 'pcntl_alarm', 'eval', 'assert',
		'create_function', 'call_user_func', 'call_user_func_array',
		'forward_static_call', 'forward_static_call_array',
		'register_shutdown_function', 'register_tick_function',
		'include', 'include_once', 'require', 'require_once',
		'dl', 'putenv', 'ini_alter', 'apache_setenv',
		'mail', 'mb_send_mail',
		'file_put_contents', 'file_get_contents', 'fopen', 'fwrite', 'fputs',
		'unlink', 'rmdir', 'mkdir', 'rename', 'copy', 'link', 'symlink',
		'chmod', 'chown', 'chgrp', 'touch',
		'move_uploaded_file', 'parse_ini_file',
		'curl_exec', 'curl_multi_exec',
		'fsockopen', 'pfsockopen', 'stream_socket_client', 'stream_socket_server',
		'socket_create', 'socket_connect',
		'posix_kill', 'posix_setuid', 'posix_seteuid',
		'ReflectionFunction', 'ReflectionMethod', 'ReflectionClass',
		// Panel shell bridges must not be reachable via action=get (S4).
		'exec_with_all_closed', 'exec_with_all_closed_output',
		'lxshell_direct', 'lxshell_return', 'lxshell_output', 'lxshell_php',
		'lxshell_background', 'lxshell_expect', 'lxshell_input',
		'lxshell_zip', 'lxshell_unzip', 'lxshell_tar', 'lxshell_tgz',
	);
	static $denyClass = array(
		'PDO', 'PDOStatement', 'mysqli', 'SQLite3', 'Phar', 'PharData',
		'SimpleXMLElement', 'SplFileObject', 'SplFileInfo', 'DirectoryIterator',
		'FilesystemIterator', 'CallbackFilterIterator', 'CurlHandle',
		'ReflectionFunction', 'ReflectionMethod', 'ReflectionClass',
		'ReflectionObject', 'ReflectionProperty',
	);

	if (is_string($func)) {
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $func)) {
			return false;
		}
		$lower = strtolower($func);
		if (in_array($lower, $denyFn, true)
			|| str_starts_with($lower, 'lxshell_')
			|| str_starts_with($lower, 'exec_with_')) {
			return false;
		}
		return function_exists($func);
	}

	if (is_array($func) && count($func) === 2 && is_string($func[0]) && is_string($func[1])) {
		$class = $func[0];
		$method = $func[1];
		if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class)
			|| !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method)) {
			return false;
		}
		if (in_array($class, $denyClass, true)
			|| in_array(strtolower($method), $denyFn, true)) {
			return false;
		}
		return true;
	}

	return false;
}

/**
 * Unserialize with a deny-list of known PHP object-injection gadgets (N6/N22).
 * Prefer this over bare unserialize() for network/DB blobs when a full class
 * allow-list is not yet available.
 *
 * @param string $raw
 * @param array|bool $options passed to unserialize (PHP 7+)
 * @return mixed|false
 */
function kloxo_unserialize_deny_gadgets($raw, $options = true)
{
	if (!is_string($raw) || $raw === '') {
		return false;
	}
	static $bad = array(
		'PDO', 'PDOStatement', 'Phar', 'PharData', 'SimpleXMLElement',
		'SplFileObject', 'SplFileInfo', 'DirectoryIterator', 'FilesystemIterator',
		'CallbackFilterIterator', 'SQLite3', 'mysqli', 'CurlHandle',
		'GuzzleHttp\\Psr7\\FnStream', 'Monolog\\Handler\\SyslogUdpHandler',
		'Swift_KeyCache_DiskKeyCache', 'Swift_KeyCache_SimpleKeyCacheInputStream',
		'ReflectionFunction', 'ReflectionMethod', 'ReflectionClass', 'ReflectionObject',
		'ArrayIterator', 'ArrayObject', 'Generator', 'Closure',
		'Error', 'TypeError', 'ParseError', 'SoapClient', 'XMLWriter',
	);
	foreach ($bad as $c) {
		$needle = ':"' . $c . '"';
		$needle2 = ":'" . $c . "'";
		if (strpos($raw, $needle) !== false || strpos($raw, $needle2) !== false) {
			return false;
		}
		if (strpos($raw, 'O:') !== false && preg_match('/O:\\d+:"' . preg_quote($c, '/') . '"/', $raw)) {
			return false;
		}
	}
	// Reject any namespaced class in the blob (panel uses flat class names) (S11).
	if (preg_match('/O:\\d+:"[^"]*\\\\/', $raw)) {
		return false;
	}
	if (is_array($options)) {
		return @unserialize($raw, $options);
	}
	if ($options === false) {
		return @unserialize($raw, array('allowed_classes' => false));
	}
	return @unserialize($raw);
}

/**
 * Decode base64 then gadget-filter unserialize (network / DB helpers).
 *
 * @param string $b64
 * @param array|bool $options
 * @return mixed|false
 */
function kloxo_b64_unserialize($b64, $options = true)
{
	if (!is_string($b64) || $b64 === '') {
		return false;
	}
	// Backend replies are base64 then "\n___...___\n". Strict base64_decode fails
	// on that trailer and made every rl_exec_get look like "could not connect"
	// (Fail2Ban / UFW / Services). Keep only base64 alphabet, then strict decode.
	$b64 = preg_replace('/[^A-Za-z0-9+\/=]/', '', $b64);
	if (!is_string($b64) || $b64 === '') {
		return false;
	}
	$raw = base64_decode($b64, true);
	if (!is_string($raw) || $raw === '') {
		return false;
	}
	return kloxo_unserialize_deny_gadgets($raw, $options);
}
