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
