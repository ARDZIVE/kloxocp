#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Y-wave security unit checks (php-cli only; no live panel).
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

// Extract randomString + kloxo_password_hash from lxlib without full bootstrap.
$lx = file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
if ($lx === false) {
	fwrite(STDERR, "cannot read lxlib.php\n");
	exit(2);
}

if (!preg_match('/function randomString\(\$length\)\s*\{.*?^\}\s*$/ms', $lx, $m)) {
	fwrite(STDERR, "cannot extract randomString\n");
	exit(2);
}
eval($m[0]);

if (!preg_match('/function kloxo_password_hash\(\$plain\)\s*\{.*?^\}\s*$/ms', $lx, $m2)) {
	fwrite(STDERR, "cannot extract kloxo_password_hash\n");
	exit(2);
}
eval($m2[0]);

$s = randomString(32);
expect(strlen($s) === 32, 'Y1 randomString length');
expect((bool) preg_match('/^[0-9a-zA-Z]+$/', $s), 'Y1 randomString alphabet');
$s2 = randomString(32);
expect($s !== $s2, 'Y1 randomString not constant');

$h = kloxo_password_hash('test-password-y7');
expect(is_string($h) && $h !== '' && !str_starts_with($h, '$1$'), 'Y7 hash is not MD5-crypt');
expect(password_verify('test-password-y7', $h), 'Y7 password_verify');

$src = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(str_contains($src, 'random_int') && !preg_match('/function randomString\(\$length\)\s*\{[^}]*array_rand/s', $src), 'Y1 source');
expect(str_contains($src, 'KLOXO_LICENSE_KEY_PASS') && !str_contains(function_body_local($src, 'licenseEncrypt'), '"helloworld"'), 'Y9 source');

$remote = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/remotelib.php');
expect(substr_count($remote, 'security Y2') >= 3, 'Y2 markers');

$prep = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$prepBody = function_body_local($prep, 'PrepareMyDnsDb');
expect(str_contains($prepBody, 'security Y6') && !str_contains($prepBody, 'mysqladmin'), 'Y6 no mysqladmin');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

function function_body_local(string $src, string $name): string
{
	if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
		return '';
	}
	$start = (int) $m[0][1] + strlen($m[0][0]) - 1;
	$depth = 0;
	$len = strlen($src);
	for ($i = $start; $i < $len; $i++) {
		if ($src[$i] === '{') {
			$depth++;
		} elseif ($src[$i] === '}') {
			$depth--;
			if ($depth === 0) {
				return substr($src, $start, $i - $start + 1);
			}
		}
	}
	return '';
}
