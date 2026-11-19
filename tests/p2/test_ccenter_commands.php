<?php
/**
 * Command Center standard commands: POSIX sh (dash) for fix.inc/restart.inc,
 * "$@" forwarding, no bash arrays in restart-list.inc. php-cli only.
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
$ps = $repo . '/kloxo/pscript';

$fixInc = (string) file_get_contents($ps . '/fix.inc');
check(!preg_match('/\[\s*"\$[^"]*"\s*==/', $fixInc)
	&& str_contains($fixInc, '[ "$1" = "--help" ]')
	&& str_contains($fixInc, '[ "$#" = 0 ]'),
	'fix.inc is POSIX (= not ==)');

$restartInc = (string) file_get_contents($ps . '/restart.inc');
check(!preg_match('/\[\s*"\$[^"]*"\s*==/', $restartInc)
	&& str_contains($restartInc, '[ "$1" = "--help" ]')
	&& str_contains($restartInc, '[ "$1" = "--force" ]')
	&& str_contains($restartInc, 'for item in $list'),
	'restart.inc is POSIX (= not ==, for $list)');

$listInc = (string) file_get_contents($ps . '/restart-list.inc');
check(!str_contains($listInc, '=(')
	&& !str_contains($listInc, '[@]')
	&& str_contains($listInc, 'list_dns=')
	&& str_contains($listInc, 'list_web=')
	&& str_contains($listInc, 'list_mail=')
	&& str_contains($listInc, 'list_ftp=')
	&& str_contains($listInc, 'list_all=')
	&& str_contains($listInc, 'list_perlfastcgi='),
	'restart-list.inc uses space-separated strings (no bash arrays)');

$wrappers = array(
	'fixdns' => 'fixdns.php "$@"',
	'fixweb' => 'fixweb.php "$@"',
	'fixphp' => 'fixphpini.php "$@"',
	'fixmail' => 'fixmmail.php "$@"',
	'fixftp' => 'fixftp.php "$@"',
	'fixftp-all' => 'fixftp "$@"',
	'fixtraffic' => 'fixtraffic.php "$@"',
	'fixwebcache' => 'fixwebcache.php "$@"',
);
foreach ($wrappers as $name => $needle) {
	$src = (string) file_get_contents($ps . '/' . $name);
	check(str_contains($src, $needle) && !preg_match('/\$\*(?!")/', $src),
		$name . ' passes "$@" (no unquoted $*)');
}

$fixweb = (string) file_get_contents($ps . '/fixweb');
check(str_contains($fixweb, 'sh /script/fixphp "$@"'),
	'fixweb forwards args into nested fixphp');

$fixAll = (string) file_get_contents($ps . '/fix-all');
check(str_contains($fixAll, 'fixdns "$@"')
	&& str_contains($fixAll, 'fixweb "$@"')
	&& str_contains($fixAll, 'fixphp "$@"')
	&& str_contains($fixAll, 'fixmail "$@"')
	&& str_contains($fixAll, 'fixftp-all "$@"'),
	'fix-all forwards args to every stage');

foreach (array('restart-dns', 'restart-web', 'restart-mail', 'restart-ftp', 'restart-all') as $name) {
	$src = (string) file_get_contents($ps . '/' . $name);
	check(preg_match('/list="\$list_[a-z]+"/', $src) === 1
		&& !str_contains($src, '[*]'),
		$name . ' assigns list="$list_…"');
}

$lx = (string) file_get_contents($repo . '/kloxo/httpdocs/lib/php/lxclass.php');
foreach (array('fixdns', 'fixweb', 'fixphp', 'fixmail', 'fixftp-all', 'fix-all', 'fixtraffic',
	'restart-dns', 'restart-web', 'restart-mail', 'restart-ftp', 'restart-all') as $script) {
	check(str_contains($lx, '/script/' . $script),
		'Command Center references /script/' . $script);
}

$dnsLib = (string) file_get_contents($repo . '/kloxo/httpdocs/driver/dns/dnslib.php');
check(str_contains($dnsLib, '(int) $this->serial')
	&& !preg_match('/\$this->serial\s*\+\s*1/', $dnsLib),
	'fixdns PHP path casts DNS serial (no string + int)');

$traffic = (string) file_get_contents($repo . '/kloxo/bin/common/fixtraffic.php');
check(str_contains($traffic, '(int) $list[\'day\']'),
	'fixtraffic casts --day to int');

// dash/sh -n on the wrappers (macOS /bin/sh is often bash-as-sh; still catches syntax).
$sh = is_executable('/bin/sh') ? '/bin/sh' : 'sh';
foreach (array_merge(array_keys($wrappers), array('fix-all', 'fix.inc', 'restart.inc', 'restart-list.inc',
	'restart-dns', 'restart-web', 'restart-mail', 'restart-ftp', 'restart-all')) as $name) {
	$path = $ps . '/' . $name;
	$out = array();
	$code = 0;
	exec(escapeshellarg($sh) . ' -n ' . escapeshellarg($path) . ' 2>&1', $out, $code);
	check($code === 0, 'sh -n ' . $name . ($out !== array() ? (': ' . implode(' ', $out)) : ''));
}

// Runtime: dash-compatible [ = ] with --server=all (the Command Center failure mode).
$probe = $sh . ' -c ' . escapeshellarg(
	'set -- --server=all --force; '
	. 'if [ "$1" = "--help" ] || [ "$1" = "-h" ]; then exit 2; fi; '
	. 'if [ "$1" = "--force" ] || [ "$2" = "--force" ]; then echo force_ok; fi; '
	. 'if [ "$#" = 0 ]; then exit 3; fi; '
	. 'echo argc=$#'
);
$out = (string) shell_exec($probe . ' 2>&1');
check(str_contains($out, 'force_ok') && str_contains($out, 'argc=2'),
	'dash/sh [ = ] accepts --server=all / --force');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
