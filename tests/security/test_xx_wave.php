#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * XX-wave security unit checks (php-cli only; no live panel).
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

$ftp = (string) file_get_contents($root . '/kloxo/httpdocs/driver/ftp/ftpuser__pureftplib.php');
expect(
	str_contains($ftp, 'security XX1')
	&& str_contains($ftp, 'kloxo_sql_and_eq')
	&& !str_contains($ftp, "nname = '{\$n}' AND username = '{\$u}'"),
	'XX1 ftpuser db_get_count quoted'
);

$web = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/weblib.php');
expect(
	str_contains($web, 'security XX2')
	&& str_contains($web, 'kloxo_sql_and_eq')
	&& !str_contains($web, "customer_name = '{\$this->customer_name}' AND docroot = '\$this->docroot'"),
	'XX2 weblib db_get_count quoted'
);

$sql = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/sqlitelib.php');
expect(
	str_contains($sql, 'security XX3')
	&& str_contains($sql, 'getRowsGeneric refused unsafe table ident')
	&& str_contains($sql, 'kloxo_sql_ident($col)'),
	'XX3 getRowsGeneric column ident'
);

$panel = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/xpmenu/PanelBarOrig.js');
$frame = (string) file_get_contents($root . '/kloxo/httpdocs/theme/frame_left.php');
expect(
	str_contains($panel, 'security XX4')
	&& str_contains($panel, 'Plain-text labels only')
	&& str_contains($frame, 'security WW2 / XX4')
	&& str_contains($frame, "createMenu('Usage'")
	&& !str_contains($frame, "createMenu('<span style=\"color:#003360\">Usage'"),
	'XX4 createMenu plain-text labels'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lib, 'security LL3 / VV5 / WW5 / XX5')
	&& str_contains($lib, 'security MM1 / VV5 / WW5 / XX5')
	&& str_contains($lxlib, 'security TT1 / VV5 / WW5 / XX5'),
	'XX5 schema rawQuery residual markers'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
expect(
	str_contains($lxlib, 'security XX6')
	&& str_contains($extSec, 'security XX6')
	&& str_contains($toast, 'security XX6')
	&& str_contains($lxlib, 'gated unserialize'),
	'XX6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
