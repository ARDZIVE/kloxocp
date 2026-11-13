<?php
/**
 * Switch Program spam drivers install through apt (not yum/rpm). php-cli only;
 * uses a fake apt-get, never touches the system.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function ok(string $d): void { global $pass; $pass++; printf("  ok   %s\n", $d); }
function bad(string $d, string $x = ''): void
{
	global $fail;
	$fail++;
	printf("  FAIL %s\n", $d);
	if ($x !== '') {
		printf("       %s\n", $x);
	}
}

$root = dirname(__DIR__, 2);
require $root . '/kloxo/httpdocs/lib/php/kloxo_apt.php';
class lxException extends Exception
{
	public function __construct($message, $variable = 'nname', $value = null)
	{
		parent::__construct((string) $message);
	}
}

$tmp = sys_get_temp_dir() . '/kloxo-apt-test-' . getmypid();
@mkdir($tmp, 0700, true);
$log = $tmp . '/calls.log';
$mode = $tmp . '/mode';
$fake = $tmp . '/apt-get';
file_put_contents($fake, strtr(<<<'SH'
#!/bin/sh
echo "$DEBIAN_FRONTEND $*" >> "@LOG@"
mode=$(cat "@MODE@")
case "$*" in
  *" update"*) exit 0 ;;
esac
case "$mode" in
  ok) echo "Setting up bogofilter"; exit 0 ;;
  missing) echo "Reading package lists..."; echo "E: Unable to locate package bogofilter" >&2; exit 100 ;;
  stale)
    if [ -f "@LOG@.updated" ]; then exit 0; fi
    grep -q " update" "@LOG@" && { touch "@LOG@.updated"; exit 0; }
    echo "E: Package has no installation candidate" >&2; exit 100 ;;
esac
SH, array('@LOG@' => $log, '@MODE@' => $mode)));
chmod($fake, 0700);
define('KLOXO_APT_TEST_BIN', $fake);

// success
file_put_contents($mode, 'ok');
$r = KloxoApt::install(['bogofilter']);
$calls = (string) file_get_contents($log);
if ($r['ok'] === true && str_contains($calls, 'noninteractive -y')
	&& str_contains($calls, 'install bogofilter') && substr_count($calls, "\n") === 1) {
	ok('install runs apt-get non-interactively once on success');
} else {
	bad('install runs apt-get non-interactively once on success', $calls);
}

// missing package: update + retry, then fail with the apt E: line
@unlink($log);
file_put_contents($mode, 'missing');
$r = KloxoApt::install(['bogofilter']);
$calls = (string) file_get_contents($log);
if ($r['ok'] === false && $r['error'] === 'E: Unable to locate package bogofilter'
	&& str_contains($calls, ' update') && substr_count($calls, 'install bogofilter') === 2) {
	ok('failed install updates the lists, retries once and reports the apt error');
} else {
	bad('failed install updates the lists, retries once and reports the apt error', json_encode($r) . $calls);
}

// exception carries the apt reason
$thrown = '';
try {
	KloxoApt::installOrThrow(['bogofilter']);
} catch (lxException $e) {
	$thrown = $e->getMessage();
}
if (str_starts_with($thrown, 'Install failed: bogofilter')
	&& str_contains($thrown, 'Unable to locate package')) {
	ok('installOrThrow names the package and the apt error');
} else {
	bad('installOrThrow names the package and the apt error', $thrown);
}

// stale lists: first install fails, update fixes it
@unlink($log);
@unlink($log . '.updated');
file_put_contents($mode, 'stale');
$r = KloxoApt::install(['bogofilter']);
if ($r['ok'] === true) {
	ok('install succeeds after the automatic apt-get update');
} else {
	bad('install succeeds after the automatic apt-get update', json_encode($r));
}

// package names are validated (no option injection)
$badNames = 0;
foreach (['--version', 'a b', 'x;y', '', '-oAPT::x=1', 'Bogo'] as $n) {
	try {
		KloxoApt::validPackages([$n]);
	} catch (InvalidArgumentException $e) {
		$badNames++;
	}
}
if ($badNames === 6 && KloxoApt::validPackages(['rspamd', 'libfoo2.0+b']) === ['rspamd', 'libfoo2.0+b']) {
	ok('package names are validated');
} else {
	bad('package names are validated', (string) $badNames);
}

// Rspamd is the Switch Program spam driver: apt only, no yum/rpm, SpamAssassin gone.
$rsp = (string) file_get_contents($root . '/kloxo/httpdocs/driver/mmail/spam__rspamdlib.php');
$inc = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/include.php');
$code = preg_replace('#//[^\n]*#', '', $rsp) ?? '';
if (str_contains($rsp, "array('rspamd')")
	&& str_contains($rsp, "'enable', '--now', 'rspamd'")
	&& !preg_match('/\b(yum|rpm)\b/', $code)
	&& !is_file($root . '/kloxo/httpdocs/driver/mmail/spam__spamassassinlib.php')
	&& str_contains($inc, 'kloxo_apt.php')) {
	ok('rspamd driver installs with apt and no rpm/yum; SpamAssassin driver is gone');
} else {
	bad('rspamd driver installs with apt and no rpm/yum; SpamAssassin driver is gone');
}

foreach (glob($tmp . '/*') ?: [] as $f) {
	@unlink($f);
}
@rmdir($tmp);
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
