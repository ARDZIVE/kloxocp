<?php
/**
 * Dns::fixDateSerial must accept a string serial from SQLite (PHP 8
 * rejects '' + 1). Source/shell: fix.inc is POSIX (= not ==). php-cli only.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function check(bool $ok, string $d): void
{
	global $pass, $fail;
	if ($ok) {
		$pass++;
		printf("  ok   %s\n", $d);
		return;
	}
	$fail++;
	printf("  FAIL %s\n", $d);
}

$repo = dirname(__DIR__, 2);
$src = (string) file_get_contents($repo . '/kloxo/httpdocs/driver/dns/dnslib.php');
check(str_contains($src, '(int) $this->serial')
	&& !preg_match('/\$this->serial\s*\+\s*1/', $src),
	'fixDateSerial casts serial before increment');

$fixInc = (string) file_get_contents($repo . '/kloxo/pscript/fix.inc');
check(!preg_match('/\[\s*"\$1"\s*==/', $fixInc)
	&& str_contains($fixInc, '[ "$1" = "--help" ]')
	&& str_contains($fixInc, '[ "$#" = 0 ]'),
	'fix.inc uses POSIX [ = ] for sh/dash');

$fixdns = (string) file_get_contents($repo . '/kloxo/pscript/fixdns');
check(str_contains($fixdns, 'fixdns.php "$@"')
	&& !str_contains($fixdns, 'fixdns.php $*'),
	'fixdns passes "$@" to PHP');

// Behaviour without loading the full panel: mirror the fixed arithmetic.
$cases = array(
	array(null, 1, date('Ymd') . '01'),
	array('', 1, date('Ymd') . '01'),
	array('0', 1, date('Ymd') . '01'),
	array('9', 10, date('Ymd') . '10'),
	array('99', 0, date('Ymd') . '00'),
	array('5', 6, date('Ymd') . '06'),
);
foreach ($cases as [$in, $wantSerial, $wantDdate]) {
	$serial = ($in === null) ? 0 : (int) $in;
	if ($serial < 0) {
		$serial = 0;
	}
	$serial++;
	if ($serial > 99) {
		$serial = 0;
	}
	$v = ($serial < 10) ? ('0' . $serial) : (string) $serial;
	$ddate = date('Ymd') . $v;
	check($serial === $wantSerial && $ddate === $wantDdate,
		'serial ' . var_export($in, true) . " -> {$wantSerial} / {$wantDdate}");
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
