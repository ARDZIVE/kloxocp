#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * PP-wave security unit checks (php-cli only; no live panel).
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

$help = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/helptext.js');
$lxa = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/lxa.js');
$lxaBar = '';
if (preg_match('/function updateStatusBar\s*\([^)]*\)\s*\{/', $lxa, $m, PREG_OFFSET_CAPTURE)) {
	$lxaBar = substr($lxa, $m[0][1], 500);
	$end = strpos($lxaBar, "\nfunction ");
	if ($end !== false) {
		$lxaBar = substr($lxaBar, 0, $end);
	}
}
expect(
	str_contains($help, 'security PP1')
	&& str_contains($help, 'textContent')
	&& !str_contains($help, 'el.innerHTML')
	&& str_contains($lxa, 'security PP1')
	&& $lxaBar !== ''
	&& !preg_match('/\.innerHTML\s*=/', $lxaBar)
	&& str_contains($lxaBar, 'textContent'),
	'PP1 helptext/lxa textContent'
);

$sql = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/sqlitelib.php');
expect(
	str_contains($sql, 'security PP2')
	&& str_contains($sql, 'kloxo_sql_quote')
	&& str_contains($sql, 'setRow refused unsafe')
	&& str_contains($sql, 'escapeBack($nname, $value)')
	&& str_contains($sql, 'kloxo_sql_ident'),
	'PP2 sqlitelib quote/ident'
);

$sgbl = (string) file_get_contents($root . '/kloxo/httpdocs/lib/sgbl.php');
expect(
	str_contains($sgbl, 'security PP3')
	&& !str_contains($sgbl, '__path_easyinstaller_servervar'),
	'PP3 easyinstaller path removed'
);

$web = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/weblib.php');
$dcore = (string) file_get_contents($root . '/kloxo/httpdocs/lib/domain/domaincorelib.php');
$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
expect(
	str_contains($web, 'security PP4')
	&& !preg_match('/^\s*static \$__desc_dotnet_flag/m', $web)
	&& !preg_match('/^\s*static \$__desc_dotnet_flag/m', $dcore)
	&& str_contains($lib, 'security PP4')
	&& (bool) preg_match('/if \(\$var === \'dotnet_flag\'\)[\s\S]{0,120}return false;/', $lib),
	'PP4 dotnet_flag removed'
);

$scav = (string) file_get_contents($root . '/kloxo/bin/scavenge.php');
expect(
	str_contains($scav, 'security PP5')
	&& !str_contains($scav, "Scavenge: 'Easy Installer'")
	&& !str_contains($scav, 'easyinstaller-update.php'),
	'PP5 scavenge EasyInstaller removed'
);

$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
expect(
	str_contains($lxlib, 'security PP6')
	&& str_contains($extSec, 'security PP6')
	&& str_contains($lxlib, 'gated unserialize'),
	'PP6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
