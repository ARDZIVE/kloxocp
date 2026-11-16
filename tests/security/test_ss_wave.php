#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * SS-wave security unit checks (php-cli only; no live panel).
 */

$root = realpath(dirname(__DIR__, 2));
if ($root === false) {
	fwrite(STDERR, "cannot resolve repo root\n");
	exit(2);
}

$failed = 0;
$passed = 0;

function expect(bool $ok, string $msg): void
{
	global $failed, $passed;
	if ($ok) {
		echo "OK  {$msg}\n";
		$passed++;
	} else {
		echo "FAIL {$msg}\n";
		$failed++;
	}
}

$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
$dav = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/davuserlib.php');
$cron = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/cronlib.php');
$dns = (string) file_get_contents($root . '/kloxo/httpdocs/driver/dns/dnslib.php');
$site = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/kloxo_sitepreview.php');
expect(
	str_contains($lxlib, 'function kloxo_sql_like')
	&& !str_contains($dav, "parent_clname = '\$this->parent_clname'")
	&& !str_contains($cron, "username = '{\$username}'")
	&& !str_contains($dns, "LIKE '%\$server%'")
	&& !str_contains($site, "nname = '{\$q}'"),
	'SS1 getRowsWhere sites converted'
);

$mssql = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/ddatabaselib.php');
$mssqlu = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/ddatabaseuserlib.php');
expect(
	str_contains($mssql, 'security SS2')
	&& str_contains($mssql, 'mssql_not_available')
	&& str_contains($mssqlu, 'security SS2')
	&& str_contains($mssqlu, 'mssql_not_available'),
	'SS2 mssqldb stubs'
);

$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
$html = (string) file_get_contents($root . '/kloxo/httpdocs/theme/htmllib.php');
expect(
	str_contains($toast, 'security SS3')
	&& str_contains($toast, 'body.textContent')
	&& str_contains($html, 'security SS3')
	&& str_contains($html, "progress_speed').textContent")
	&& !str_contains($html, "progress_speed').innerHTML = iSpeed"),
	'SS3 toast/progress textContent'
);

$sql = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/sqlitelib.php');
$lxclass = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxclass.php');
expect(
	str_contains($sql, 'security SS4')
	&& str_contains($sql, 'getCountWhere refused unsafe')
	&& str_contains($lxclass, 'kloxo_sql_eq')
	&& !str_contains($lxclass, "getCountWhere(\"parent_clname = '{\$this->getClName()}'\")"),
	'SS4 getCountWhere harden'
);

$ip = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/ippoollib.php');
expect(
	str_contains($lxlib, 'security SS5')
	&& str_contains($ip, 'kloxo_sql_like')
	&& str_contains($dns, 'kloxo_sql_like')
	&& !str_contains($ip, "LIKE '%,\$ipaddr,%'")
	&& !str_contains($dns, "LIKE '%\$server%'"),
	'SS5 LIKE helpers'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
expect(
	str_contains($lxlib, 'security SS6')
	&& str_contains($extSec, 'security SS6')
	&& str_contains($lxlib, 'gated unserialize'),
	'SS6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
