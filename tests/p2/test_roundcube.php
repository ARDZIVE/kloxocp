<?php
declare(strict_types=1);

/**
 * Roundcube installer: docroot symlink, config, PHP sock preference (no apt).
 */
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

$repo = dirname(__DIR__, 2);
$lib = $repo . '/kloxo/httpdocs/lib/php/kloxo_roundcube.php';
require_once $lib;

$fs = sys_get_temp_dir() . '/kloxo-rc-' . bin2hex(random_bytes(4));
@mkdir($fs . '/run/php', 0755, true);
@mkdir($fs . '/usr/share/roundcube/SQL', 0755, true);
@mkdir($fs . '/root', 0755, true);
file_put_contents($fs . '/usr/share/roundcube/index.php', "<?php\n");
file_put_contents($fs . '/usr/share/roundcube/SQL/mysql.initial.sql', "-- empty\n");
// Fake a php8.3 socket file (filetype may not be socket in tmpfs).
file_put_contents($fs . '/run/php/php8.3-fpm.sock', '');
file_put_contents($fs . '/run/php/php8.6-fpm.sock', '');

KloxoRoundcube::$fsPrefix = $fs;
KloxoRoundcube::$runCmd = static function (array $argv, ?string $stdin = null): array {
	$cmd = $argv[0] ?? '';
	if ($cmd === 'mysql') {
		return ['code' => 0, 'out' => '0', 'err' => ''];
	}
	if ($cmd === 'apt-get' || $cmd === 'debconf-set-selections') {
		return ['code' => 0, 'out' => '', 'err' => ''];
	}
	if ($cmd === 'rm') {
		return ['code' => 0, 'out' => '', 'err' => ''];
	}

	return ['code' => 0, 'out' => '', 'err' => ''];
};

try {
	$sock = KloxoRoundcube::ensurePhpSock();
	if ($sock === '/run/php/php8.3-fpm.sock'
		&& is_link($fs . '/opt/configs/php-fpm/sock/php-www-data.sock')) {
		ok('ensurePhpSock prefers php8.3 and creates symlink');
	} else {
		bad('ensurePhpSock prefers php8.3 and creates symlink', $sock);
	}
} catch (Throwable $e) {
	bad('ensurePhpSock prefers php8.3 and creates symlink', $e->getMessage());
}

try {
	$pw = KloxoRoundcube::ensureDbPassword();
	if (preg_match('/^[a-f0-9]{24}$/', $pw) && is_file($fs . '/root/kloxo-roundcube.pass')) {
		ok('ensureDbPassword writes hex password once');
	} else {
		bad('ensureDbPassword writes hex password once', $pw);
	}
	$pw2 = KloxoRoundcube::ensureDbPassword();
	if ($pw2 === $pw) {
		ok('ensureDbPassword keeps existing password');
	} else {
		bad('ensureDbPassword keeps existing password');
	}
} catch (Throwable $e) {
	bad('ensureDbPassword', $e->getMessage());
}

try {
	KloxoRoundcube::writeConfig('aabbccddeeff001122334455');
	$cfg = (string) file_get_contents($fs . '/etc/roundcube/config.kloxo.inc.php');
	$okCfg = str_contains($cfg, "\$config['db_dsnw']")
		&& str_contains($cfg, 'imap_host')
		&& str_contains($cfg, 'smtp_host')
		&& str_contains($cfg, 'aabbccddeeff001122334455')
		&& !str_contains($cfg, '["db_dsnw"]');
	if ($okCfg) {
		ok('writeConfig emits $config keys for Roundcube 1.6');
	} else {
		bad('writeConfig emits $config keys for Roundcube 1.6', substr($cfg, 0, 200));
	}
} catch (Throwable $e) {
	bad('writeConfig emits $config keys for Roundcube 1.6', $e->getMessage());
}

try {
	$src = KloxoRoundcube::linkDocroot();
	$dest = $fs . '/home/kloxo/httpd/webmail/roundcube';
	if ($src === '/usr/share/roundcube' && is_link($dest) && readlink($dest) === '/usr/share/roundcube') {
		ok('linkDocroot symlinks /home/kloxo/httpd/webmail/roundcube');
	} else {
		bad('linkDocroot symlinks /home/kloxo/httpd/webmail/roundcube', $src . ' ' . (string) @readlink($dest));
	}
} catch (Throwable $e) {
	bad('linkDocroot symlinks /home/kloxo/httpd/webmail/roundcube', $e->getMessage());
}

$plan = KloxoRoundcube::plan();
if (count($plan) >= 4) {
	ok('plan lists install steps');
} else {
	bad('plan lists install steps');
}

$bin = $repo . '/kloxo/bin/install/install-roundcube';
if (is_file($bin) && str_contains((string) file_get_contents($bin), 'KloxoRoundcube::install')) {
	ok('install-roundcube binary exists');
} else {
	bad('install-roundcube binary exists');
}

exec('rm -rf ' . escapeshellarg($fs));
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
