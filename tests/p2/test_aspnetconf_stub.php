<?php
declare(strict_types=1);

/**
 * Legacy web rows still have ser_aspnetconf_b. Client delete loads those rows;
 * setFromArray must not fatal when the class is a stub or missing.
 */

$root = dirname(__DIR__, 2);
$failed = 0;
$passed = 0;

function expect(bool $cond, string $msg): void
{
	global $failed, $passed;
	if ($cond) {
		echo "ok - {$msg}\n";
		$passed++;
	} else {
		echo "FAIL - {$msg}\n";
		$failed++;
	}
}

$web = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/weblib.php');
expect(
	(bool) preg_match('/^\s*class aspnetconf_b extends Lxaclass/m', $web),
	'aspnetconf_b stub class present for legacy ser_aspnetconf_b'
);
expect(
	str_contains($web, 'ser_aspnetconf_b'),
	'stub comment documents ser_aspnetconf_b client-delete path'
);

$lx = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxclass.php');
expect(
	substr_count($lx, 'if (!class_exists($key))') >= 2,
	'setFromArray guards new $key with class_exists for *_b'
);

$def = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/definecore.php');
expect(
	!preg_match('/^\s*\$gl_class_array\["aspnet"\]/m', $def),
	'aspnet driver stays unregistered (QQ5)'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
