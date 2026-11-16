#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * WW-wave security unit checks (php-cli only; no live panel).
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

$top = (string) file_get_contents($root . '/kloxo/httpdocs/theme/skin/simplicity/default/topbar_left.php');
expect(
	str_contains($top, 'security WW1')
	&& str_contains($top, 'kloxo_sql_like')
	&& str_contains($top, 'kloxo_sql_eq')
	&& !str_contains($top, "LIKE '%client-{\$loginas}%'")
	&& !str_contains($top, "sent_to='client-{\$loginas}'"),
	'WW1 topbar_left quoted counts'
);

$frame = (string) file_get_contents($root . '/kloxo/httpdocs/theme/frame_left.php');
expect(
	str_contains($frame, 'security WW2')
	&& str_contains($frame, 'rdescJs')
	&& !str_contains($frame, "createSubMenu(xxpFav, '<?=\$rdesc?>'"),
	'WW2 favorites createSubMenu json'
);

$lxclass = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxclass.php');
expect(
	str_contains($lxclass, 'security WW3')
	&& str_contains($lxclass, 'Named hpfilter')
	&& !str_contains($lxclass, 'implode(" ", $res)'),
	'WW3 getdbFilter hpfilter harden'
);

$panel = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/xpmenu/PanelBarOrig.js');
expect(
	str_contains($panel, 'security WW4')
	&& str_contains($panel, 'safeHref')
	&& str_contains($panel, 'kloxoSafePath')
	&& !str_contains($panel, 'href=\\"" + _themeFolder + "/" + _themeFile'),
	'WW4 PanelBar theme path escape'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lib, 'security LL3 / VV5 / WW5')
	&& str_contains($lib, 'security MM1 / VV5 / WW5')
	&& str_contains($lxlib, 'security TT1 / VV5 / WW5'),
	'WW5 schema rawQuery residual markers'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
expect(
	str_contains($lxlib, 'security WW6')
	&& str_contains($extSec, 'security WW6')
	&& str_contains($toast, 'security WW6')
	&& str_contains($lxlib, 'gated unserialize'),
	'WW6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
