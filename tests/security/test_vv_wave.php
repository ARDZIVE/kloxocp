#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * VV-wave security unit checks (php-cli only; no live panel).
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

$lxclass = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxclass.php');
expect(
	str_contains($lxclass, 'security VV1')
	&& str_contains($lxclass, 'kloxo_sql_like')
	&& !str_contains($lxclass, "LIKE '%{\$filter['searchstring']}%'"),
	'VV1 getdbFilter quote/LIKE'
);

expect(
	str_contains($lxclass, 'security VV2')
	&& str_contains($lxclass, "sortdir !== 'asc' && \$sortdir !== 'desc'")
	&& str_contains($lxclass, 'kloxo_sql_ident($sortby)'),
	'VV2 ORDER BY allow-list'
);

$frame = (string) file_get_contents($root . '/kloxo/httpdocs/theme/frame_left.php');
$panel = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/xpmenu/PanelBarOrig.js');
$lxclient = (string) file_get_contents($root . '/kloxo/httpdocs/lib/client/lxclient.php');
expect(
	str_contains($frame, 'security VV3')
	&& str_contains($frame, 'kloxoSkinDir')
	&& !str_contains($frame, "image = '<?=\$skinget?>/images/")
	&& str_contains($panel, 'security VV3')
	&& str_contains($panel, 'safeImage')
	&& str_contains($lxclient, 'security VV3'),
	'VV3 frame_left/PanelBar/skin path escape'
);

expect(
	str_contains($lxclass, 'security VV4')
	&& str_contains($lxclass, 'customaction general string exec refused')
	&& str_contains($lxclass, 'customaction row string exec refused'),
	'VV4 customaction argv-only'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lib, 'security LL3 / VV5')
	&& str_contains($lib, 'security MM1 / VV5')
	&& str_contains($lxlib, 'security TT1 / VV5'),
	'VV5 schema rawQuery audit markers'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
expect(
	str_contains($lxlib, 'security VV6')
	&& str_contains($extSec, 'security VV6')
	&& str_contains($toast, 'security VV6')
	&& str_contains($lxlib, 'gated')
	&& str_contains($lxlib, 'unserialize'),
	'VV6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
