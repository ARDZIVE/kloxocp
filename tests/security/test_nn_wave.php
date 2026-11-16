#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * NN-wave security unit checks (php-cli only; no live panel).
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

$prog = (string) file_get_contents($root . '/kloxo/httpdocs/lib/programlib.php');
$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
expect(
	str_contains($prog, 'security NN1')
	&& !str_contains($prog, 'all_easyinstaller__linux::')
	&& !str_contains($prog, 'all_easyinstaller::')
	&& str_contains($lib, 'security NN1')
	&& str_contains($lib, 'security NN5')
	&& !str_contains($lib, 'easyinstaller-update.php')
	&& !str_contains($lib, 'kloxo-install-disableeasyinstaller.flg'),
	'NN1/NN5 programlib + installEasyinstaller'
);

$gen = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/generallib.php');
expect(
	str_contains($gen, 'security NN2')
	&& !str_contains($gen, 'static $__desc_disableeasyinstaller')
	&& !str_contains($gen, 'remoteinstall_flag')
	&& !str_contains($gen, 'easyinstaller_url')
	&& !str_contains($gen, "disableeasyinstaller = 'off'")
	&& str_contains($gen, "disableeasyinstaller = 'on'"),
	'NN2 generallib EasyInstaller UI removed'
);

$html = (string) file_get_contents($root . '/kloxo/httpdocs/theme/htmllib.php');
$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
expect(
	str_contains($html, 'security NN3')
	&& str_contains($html, 'refused_unsafe_form_html')
	&& str_contains($html, 'JSON.parse')
	&& str_contains($extSec, 'security NN3'),
	'NN3 ExtJS Template/ajax harden'
);

$ei = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/easyinstallerlib.php');
$all = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/all_easyinstallerlib.php');
$snap = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/easyinstallersnapshot__synclib.php');
$linux = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/easyinstaller__linuxlib.php');
expect(
	str_contains($ei, 'security NN4')
	&& str_contains($ei, 'security LL1')
	&& strlen($ei) < 1500
	&& !str_contains($ei, 'md5(time())')
	&& str_contains($all, 'security NN4')
	&& strlen($all) < 800
	&& str_contains($snap, 'security NN4')
	&& !str_contains($snap, 'lfile_get_unserialize')
	&& str_contains($linux, 'security NN4')
	&& str_contains($linux, 'easyinstaller_removed'),
	'NN4 EasyInstaller drivers stubbed'
);

$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lxlib, 'security NN6')
	&& str_contains($lxlib, 'gated unserialize')
	&& str_contains($extSec, 'security NN6'),
	'NN6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
