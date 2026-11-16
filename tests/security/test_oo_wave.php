#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * OO-wave security unit checks (php-cli only; no live panel).
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

$dom = (string) file_get_contents($root . '/kloxo/httpdocs/lib/domain/domainlib.php');
$web = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/weblib.php');
$res = (string) file_get_contents($root . '/kloxo/httpdocs/lib/resourcecorelib.php');
$dcore = (string) file_get_contents($root . '/kloxo/httpdocs/lib/domain/domaincorelib.php');
$base = (string) file_get_contents($root . '/kloxo/httpdocs/lib/client/clientbaselib.php');
$upd = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/updatelib.php');
$ddb = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/ddatabaselib.php');
expect(
	str_contains($dom, 'security OO1')
	&& !str_contains($dom, "\$vlist['easyinstaller_flag']")
	&& str_contains($web, 'security OO1')
	&& !preg_match('/^\s*static \$__desc_easyinstaller_flag/m', $web)
	&& !preg_match('/^\s*static \$__desc_easyinstaller_flag/m', $res)
	&& !preg_match('/^\s*static \$__desc_easyinstaller_flag/m', $dcore)
	&& !preg_match('/^\s*static \$__desc_easyinstaller_app/m', $base)
	&& str_contains($upd, 'security OO1')
	&& str_contains($upd, "priv_q_easyinstaller_flag', 'off'")
	&& str_contains($ddb, 'security OO1')
	&& !str_contains($ddb, "\$nlist['easyinstaller_flag']"),
	'OO1 EasyInstaller privilege/flag UI removed'
);

$lxa = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/lxa.js');
$lxaToggle = '';
if (preg_match('/function checkBoxTextToggle\s*\([^)]*\)\s*\{/', $lxa, $m, PREG_OFFSET_CAPTURE)) {
	$lxaToggle = substr($lxa, $m[0][1], 700);
	$end = strpos($lxaToggle, "\nfunction ");
	if ($end !== false) {
		$lxaToggle = substr($lxaToggle, 0, $end);
	}
}
expect(
	str_contains($lxa, 'security OO2')
	&& $lxaToggle !== ''
	&& !str_contains($lxaToggle, 'eval(')
	&& str_contains($lxaToggle, 'frm[cname].checked'),
	'OO2 lxa.js no eval'
);

$jsv = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/jsvalidator.js');
expect(
	str_contains($jsv, 'security OO3')
	&& str_contains($jsv, 'dotPos')
	&& !str_contains($jsv, "eval('dot'")
	&& str_contains($jsv, 'dotPos[1]'),
	'OO3 jsvalidator IP no eval'
);

$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lxlib, 'security OO4')
	&& str_contains($lxlib, "setRow('nname', \$client")
	&& !str_contains($lxlib, "update {\$class} set password = '{\$escPass}'"),
	'OO4 login rehash setRow'
);

$asp = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/aspnetlib.php');
expect(
	str_contains($asp, 'security OO5')
	&& !str_contains($asp, 'cscript')
	&& !str_contains($asp, 'regASPVer')
	&& str_contains($asp, 'aspnet_not_available')
	&& strlen($asp) < 1200,
	'OO5 aspnetlib stubbed'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
expect(
	str_contains($lxlib, 'security OO6')
	&& str_contains($extSec, 'security OO6')
	&& str_contains($lxlib, 'gated unserialize'),
	'OO6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
