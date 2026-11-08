<?php
/**
 * Services list after an Exim <-> Postfix switch: the removed MTA must not keep a green
 * boot icon. `apt remove` keeps /etc/init.d/<mta> and its rc links; systemd then generates a
 * unit from the script whose UnitFileState is always "generated" (it used to count as
 * enabled). Fake systemd/dpkg/filesystem probes; no real systemd.
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
function check(bool $cond, string $d): void { $cond ? ok($d) : bad($d); }

$root = dirname(__DIR__, 2) . '/kloxo';
$doc = $root . '/httpdocs';
putenv('KLOXO_ROOT=' . $root);
$_SERVER['DOCUMENT_ROOT'] = $doc;
chdir($doc);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
include_once $doc . '/lib/html/include.php';
class_exists('Service__Linux');

$tmp = sys_get_temp_dir() . '/kloxo-mta-leftover-' . getmypid();
foreach (array('/etc/init.d', '/etc/rc2.d', '/etc/rc3.d', '/etc/rc4.d', '/etc/rc5.d', '/lib/systemd/system') as $d) {
	mkdir($tmp . $d, 0755, true);
}
Service__Linux::$fsRoot = $tmp;

// Removed Exim: SysV script and enabled rc links left behind, package state "rc".
touch($tmp . '/etc/init.d/exim4');
foreach (array(2, 3, 4, 5) as $l) {
	touch($tmp . "/etc/rc{$l}.d/S01exim4");
}
$show = array('exim4' => array('LoadState' => 'loaded', 'UnitFileState' => 'generated', 'SourcePath' => '/etc/init.d/exim4'));
Service__Linux::$showProbe = static function (string $u, string $p) use (&$show): string { return $show[$u][$p] ?? ''; };
$pkg = 'rc';
Service__Linux::$pkgStateProbe = static function (string $path) use (&$pkg): string { return $pkg; };

check(Service__Linux::isRemovedSysvLeftover('exim4') === true, 'leftover: script only, package state rc -> removed leftover');
check(Service__Linux::unitIsInstalled('exim4') === true && Service__Linux::resolveInstalledUnit('exim4') === 'exim4',
	'leftover row stays on the Services list (installed row)');
check(Service__Linux::bootStateOn('exim4') === false, 'leftover reads Sb off even while its S links exist');
$rows = Service__Linux::getServiceDetails(array('exim4' => array('servicename' => 'exim4', 'nname' => 'exim4___localhost')));
check(($rows['exim4']['install_state'] ?? '') === 'on' && ($rows['exim4']['state'] ?? '') === 'off' && ($rows['exim4']['boot_state'] ?? '') === 'off',
	'getServiceDetails: removed Exim row is listed with State off and Sb off');
$show['exim4']['ActiveState'] = 'inactive';
$pkg = 'ii';
check(Service__Linux::bootStateOn('exim4') === true, 'installed unit generated from a SysV script with S links reads Sb on');
$pkg = 'rc';

$pkg = 'ii';
foreach (array(2, 3, 4, 5) as $l) {
	rename($tmp . "/etc/rc{$l}.d/S01exim4", $tmp . "/etc/rc{$l}.d/K01exim4");
}
check(Service__Linux::bootStateOn('exim4') === false, 'installed generated SysV unit after `update-rc.d disable` (K links) reads Sb off');

// Still installed (package state ii): listed, and boot state follows the links.
$pkg = 'ii';
check(Service__Linux::isRemovedSysvLeftover('exim4') === false && Service__Linux::unitIsInstalled('exim4') === true,
	'installed package (ii) with a SysV script is not a leftover');

// Native unit file present: never a leftover, even if dpkg says rc.
$pkg = 'rc';
touch($tmp . '/lib/systemd/system/exim4.service');
check(Service__Linux::isRemovedSysvLeftover('exim4') === false, 'native unit file present: not a leftover');
unlink($tmp . '/lib/systemd/system/exim4.service');

// Unknown to dpkg: do not hide anything.
$pkg = '';
check(Service__Linux::isRemovedSysvLeftover('exim4') === false, 'owner package unknown: not hidden');

// Native units keep the old rule.
$show['postfix'] = array('LoadState' => 'loaded', 'UnitFileState' => 'enabled');
check(Service__Linux::bootStateOn('postfix') === true, 'native enabled unit: boot on');
$show['postfix']['UnitFileState'] = 'disabled';
check(Service__Linux::bootStateOn('postfix') === false, 'native disabled unit: boot off');
$show['postfix']['UnitFileState'] = 'generated';
$show['postfix']['SourcePath'] = '/etc/fstab';
check(Service__Linux::bootStateOn('postfix') === true, 'generated from something other than SysV: unchanged (on)');

// Unsafe names are refused.
check(Service__Linux::isRemovedSysvLeftover('../x') === false && Service__Linux::sysvLinksEnabled('a b') === false, 'unsafe unit names are refused');

exec('rm -rf ' . escapeshellarg($tmp));
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
