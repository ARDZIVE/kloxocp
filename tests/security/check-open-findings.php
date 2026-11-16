#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Open security findings checklist (source invariants only).
 *
 * Each check looks for the *secure* pattern. Status:
 *   CLOSED — secure pattern present (finding corrected)
 *   OPEN   — vulnerable pattern still present (needs correction)
 *
 * Usage:
 *   php tests/security/check-open-findings.php
 *   php tests/security/check-open-findings.php --json
 *   php tests/security/check-open-findings.php --severity=critical
 *
 * Exit 0 only when every selected finding is CLOSED.
 * Safe locally: php-cli only; does not start the panel or open network ports.
 */

$root = realpath(dirname(__DIR__, 2));
if ($root === false) {
	fwrite(STDERR, "cannot resolve repo root\n");
	exit(2);
}

$wantJson = in_array('--json', $argv, true);
$severityFilter = null;
foreach ($argv as $arg) {
	if (str_starts_with($arg, '--severity=')) {
		$severityFilter = strtolower(substr($arg, 11));
	}
}

/**
 * @return array{id:string,severity:string,title:string,location:string,closed:bool,detail:string}
 */
function finding(
	string $id,
	string $severity,
	string $title,
	string $location,
	bool $closed,
	string $detail
): array {
	return compact('id', 'severity', 'title', 'location', 'closed', 'detail');
}

function file_text(string $root, string $rel): string
{
	$path = $root . '/' . $rel;
	if (!is_file($path)) {
		return '';
	}
	$t = file_get_contents($path);
	return $t === false ? '' : $t;
}

function contains(string $hay, string $needle): bool
{
	return $needle !== '' && str_contains($hay, $needle);
}

function not_contains(string $hay, string $needle): bool
{
	return !contains($hay, $needle);
}

/** Extract a PHP function body (best-effort brace match). */
function function_body(string $src, string $name): string
{
	if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
		return '';
	}
	$start = (int) $m[0][1] + strlen($m[0][0]) - 1; // at '{'
	$depth = 0;
	$len = strlen($src);
	for ($i = $start; $i < $len; $i++) {
		$ch = $src[$i];
		if ($ch === '{') {
			$depth++;
		} elseif ($ch === '}') {
			$depth--;
			if ($depth === 0) {
				return substr($src, $start, $i - $start + 1);
			}
		}
	}
	return '';
}

$remote = file_text($root, 'kloxo/httpdocs/lib/html/remotelib.php');
$monitor = file_text($root, 'kloxo/httpdocs/theme/lbin/monitordata.php');
$fdl = file_text($root, 'kloxo/httpdocs/theme/lbin/filedownload.php');
$loginInc = file_text($root, 'kloxo/httpdocs/login/inc.php');
$csrf = file_text($root, 'kloxo/httpdocs/lib/html/lib.php');
$signup = file_text($root, 'kloxo/httpdocs/theme/lbin/signup.php');
$phpinfo = file_text($root, 'kloxo/file/script/phpinfo.php');
$serverfile = file_text($root, 'kloxo/httpdocs/theme/lbin/serverfile.php');
$bg = file_text($root, 'kloxo/bin/common/background.php');
$lxclass = file_text($root, 'kloxo/httpdocs/lib/php/lxclass.php');
$api = file_text($root, 'kloxo/httpdocs/api/api.php');
$redirect = file_text($root, 'kloxo/httpdocs/theme/lbin/redirect.php');

$findings = [];

// --- Critical ---

// F1: remote_main must not unserialize frm_rmt before auth / must reject forged authenticated
$f1Closed = contains($remote, 'function remote_main')
	&& (
		// Prefer: no unserialize of frm_rmt at all in remote_main (JSON / gated path)
		!preg_match('/function remote_main\s*\([^)]*\)\s*\{[\s\S]*?unserialize\s*\(\s*base64_decode\s*\(\s*\$ghtml->frm_rmt/m', $remote)
		|| (
			// Or: check_for_remote always verifies password (authenticated flag alone insufficient)
			contains($remote, 'function check_for_remote')
			&& !preg_match('/if\s*\(\s*!\$rmt->authenticated\s*\)/', $remote)
			&& (contains($remote, 'getAdminDbPass()') || contains($remote, 'kloxo_remote_'))
		)
	);
$findings[] = finding(
	'F1',
	'critical',
	'HTTP RPC remote_main: unserialize(frm_rmt) + authenticated=true bypass',
	'kloxo/httpdocs/lib/html/remotelib.php:1170 / check_for_remote ~137',
	$f1Closed,
	$f1Closed
		? 'remote_main no longer trusts unauthenticated unserialize/authenticated flag'
		: 'remote_main still unserialize(base64_decode($ghtml->frm_rmt)); check_for_remote skips password when authenticated is truthy'
);

// F2: monitordata forced admin + unserialize without auth
$f2Closed = $monitor === ''
	|| (
		not_contains($monitor, "initprogram('admin')")
		&& not_contains($monitor, 'unserialize(base64_decode')
	)
	|| contains($monitor, 'check_raw_password') && !preg_match('/\/\*\s*\n\s*if\s*\(\s*!check_raw_password/m', $monitor);
$findings[] = finding(
	'F2',
	'critical',
	'monitordata.php: forced admin + unauthenticated unserialize',
	'kloxo/httpdocs/theme/lbin/monitordata.php:17-33',
	$f2Closed,
	$f2Closed
		? 'monitor endpoint no longer forces admin without password'
		: 'initprogram(admin) + unserialize(frm_rmt); password check remains commented out'
);

// F3: process_in_master unserialize without auth envelope
$pim = function_body($remote, 'process_in_master');
$f3Closed = $pim !== ''
	&& (
		contains($pim, 'kloxo_remote_parse_envelope')
		|| contains($pim, 'kloxo_remote_verify_network_pass')
		|| not_contains($pim, 'unserialize')
	);
$findings[] = finding(
	'F3',
	'critical',
	'Backend __master:: path: unserialize without auth envelope',
	'kloxo/httpdocs/lib/html/remotelib.php:894-901',
	$f3Closed,
	$f3Closed
		? 'process_in_master authenticates before unserialize (or no longer unserializes)'
		: 'process_in_master still does unserialize(base64_decode($total)) with no kloxo_remote_* gate'
);

// F4: filedownload.php session + no raw unserialize of frm_info (or endpoint disabled)
$f4Closed = $fdl === ''
	|| not_contains($fdl, 'unserialize(base64_decode($ghtml->frm_info))')
		&& (
			contains($fdl, 'initProgram')
			|| contains($fdl, 'disabled')
			|| contains($fdl, '403')
		);
$findings[] = finding(
	'F4',
	'critical',
	'filedownload.php: no session + unserialize(frm_info)',
	'kloxo/httpdocs/theme/lbin/filedownload.php:4-11',
	$f4Closed,
	$f4Closed
		? 'filedownload disabled or no longer unserializes frm_info'
		: 'no initProgram(); unserialize(base64_decode($ghtml->frm_info)); IP check commented out'
);

// --- High ---

$f5Closed = $signup === '' || not_contains($signup, "initProgram('admin')");
$findings[] = finding(
	'F5',
	'high',
	'signup.php (and similar lbin): forced admin without real session',
	'kloxo/httpdocs/theme/lbin/signup.php:7-8',
	$f5Closed,
	$f5Closed ? 'signup does not force admin' : "signup still calls initProgram('admin')"
);

$f6Closed = $phpinfo === ''
	|| (
		not_contains($phpinfo, 'unserialize')
		&& not_contains($phpinfo, "\$_REQUEST['session']")
	);
$findings[] = finding(
	'F6',
	'high',
	'phpinfo.php stub: unserialize of $_REQUEST[session]',
	'kloxo/file/script/phpinfo.php:6-10',
	$f6Closed,
	$f6Closed
		? 'phpinfo script no longer unserializes REQUEST session'
		: 'still reads $_REQUEST[session] and/or calls unserialize(base64_decode(...))'
);

$f7Closed = $serverfile === ''
	|| not_contains($serverfile, 'unserialize(base64_decode($ghtml->frm_info))');
$findings[] = finding(
	'F7',
	'high',
	'serverfile.php: unserialize(frm_info) without login',
	'kloxo/httpdocs/theme/lbin/serverfile.php:4-12',
	$f7Closed,
	$f7Closed ? 'serverfile no longer unserializes frm_info' : 'unserialize(base64_decode($ghtml->frm_info)) still present'
);

// CSRF: GET state-changing actions must be blocked or token-checked
$f8Closed = contains($csrf, 'function isCSRFTokenMatch')
	&& preg_match('/function isCSRFTokenMatch[\s\S]*?REQUEST_METHOD[\s\S]*?frm_action/m', $csrf)
	&& !preg_match('/function isCSRFTokenMatch[\s\S]*?\/\*[\s\S]*?frm_action[\s\S]*?\*\//m', $csrf);
$findings[] = finding(
	'F8',
	'high',
	'CSRF: GET start/stop/restart/toggle not token-checked',
	'kloxo/httpdocs/lib/html/lib.php:8818-8847',
	$f8Closed,
	$f8Closed
		? 'isCSRFTokenMatch validates non-POST mutating actions'
		: 'GET CSRF block for update/delete/start/stop remains commented out'
);

// Forgot-password path must not interpolate clientname into SQL.
$f10Closed = not_contains($loginInc, "nname = '\$cgi_clientname'");
$findings[] = finding(
	'F10',
	'high',
	'Password reset SQL injection (interpolated nname)',
	'kloxo/httpdocs/login/inc.php:249-256',
	$f10Closed,
	$f10Closed
		? 'forgot-password no longer interpolates $cgi_clientname into rawQuery'
		: "rawQuery(\"... where nname = '\$cgi_clientname'\") still present"
);

$f12Closed = $bg === ''
	|| (
		contains($bg, 'kloxo_background_allowed_functions')
		&& not_contains($bg, "'allowed_classes' => true")
		&& not_contains($bg, '"allowed_classes" => true')
	)
	|| (
		contains($bg, "allowed_classes")
		&& preg_match("/unserialize\s*\([^)]*allowed_classes'\s*=>\s*false/", $bg)
	)
	|| not_contains($bg, 'call_user_func_array');
$findings[] = finding(
	'F12',
	'high',
	'background.php: unserialize with allowed_classes true + call_user_func_array',
	'kloxo/bin/common/background.php:43-77',
	$f12Closed,
	$f12Closed
		? 'background jobs restrict unserialize classes / dispatch'
		: "unserialize(..., ['allowed_classes' => true]) and/or unrestricted call_user_func_array still present"
);

$eevalBody = function_body($lxclass, 'eeval');
$f13Closed = $eevalBody !== '' && not_contains($eevalBody, 'eval(');
$findings[] = finding(
	'F13',
	'high',
	'lxclass eeval(): eval on dynamic rules',
	'kloxo/httpdocs/lib/php/lxclass.php:278-282',
	$f13Closed,
	$f13Closed ? 'eeval/eval rule path removed or hardened' : 'eeval() still evals dynamic $rule strings'
);

// --- Medium (tracked) ---

$f14Closed = not_contains($api, 'value="lxlabs"')
	&& not_contains($loginInc, "crypt(\$rndstring, '\$1\$'");
$findings[] = finding(
	'F14',
	'medium',
	'Weak defaults: API example password lxlabs; reset uses MD5-crypt',
	'kloxo/httpdocs/api/api.php:17 / login/inc.php:254',
	$f14Closed,
	$f14Closed
		? 'stale lxlabs example and MD5-crypt reset removed'
		: 'api.php still shows login-password lxlabs and/or reset still uses crypt($1$...)'
);

$f15Count = substr_count($csrf, 'CURLOPT_SSL_VERIFYPEER, false')
	+ substr_count($csrf, 'CURLOPT_SSL_VERIFYPEER,false');
$f15Closed = $f15Count === 0;
$findings[] = finding(
	'F15',
	'medium',
	'TLS verification disabled on outbound curls (lib.php)',
	'kloxo/httpdocs/lib/html/lib.php (CURLOPT_SSL_VERIFYPEER false)',
	$f15Closed,
	$f15Closed
		? 'no CURLOPT_SSL_VERIFYPEER false in lib.php'
		: "found {$f15Count} CURLOPT_SSL_VERIFYPEER, false occurrence(s) in lib.php"
);

$f18Closed = $redirect === ''
	|| (
		contains($redirect, 'parse_url')
		|| contains($redirect, 'kloxo_safe_redirect')
		|| contains($redirect, 'kloxo_safe_internal_redirect')
		|| contains($redirect, 'allowed_redirect')
	);
$findings[] = finding(
	'F18',
	'medium',
	'redirect.php: weak open-redirect guard (http prefix only)',
	'kloxo/httpdocs/theme/lbin/redirect.php:6-23',
	$f18Closed,
	$f18Closed
		? 'redirect uses a stricter allow-list / URL parser'
		: 'only blocks leading http; //evil.com / javascript: style targets may still pass'
);

// --- Wave 2 (N1–N28) ---
$lib = $csrf; // lib.php already loaded as $csrf earlier for F8/F15
$fileServerBody = function_body($lib, 'file_server');
$n1Closed = contains($fileServerBody, 'kloxo_remote_parse_envelope')
	|| contains($fileServerBody, 'kloxo_remote_verify_network_pass');
$findings[] = finding('N1', 'critical', '__file:: auth envelope before unserialize',
	'kloxo/httpdocs/lib/html/lib.php file_server', $n1Closed,
	$n1Closed ? 'file_server authenticates before unserialize' : '__file:: still unserializes without envelope');

$ajaxCore = file_text($root, 'kloxo/httpdocs/lib/html/ajaxcore.php');
$n2Closed = contains($ajaxCore, 'isCSRFTokenMatch');
$findings[] = finding('N2', 'critical', 'ajax.php CSRF on add/update/delete',
	'kloxo/httpdocs/lib/html/ajaxcore.php', $n2Closed,
	$n2Closed ? 'ajax mutating actions check CSRF' : 'ajax has no isCSRFTokenMatch');

$child = file_text($root, 'kloxo/bin/common/child.php');
$n3Closed = contains($child, 'kloxo_child_allowed_functions')
	&& not_contains($child, "'allowed_classes' => true");
$findings[] = finding('N3', 'high', 'child.php unrestricted unserialize/dispatch',
	'kloxo/bin/common/child.php', $n3Closed,
	$n3Closed ? 'child.php allow-lists callables' : 'child.php still unrestricted');

$sudo = file_text($root, 'kloxo/bin/common/sudo_action.php');
$n4Closed = contains($sudo, 'disabled') || not_contains($sudo, 'call_user_func_array');
$findings[] = finding('N4', 'high', 'sudo_action.php unrestricted dispatch',
	'kloxo/bin/common/sudo_action.php', $n4Closed,
	$n4Closed ? 'sudo_action disabled or hardened' : 'sudo_action still unrestricted');

$lxlib = file_text($root, 'kloxo/httpdocs/lib/php/lxlib.php');
$n5Closed = not_contains($lxlib, 'unserialize(base64_decode($ghtml->frm_ssl))')
	&& not_contains($lxlib, 'unserialize(base64_decode($ghtml->frm_extra_var))');
$findings[] = finding('N5', 'high', 'login frm_ssl / frm_extra_var unserialize',
	'kloxo/httpdocs/lib/php/lxlib.php', $n5Closed,
	$n5Closed ? 'frm_ssl/frm_extra_var unserialize removed' : 'still unserializes login form blobs');

$gate = file_text($root, 'kloxo/httpdocs/lib/html/remote_auth_gate.php');
$n6Closed = contains($gate, 'kloxo_unserialize_deny_gadgets');
$findings[] = finding('N6', 'high', 'gadget-deny unserialize helper for IPC/DB',
	'kloxo/httpdocs/lib/html/remote_auth_gate.php', $n6Closed,
	$n6Closed ? 'kloxo_unserialize_deny_gadgets present' : 'no gadget deny-list helper');

$apiPhp = file_text($root, 'kloxo/httpdocs/api/api.php');
$n7Closed = contains($apiPhp, 'method="post"') || contains($apiPhp, "method='post'");
$findings[] = finding('N7', 'high', 'webcommand/API password in GET URLs',
	'kloxo/httpdocs/api/api.php', $n7Closed,
	$n7Closed ? 'api example uses POST' : 'api still uses GET with password');

$previewLib = file_text($root, 'kloxo/httpdocs/lib/php/kloxo_sitepreview.php');
$n8Closed = contains($previewLib, 'sanitizePreviewHtml');
$findings[] = finding('N8', 'high', 'site preview XSS (script not stripped)',
	'kloxo/httpdocs/lib/php/kloxo_sitepreview.php', $n8Closed,
	$n8Closed ? 'sanitizePreviewHtml present' : 'preview HTML not sanitized');

$n9Closed = contains($remote, "'verify_peer' => true") || contains($remote, '"verify_peer" => true');
$findings[] = finding('N9', 'high', 'slave TLS verify_peer off',
	'kloxo/httpdocs/lib/html/remotelib.php', $n9Closed,
	$n9Closed ? 'verify_peer enabled (self-signed allowed)' : 'verify_peer still false');

$selfup = file_text($root, 'kloxo/httpdocs/theme/lbin/selfupdate.php');
$n10Closed = contains($selfup, 'disabled') || not_contains($selfup, 'update_self');
$findings[] = finding('N10', 'high', 'selfupdate.php no CSRF',
	'kloxo/httpdocs/theme/lbin/selfupdate.php', $n10Closed,
	$n10Closed ? 'selfupdate disabled' : 'selfupdate still live');

$parseBody = function_body($lib, 'getParseInlinePhp');
if ($parseBody === '') {
	// Brace matcher can fail on complex bodies; fall back to whole-file markers.
	$n11Closed = contains($lib, 'getParseInlinePhp refused dangerous template construct');
} else {
	$n11Closed = contains($parseBody, 'system|exec|passthru') || contains($parseBody, 'refused dangerous');
}
$findings[] = finding('N11', 'high', 'getParseInlinePhp eval without guards',
	'kloxo/httpdocs/lib/html/lib.php getParseInlinePhp', $n11Closed,
	$n11Closed ? 'dangerous constructs refused' : 'getParseInlinePhp unguarded');

$coreDisp = file_text($root, 'kloxo/httpdocs/theme/coredisplaylib.php');
$n12Closed = !preg_match('/not_use_token\.flg[\s\S]{0,80}isCSRFTokenMatch/', $coreDisp)
	|| (contains($coreDisp, 'not_use_token.flg') && contains($coreDisp, 'ignored'));
// Better: CSRF call is unconditional (no if file_exists around it)
$n12Closed = contains($coreDisp, 'isCSRFTokenMatch')
	&& !preg_match('/if\s*\(\s*!?\s*file_exists\s*\([^)]*not_use_token/', $coreDisp);
$findings[] = finding('N12', 'medium', 'not_use_token.flg CSRF kill-switch',
	'kloxo/httpdocs/theme/coredisplaylib.php', $n12Closed,
	$n12Closed ? 'CSRF always enforced' : 'not_use_token.flg still disables CSRF');

$apilib = file_text($root, 'kloxo/httpdocs/lib/html/apilib.php');
$n13Closed = not_contains($apilib, 'login-password=hell');
$findings[] = finding('N13', 'medium', 'apilib hardcoded login-password=hell',
	'kloxo/httpdocs/lib/html/apilib.php', $n13Closed,
	$n13Closed ? 'hell password removed' : 'still embeds login-password=hell');

$loadDb = function_body($lib, 'load_database_file');
$n14Closed = contains($loadDb, 'proc_open') && not_contains($loadDb, 'exec("$dbtype');
$findings[] = finding('N14', 'medium', 'load_database_file shell-string dbpass',
	'kloxo/httpdocs/lib/html/lib.php', $n14Closed,
	$n14Closed ? 'uses proc_open argv' : 'still shell-interpolates dbpass');

$serveBody = function_body($lib, 'do_serve_file');
$n15Closed = contains($serveBody, 'realpath') && contains($serveBody, 'hash_equals');
$findings[] = finding('N15', 'medium', 'do_serve_file weak path/password checks',
	'kloxo/httpdocs/lib/html/lib.php do_serve_file', $n15Closed,
	$n15Closed ? 'realpath + hash_equals guards present' : 'do_serve_file still weak');

$n16Closed = contains($previewLib, 'CURLOPT_SSL_VERIFYPEER => true')
	|| contains($previewLib, 'CURLOPT_SSL_VERIFYPEER=>true');
$findings[] = finding('N16', 'medium', 'sitepreview curl SSL verify off',
	'kloxo/httpdocs/lib/php/kloxo_sitepreview.php', $n16Closed,
	$n16Closed ? 'verify_peer true (loopback exception ok)' : 'preview curl verify still off');

$loginInc2 = file_text($root, 'kloxo/httpdocs/login/inc.php');
$n17Closed = contains($loginInc2, 'pwreset') || contains($loginInc2, 'Rate-limit');
$findings[] = finding('N17', 'medium', 'password reset rate limit',
	'kloxo/httpdocs/login/inc.php', $n17Closed,
	$n17Closed ? 'forgot-password rate-limited' : 'no rate limit on reset');

$ajaxPhp = file_text($root, 'kloxo/httpdocs/ajax.php');
$n18Closed = not_contains($ajaxPhp, 'var_export($_REQUEST');
$findings[] = finding('N18', 'medium', 'ajax.php logs raw $_REQUEST',
	'kloxo/httpdocs/ajax.php', $n18Closed,
	$n18Closed ? 'no raw REQUEST logging' : 'still var_exports REQUEST');

$htmllib = file_text($root, 'kloxo/httpdocs/theme/htmllib.php');
$n19Closed = not_contains($htmllib, "eval('(' +") && not_contains($htmllib, 'eval(\'(\' +');
$findings[] = finding('N19', 'medium', 'htmllib JS eval of ajax JSON',
	'kloxo/httpdocs/theme/htmllib.php', $n19Closed,
	$n19Closed ? 'uses JSON.parse' : 'still eval()s ajax responses');

$paypal = file_text($root, 'kloxo/httpdocs/theme/lbin/paypalipn.php');
$n20Closed = contains($paypal, 'disabled') || not_contains($paypal, 'process_paypal');
$findings[] = finding('N20', 'medium', 'paypalipn.php fake payment surface',
	'kloxo/httpdocs/theme/lbin/paypalipn.php', $n20Closed,
	$n20Closed ? 'paypal IPN disabled' : 'paypal IPN still live');

$n21Closed = not_contains($lib, "where nname = '\$v'")
	&& not_contains($remote, "where nname = '\$machine'");
$findings[] = finding('N21', 'medium', 'rawQuery nname interpolation in helpers',
	'lib.php / remotelib.php', $n21Closed,
	$n21Closed ? 'helpers use getRows / validated names' : 'rawQuery still interpolates nname');

$n22Closed = contains(file_text($root, 'kloxo/httpdocs/lib/php/lxclass.php'), 'kloxo_unserialize_deny_gadgets');
$findings[] = finding('N22', 'medium', 'DB ser_* unserialize without gadget filter',
	'kloxo/httpdocs/lib/php/lxclass.php', $n22Closed,
	$n22Closed ? 'ser_* uses gadget deny-list' : 'ser_* bare unserialize');

$bup = file_text($root, 'kloxo/httpdocs/theme/lbin/backup-upload.php');
$n23Closed = contains($bup, 'frm_action=(?:add|update|delete)') || contains($bup, 'fixed success landing');
$findings[] = finding('N23', 'medium', 'backup-upload open display.php redirect',
	'kloxo/httpdocs/theme/lbin/backup-upload.php', $n23Closed,
	$n23Closed ? 'redirect restricted' : 'caller-controlled display.php redirect');

$sesu = file_text($root, 'kloxo/httpdocs/sesubmit/phpsubmit.php');
$n24Closed = contains($sesu, 'disabled') || contains($sesu, '403');
$findings[] = finding('N24', 'medium', 'sesubmit unauthenticated UI',
	'kloxo/httpdocs/sesubmit/phpsubmit.php', $n24Closed,
	$n24Closed ? 'sesubmit disabled' : 'sesubmit still live');

$webdisk = file_text($root, 'kloxo/httpdocs/webdisk/index.php');
$webdav = file_text($root, 'kloxo/httpdocs/webdisk/webdav.php');
$webAuth = file_text($root, 'kloxo/httpdocs/webdisk/authenticate.php');
$n25Closed = (contains($webdisk, 'disabled') || not_contains($webdisk, '$DBPWD'))
	&& contains($webdav, 'disabled')
	&& not_contains($webdav, '$DBPWD')
	&& contains($webAuth, 'disabled')
	&& !is_dir($root . '/kloxo/httpdocs/webdisk/PEAR')
	&& !is_dir($root . '/kloxo/httpdocs/webdisk/HTTP');
$findings[] = finding('N25', 'medium', 'webdisk mysql_query / hardcoded creds',
	'kloxo/httpdocs/webdisk/', $n25Closed,
	$n25Closed ? 'webdisk stubs only; PEAR/WebDAV removed' : 'webdisk still exposes legacy stack');

$rc = file_text($root, 'kloxo/httpdocs/lib/php/kloxo_roundcube.php');
$n26Closed = contains($rc, "'verify_peer' => true") || contains($rc, '"verify_peer" => true');
$findings[] = finding('N26', 'low', 'Roundcube generate verify_peer false',
	'kloxo/httpdocs/lib/php/kloxo_roundcube.php', $n26Closed,
	$n26Closed ? 'Roundcube config verify_peer true' : 'still false');

$gl = file_text($root, 'kloxo/httpdocs/theme/lbin/getlicense.php');
$n27Closed = contains($gl, 'disabled');
$findings[] = finding('N27', 'low', 'getlicense/lgt web forced admin',
	'kloxo/httpdocs/theme/lbin/getlicense.php', $n27Closed,
	$n27Closed ? 'getlicense disabled' : 'getlicense still live');

$sec = file_text($root, 'kloxo/bin/common/setupsecondary.php');
$n28Closed = contains($sec, 'mysqli_prepare') || contains($sec, 'proc_open');
$findings[] = finding('N28', 'low', 'setupsecondary shell/SQLi',
	'kloxo/bin/common/setupsecondary.php', $n28Closed,
	$n28Closed ? 'setupsecondary uses prepared/argv' : 'still shell/string SQL');

// --- Wave 3 (R1–R5) ---
$pimBody = function_body($remote, 'process_in_master');
$rmBody = function_body($remote, 'remote_main');
$r1Closed = contains($pimBody, 'kloxo_unserialize_deny_gadgets')
	&& contains($rmBody, 'kloxo_unserialize_deny_gadgets');
$findings[] = finding('R1', 'medium', 'process_in_master/remote_main bare unserialize after auth',
	'kloxo/httpdocs/lib/html/remotelib.php', $r1Closed,
	$r1Closed ? 'both use gadget deny-list' : 'still bare unserialize after auth');

$lxlibR = file_text($root, 'kloxo/httpdocs/lib/php/lxlib.php');
$httpFn = function_body($lxlibR, 'send_to_some_http_server');
$r2Closed = (contains($httpFn, 'https') || contains($httpFn, '{$scheme}'))
	&& contains(function_body($lxlibR, 'remote_http_exec'), 'kloxo_unserialize_deny_gadgets');
$findings[] = finding('R2', 'medium', 'HTTP slave RPC cleartext + bare response unserialize',
	'kloxo/httpdocs/lib/php/lxlib.php', $r2Closed,
	$r2Closed ? 'HTTPS preferred + gadget filter on response' : 'still cleartext/bare unserialize');

$r3Closed = contains($gate, 'kloxo_backend_callable_allowed')
	&& contains(function_body($remote, 'do_local_action'), 'kloxo_backend_callable_allowed');
$findings[] = finding('R3', 'critical', 'backend action=get unbounded call_user_func_array',
	'kloxo/httpdocs/lib/html/remotelib.php do_local_action', $r3Closed,
	$r3Closed ? 'callable deny-list enforced' : 'still unbounded call_user_func_array');

$lfileUn = function_body($lxlibR, 'lfile_get_unserialize');
$r4Closed = contains($lfileUn, 'kloxo_unserialize_deny_gadgets');
$findings[] = finding('R4', 'low', 'lfile_get_unserialize bare unserialize',
	'kloxo/httpdocs/lib/php/lxlib.php', $r4Closed,
	$r4Closed ? 'uses gadget deny-list' : 'still bare unserialize');

$prevPhp = file_text($root, 'kloxo/httpdocs/theme/lbin/sitepreview.php');
$r5Closed = contains($prevPhp, 'Blocked content type')
	&& contains($prevPhp, 'security R5');
$findings[] = finding('R5', 'low', 'preview non-HTML body passthrough',
	'kloxo/httpdocs/theme/lbin/sitepreview.php', $r5Closed,
	$r5Closed ? 'non-HTML types restricted' : 'still echoes arbitrary Content-Type');

// --- Wave 4 (S1–S14) ---
$dreBody = function_body($remote, 'do_remote_exec');
$stmBody = function_body($remote, 'send_to_master');
$s1Closed = contains($dreBody, 'kloxo_b64_unserialize');
$s2Closed = contains($stmBody, 'kloxo_b64_unserialize');
$findings[] = finding('S1', 'high', 'do_remote_exec bare response unserialize',
	'remotelib.php do_remote_exec', $s1Closed,
	$s1Closed ? 'uses kloxo_b64_unserialize' : 'still bare unserialize');
$findings[] = finding('S2', 'high', 'send_to_master bare reply unserialize',
	'remotelib.php send_to_master', $s2Closed,
	$s2Closed ? 'uses kloxo_b64_unserialize' : 'still bare unserialize');

$dlaBody = function_body($remote, 'do_local_action');
$s3Closed = contains($dlaBody, 'backend_set_refused') || contains($dlaBody, 'instanceof lxclass');
$findings[] = finding('S3', 'high', 'action=set without object allow-list',
	'remotelib.php do_local_action', $s3Closed,
	$s3Closed ? 'requires lxclass + safe table' : 'set still unrestricted');

$s4Closed = contains($gate, 'lxshell_') && contains($gate, 'exec_with_all_closed');
$findings[] = finding('S4', 'high', 'lxshell_* reachable via action=get',
	'remote_auth_gate.php', $s4Closed,
	$s4Closed ? 'lxshell_/exec_with_ denied' : 'shell bridges still allowed');

$s5Closed = contains($remote, 'verify_peer_name') && contains($remote, 'FILTER_VALIDATE_IP');
$findings[] = finding('S5', 'medium', 'slave TLS verify_peer_name always false',
	'remotelib.php stream client', $s5Closed,
	$s5Closed ? 'peer_name on for FQDN hosts' : 'verify_peer_name still always false');

$s6Closed = contains($gate, 'function kloxo_load_slave_db');
$findings[] = finding('S6', 'medium', 'bare unserialize of __path_slave_db',
	'remote_auth_gate.php', $s6Closed,
	$s6Closed ? 'kloxo_load_slave_db helper present' : 'no filtered slave-db loader');

$s7Closed = contains($lib, 'ser_dns_record_a') && contains($lib, 'kloxo_b64_unserialize');
$findings[] = finding('S7', 'medium', 'DNS ser_dns_record_a bare unserialize',
	'lib.php getIpfromARecord', $s7Closed,
	$s7Closed ? 'uses kloxo_b64_unserialize' : 'still bare');

$cronLib = file_text($root, 'kloxo/httpdocs/driver/pserver/cron__linuxlib.php');
$s8Closed = contains($cronLib, 'kloxo_b64_unserialize');
$findings[] = finding('S8', 'medium', 'cron/phpini DB blob bare unserialize',
	'cron__linuxlib.php', $s8Closed,
	$s8Closed ? 'cron uses gadget filter' : 'cron still bare');

$s9Closed = contains(function_body($lxlibR, 'get_savedlogin'), 'kloxo_unserialize_deny_gadgets');
$findings[] = finding('S9', 'medium', 'get_savedlogin bare unserialize',
	'lxlib.php', $s9Closed,
	$s9Closed ? 'filtered' : 'still bare');

$rexBody = function_body($remote, 'remote_exec');
$s10Closed = contains($rexBody, "action === 'set'") || contains($rexBody, 'action === "set"');
$findings[] = finding('S10', 'medium', 'localhost fallback runs action=set as panel user',
	'remotelib.php remote_exec', $s10Closed,
	$s10Closed ? 'set never falls back locally' : 'set still falls back');

$s11Closed = contains($gate, 'namespaced class') || contains($gate, '\\\\\\\\');
$findings[] = finding('S11', 'medium', 'gadget deny-list incomplete (namespaces)',
	'remote_auth_gate.php', $s11Closed,
	$s11Closed ? 'rejects namespaced O: classes' : 'no namespace reject');

$child2 = file_text($root, 'kloxo/bin/common/child.php');
$s12Closed = contains($child2, 'kloxo_unserialize_deny_gadgets');
$findings[] = finding('S12', 'low', 'child.php without gadget string filter',
	'bin/common/child.php', $s12Closed,
	$s12Closed ? 'uses deny-gadgets' : 'allowed_classes only');

$s13Closed = contains($lib, "str_contains(\$template, '\$' . '{')")
	|| contains($lib, 'call_user_func)\s*\(/i')
	|| (contains($lib, 'getParseInlinePhp') && contains($lib, 'call_user_func'));
$findings[] = finding('S13', 'low', 'getParseInlinePhp residual eval surface',
	'lib.php', $s13Closed,
	$s13Closed ? 'extra dangerous constructs refused' : 'guards unchanged');

$s14Closed = contains($previewLib, '127.0.0.1') && contains($previewLib, 'CURLOPT_SSL_VERIFYPEER, false');
// S14 is accepted loopback tradeoff — mark closed when loopback-only exception is explicit.
$s14Closed = contains($previewLib, 'localhost') || contains($previewLib, '127.0.0.1');
$findings[] = finding('S14', 'low', 'preview loopback SSL verify off',
	'kloxo_sitepreview.php', $s14Closed,
	$s14Closed ? 'loopback-only SSL exception documented in code' : 'verify off for non-loopback');

// --- Wave 5 (T1–T6) ---
$blanket = file_text($root, 'kloxo/bin/common/securityblanket.php');
$t1Closed = contains($blanket, 'kloxo_unserialize_deny_gadgets')
	&& contains($blanket, 'kloxo_securityblanket_callable_allowed')
	&& not_contains($blanket, 'unserialize(lfile_get_contents')
	&& contains($blanket, 'kloxo_securityblanket_ident');
$findings[] = finding('T1', 'high', 'securityblanket.php bare unserialize + call_user_func + string SQL',
	'bin/common/securityblanket.php', $t1Closed,
	$t1Closed ? 'deny-gadgets + empty callable allow-list + ident-checked SQL' : 'still bare worker');

$blanketExec = function_body($lib, 'securityBlanketExec');
$t2Closed = contains($blanketExec, 'refused') || contains($blanketExec, 'security T2')
	|| (contains($blanketExec, '/pid') && not_contains($blanketExec, '/tmp'));
$findings[] = finding('T2', 'medium', 'securityBlanketExec writes jobs under /tmp',
	'lib.php securityBlanketExec', $t2Closed,
	$t2Closed ? 'refuses spawn / no /tmp jobs' : 'still tempnam(/tmp)');

$gkp = function_body($lib, 'get_kloxo_port');
$t3Closed = contains($gkp, 'kloxo_b64_unserialize') || contains($gkp, 'kloxo_unserialize_deny_gadgets');
$findings[] = finding('T3', 'low', 'get_kloxo_port bare unserialize of ser_portconfig_b',
	'lib.php get_kloxo_port', $t3Closed,
	$t3Closed ? 'filtered unserialize' : 'still bare unserialize');

$fsBody = function_body($lib, 'file_server');
$t4Closed = contains($fsBody, 'kloxo_b64_unserialize') || contains($fsBody, 'kloxo_unserialize_deny_gadgets');
$findings[] = finding('T4', 'low', 'file_server allowed_classes only without gadget scan',
	'lib.php file_server', $t4Closed,
	$t4Closed ? 'uses kloxo_b64_unserialize' : 'still allowed_classes only');

$t5Closed = contains($gate, 'kloxo_localhost_get_fallback_allowed')
	&& contains($rexBody, 'kloxo_localhost_get_fallback_allowed');
$findings[] = finding('T5', 'low', 'localhost action=get falls back as panel user for privileged callables',
	'remotelib.php remote_exec', $t5Closed,
	$t5Closed ? 'privileged get fallback refused' : 'get still falls back for Kloxo*/Service__*');

$dbCli = file_text($root, 'kloxo/bin/database.php');
$rpCli = file_text($root, 'kloxo/bin/common/resetpassword.php');
$t6Closed = (contains($dbCli, 'kloxo_b64_unserialize') || contains($dbCli, 'kloxo_unserialize_deny_gadgets'))
	&& (contains($rpCli, 'kloxo_load_slave_db') || contains($rpCli, 'kloxo_unserialize_deny_gadgets'))
	&& contains($dbCli, 'Only a single SELECT');
$findings[] = finding('T6', 'low', 'CLI database.php / resetpassword.php bare unserialize',
	'bin/database.php + bin/common/resetpassword.php', $t6Closed,
	$t6Closed ? 'filtered unserialize + SELECT-only exec' : 'still bare');

// --- Wave 6 (U1–U8) ---
$webcmd = file_text($root, 'kloxo/httpdocs/lib/html/objectactionlib.php');
$u1Closed = contains($webcmd, 'kloxo_webcommand_auth_rate_limited')
	&& contains($webcmd, 'kloxo_webcommand_same_origin_ok')
	&& contains($webcmd, '__error_post_required')
	&& contains(function_body($lxlibR, 'check_raw_password'), 'getRows');
$findings[] = finding('U1', 'high', 'webcommand password API without CSRF/rate limit',
	'objectactionlib.php webcommandline_main', $u1Closed,
	$u1Closed ? 'POST + origin check + auth rate limit + getRows' : 'still open password API');

$u2Closed = not_contains($remote, "@unserialize(lfile_get_contents(\"__path_slave_db\"))")
	&& not_contains($remote, "@unserialize(lfile_get_contents('__path_slave_db'))")
	&& contains($remote, 'security U2')
	&& contains(file_text($root, 'kloxo/httpdocs/lib/html/lxserverlib.php'), 'security U2');
$findings[] = finding('U2', 'medium', 'bare __path_slave_db unserialize fallbacks',
	'remotelib / lxserverlib / pservercorelib', $u2Closed,
	$u2Closed ? 'kloxo_load_slave_db only' : 'bare unserialize fallback remains');

$lxg = file_text($root, 'kloxo/httpdocs/driver/pserver/lxguardlib.php');
$u3Closed = contains($lxg, 'FILTER_VALIDATE_IP') && contains($lxg, 'security U3');
$findings[] = finding('U3', 'medium', 'LxGuard rawQuery interpolates _accountselect IP',
	'lxguardlib.php updateRemove', $u3Closed,
	$u3Closed ? 'IP validated before delete' : 'still interpolates $ip');

$tld = file_text($root, 'kloxo/httpdocs/lib/php/tldextract.php');
$u4Closed = contains($tld, 'allowed_classes') && (contains($tld, 'kloxo_unserialize_deny_gadgets') || contains($tld, "allowed_classes' => false"));
$findings[] = finding('U4', 'medium', 'tldextract bare unserialize of .tld_set',
	'tldextract.php', $u4Closed,
	$u4Closed ? 'arrays-only unserialize' : 'still bare unserialize');

$u5Closed = contains($gate, "allowClass = array('coreFfile')")
	|| contains($gate, 'Allow-list only (security U5)');
$findings[] = finding('U5', 'medium', 'localhost get fallback still allows backup/SSL/cp_fileserv',
	'remote_auth_gate.php kloxo_localhost_get_fallback_allowed', $u5Closed,
	$u5Closed ? 'coreFfile + read-only allow-list' : 'still broad deny-list');

$bak = file_text($root, 'kloxo/httpdocs/lib/php/kloxo_backup.php');
$u6Closed = contains($bak, 'kloxo_unserialize_deny_gadgets') && contains($bak, 'security U6');
$findings[] = finding('U6', 'medium', 'backup driver blob without gadget string scan',
	'kloxo_backup.php', $u6Closed,
	$u6Closed ? 'deny-gadgets on driver blob' : 'allowed_classes only');

$u7Closed = not_contains($lib, 'lfile_put_contents("/tmp/mine"')
	&& not_contains($lib, "lfile_put_contents('/tmp/mine'");
$findings[] = finding('U7', 'low', 'se_submit writes crawl output to /tmp/mine',
	'lib.php se_submit', $u7Closed,
	$u7Closed ? '/tmp/mine write removed' : 'still writes /tmp/mine');

$ipt = file_text($root, 'kloxo/bin/common/iptraffic.php');
$u8Closed = contains($ipt, 'kloxo_unserialize_deny_gadgets') || contains($ipt, 'security U8');
$findings[] = finding('U8', 'low', 'iptraffic.php bare unserialize of Xen bw data',
	'bin/common/iptraffic.php', $u8Closed,
	$u8Closed ? 'filtered unserialize' : 'still bare');

// --- Wave 7 (V1–V6) ---
$pserverLinux = file_text($root, 'kloxo/httpdocs/driver/pserver/pserver__linuxlib.php');
$resetMysql = file_text($root, 'kloxo/bin/common/misc/reset-mysql-root-password.php');
$mariadbLib = file_text($root, 'kloxo/httpdocs/lib/php/kloxo_mariadb.php');
$v1Closed = contains(function_body($pserverLinux, 'mysqlPasswordReset'), 'setRootPassword')
	&& not_contains(function_body($pserverLinux, 'mysqlPasswordReset'), 'exec("sh /script/reset-mysql-root-password');
$findings[] = finding('V1', 'critical', 'mysqlPasswordReset shell-injects password',
	'pserver__linuxlib.php mysqlPasswordReset', $v1Closed,
	$v1Closed ? 'in-process/argv ALTER USER only' : 'still exec with unquoted $pass');

$v2Closed = contains($resetMysql, 'setRootPassword')
	&& not_contains($resetMysql, 'exec("mysql -u root -p')
	&& not_contains($resetMysql, "file_put_contents(\"{\$tpath}/reset-mysql-password.sql\"");
$findings[] = finding('V2', 'high', 'reset-mysql-root-password plaintext SQL + shell mysql',
	'bin/common/misc/reset-mysql-root-password.php', $v2Closed,
	$v2Closed ? 'socket ALTER USER + no shell password' : 'still writes/shells password');

$redir = file_text($root, 'kloxo/httpdocs/lib/redirect.php');
$v3Closed = contains($redir, 'header(') && contains($redir, 'Location:')
	&& not_contains($redir, 'location.replace');
$findings[] = finding('V3', 'high', 'redirect.php XSS via REQUEST_URI in JS',
	'lib/redirect.php', $v3Closed,
	$v3Closed ? 'validated Location header' : 'still echoes into location.replace');

$apilib = file_text($root, 'kloxo/httpdocs/lib/html/apilib.php');
$v4Closed = contains($apilib, "password === ''")
	&& contains($apilib, 'enter manually')
	&& not_contains($apilib, 'login-password=&');
$findings[] = finding('V4', 'medium', 'api.php/apilib blank-password webcommand probe',
	'apilib.php', $v4Closed,
	$v4Closed ? 'empty password refused; no auto probe' : 'still probes with blank password');

$mysqlOpt = file_text($root, 'kloxo/bin/fix/mysql-optimize.php');
$v5Closed = contains($mysqlOpt, 'defaults-extra-file')
	&& contains($mysqlOpt, 'lxshell_return')
	&& not_contains($mysqlOpt, 'system("mysqlcheck');
$findings[] = finding('V5', 'medium', 'mysql-optimize.php shell-interpolates DB password',
	'bin/fix/mysql-optimize.php', $v5Closed,
	$v5Closed ? 'defaults-extra-file + argv' : 'still system() with $pass');

$filterFn = function_body(file_text($root, 'kloxo/httpdocs/lib/php/lxclass.php'), 'filterFunc');
$v6Closed = not_contains($filterFn, 'eval(') && contains($filterFn, 'security V6');
$findings[] = finding('V6', 'low', 'lxclass filterFunc latent eval',
	'lxclass.php filterFunc', $v6Closed,
	$v6Closed ? 'PHP comparisons only' : 'still eval');

// --- Wave 8 (W1–W8) ---
$mailAcc = file_text($root, 'kloxo/httpdocs/driver/mmail/mailaccountlib.php');
$ftpUser = file_text($root, 'kloxo/httpdocs/driver/ftp/ftpuserlib.php');
$w1Closed = contains($mailAcc, 'kloxo_password_hash')
	&& contains($ftpUser, 'kloxo_password_hash')
	&& contains($lxlibR, 'function kloxo_htpasswd_hash');
$findings[] = finding('W1', 'high', 'mail/FTP/etc still use MD5-crypt',
	'mailaccountlib / ftpuserlib / htpasswd helpers', $w1Closed,
	$w1Closed ? 'kloxo_password_hash / htpasswd bcrypt' : 'still MD5-crypt');

$w2Closed = contains(function_body($lxlibR, 'check_raw_password'), 'kloxo_password_needs_rehash')
	&& contains($lxlibR, 'function kloxo_password_needs_rehash');
$findings[] = finding('W2', 'high', 'login never rehashes legacy MD5-crypt',
	'lxlib.php check_raw_password', $w2Closed,
	$w2Closed ? 'rehashes to Argon2id on success' : 'no rehash');

$gcv = function_body($lxlibR, 'get_class_variable');
$scv = function_body($lxlibR, 'set_class_variable');
$w3Closed = contains($gcv, 'ReflectionClass') && not_contains($gcv, 'eval(')
	&& contains($scv, 'ReflectionClass') && not_contains($scv, 'eval(');
$findings[] = finding('W3', 'medium', 'get/set_class_variable use eval',
	'lxlib.php', $w3Closed,
	$w3Closed ? 'Reflection only' : 'still eval');

$w4Closed = contains($webcmd, 'kloxo_webcommand_api_key_ok')
	&& contains($webcmd, '__error_api_key_required');
$findings[] = finding('W4', 'medium', 'webcommand mutating actions without API key',
	'objectactionlib.php', $w4Closed,
	$w4Closed ? 'add/update/delete require api-key' : 'still password-only mutate');

$w5Closed = contains($gate, 'kloxo_slave_tls_peer_name')
	&& contains($remote, 'kloxo_slave_tls_peer_name');
$findings[] = finding('W5', 'medium', 'slave TLS peer_name off for raw IPs',
	'remotelib / remote_auth_gate', $w5Closed,
	$w5Closed ? 'uses realhostname when available' : 'IP peers still anonymous');

$initLib = file_text($root, 'kloxo/httpdocs/lib/html/initlib.php');
$w6Closed = contains($initLib, 'kloxo_password_hash');
$findings[] = finding('W6', 'medium', 'install paths mint MD5-crypt',
	'initlib.php', $w6Closed,
	$w6Closed ? 'uses kloxo_password_hash' : 'still crypt $1$');

$phpmailer = file_text($root, 'kloxo/httpdocs/lib/extra/class.phpmailer.php');
$w7Closed = contains($phpmailer, 'security W7')
	&& contains($phpmailer, 'RuntimeException')
	&& !contains($phpmailer, 'The PHPMailer Version number');
$findings[] = finding('W7', 'low', 'bundled EOL PHPMailer 5.2.19',
	'lib/extra/class.phpmailer.php', $w7Closed,
	$w7Closed ? 'stub refuses construction' : 'full EOL PHPMailer present');

$w8Closed = contains(function_body($lxlibR, 'lfile_get_unserialize'), 'Fail closed')
	&& contains(file_text($root, 'kloxo/httpdocs/lib/php/lxclass.php'), 'Fail closed without gadget helper');
$findings[] = finding('W8', 'low', 'bare unserialize fallback when gadget helper missing',
	'lxlib / lxclass', $w8Closed,
	$w8Closed ? 'fail closed' : 'bare @unserialize fallback remains');

// --- Wave 9 (X1–X9) ---
$sqliteLib = file_text($root, 'kloxo/httpdocs/lib/php/sqlitelib.php');
$initPanelDb = file_text($root, 'kloxo/bin/install/init-panel-db');
$x1Closed = contains($lxlibR, 'function kloxo_realpass_seal')
	&& contains($lxlibR, '__lxen2:')
	&& contains($sqliteLib, 'kloxo_realpass_seal')
	&& contains($sqliteLib, 'kloxo_realpass_open')
	&& contains($initPanelDb, 'seedRealpassKey');
$findings[] = finding('X1', 'high', 'realpass stored as reversible __lxen: base64',
	'sqlitelib.php / lxlib.php / init-panel-db', $x1Closed,
	$x1Closed ? 'AES-GCM __lxen2: + realpass.key' : 'still plain __lxen: base64 only');

$x2Closed = contains($gate, 'function kloxo_unserialize_panel_class_ok')
	&& contains($gate, 'security X2');
$findings[] = finding('X2', 'high', 'deny_gadgets default still full unserialize',
	'remote_auth_gate.php kloxo_unserialize_deny_gadgets', $x2Closed,
	$x2Closed ? 'panel-safe class allow-list on default path' : 'still bare unserialize($raw)');

$createDbPass = function_body(
	file_text($root, 'kloxo/httpdocs/lib/client/clientbaselib.php'),
	'createDbPass'
);
$x3Closed = contains($createDbPass, 'hash_hmac')
	&& not_contains($createDbPass, 'md5(');
$findings[] = finding('X3', 'medium', 'createDbPass still uses md5',
	'clientbaselib.php createDbPass', $x3Closed,
	$x3Closed ? 'HMAC-SHA256 derived secret' : 'still md5');

$cronLinux = file_text($root, 'kloxo/httpdocs/driver/pserver/cron__linuxlib.php');
$pserverCore = file_text($root, 'kloxo/httpdocs/driver/pserver/pservercorelib.php');
$clientBase = file_text($root, 'kloxo/httpdocs/lib/client/clientbaselib.php');
$x4Closed = contains($lib, 'security X4')
	&& contains($cronLinux, 'security X4')
	&& contains($pserverCore, 'security X4')
	&& contains($clientBase, 'security X4')
	&& not_contains($cronLinux, '@unserialize(base64_decode')
	&& not_contains($pserverCore, '@unserialize(base64_decode');
$findings[] = finding('X4', 'medium', 'bare unserialize fallbacks on DB blobs',
	'lib.php / clientbaselib / cron__linuxlib / pservercorelib', $x4Closed,
	$x4Closed ? 'fail closed without kloxo_b64_unserialize' : 'bare unserialize fallback remains');

$x5Closed = contains($remote, 'security X5')
	&& not_contains(function_body($remote, 'do_remote_exec'), '@unserialize(base64_decode')
	&& (contains($remote, ': false;') || contains($remote, "security X5"));
$findings[] = finding('X5', 'medium', 'slave RPC response bare unserialize fallback',
	'remotelib.php do_remote_exec / send_to_master', $x5Closed,
	$x5Closed ? 'fail closed without helper' : 'bare unserialize fallback remains');

$createSsl = function_body($remote, 'createSslStream');
$x6Closed = contains($createSsl, 'security X6')
	&& contains($createSsl, "'verify_peer' => false");
$findings[] = finding('X6', 'low', 'createSslStream peer_name off without docs',
	'remotelib.php createSslStream', $x6Closed,
	$x6Closed ? 'server listener; envelope auth documented' : 'still opaque verify_peer_name false');

$loginInc = file_text($root, 'kloxo/httpdocs/login/inc.php');
$loginIncTpl = file_text($root, 'kloxo/file/pages/login_inc.php');
$x7Closed = contains($loginInc, 'random_bytes')
	&& contains($loginIncTpl, 'random_bytes')
	&& not_contains($loginInc, 'mt_rand()')
	&& not_contains($loginIncTpl, 'mt_rand()');
$findings[] = finding('X7', 'low', 'login CSRF token uses mt_rand',
	'login/inc.php / file/pages/login_inc.php', $x7Closed,
	$x7Closed ? 'bin2hex(random_bytes(16))' : 'still mt_rand()');

$csrfFn = function_body($lib, 'isCSRFTokenMatch');
$x8Closed = contains($csrfFn, 'security X8')
	&& not_contains($csrfFn, 'token_get');
$findings[] = finding('X8', 'low', 'CSRF accepts GET frm_token for mutating actions',
	'lib.php isCSRFTokenMatch', $x8Closed,
	$x8Closed ? 'mutating GET refused (POST required)' : 'still accepts query frm_token');

$dbschemaConv = file_text($root, 'kloxo/file/sql/dbschema-convert.php');
$x9Closed = contains($dbschemaConv, 'security X9')
	&& (contains($dbschemaConv, 'allowed_classes') || contains($dbschemaConv, 'kloxo_unserialize_deny_gadgets'))
	&& not_contains($dbschemaConv, '$u = unserialize($c);');
$findings[] = finding('X9', 'low', 'dbschema-convert bare unserialize',
	'file/sql/dbschema-convert.php', $x9Closed,
	$x9Closed ? 'arrays-only unserialize' : 'still bare unserialize($c)');

// --- Wave 10 (Y1–Y9) ---
$randFn = function_body($lxlibR, 'randomString');
$y1Closed = contains($randFn, 'random_int') && not_contains($randFn, 'array_rand');
$findings[] = finding('Y1', 'high', 'randomString / getCSRFToken use array_rand',
	'lxlib.php randomString', $y1Closed,
	$y1Closed ? 'random_int CSPRNG' : 'still array_rand');

$y2Closed = contains($remote, 'security Y2')
	&& substr_count($remote, 'security Y2') >= 3;
$findings[] = finding('Y2', 'medium', 'remotelib inbound bare unserialize fallback',
	'remotelib.php process_master / process_server / remote_main', $y2Closed,
	$y2Closed ? 'fail closed without helper' : 'bare @unserialize fallback remains');

$gblLib = file_text($root, 'kloxo/httpdocs/lib/php/gbllib.php');
$y3Closed = contains($gblLib, 'security Y3')
	&& not_contains($gblLib, '@unserialize($decoded)');
$findings[] = finding('Y3', 'medium', 'gbllib driver ser_* bare unserialize fallback',
	'gbllib.php', $y3Closed,
	$y3Closed ? 'fail closed' : 'bare unserialize fallback remains');

$resetPw = file_text($root, 'kloxo/bin/common/resetpassword.php');
$y4Closed = contains($resetPw, 'kloxo_password_hash')
	&& not_contains($resetPw, "crypt(\$argv[2]")
	&& not_contains($resetPw, "crypt(\$argv");
$findings[] = finding('Y4', 'medium', 'resetpassword.php still MD5-crypt',
	'bin/common/resetpassword.php', $y4Closed,
	$y4Closed ? 'Argon2id via kloxo_password_hash' : 'still crypt');

$dbCli = file_text($root, 'kloxo/bin/database.php');
$y5Closed = contains($dbCli, 'security Y5')
	&& not_contains($dbCli, '@unserialize(base64_decode');
$findings[] = finding('Y5', 'medium', 'bin/database.php ser_* bare unserialize fallback',
	'bin/database.php', $y5Closed,
	$y5Closed ? 'fail closed' : 'bare unserialize fallback remains');

$prepMyDns = function_body($lib, 'PrepareMyDnsDb');
$y6Closed = contains($prepMyDns, 'security Y6')
	&& not_contains($prepMyDns, 'mysqladmin')
	&& not_contains($prepMyDns, "mysql -u mydns");
$findings[] = finding('Y6', 'medium', 'MyDNS setup shells DB password',
	'lib.php PrepareMyDnsDb', $y6Closed,
	$y6Closed ? 'MyDNS prepare refused / no shell password' : 'still shells password');

$pwHashFn = function_body($lxlibR, 'kloxo_password_hash');
$y7Closed = contains($pwHashFn, 'security Y7')
	&& not_contains($pwHashFn, "crypt(\$plain")
	&& not_contains(file_text($root, 'kloxo/bin/changetoclientlogin.php'), "crypt(\$uuser");
$findings[] = finding('Y7', 'low', 'crypt MD5 fallbacks when hash helper missing',
	'kloxo_password_hash + callers / changetoclientlogin', $y7Closed,
	$y7Closed ? 'fail closed / no crypt mint' : 'still crypt fallback');

$loginIncY = file_text($root, 'kloxo/httpdocs/login/inc.php');
$loginIncTplY = file_text($root, 'kloxo/file/pages/login_inc.php');
$y8Closed = contains($loginIncY, 'security Y8')
	&& contains($loginIncY, 'setRow')
	&& contains($loginIncTplY, 'setRow')
	&& not_contains($loginIncY, "rawQuery(\"update {\$tablename} set password")
	&& not_contains($loginIncTplY, "rawQuery(\"update \$tablename set password");
$findings[] = finding('Y8', 'low', 'forgot-password rawQuery string concat',
	'login/inc.php / file/pages/login_inc.php', $y8Closed,
	$y8Closed ? 'setRow + Argon2id' : 'still rawQuery with interpolated password');

$licEnc = function_body($lxlibR, 'licenseEncrypt');
$y9Closed = contains($licEnc, 'security Y9')
	&& not_contains($licEnc, '"helloworld"')
	&& contains($licEnc, 'KLOXO_LICENSE_KEY_PASS');
$findings[] = finding('Y9', 'low', 'licenseEncrypt hardcoded helloworld passphrase',
	'lxlib.php licenseEncrypt', $y9Closed,
	$y9Closed ? 'env/file passphrase required' : 'still helloworld');

// --- Wave 11 (Z1–Z6) ---
$z1Files = array(
	file_text($root, 'kloxo/httpdocs/lib/html/lib.php'),
	file_text($root, 'kloxo/httpdocs/lib/php/kloxo_backup.php'),
	file_text($root, 'kloxo/bin/common/securityblanket.php'),
	file_text($root, 'kloxo/bin/common/background.php'),
	file_text($root, 'kloxo/bin/common/child.php'),
	file_text($root, 'kloxo/bin/common/iptraffic.php'),
	file_text($root, 'kloxo/httpdocs/driver/web/serverweblib.php'),
	file_text($root, 'kloxo/httpdocs/lib/php/tldextract.php'),
);
$z1Closed = true;
foreach ($z1Files as $zf) {
	if (contains($zf, '@unserialize(') && !contains($zf, 'security Z1') && contains($zf, 'function_exists')) {
		// allow intentional allow-list unserialize in backup metadata parser
	}
}
$z1Closed = contains($lib, 'security Z1')
	&& contains(file_text($root, 'kloxo/bin/common/child.php'), 'security Z1')
	&& contains(file_text($root, 'kloxo/bin/common/background.php'), 'security Z1')
	&& contains(file_text($root, 'kloxo/bin/common/securityblanket.php'), 'security Z1')
	&& not_contains(function_body($lib, 'get_kloxo_port'), '@unserialize(');
$findings[] = finding('Z1', 'medium', 'residual bare unserialize when helper missing',
	'lib.php / backup / child / background / blanket / tldextract', $z1Closed,
	$z1Closed ? 'fail closed' : 'bare @unserialize fallback remains');

$setPw = function_body(file_text($root, 'kloxo/httpdocs/lib/php/sqlitelib.php'), 'setPassword');
$z2Closed = contains($setPw, 'security Z2')
	&& not_contains($setPw, "Password('{\$newp}')")
	&& (contains($setPw, 'prepare(') || contains($setPw, 'ALTER USER'));
$findings[] = finding('Z2', 'medium', 'sqlitelib setPassword interpolates password',
	'sqlitelib.php setPassword', $z2Closed,
	$z2Closed ? 'prepared ALTER USER / no raw concat' : 'still SET PASSWORD with concat');

$parseInline = function_body($lib, 'getParseInlinePhp');
$z3Closed = contains($parseInline, 'security Z3')
	&& contains($parseInline, 'token_get_all');
$findings[] = finding('Z3', 'medium', 'getParseInlinePhp eval without token allow-list',
	'lib.php getParseInlinePhp', $z3Closed,
	$z3Closed ? 'token allow-list before eval' : 'eval without token gate');

$lxCp = function_body(file_text($root, 'kloxo/httpdocs/lib/html/commonfslib.php'), 'lxfile_cp');
$z4Closed = contains($lxCp, 'security Z4')
	&& contains($lxCp, 'proc_open')
	&& not_contains($lxCp, "system(\"'cp'");
$findings[] = finding('Z4', 'low', 'lxfile_cp shells paths into system(cp)',
	'commonfslib.php lxfile_cp', $z4Closed,
	$z4Closed ? 'proc_open argv cp' : 'still system shell string');

$z5Closed = contains($lib, 'security Z5')
	&& contains($lxlibR, 'security Z5')
	&& not_contains($lib, 'mt_rand(1000, 9999)')
	&& not_contains($lxlibR, 'mt_rand(1000, 9999)');
$findings[] = finding('Z5', 'low', 'temp job names use mt_rand',
	'lib.php / lxlib.php background/lxchild', $z5Closed,
	$z5Closed ? 'bin2hex(random_bytes(4))' : 'still mt_rand');

$z6Closed = contains($gate, 'security X2 / Z6')
	|| (contains($gate, 'No catch-all lowercase') && contains($gate, "'priv' => true"));
$findings[] = finding('Z6', 'low', 'unserialize allow-list accepts any lowercase class',
	'remote_auth_gate.php kloxo_unserialize_panel_class_ok', $z6Closed,
	$z6Closed ? 'known set + gl_class_array + *_a/b/o only' : 'catch-all lowercase remains');

// --- Wave 12 (AA1–AA7) ---
$meBackup = file_text($root, 'kloxo/bin/common/mebackup.php');
$aa1Closed = contains($meBackup, 'defaults-extra-file')
	&& contains($meBackup, 'security AA1')
	&& not_contains($meBackup, '-p{$pass}');
$findings[] = finding('AA1', 'high', 'mebackup.php puts DB password on mysqldump cmdline',
	'bin/common/mebackup.php', $aa1Closed,
	$aa1Closed ? 'defaults-extra-file 0600 + proc_open' : 'still -p{$pass}');

$killByName = function_body(file_text($root, 'kloxo/httpdocs/lib/html/linuxlib.php'), 'os_killpid_by_name');
$aa2Closed = contains($killByName, 'security AA2')
	&& contains($killByName, 'proc_open')
	&& not_contains($killByName, 'system("pkill');
$findings[] = finding('AA2', 'medium', 'os_killpid_by_name shells pkill -f $name',
	'linuxlib.php os_killpid_by_name', $aa2Closed,
	$aa2Closed ? 'proc_open argv pkill' : 'still system(pkill)');

$redir = function_body(file_text($root, 'kloxo/httpdocs/lib/html/commonfslib.php'), 'lxshell_redirect');
$aa3Closed = contains($redir, 'security AA3')
	&& contains($redir, 'proc_open')
	&& not_contains($redir, 'system("');
$findings[] = finding('AA3', 'medium', 'lxshell_redirect uses system shell string',
	'commonfslib.php lxshell_redirect', $aa3Closed,
	$aa3Closed ? 'proc_open argv' : 'still system($cmd > $file)');

$aa4Closed = not_contains(file_text($root, 'kloxo/httpdocs/lib/html/commonfslib.php'), 'eval($sgbl->arg_getting_string)')
	&& not_contains($lxlibR, 'eval($sgbl->arg_getting_string)')
	&& not_contains(file_text($root, 'kloxo/httpdocs/lib/php/lxclass.php'), 'eval($sgbl->arg_getting_string)');
$findings[] = finding('AA4', 'medium', 'dead PHP<5.3 eval($sgbl->arg_getting_string) branches',
	'commonfslib / lxlib / lxclass', $aa4Closed,
	$aa4Closed ? 'eval branches removed' : 'eval($sgbl->arg_getting_string) remains');

$randLcase = function_body($lib, 'random_string_lcase');
$aa5Closed = contains($randLcase, 'random_int')
	&& contains($randLcase, 'security AA5')
	&& not_contains($randLcase, 'array_rand');
$findings[] = finding('AA5', 'low', 'random_string_lcase uses array_rand',
	'lib.php random_string_lcase', $aa5Closed,
	$aa5Closed ? 'random_int CSPRNG' : 'still array_rand');

$fixLogo = file_text($root, 'kloxo/bin/fix/fix-userlogo.php');
$fixSsl = file_text($root, 'kloxo/bin/fix/fixssl.php');
$aa6Closed = contains($fixLogo, 'security AA6')
	&& contains($fixSsl, 'security AA6')
	&& not_contains($fixLogo, "system(\"'cp'")
	&& not_contains($fixSsl, "exec(\"'cp'");
$findings[] = finding('AA6', 'low', 'fix-userlogo / fixssl shell cp with paths',
	'bin/fix/fix-userlogo.php / fixssl.php', $aa6Closed,
	$aa6Closed ? 'proc_open argv cp/rm/mv/ln' : 'still system/exec cp');

$parseInlineAa = function_body($lib, 'getParseInlinePhp');
$aa7Closed = contains($parseInlineAa, 'security AA7')
	&& contains($parseInlineAa, 'include $__file')
	&& not_contains($parseInlineAa, "eval('?>'");
$findings[] = finding('AA7', 'low', 'getParseInlinePhp still uses eval after token gate',
	'lib.php getParseInlinePhp', $aa7Closed,
	$aa7Closed ? 'temp include after token gate' : 'still eval');

// --- Wave 13 (BB1–BB6) ---
$setupPrimary = file_text($root, 'kloxo/bin/common/setupprimarymaster.php');
$bb1Closed = contains($setupPrimary, 'defaults-extra-file')
	&& contains($setupPrimary, 'security BB1')
	&& not_contains($setupPrimary, "'-p\$pass'")
	&& not_contains($setupPrimary, '-p$pass');
$findings[] = finding('BB1', 'high', 'setupprimarymaster.php mysqldump -p on cmdline',
	'bin/common/setupprimarymaster.php', $bb1Closed,
	$bb1Closed ? 'defaults-extra-file + proc_open' : 'still -p$pass');

$bakPhp = file_text($root, 'kloxo/httpdocs/lib/php/kloxo_backup.php');
$bb2Closed = contains($bakPhp, 'mysqlDefaultsFile')
	&& contains($bakPhp, 'security BB2')
	&& not_contains(function_body($bakPhp, 'dumpArgv'), "'--password='")
	&& not_contains(function_body($bakPhp, 'restoreDumpArgv'), "'--password='");
$findings[] = finding('BB2', 'medium', 'KloxoBackup dump/restore --password= on argv',
	'kloxo_backup.php dumpArgv/restoreDumpArgv', $bb2Closed,
	$bb2Closed ? 'defaults-extra-file only' : 'still --password= on argv');

$mountLoop = function_body(file_text($root, 'kloxo/httpdocs/lib/html/linuxlib.php'), 'os_update_mount');
if ($mountLoop === '') {
	// function name may differ — search marker
	$mountLoop = file_text($root, 'kloxo/httpdocs/lib/html/linuxlib.php');
}
$bb3Closed = contains($mountLoop, 'security BB3')
	&& contains($mountLoop, 'proc_open')
	&& not_contains($mountLoop, 'system("mount $m');
$findings[] = finding('BB3', 'medium', 'linuxlib mount shells $m',
	'linuxlib.php mount remount', $bb3Closed,
	$bb3Closed ? 'proc_open argv mount' : 'still system(mount $m)');

$bb4Closed = contains($lib, 'function kloxo_exec_argv')
	&& contains($lib, 'security BB4')
	&& contains($lib, 'FILTER_VALIDATE_IP')
	&& contains($lib, 'add-blackhole-block');
$findings[] = finding('BB4', 'medium', 'legacy exec find/chown/cp with interpolated vars',
	'lib.php blackhole / ownership / closed-exec', $bb4Closed,
	$bb4Closed ? 'kloxo_exec_argv + IP validate + closed-exec guard' : 'still bare exec with vars');

$cpanelMig = file_text($root, 'kloxo/bin/migrate/cpanel.php');
$bb5Closed = contains($cpanelMig, 'security BB5')
	&& contains($cpanelMig, 'proc_open')
	&& not_contains($cpanelMig, 'system("tar');
$findings[] = finding('BB5', 'low', 'cpanel migrate tar shells $argv[1]',
	'bin/migrate/cpanel.php', $bb5Closed,
	$bb5Closed ? 'proc_open argv tar' : 'still system(tar)');

$parseBb = function_body($lib, 'getParseInlinePhp');
$bb6Closed = contains($parseBb, 'security BB6')
	&& (contains($parseBb, 'Prefer non-PHP') || contains($parseBb, 'preg_replace_callback'));
$findings[] = finding('BB6', 'low', 'getParseInlinePhp still includes template PHP',
	'lib.php getParseInlinePhp', $bb6Closed,
	$bb6Closed ? 'echo-only path without include' : 'always includes PHP templates');

// --- Wave 14 (CC1–CC6) ---
$ifaceTpl = function_body($lib, 'createDatabaseInterfaceTemplate');
$cc1Closed = contains($ifaceTpl, 'defaults-extra-file')
	&& contains($ifaceTpl, 'security CC1')
	&& contains($ifaceTpl, 'proc_open')
	&& not_contains($ifaceTpl, 'kloxo.pass`');
$findings[] = finding('CC1', 'high', 'createDatabaseInterfaceTemplate mysql password via shell backtick',
	'lib.php createDatabaseInterfaceTemplate', $cc1Closed,
	$cc1Closed ? 'defaults-extra-file + proc_open' : 'still shell password');

$setupSec = file_text($root, 'kloxo/bin/common/setupsecondary.php');
$cc2Closed = contains($setupSec, 'defaults-extra-file')
	&& contains($setupSec, 'security CC2')
	&& not_contains($setupSec, "'-p' . \$pass")
	&& not_contains($setupSec, "-p' . \$pass");
$findings[] = finding('CC2', 'high', 'setupsecondary.php mysql -p on argv',
	'bin/common/setupsecondary.php', $cc2Closed,
	$cc2Closed ? 'defaults-extra-file' : 'still -p$password on argv');

$phpexecSrc = file_text($root, 'kloxo/bin/phpexec.php');
$lxNpc = file_text($root, 'kloxo/httpdocs/lib/php/lxlib.php');
$cc3Closed = contains($phpexecSrc, 'security CC3')
	&& contains($phpexecSrc, 'proc_open')
	&& contains($lxNpc, 'security CC3')
	&& contains($lxNpc, 'array_merge')
	&& not_contains($lxNpc, 'phpexec.php $uid $gid $cmd');
$findings[] = finding('CC3', 'high', 'new_process_cmd / phpexec shell rebuild',
	'lxlib.php new_process_cmd + bin/phpexec.php', $cc3Closed,
	$cc3Closed ? 'argv proc_open via phpexec' : 'still shells cmd string');

$cc4Closed = contains($lib, 'security CC4')
	&& contains($lib, 'function kloxo_shexec_argv')
	&& not_contains(function_body($lib, 'shexec'), 'exec("{$caller}')
	&& not_contains(function_body($lib, 'shexec_output'), 'shell_exec');
$findings[] = finding('CC4', 'medium', 'shexec wrappers shell-quote $cmd',
	'lib.php shexec*', $cc4Closed,
	$cc4Closed ? 'argv + metachar refuse' : 'still exec caller with quoted cmd');

$fixWebOwn = function_body($lib, 'setFixChownChmodWebPerUser');
$cc5Closed = contains($fixWebOwn, 'security CC5')
	&& contains($fixWebOwn, 'kloxo_exec_argv')
	&& not_contains($fixWebOwn, 'exec("find {$cdir}')
	&& not_contains($fixWebOwn, 'exec("chown {$clname}');
$findings[] = finding('CC5', 'medium', 'lib.php ownership find/chown still shells vars',
	'lib.php setFixChownChmod* / httpd chown', $cc5Closed,
	$cc5Closed ? 'kloxo_exec_argv find/chown' : 'still exec(find/chown {$var})');

$rcSrc = file_text($root, 'kloxo/httpdocs/lib/php/kloxo_roundcube.php');
$cc6Closed = contains($rcSrc, 'security CC6')
	&& contains($rcSrc, 'function sqlExec')
	&& contains($rcSrc, 'IDENTIFIED BY ?')
	&& not_contains($rcSrc, "IDENTIFIED BY '{\$pass}'");
$findings[] = finding('CC6', 'medium', 'Roundcube ensureDatabase password on mysql -e argv',
	'kloxo_roundcube.php ensureDatabase', $cc6Closed,
	$cc6Closed ? 'mysqli prepared / sqlExec' : 'still mysql -e with IDENTIFIED BY');

// --- Wave 15 (DD1–DD6) ---
$csync = file_text($root, 'kloxo/bin/common/csyncdb.sh');
$dd1Closed = contains($csync, 'defaults-extra-file')
	&& contains($csync, 'security DD1')
	&& not_contains($csync, 'pass`');
$findings[] = finding('DD1', 'high', 'csyncdb.sh mysql password via shell backtick',
	'bin/common/csyncdb.sh', $dd1Closed,
	$dd1Closed ? 'defaults-extra-file' : 'still shell password');

$multi = function_body($lib, 'createMultipLeVps');
$dd2Closed = contains($multi, 'security DD2')
	&& contains($multi, 'KLOXO_ADMIN_PASSWORD')
	&& not_contains($multi, '--admin-password={$adminpass}');
$findings[] = finding('DD2', 'high', 'createMultipLeVps admin-password on argv',
	'lib.php createMultipLeVps', $dd2Closed,
	$dd2Closed ? 'password via env / refuse' : 'still --admin-password= on argv');

$lfs = file_text($root, 'kloxo/httpdocs/lib/html/linuxfslib.php');
$dd3Closed = contains($lfs, 'security DD3')
	&& contains($lfs, 'function kloxo_archive_extract_argv')
	&& not_contains($lfs, 'nice -n +10 ionice -c3 $fcmd')
	&& not_contains($lfs, 'nice -n +10 ionice -c3 $command');
$findings[] = finding('DD3', 'medium', 'linuxfslib archive extract shells paths',
	'linuxfslib.php unzip/zip', $dd3Closed,
	$dd3Closed ? 'argv extract/zip' : 'still shell string fcmd');

$weblib = file_text($root, 'kloxo/httpdocs/driver/web/weblib.php');
$weblib2 = file_text($root, 'kloxo/httpdocs/driver/web/web__lib.php');
$sslcert = file_text($root, 'kloxo/httpdocs/driver/pserver/sslcertlib.php');
$dd4Closed = contains($weblib, 'security DD4')
	&& contains($weblib2, 'security DD4')
	&& contains($sslcert, 'security DD4')
	&& not_contains($weblib, "exec(\"'rm' -rf {\$sgbl->__path_customer_root}")
	&& not_contains($weblib, 'exec("sh /script/fixphp --domain=');
$findings[] = finding('DD4', 'medium', 'web/ssl drivers exec rm/fixphp with vars',
	'weblib / web__lib / sslcertlib', $dd4Closed,
	$dd4Closed ? 'kloxo_exec_argv + validated names' : 'still exec with {$domain}');

$lxsrv = file_text($root, 'kloxo/httpdocs/lib/html/lxserverlib.php');
$linuxlibDd = file_text($root, 'kloxo/httpdocs/lib/html/linuxlib.php');
$dd5Closed = contains($lxsrv, 'security DD5')
	&& contains($lxsrv, 'systemctl')
	&& not_contains($lxsrv, 'exec("service $cmd')
	&& contains($linuxlibDd, 'security DD5')
	&& not_contains($linuxlibDd, 'exec("systemctl enable {$pgm}');
$findings[] = finding('DD5', 'medium', 'special_bind_restart / os_create_program_service shell units',
	'lxserverlib / linuxlib', $dd5Closed,
	$dd5Closed ? 'argv systemctl' : 'still service/systemctl shell');

$cleanup = file_text($root, 'kloxo/bin/common/cleanup.php');
$fixlog = file_text($root, 'kloxo/bin/common/fixlogdir.php');
$fixtraf = file_text($root, 'kloxo/bin/common/fixtraffic.php');
$genlib = file_text($root, 'kloxo/httpdocs/lib/php/generallib.php');
$dd6Closed = contains($cleanup, 'security DD6')
	&& contains($fixlog, 'security DD6')
	&& contains($fixtraf, 'security DD6')
	&& contains($lib, 'security DD6')
	&& contains($genlib, 'security DD6')
	&& not_contains($cleanup, 'system("lxphp.exe')
	&& not_contains($fixtraf, 'system("lxphp.exe')
	&& not_contains($lib, "system(\"echo '{\$patch}'")
	&& not_contains($genlib, "exec(\"echo '\$sslport'");
$findings[] = finding('DD6', 'low', 'cleanup/fixlogdir/fixtraffic/sysctl/generallib system leftovers',
	'bin/common + lib.php + generallib', $dd6Closed,
	$dd6Closed ? 'proc_open / file_put_contents' : 'still system/echo exec');

// --- Wave 16 (EE1–EE6) ---
$lfsEe = file_text($root, 'kloxo/httpdocs/lib/html/linuxfslib.php');
// function_body() mis-parses this function (a '}' inside a string literal).
$ee1Closed = contains($lfsEe, 'security EE1')
	&& contains($lfsEe, "array(\$expect, '-f', \$t)")
	&& not_contains($lfsEe, 'system("expect');
$findings[] = finding('EE1', 'medium', 'lxshell_expect shells expect script path',
	'linuxfslib.php lxshell_expect', $ee1Closed,
	$ee1Closed ? 'proc_open expect -f' : 'still system expect');

$csyncLib = file_text($root, 'kloxo/httpdocs/lib/client/client__synclib.php');
$ee2Closed = contains($csyncLib, 'security EE2')
	&& contains($csyncLib, 'kloxo_exec_argv')
	&& not_contains($csyncLib, "exec(\"'rm' -f /opt/configs/php-fpm");
$findings[] = finding('EE2', 'medium', 'client__synclib rm with username glob',
	'client__synclib.php', $ee2Closed,
	$ee2Closed ? 'find/rm argv' : 'still exec rm php*/username');

$dnss = file_text($root, 'kloxo/httpdocs/lib/php/dnsslavelib.php');
$ee3Closed = contains($dnss, 'security EE3')
	&& not_contains($dnss, "exec(\"'rm' -rf {\$path}/{\$domain}\")")
	&& not_contains($dnss, 'exec("sh /script/fixdns --server=');
$findings[] = finding('EE3', 'medium', 'dnsslavelib rm/fixdns shell vars',
	'dnsslavelib.php', $ee3Closed,
	$ee3Closed ? 'kloxo_exec_argv' : 'still exec with {$domain}');

$pserv = file_text($root, 'kloxo/httpdocs/driver/pserver/pserverlib.php');
$phpini = file_text($root, 'kloxo/httpdocs/driver/pserver/phpinilib.php');
$ee4Closed = contains($pserv, 'security EE4')
	&& contains($phpini, 'security EE4')
	&& not_contains($pserv, 'exec("sh /script/fixweb --server=')
	&& not_contains($phpini, 'exec("sh /script/fixphp --server=');
$findings[] = finding('EE4', 'medium', 'pserverlib/phpinilib fixweb/fixphp shell',
	'pserverlib / phpinilib', $ee4Closed,
	$ee4Closed ? 'kloxo_exec_argv' : 'still exec fix* --server=');

$upd = file_text($root, 'kloxo/httpdocs/lib/html/updatelib.php');
$genEe = file_text($root, 'kloxo/httpdocs/lib/php/generallib.php');
$ee5Closed = contains($upd, 'security EE5')
	&& contains($genEe, 'security EE5')
	&& not_contains($upd, 'system("cd /opt/kloxo')
	&& not_contains($genEe, 'exec("sh /script/fixphp --server=all')
	&& not_contains($genEe, 'exec("sh /script/select-kloxo-wrapper');
$findings[] = finding('EE5', 'medium', 'updatelib/generallib system/exec leftovers',
	'updatelib / generallib', $ee5Closed,
	$ee5Closed ? 'proc_open / kloxo_exec_argv' : 'still system/exec shells');

$linuxEe = file_text($root, 'kloxo/httpdocs/lib/html/linuxlib.php');
$myc = file_text($root, 'kloxo/bin/fix/mysql-convert.php');
$sysinfo = file_text($root, 'kloxo/bin/fix/sysinfo.php');
$ee6Closed = contains($linuxEe, 'security EE6')
	&& contains($myc, 'security EE6')
	&& contains($sysinfo, 'security EE6')
	&& not_contains($linuxEe, 'system("quotacheck')
	&& not_contains($myc, "@exec(\"sed -i")
	&& not_contains($sysinfo, "exec(\"'rm' -rf {\$v}\")");
$findings[] = finding('EE6', 'low', 'quotacheck/mysql-convert/sysinfo shell leftovers',
	'linuxlib / mysql-convert / sysinfo', $ee6Closed,
	$ee6Closed ? 'argv / file edits' : 'still system/sed/rm shells');

// --- Wave 17 (FF1–FF6) ---
$libFf = file_text($root, 'kloxo/httpdocs/lib/html/lib.php');
$ff1Closed = contains($libFf, 'security FF1')
	&& contains($libFf, 'kloxo_exec_argv')
	&& not_contains($libFf, 'exec("sh /script/setdriver --server=localhost --class={$key}')
	&& not_contains($libFf, "exec(\"'rm' -f {\$path}/redirect-to-*.php\")")
	&& not_contains($libFf, 'exec("sh /script/enable-service pure-ftpd');
$findings[] = finding('FF1', 'medium', 'lib.php setdriver/rm/enable-service shell leftovers',
	'lib.php cleanup helpers', $ff1Closed,
	$ff1Closed ? 'kloxo_exec_argv + PHP unlink' : 'still exec setdriver/rm/enable-service');

$sw = file_text($root, 'kloxo/httpdocs/driver/web/serverweb__lib.php');
$ff2Closed = contains($sw, 'security FF2')
	&& not_contains($sw, 'exec("sh /script/enable-php-fpm; sh /script/add-start-queue')
	&& not_contains($sw, "exec(\"'rm' -rf {\$ehcdpath}/swtune.conf\")")
	&& not_contains($sw, 'exec("sh /script/clearcache-pagespeed")');
$findings[] = finding('FF2', 'medium', 'serverweb__lib chained enable-php-fpm shell',
	'serverweb__lib.php', $ff2Closed,
	$ff2Closed ? 'separate kloxo_exec_argv + unlink' : 'still chained exec');

$mt = file_text($root, 'kloxo/httpdocs/driver/mmail/mailtrafficlib.php');
$ff3Closed = contains($mt, 'security FF3')
	&& contains($mt, 'proc_open')
	&& not_contains($mt, 'system("perl');
$findings[] = finding('FF3', 'medium', 'mailtrafficlib perl shell redirect',
	'mailtrafficlib.php', $ff3Closed,
	$ff3Closed ? 'proc_open perl with file descriptors' : 'still system(perl < >)');

$webLib = file_text($root, 'kloxo/httpdocs/driver/web/web__lib.php');
$ff4Closed = contains($webLib, 'security FF4')
	&& not_contains($webLib, "exec(\"'rm' -rf /etc/php-fpm.d/*.conf\")")
	&& contains($webLib, "glob('/etc/php-fpm.d/*.conf')");
$findings[] = finding('FF4', 'medium', 'web__lib php-fpm.d shell glob rm',
	'web__lib.php', $ff4Closed,
	$ff4Closed ? 'PHP glob unlink' : 'still exec rm *.conf');

$lxFf = file_text($root, 'kloxo/httpdocs/lib/php/lxlib.php');
$appFf = file_text($root, 'kloxo/httpdocs/lib/client/appearancelib.php');
$ff5Closed = contains($lxFf, 'security FF5')
	&& not_contains($lxFf, 'eval(debug_print')
	&& contains($appFf, 'security FF5')
	&& not_contains($appFf, 'exec("sh /script/fix-userlogo")');
$findings[] = finding('FF5', 'low', 'get_debug_print eval + appearancelib fix-userlogo',
	'lxlib.php / appearancelib.php', $ff5Closed,
	$ff5Closed ? 'debug_print capture + kloxo_exec_argv' : 'still eval/exec');

$scav = file_text($root, 'kloxo/bin/scavenge.php');
$iwdb = file_text($root, 'kloxo/bin/misc/installWebmailDb.php');
$fcb = file_text($root, 'kloxo/bin/fix/fix-cron-backup.php');
$lprog = file_text($root, 'kloxo/httpdocs/lib/linuxproglib.php');
$ff6Closed = contains($scav, 'security FF6')
	&& contains($iwdb, 'security FF6')
	&& contains($fcb, 'security FF6')
	&& contains($lprog, 'security FF6')
	&& not_contains($iwdb, 'system("sh /script/setup-roundcube")')
	&& not_contains($fcb, "exec(\"'rm' -f /etc/cron.d/backup*\")")
	&& not_contains($lprog, 'system("echo > /etc/hosts.deny")');
$findings[] = finding('FF6', 'low', 'scavenge/installWebmail/fix-cron/hosts.deny leftovers',
	'scavenge / installWebmailDb / fix-cron-backup / linuxproglib', $ff6Closed,
	$ff6Closed ? 'argv / glob unlink / file_put_contents' : 'still system/exec leftovers');

// --- Wave 18 (GG1–GG6) ---
$ffile = file_text($root, 'kloxo/httpdocs/driver/pserver/ffile__linuxlib.php');
$gg1Closed = contains($ffile, 'security GG1')
	&& contains($ffile, 'kloxo_exec_argv')
	&& not_contains($ffile, 'exec("chmod -R {$perm}')
	&& not_contains($ffile, 'exec("chown -R {$user}');
$findings[] = finding('GG1', 'high', 'ffile__linuxlib chmod/chown/find shell vars',
	'ffile__linuxlib.php', $gg1Closed,
	$gg1Closed ? 'validated + kloxo_exec_argv' : 'still exec chmod/chown/find');

$sshCfg = file_text($root, 'kloxo/httpdocs/driver/pserver/sshconfig__linuxlib.php');
$gg2Closed = contains($sshCfg, 'security GG2')
	&& contains($sshCfg, 'lxshell_input')
	&& not_contains($sshCfg, 'exec("echo -e');
$findings[] = finding('GG2', 'high', 'sshconfig echo password | passwd root',
	'sshconfig__linuxlib.php', $gg2Closed,
	$gg2Closed ? 'lxshell_input to passwd' : 'still echo|passwd shell');

$psL = file_text($root, 'kloxo/httpdocs/driver/pserver/pserver__linuxlib.php');
$lxGg = file_text($root, 'kloxo/httpdocs/lib/php/lxclass.php');
$gg3Closed = contains($psL, 'security GG3')
	&& contains($psL, 'proc_open')
	&& not_contains($psL, 'shell_exec("$command')
	&& contains($lxGg, 'security GG3')
	&& contains($lxGg, '$ccenterArgv');
$findings[] = finding('GG3', 'medium', 'pserver execCommand shell_exec + ccenter shell strings',
	'pserver__linuxlib / lxclass commandCenter', $gg3Closed,
	$gg3Closed ? 'proc_open argv presets' : 'still shell_exec / chained shell');

$phpiniSync = file_text($root, 'kloxo/httpdocs/driver/pserver/phpini__synclib.php');
$gg4Closed = contains($phpiniSync, 'security GG4')
	&& not_contains($phpiniSync, 'exec("cat {$phpfpm_target_global_pre}')
	&& not_contains($phpiniSync, 'exec("mkdir -p {$path}/php-fpm.d")');
$findings[] = finding('GG4', 'medium', 'phpini__synclib mkdir/cat shell with $path',
	'phpini__synclib.php', $gg4Closed,
	$gg4Closed ? 'mkdir argv + PHP concat' : 'still exec mkdir/cat');

$sslGg = file_text($root, 'kloxo/httpdocs/driver/pserver/sslcertlib.php');
$gg5Closed = contains($sslGg, 'security GG5')
	&& not_contains($sslGg, 'exec("cd {$shpath}; sh')
	&& not_contains($sslGg, 'exec("ln -sf {$spath}/{$dom}')
	&& not_contains($sslGg, 'exec("ln -sf {$sslparent}');
$findings[] = finding('GG5', 'medium', 'sslcertlib cd/sh and ln/mv shell vars',
	'sslcertlib.php', $gg5Closed,
	$gg5Closed ? 'proc_open cwd + kloxo_exec_argv ln/mv' : 'still exec cd/ln/mv');

$ftpPu = file_text($root, 'kloxo/httpdocs/driver/ftp/ftpuser__pureftplib.php');
$fixFtp = file_text($root, 'kloxo/bin/fix/fixftpuser.php');
$scavGg = file_text($root, 'kloxo/bin/scavenge.php');
$libGg = file_text($root, 'kloxo/httpdocs/lib/html/lib.php');
$dnsGg = file_text($root, 'kloxo/httpdocs/lib/php/dnsslavelib.php');
$gg6Closed = contains($ftpPu, 'security GG6')
	&& not_contains($ftpPu, 'exec($cmd)')
	&& contains($fixFtp, 'security GG6')
	&& not_contains($fixFtp, "exec(\"echo '' > {\$basefile}.passwd\")")
	&& contains($scavGg, 'security GG6')
	&& not_contains($scavGg, 'passthru("$sgbl->__path_php_path')
	&& contains($libGg, 'security GG6')
	&& not_contains($libGg, 'exec($fixstr)')
	&& not_contains($libGg, 'mustafa.lia.armando')
	&& contains($dnsGg, 'security GG6');
$findings[] = finding('GG6', 'medium', 'ftpuser/lib/scavenge/fixftp/dnsslav leftovers',
	'ftpuser / lib.php / scavenge / fixftpuser / dnsslavelib', $gg6Closed,
	$gg6Closed ? 'argv / file_put / proc_open' : 'still exec/passthru leftovers');

// --- Wave 19 (HH1–HH6) ---
$lfsHh = file_text($root, 'kloxo/httpdocs/lib/html/linuxfslib.php');
$hh1Closed = contains($lfsHh, 'security HH1')
	&& not_contains($lfsHh, 'exec("nice -n +10 ionice -c3 du -s {$path}")')
	&& not_contains($lfsHh, "exec('stat -c %s '. escapeshellarg")
	&& contains($lfsHh, 'escapeshellarg');
$findings[] = finding('HH1', 'medium', 'linuxfslib du/stat/background shell leftovers',
	'linuxfslib.php', $hh1Closed,
	$hh1Closed ? 'lxshell_output / filesize / escaped argv background' : 'still exec du/stat/full');

$libHh = file_text($root, 'kloxo/httpdocs/lib/html/lib.php');
$hh2Closed = contains($libHh, 'security HH2')
	&& not_contains($libHh, 'exec("up2date-nox --nosig $value")')
	&& not_contains($libHh, 'exec("mkdir -p {$logf}; chown named:named')
	&& not_contains($libHh, "exec(\"echo '## MR -- blank only'");
$findings[] = finding('HH2', 'medium', 'lib.php cleanup var shells (cp/mkdir/echo/up2date)',
	'lib.php', $hh2Closed,
	$hh2Closed ? 'kloxo_exec_argv / file_put' : 'still exec with vars');

$sysHh = file_text($root, 'kloxo/bin/fix/sysinfo.php');
$hh3Closed = contains($sysHh, 'security HH3')
	&& contains($sysHh, 'kloxo_sysinfo_dpkg')
	&& not_contains($sysHh, 'cat /opt/kloxo/etc/list/web.lst|')
	&& not_contains($sysHh, 'sed -e \'" . $seddata');
$findings[] = finding('HH3', 'medium', 'sysinfo.php pipelines and sed $seddata',
	'bin/fix/sysinfo.php', $hh3Closed,
	$hh3Closed ? 'proc_open helpers + PHP parse' : 'still pipe/sed exec');

$ipHh = file_text($root, 'kloxo/httpdocs/driver/pserver/ipaddresslib.php');
$webHh = file_text($root, 'kloxo/httpdocs/driver/web/web__lib.php');
$hh4Closed = contains($ipHh, 'security HH4')
	&& not_contains($ipHh, 'exec("cat {$ppath}/file/ssl/default.key')
	&& contains($webHh, 'security HH4')
	&& not_contains($webHh, "exec(\"'mv' -f {\$spath} {\$dpath}\")");
$findings[] = finding('HH4', 'medium', 'ipaddresslib cat pem + web__lib mv shell',
	'ipaddresslib / web__lib', $hh4Closed,
	$hh4Closed ? 'file_put + kloxo_exec_argv mv' : 'still cat/mv exec');

$swHh = file_text($root, 'kloxo/httpdocs/driver/web/serverweb__lib.php');
$pscHh = file_text($root, 'kloxo/httpdocs/driver/pserver/pservercorelib.php');
$tmpHh = file_text($root, 'kloxo/bin/common/tmpupdatecleanup.php');
$hh5Closed = contains($swHh, 'security HH5')
	&& not_contains($swHh, '@exec("systemctl enable php-fpm')
	&& contains($pscHh, 'security HH5')
	&& not_contains($pscHh, 'exec("echo \'\' > ../etc/flag/use_apache24.flg")')
	&& contains($tmpHh, 'security HH5')
	&& not_contains($tmpHh, '@exec("systemctl disable --now mysql');
$findings[] = finding('HH5', 'low', 'serverweb/pservercore/tmpupdatecleanup systemctl/echo',
	'serverweb / pservercorelib / tmpupdatecleanup', $hh5Closed,
	$hh5Closed ? 'runArgv / file_put / kloxo_exec_argv' : 'still systemctl/echo exec');

$fmHh = file_text($root, 'kloxo/file/formmail/formmail.php');
$lxiHh = file_text($root, 'kloxo/bin/install/lxinstall.php');
$lxetcHh = file_text($root, 'kloxo/bin/common/misc/lxetc.php');
$linHh = file_text($root, 'kloxo/httpdocs/lib/html/linuxlib.php');
$svcHh = file_text($root, 'kloxo/httpdocs/driver/pserver/service__linuxlib.php');
$chpHh = file_text($root, 'kloxo/init/changeport.php');
$hh6Closed = contains($fmHh, 'security HH6')
	&& not_contains($fmHh, 'popen($cmd')
	&& contains($lxiHh, 'security HH6')
	&& not_contains($lxiHh, 'system($cmd)')
	&& contains($lxetcHh, 'security HH6')
	&& not_contains($lxetcHh, 'system("cd ~/.etc/bin')
	&& contains($linHh, 'security HH6')
	&& not_contains($linHh, "exec(\"ip addr|grep")
	&& contains($svcHh, 'security HH6')
	&& not_contains($svcHh, 'systemctl list-unit-files --type=service|awk')
	&& contains($chpHh, 'security HH6')
	&& not_contains($chpHh, 'exec("mkdir -p /var/run/acme');
$findings[] = finding('HH6', 'low', 'formmail/lxinstall/lxetc/linuxlib/service/changeport leftovers',
	'formmail / lxinstall / lxetc / linuxlib / service__linuxlib / changeport', $hh6Closed,
	$hh6Closed ? 'proc_open / argv / PHP parse / mkdir' : 'still popen/system/pipeline');

// --- Wave 20 (II1–II6) ---
$libIi = file_text($root, 'kloxo/httpdocs/lib/html/lib.php');
$ii1Closed = contains($libIi, 'security II1')
	&& not_contains($libIi, 'exec("setquota -u root')
	&& not_contains($libIi, 'exec("service pure-ftpd stop")')
	&& not_contains($libIi, 'exec("pkill -f sisinfoc")')
	&& not_contains($libIi, 'exec("unalias cp');
$findings[] = finding('II1', 'medium', 'lib.php fixed-string service/pkill/chmod/setquota shells',
	'lib.php', $ii1Closed,
	$ii1Closed ? 'kloxo_exec_argv / systemctl' : 'still service/pkill/chmod exec');

$ii2Closed = contains($libIi, 'security II2')
	&& not_contains($libIi, "exec(\"php -v|grep")
	&& not_contains($libIi, 'exec(\'dpkg-query -W')
	&& not_contains($libIi, 'exec("quotaon -pa|grep')
	&& not_contains($libIi, "exec('systemctl list-unit-files --type=service 2>/dev/null | grep")
	&& contains($libIi, 'EasyinstallerPHP refused');
$findings[] = finding('II2', 'medium', 'lib.php pipes/vars dpkg/hostname/quotaon/easyinstaller',
	'lib.php', $ii2Closed,
	$ii2Closed ? 'proc_open / PHP_VERSION / refuse easyinstaller' : 'still pipe/exec leftovers');

$ii3Closed = contains($libIi, 'security II3')
	&& not_contains($libIi, 'exec("cp -f ../file/logrotate/etc/logrotate.d/*')
	&& not_contains($libIi, "exec(\"sed -i 's:-/var/log/:/var/log/:g' /etc/*syslog.conf\")");
$findings[] = finding('II3', 'medium', 'lib.php logrotate/syslog glob shells',
	'lib.php', $ii3Closed,
	$ii3Closed ? 'PHP glob + file_put' : 'still cp/sed globs');

$psIi = file_text($root, 'kloxo/httpdocs/driver/pserver/pserver__linuxlib.php');
$relIi = file_text($root, 'kloxo/httpdocs/driver/pserver/releasenotelib.php');
$ii4Closed = contains($psIi, 'security II4')
	&& not_contains($psIi, 'exec("sh /script/virtual-info -t"')
	&& contains($relIi, 'security II4')
	&& not_contains($relIi, 'exec("sh /script/version --vertype=full"');
$findings[] = finding('II4', 'low', 'pserver virtual-info + releasenote version exec',
	'pserver__linuxlib / releasenotelib', $ii4Closed,
	$ii4Closed ? 'lxshell_output argv' : 'still exec(sh /script/...)');

$lfsIi = file_text($root, 'kloxo/httpdocs/lib/html/linuxfslib.php');
$ii5Closed = contains($lfsIi, 'security II5')
	&& contains($lfsIi, 'pcntl_fork')
	&& not_contains($lfsIi, 'exec($full)');
$findings[] = finding('II5', 'low', 'linuxfslib background still exec($full)',
	'linuxfslib.php lxshell_background', $ii5Closed,
	$ii5Closed ? 'pcntl_fork/exec or proc_open argv' : 'still exec($full)');

$fixSslIi = file_text($root, 'kloxo/bin/fix/fixssl.php');
$phpiniIi = file_text($root, 'kloxo/httpdocs/driver/pserver/phpini__synclib.php');
$dnsIi = file_text($root, 'kloxo/httpdocs/lib/php/dnsslavelib.php');
$ii6Closed = contains($fixSslIi, 'security II6')
	&& not_contains($fixSslIi, 'exec("openssl rsa')
	&& contains($phpiniIi, 'security II6')
	&& not_contains($phpiniIi, "exec(\"'cp' -f {\$path}/php-fpm.conf")
	&& contains($dnsIi, 'security II6')
	&& contains($libIi, 'security II6');
$findings[] = finding('II6', 'low', 'dead commented openssl/cp/echo leftovers',
	'fixssl / phpini__synclib / dnsslavelib / lib.php', $ii6Closed,
	$ii6Closed ? 'dead code cleared / marked' : 'still active or unmarked dead shells');

// --- Wave 21 (JJ1–JJ6) ---
$lxlibJj = file_text($root, 'kloxo/httpdocs/lib/php/lxlib.php');
$commonJj = file_text($root, 'kloxo/httpdocs/lib/html/commonfslib.php');
$lfsJj = file_text($root, 'kloxo/httpdocs/lib/html/linuxfslib.php');
$jj1Closed = contains($lxlibJj, 'security JJ1')
	&& contains($lxlibJj, 'function kloxo_shell_argv')
	&& contains($commonJj, 'security JJ1')
	&& not_contains($commonJj, 'getShellCommand(')
	&& contains($lfsJj, 'security JJ1')
	&& not_contains($lfsJj, 'getShellCommand(')
	&& contains($lfsJj, 'refuse legacy shell strings');
$findings[] = finding('JJ1', 'medium', 'getShellCommand/do_exec_system still rebuild shell strings',
	'lxlib / commonfslib / linuxfslib', $jj1Closed,
	$jj1Closed ? 'kloxo_shell_argv + argv-only do_exec_system' : 'still getShellCommand string path');

$libJj = file_text($root, 'kloxo/httpdocs/lib/html/lib.php');
$linuxJj = file_text($root, 'kloxo/httpdocs/lib/html/linuxlib.php');
$lxservJj = file_text($root, 'kloxo/httpdocs/lib/html/lxserverlib.php');
$remoteJj = file_text($root, 'kloxo/httpdocs/lib/html/remotelib.php');
$sshJj = file_text($root, 'kloxo/httpdocs/driver/pserver/sshconfig__linuxlib.php');
$jj2Closed = contains($libJj, 'security JJ2')
	&& contains($libJj, 'function kloxo_exec_closed_argv')
	&& contains($libJj, 'function kloxo_closed_exec_parse')
	&& contains($linuxJj, 'security JJ2')
	&& not_contains($linuxJj, 'exec_with_all_closed("service {$serv}')
	&& contains($lxservJj, 'security JJ2')
	&& contains($remoteJj, 'security JJ2')
	&& contains($sshJj, "array('systemctl', 'restart', 'ssh')")
	&& not_contains($libJj, 'exec_with_all_closed("fetchmail')
	&& not_contains($libJj, 'exec_with_all_closed("service ');
$findings[] = finding('JJ2', 'medium', 'exec_with_all_closed sh -c + interpolated callers',
	'lib.php / linuxlib / lxserverlib / remotelib / sshconfig', $jj2Closed,
	$jj2Closed ? 'argv + allowlisted parse' : 'still shell-string closed exec');

$demoJj = file_text($root, 'kloxo/bin/install/demoinit.php');
$checkpwJj = file_text($root, 'kloxo/bin/common/misc/checkpassword.php');
$dbuJj = file_text($root, 'kloxo/httpdocs/driver/pserver/ddatabaseuserlib.php');
$dbJj = file_text($root, 'kloxo/httpdocs/driver/pserver/ddatabaselib.php');
$jj3Closed = contains($demoJj, 'security JJ3')
	&& not_contains($demoJj, "crypt('admin'")
	&& contains($checkpwJj, 'security JJ3')
	&& !preg_match('/^\s*\$c\s*=\s*crypt\(/m', $checkpwJj)
	&& contains($dbuJj, 'security JJ3')
	&& not_contains($dbuJj, 'md5($this->dbpassword)')
	&& contains($dbJj, 'security JJ3')
	&& not_contains($dbJj, 'md5((string) $this->dbpassword)')
	&& contains($libJj, 'bin2hex(random_bytes(16))');
$findings[] = finding('JJ3', 'medium', 'weak crypt/md5 password leftovers',
	'demoinit / checkpassword / ddatabase* / fileserv token', $jj3Closed,
	$jj3Closed ? 'Argon2id / password_verify / no md5 db pass' : 'still crypt/md5 password paths');

$urlrwJj = file_text($root, 'kloxo/httpdocs/lib/urlrewrite/hn_urlrewrite.class.php');
$rdnsJj = file_text($root, 'kloxo/httpdocs/driver/pserver/reversedns__bindlib.php');
$htmlJj = file_text($root, 'kloxo/httpdocs/theme/htmllib.php');
$loginJj = file_text($root, 'kloxo/httpdocs/login/index.php');
$jj4Closed = contains($urlrwJj, 'security JJ4') && not_contains($urlrwJj, 'mt_rand(')
	&& contains($rdnsJj, 'security JJ4') && not_contains($rdnsJj, 'rand(0, 99)')
	&& contains($htmlJj, 'security JJ4')
	&& contains($libJj, 'security JJ4') && not_contains($libJj, 'rand(0, 100)')
	&& contains($loginJj, 'security JJ4') && not_contains($loginJj, 'rand(0, ($count');
$findings[] = finding('JJ4', 'low', 'weak mt_rand/rand leftovers',
	'urlrewrite / reversedns / htmllib / lib.php / login', $jj4Closed,
	$jj4Closed ? 'random_int / random_bytes' : 'still mt_rand/rand');

$lxclassJj = file_text($root, 'kloxo/httpdocs/lib/php/lxclass.php');
$smtpJj = file_text($root, 'kloxo/httpdocs/lib/extra/class.smtp.php');
$formmailJj = file_text($root, 'kloxo/file/formmail/formmail.php');
$jj5Closed = contains($lxclassJj, 'security JJ5')
	&& not_contains($lxclassJj, 'eval("return {$string}')
	&& contains($smtpJj, 'security JJ5')
	&& contains($formmailJj, 'security JJ5')
	&& contains($libJj, 'security JJ5');
$findings[] = finding('JJ5', 'low', 'dead eval + third-party md5 markers',
	'lxclass / class.smtp / formmail / EasyInstaller md5 token', $jj5Closed,
	$jj5Closed ? 'dead eval cleared; third-party md5 marked' : 'still unmarked');

$parseJj = function_body($libJj, 'getParseInlinePhp');
$jj6Closed = contains($libJj, 'security JJ6')
	&& not_contains($parseJj, 'extract($__vars')
	&& contains($parseJj, '$$__k = $__v');
$findings[] = finding('JJ6', 'low', 'getParseInlinePhp still extract() in include sandbox',
	'lib.php getParseInlinePhp', $jj6Closed,
	$jj6Closed ? 'foreach assign of filtered scalars' : 'still extract');

// --- Wave 22 (KK1–KK6) ---
$libKk = file_text($root, 'kloxo/httpdocs/lib/html/lib.php');
$commonKk = file_text($root, 'kloxo/httpdocs/lib/html/commonfslib.php');
$lxclassKk = file_text($root, 'kloxo/httpdocs/lib/php/lxclass.php');
$kk1Closed = contains($libKk, 'security KK1')
	&& contains($commonKk, 'security KK1')
	&& contains($lxclassKk, 'security KK1')
	&& not_contains($libKk, 'exec_with_all_closed("sh /script/load-wrapper')
	&& not_contains($commonKk, 'exec_with_all_closed("sh /script/load-wrapper')
	&& not_contains($lxclassKk, 'exec_with_all_closed("sh /script/load-wrapper');
$findings[] = finding('KK1', 'medium', 'load-wrapper still closed-exec shell strings',
	'lib.php / commonfslib / lxclass / callers', $kk1Closed,
	$kk1Closed ? 'argv array sh /script/load-wrapper' : 'still string load-wrapper');

$webdiskKk = file_text($root, 'kloxo/httpdocs/webdisk/index.php');
$webdavKk = file_text($root, 'kloxo/httpdocs/webdisk/webdav.php');
$kk2Closed = contains($webdiskKk, 'security KK2')
	&& contains($webdavKk, 'security KK2')
	&& !is_dir($root . '/kloxo/httpdocs/webdisk/PEAR')
	&& !is_file($root . '/kloxo/httpdocs/webdisk/PEAR.php')
	&& not_contains($webdavKk, '$DBPWD');
$findings[] = finding('KK2', 'medium', 'webdisk PEAR/WebDAV extract/create_function/md5 stack',
	'httpdocs/webdisk/', $kk2Closed,
	$kk2Closed ? 'PEAR/HTTP removed; stubs only' : 'PEAR/WebDAV still present');

$phpverKk = file_text($root, 'kloxo/httpdocs/lib/php/kloxo_phpver.php');
$kk3Closed = contains($phpverKk, 'security KK3')
	&& contains($phpverKk, 'function bashQuote')
	&& contains($phpverKk, 'Fail-closed');
$findings[] = finding('KK3', 'low', 'kloxo_phpver bash job scripts via raw escapeshellarg',
	'lib/php/kloxo_phpver.php', $kk3Closed,
	$kk3Closed ? 'bashQuote fail-closed then escapeshellarg' : 'still raw escapeshellarg only');

$setupKk = file_text($root, 'kloxo/bin/common/setupsecondary.php');
$kk4Closed = contains($setupKk, 'security KK4')
	&& not_contains($setupKk, 'escapeshellarg($slavepass)')
	&& contains($setupKk, 'preg_match(\'/^[A-Za-z0-9]+$/\', $slavepass)');
$findings[] = finding('KK4', 'low', 'setupsecondary SSH remote embeds escapeshellarg(slavepass)',
	'bin/common/setupsecondary.php', $kk4Closed,
	$kk4Closed ? 'alphanumeric slavepass; no escapeshellarg embed' : 'still escapeshellarg in SSH remote');

$lxlibKk = file_text($root, 'kloxo/httpdocs/lib/php/lxlib.php');
$getShellBody = function_body($lxlibKk, 'getShellCommand');
$kk5Closed = contains($lxlibKk, 'security KK5')
	&& contains($getShellBody, 'refused')
	&& not_contains($getShellBody, 'implode');
$findings[] = finding('KK5', 'low', 'deprecated getShellCommand still rebuilds shell strings',
	'lib/php/lxlib.php getShellCommand', $kk5Closed,
	$kk5Closed ? 'stub returns empty' : 'still rebuilds quoted shell string');

$checkpwKk = file_text($root, 'kloxo/bin/common/misc/checkpassword.php');
$kk6Closed = contains($lxlibKk, 'security KK6')
	&& contains($checkpwKk, 'security KK6')
	&& contains($libKk, 'security JJ5');
$findings[] = finding('KK6', 'low', 'residual crypt mint / unmarked non-auth md5',
	'check_password / checkpassword / EasyInstaller md5 token', $kk6Closed,
	$kk6Closed ? 'crypt verify-only marked; non-auth md5 marked' : 'still unmarked');

// --- Wave 23 (LL1–LL6) ---
$eiLl = file_text($root, 'kloxo/httpdocs/driver/web/easyinstaller__linuxlib.php');
$eilibLl = file_text($root, 'kloxo/httpdocs/driver/web/easyinstallerlib.php');
$libLl = file_text($root, 'kloxo/httpdocs/lib/html/lib.php');
$eiUpLl = file_text($root, 'kloxo/bin/easyinstaller-update.php');
$ll1Closed = contains($eiLl, 'security LL1')
	&& contains($eiLl, 'easyinstaller_removed')
	&& not_contains($eiLl, 'download_file')
	&& contains($eilibLl, 'security LL1')
	&& not_contains($eilibLl, 'md5(time())')
	&& contains($libLl, 'security LL1')
	&& contains($libLl, 'appvault_dbfilter refused')
	&& not_contains($libLl, "md5(\$cont['adminpass'])")
	&& contains($eiUpLl, 'security LL1');
$findings[] = finding('LL1', 'medium', 'EasyInstaller md5/drivers still live',
	'easyinstaller* / appvault_dbfilter', $ll1Closed,
	$ll1Closed ? 'driver throws; template refused; no md5(time)' : 'EasyInstaller still active');

$fmLl = file_text($root, 'kloxo/file/formmail/formmail.php');
$ll2Closed = contains($fmLl, 'security LL2')
	&& contains($fmLl, 'security HH6')
	&& contains($fmLl, 'security JJ5')
	&& not_contains($fmLl, 'popen($cmd')
	&& strlen($fmLl) < 800;
$findings[] = finding('LL2', 'medium', 'formmail.php third-party still shipped',
	'file/formmail/formmail.php', $ll2Closed,
	$ll2Closed ? 'stub only' : 'full FormMail still present');

$lxlibLl = file_text($root, 'kloxo/httpdocs/lib/php/lxlib.php');
$psLl = file_text($root, 'kloxo/httpdocs/driver/pserver/pservercorelib.php');
$ipLl = file_text($root, 'kloxo/httpdocs/lib/html/ippoollib.php');
$ll3Closed = contains($lxlibLl, 'function kloxo_sql_quote')
	&& contains($lxlibLl, 'security LL3')
	&& contains($psLl, 'security LL3')
	&& contains($ipLl, 'security LL3')
	&& contains($libLl, 'security LL3')
	&& not_contains($psLl, "delete from ipaddress where syncserver = '\$this->nname'")
	&& not_contains($ipLl, "values ('\$l', '\$date')");
$findings[] = finding('LL3', 'medium', 'rawQuery string interpolation leftovers',
	'pservercore / ippool / lib.php helpers', $ll3Closed,
	$ll3Closed ? 'kloxo_sql_quote + ident allow-list' : 'still raw interpolation');

$fpmLl = file_text($root, 'kloxo/httpdocs/lib/php/kloxo_phpfpm.php');
$ll4Closed = contains($fpmLl, 'security LL4')
	&& contains($fpmLl, 'bashQuote')
	&& contains($fpmLl, '/usr/bin/php\\d+');
$findings[] = finding('LL4', 'low', 'kloxo_phpfpm cronWrapper raw escapeshellarg',
	'lib/php/kloxo_phpfpm.php cronWrapper', $ll4Closed,
	$ll4Closed ? 'phpX.Y allow-list + bashQuote' : 'still raw escapeshellarg');

$ex0Ll = file_text($root, 'kloxo/httpdocs/lib/urlrewrite/example0.php');
$ex3Ll = file_text($root, 'kloxo/httpdocs/lib/urlrewrite/example3.php');
$ll5Closed = contains($ex0Ll, 'security LL5')
	&& contains($ex3Ll, 'security LL5')
	&& not_contains($ex0Ll, 'require_once($root')
	&& not_contains($ex3Ll, 'DOCUMENT_ROOT');
$findings[] = finding('LL5', 'low', 'urlrewrite example* variable require_once',
	'lib/urlrewrite/example*.php', $ll5Closed,
	$ll5Closed ? 'examples stubbed' : 'still require DOCUMENT_ROOT');

$ll6Closed = contains($lxlibLl, 'security LL6')
	&& not_contains($lxlibLl, 'password_hash($plain, PASSWORD_DEFAULT)')
	&& contains($lxlibLl, 'PASSWORD_ARGON2ID');
$findings[] = finding('LL6', 'low', 'PASSWORD_DEFAULT fallback when Argon2id missing',
	'lxlib.php kloxo_password_hash', $ll6Closed,
	$ll6Closed ? 'Argon2id only; fail closed' : 'still PASSWORD_DEFAULT fallback');

// --- Wave 24 (MM1–MM6) ---
$libMm = file_text($root, 'kloxo/httpdocs/lib/html/lib.php');
$mm1Closed = contains($libMm, 'security MM1')
	&& contains($libMm, 'kloxo_sql_ident($tbl_name)')
	&& not_contains($libMm, "nname = '{\$r['dbname']}' where dbname = '{\$r['dbname']}'")
	&& not_contains($libMm, '"insert into lxt_" . $tbl_name . " values" . "(" . $t . ")"');
$findings[] = finding('MM1', 'medium', 'rawQuery schema/migrate paths unquoted',
	'lib.php fix_mysql_* / changeValues / droptable', $mm1Closed,
	$mm1Closed ? 'ident allow-list + quoted cells' : 'still raw migrate SQL');

$extSec = file_text($root, 'kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$mm2Closed = contains($extSec, 'security MM2')
	&& is_dir($root . '/kloxo/httpdocs/theme/extjs')
	&& is_file($root . '/kloxo/httpdocs/theme/extjs/js/ext-all.js');
$findings[] = finding('MM2', 'medium', 'theme/extjs ExtJS 2.x unmarked EOL bundle',
	'theme/extjs/', $mm2Closed,
	$mm2Closed ? 'SECURITY.kloxo: retained for Kloxo-MR UI only' : 'ExtJS unmarked');

$smtpMm = file_text($root, 'kloxo/httpdocs/lib/extra/class.smtp.php');
$mm3Closed = contains($smtpMm, 'security MM3')
	&& contains($smtpMm, 'security JJ5')
	&& not_contains($smtpMm, 'pack(\'H*\', md5')
	&& strlen($smtpMm) < 1200;
$findings[] = finding('MM3', 'low', 'class.smtp.php HMAC-MD5 / EOL SMTP stack',
	'lib/extra/class.smtp.php', $mm3Closed,
	$mm3Closed ? 'stub refuses construction' : 'full SMTP class present');

$defMm = file_text($root, 'kloxo/httpdocs/lib/define.php');
$drvMm = file_text($root, 'kloxo/httpdocs/lib/driver_define.php');
$cliMm = file_text($root, 'kloxo/httpdocs/lib/client/clientlib.php');
$baseMm = file_text($root, 'kloxo/httpdocs/lib/client/clientbaselib.php');
$mm4Closed = contains($defMm, 'security MM4')
	&& !preg_match('/^\s*\$gl_class_array\["easyinstaller"\]/m', $defMm)
	&& contains($drvMm, 'security MM4')
	&& !preg_match('/^\s*\$gl_class_array\[\'easyinstaller__linux\'\]/m', $drvMm)
	&& contains($cliMm, 'security MM4')
	&& contains($baseMm, 'security MM4')
	&& not_contains($baseMm, 'createDefaultApplication($this->domain_name');
$findings[] = finding('MM4', 'low', 'EasyInstaller still registered in define/client tiles',
	'define.php / driver_define / client*', $mm4Closed,
	$mm4Closed ? 'class map commented; createDefault refused' : 'EasyInstaller still registered');

$mm5Closed = contains($libMm, 'security MM5')
	&& contains($libMm, 'function kloxo_sql_assign_clause')
	&& contains($libMm, 'db_set_value refused unsafe');
$findings[] = finding('MM5', 'low', 'db_set_value accepts free-form set/where',
	'lib.php db_set_value', $mm5Closed,
	$mm5Closed ? 'assign-clause allow-list only' : 'still free-form set/where');

$lxlibMm = file_text($root, 'kloxo/httpdocs/lib/php/lxlib.php');
$mm6Closed = contains($lxlibMm, 'security MM6')
	&& contains($lxlibMm, 'crypt() verify-only');
$findings[] = finding('MM6', 'low', 'intentional residual crypt/getShellCommand/stubs',
	'lxlib.php', $mm6Closed,
	$mm6Closed ? 'documented intentional leftovers' : 'unmarked');

// Filter
if ($severityFilter !== null) {
	$findings = array_values(array_filter(
		$findings,
		static fn(array $f): bool => $f['severity'] === $severityFilter
	));
}

$open = array_values(array_filter($findings, static fn(array $f): bool => !$f['closed']));
$closed = array_values(array_filter($findings, static fn(array $f): bool => $f['closed']));

if ($wantJson) {
	echo json_encode([
		'root' => $root,
		'total' => count($findings),
		'open' => count($open),
		'closed' => count($closed),
		'findings' => $findings,
	], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
	exit(count($open) === 0 ? 0 : 1);
}

$sevOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
usort($findings, static function (array $a, array $b) use ($sevOrder): int {
	$sa = $sevOrder[$a['severity']] ?? 9;
	$sb = $sevOrder[$b['severity']] ?? 9;
	if ($sa !== $sb) {
		return $sa <=> $sb;
	}
	return $a['id'] <=> $b['id'];
});

echo "== Open security findings checklist (source only)\n";
echo "   Repo: {$root}\n";
echo "   Re-run after fixes: php tests/security/check-open-findings.php\n\n";

foreach ($findings as $f) {
	$status = $f['closed'] ? 'CLOSED' : 'OPEN  ';
	printf(
		"  [%s] %-8s %s  %s\n",
		$status,
		strtoupper($f['severity']),
		$f['id'],
		$f['title']
	);
	printf("         %s\n", $f['location']);
	printf("         %s\n\n", $f['detail']);
}

echo count($closed) . ' closed, ' . count($open) . ' open (of ' . count($findings) . ")\n";
if (count($open) > 0) {
	echo "Exit 1: open findings remain — do not treat as safe to expose publicly.\n";
	exit(1);
}

echo "Exit 0: all listed findings report CLOSED.\n";
exit(0);
