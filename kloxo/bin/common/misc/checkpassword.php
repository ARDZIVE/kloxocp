<?php
declare(strict_types=1);

/**
 * CLI password check against a stored hash (security JJ3).
 * Supports password_* (Argon2id/bcrypt) and legacy crypt() hashes.
 */

if (!isset($argv[1], $argv[2]) || !is_string($argv[1]) || !is_string($argv[2])) {
	fwrite(STDERR, "Usage: checkpassword.php <plain> <stored-hash>\n");
	exit(2);
}

$plain = $argv[1];
$enc = $argv[2];

$ok = false;
if (function_exists('password_get_info') && function_exists('password_verify')) {
	$info = password_get_info($enc);
	if (is_array($info) && isset($info['algo']) && $info['algo'] !== 0 && $info['algo'] !== null) {
		$ok = password_verify($plain, $enc);
	}
}
if (!$ok && function_exists('hash_equals') && function_exists('crypt')) {
	// Legacy MD5-crypt / DES verify only — never mint new crypt hashes (security KK6).
	$ok = hash_equals($enc, (string) crypt($plain, $enc));
}

if ($ok) {
	print("Password Match \n");
	exit(0);
}

print("No Password Match\n");
exit(1);
