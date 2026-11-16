#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Z-wave security unit checks (php-cli only; no live panel).
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

require_once $root . '/kloxo/httpdocs/lib/html/remote_auth_gate.php';

expect(kloxo_unserialize_panel_class_ok('priv'), 'Z6 priv allowed');
expect(kloxo_unserialize_panel_class_ok('dns_record_a'), 'Z6 dns_record_a allowed');
expect(!kloxo_unserialize_panel_class_ok('notarealclass'), 'Z6 unknown lowercase refused');

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
expect(str_contains($lib, 'token_get_all') && str_contains($lib, 'security Z3'), 'Z3 token gate present');
expect(str_contains($lib, 'security Z5') && !str_contains($lib, 'mt_rand(1000, 9999)'), 'Z5 no mt_rand job names');
expect(str_contains($lib, 'security Z1'), 'Z1 markers in lib.php');

$cp = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/commonfslib.php');
expect(str_contains($cp, 'security Z4') && str_contains($cp, "array('cp', '-rf'"), 'Z4 proc_open cp');

$sq = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/sqlitelib.php');
expect(str_contains($sq, 'security Z2') && !str_contains($sq, "Password('{\$newp}')"), 'Z2 setPassword');

$child = (string) file_get_contents($root . '/kloxo/bin/common/child.php');
expect(str_contains($child, 'security Z1') && !str_contains($child, '@unserialize($raw'), 'Z1 child fail-closed');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
