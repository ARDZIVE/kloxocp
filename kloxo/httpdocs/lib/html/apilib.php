<?php

/**
 * Legacy HyperVM-style HTTP helpers for the /api/ demo page.
 * Disabled for unauthenticated server-side calls (security V4 / N13).
 */

function send_to_some_http_server($raddress, $port, $url, $password = '')
{
	// Refuse empty credentials — never probe webcommand as admin with a blank password.
	$password = is_string($password) ? $password : '';
	if ($password === '') {
		return null;
	}
	$raddress = is_string($raddress) ? $raddress : '';
	$port = is_numeric($port) ? (int) $port : 0;
	if ($raddress === '' || $port < 1 || $port > 65535) {
		return null;
	}
	if (!preg_match('/^[A-Za-z0-9.:_-]+$/', $raddress)) {
		return null;
	}

	$fields = array(
		'login-class' => 'client',
		'login-name' => 'admin',
		'login-password' => $password,
		'output-type' => 'json',
	);
	if (is_string($url) && $url !== '') {
		parse_str($url, $extra);
		if (is_array($extra)) {
			foreach ($extra as $k => $v) {
				if (is_string($k) && (is_string($v) || is_numeric($v))) {
					$fields[$k] = (string) $v;
				}
			}
		}
	}

	// Prefer HTTPS on the panel SSL port; fall back to http only for loopback tests.
	$scheme = 'https';
	if ($raddress === '127.0.0.1' || strtolower($raddress) === 'localhost') {
		$scheme = (!empty($_SERVER['HTTPS']) && (string) $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
	}
	$ch = curl_init("{$scheme}://{$raddress}:{$port}/bin/webcommand.php");
	if ($ch === false) {
		return null;
	}
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
	$totalout = curl_exec($ch);
	curl_close($ch);
	if (!is_string($totalout) || $totalout === '') {
		return null;
	}

	return json_decode(trim($totalout));
}

function get_and_print_a_select_variable($description, $remotevar, $localvar)
{
	// Do not auto-call webcommand with an empty admin password on page load (V4).
	$description = htmlspecialchars((string) $description, ENT_QUOTES);
	$localvar = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $localvar) ?? 'v-plan_name';
	print("{$description}<br>\n");
	print("<input type=\"text\" name=\"{$localvar}\" value=\"\" "
		. "placeholder=\"resource plan name (enter manually)\"><br>\n");
}

function print_select($var, $list)
{
	$var = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $var) ?? 'select';
	print("<select name=\"{$var}\"> ");
	if (is_array($list)) {
		foreach ($list as $realname => $displayname) {
			$rn = htmlspecialchars((string) $realname, ENT_QUOTES);
			$dn = htmlspecialchars((string) $displayname, ENT_QUOTES);
			print("<option value=\"{$rn}\"> {$dn} </option>");
		}
	}
	print('</select>');
}
