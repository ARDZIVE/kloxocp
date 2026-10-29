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
	}

	if (function_exists('lxfile_exists') && lxfile_exists('__path_slave_db') && function_exists('lfile_get_contents')) {
		$raw = lfile_get_contents('__path_slave_db');
		if (is_string($raw) && $raw !== '') {
			$rmt = @unserialize($raw);
			if (is_object($rmt) && isset($rmt->password) && is_string($rmt->password) && $rmt->password !== '') {
				if (kloxo_remote_hash_equals($rmt->password, $pass)) {
					return true;
				}
				if (function_exists('check_password') && check_password($pass, $rmt->password)) {
					return true;
				}
			}
		}
	}

	return false;
}
