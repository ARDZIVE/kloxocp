<?php
/**
 * Information box: Last Login IP / Current Login IP / Last Login Time.
 * php-cli only. Does not start Hiawatha or touch a live panel DB.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function ok(string $d): void { global $pass; $pass++; printf("  ok   %s\n", $d); }
function bad(string $d, string $x = ''): void { global $fail; $fail++; printf("  FAIL %s\n", $d); if ($x !== '') { printf("       %s\n", $x); } }

$root = dirname(__DIR__, 2);
$lx = $root . '/kloxo/httpdocs/lib/client/lxclient.php';
$cl = $root . '/kloxo/httpdocs/lib/client/clientlib.php';
$kw = $root . '/kloxo/httpdocs/lang/en-us/langkeywordlib.php';

$src = (string) file_get_contents($lx);
$csrc = (string) file_get_contents($cl);
$ksrc = (string) file_get_contents($kw);

if (str_contains($src, 'function buildLoginInfoRows')
	&& str_contains($src, 'function getLastLogin')
	&& str_contains($src, 'info_lastloginip')
	&& str_contains($src, 'info_lastlogintime')
	&& str_contains($src, 'info_currentloginip')
	&& str_contains($src, 'getRemoteIp()')) {
	ok('getLastLogin / buildLoginInfoRows present with login keywords');
} else {
	bad('getLastLogin / buildLoginInfoRows present with login keywords');
}

if (str_contains($csrc, 'getLastLogin($ilist)')
	&& str_contains($csrc, 'info_defaultdomain')
	&& str_contains($csrc, 'info_skin')) {
	ok('createShowInfoList calls getLastLogin between domain and skin');
} else {
	bad('createShowInfoList calls getLastLogin between domain and skin');
}

if (str_contains($ksrc, 'info_lastloginip')
	&& str_contains($ksrc, 'Last Login IP')
	&& str_contains($ksrc, 'info_lastlogintime')
	&& str_contains($ksrc, 'Last Login Time')
	&& str_contains($ksrc, 'info_currentloginip')) {
	ok('en-us keywords for last/current login rows');
} else {
	bad('en-us keywords for last/current login rows');
}

// Extract the real static helper from lxclient.php (no panel boot).
if (!preg_match(
	'/static function buildLoginInfoRows\(string \$currentIp, \$fetchRows = null, \$labels = null\)\s*\{/',
	$src,
	$m,
	PREG_OFFSET_CAPTURE
)) {
	bad('buildLoginInfoRows signature found in lxclient.php');
	echo "$pass passed, $fail failed\n";
	exit(1);
}
$start = (int) $m[0][1];
$bodyStart = strpos($src, '{', $start);
if ($bodyStart === false) {
	bad('buildLoginInfoRows body start');
	echo "$pass passed, $fail failed\n";
	exit(1);
}
$depth = 0;
$end = null;
$len = strlen($src);
for ($i = $bodyStart; $i < $len; $i++) {
	$ch = $src[$i];
	if ($ch === '{') {
		$depth++;
	} elseif ($ch === '}') {
		$depth--;
		if ($depth === 0) {
			$end = $i;
			break;
		}
	}
}
if ($end === null) {
	bad('buildLoginInfoRows body end');
	echo "$pass passed, $fail failed\n";
	exit(1);
}
$method = substr($src, $start, $end - $start + 1);
$fn = 'kloxo_test_build_login_info_rows_' . bin2hex(random_bytes(4));
$wrap = 'function ' . $fn . substr($method, strlen('static function buildLoginInfoRows'));
try {
	eval($wrap);
} catch (Throwable $e) {
	bad('eval buildLoginInfoRows', $e->getMessage());
	echo "$pass passed, $fail failed\n";
	exit(1);
}
ok('buildLoginInfoRows extracted from lxclient.php');

$empty = $fn('93.26.66.26', function () {
	return array();
});
if (isset($empty['Last Login IP'], $empty['Current Login IP'], $empty['Last Login Time'])
	&& $empty['Last Login IP'] === '—'
	&& $empty['Last Login Time'] === '—'
	&& $empty['Current Login IP'] === '93.26.66.26') {
	ok('first session still shows all three rows (last = —)');
} else {
	bad('first session still shows all three rows (last = —)', var_export($empty, true));
}

$ts = 1731233822;
$withPrev = $fn('10.0.0.2', function () use ($ts) {
	return array(
		array('ip_address' => '10.0.0.2', 'logintime' => (string) ($ts + 100)),
		array('ip_address' => '93.26.66.26', 'logintime' => (string) $ts),
	);
});
$expectDate = date('Y-m-d H.i.s', $ts);
if (($withPrev['Last Login IP'] ?? '') === '_lxinurl:a=list&c=utmp:93.26.66.26:'
	&& ($withPrev['Current Login IP'] ?? '') === '10.0.0.2'
	&& ($withPrev['Last Login Time'] ?? '') === "_lxinurl:a=list&c=utmp:{$expectDate}:") {
	ok('previous utmp row fills Last Login IP and Time as links');
} else {
	bad('previous utmp row fills Last Login IP and Time as links', var_export($withPrev, true));
}

$one = $fn('1.2.3.4', function () {
	return array(
		array('ip_address' => '1.2.3.4', 'logintime' => '1731233822'),
	);
});
if (($one['Last Login IP'] ?? '') === '—'
	&& ($one['Current Login IP'] ?? '') === '1.2.3.4'
	&& ($one['Last Login Time'] ?? '') === '—') {
	ok('single utmp row keeps Last Login placeholders');
} else {
	bad('single utmp row keeps Last Login placeholders', var_export($one, true));
}

$labeled = $fn('', null, array(
	'last_ip' => 'Last Login IP',
	'cur_ip' => 'Current Login IP',
	'last_time' => 'Last Login Time',
));
if (($labeled['Current Login IP'] ?? '') === '—') {
	ok('empty remote IP becomes —');
} else {
	bad('empty remote IP becomes —', var_export($labeled, true));
}

echo "$pass passed, $fail failed\n";
exit($fail > 0 ? 1 : 0);
