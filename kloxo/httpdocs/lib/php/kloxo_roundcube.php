<?php
declare(strict_types=1);

/**
 * Roundcube webmail for Kloxo CP (Ubuntu apt packages).
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 *
 * Installs/configures Roundcube under /home/kloxo/httpd/webmail/roundcube
 * (symlink to the distro web root), MariaDB database, IMAP/SMTP to local
 * Dovecot/Exim, and the shared PHP-FPM socket nginx uses for webmail/cp.
 */

final class KloxoRoundcubeException extends RuntimeException
{
}

final class KloxoRoundcube
{
	/** @var callable|null fn(list<string> $argv, ?string $stdin=null): array{code:int,out:string,err:string} */
	public static $runCmd = null;

	public static string $fsPrefix = '';

	public static string $webmailDir = '/home/kloxo/httpd/webmail';
	public static string $sockLink = '/opt/configs/php-fpm/sock/php-www-data.sock';
	public static string $passFile = '/root/kloxo-roundcube.pass';
	public static string $configOverride = '/etc/roundcube/config.kloxo.inc.php';

	private static function p(string $path): string
	{
		if (self::$fsPrefix === '') {
			return $path;
		}

		return rtrim(self::$fsPrefix, '/') . $path;
	}

	/**
	 * @return list<string>
	 */
	public static function plan(): array
	{
		return [
			'ensure shared php-fpm socket symlink',
			'apt install roundcube roundcube-mysql roundcube-plugins (when not dry)',
			'create MariaDB roundcube DB/user',
			'symlink ' . self::$webmailDir . '/roundcube to distro web root',
			'write ' . self::$configOverride . ' (IMAP 993 / SMTP 587 local)',
		];
	}

	public static function install(): string
	{
		$sock = self::ensurePhpSock();
		$pass = self::ensureDbPassword();
		self::ensureDatabase($pass);
		self::aptInstall();
		$web = self::linkDocroot();
		self::writeConfig($pass);
		self::importSchemaIfNeeded();

		return 'roundcube ready at ' . $web . ' (php sock ' . $sock . ')';
	}

	public static function ensurePhpSock(): string
	{
		$dir = self::p(dirname(self::$sockLink));
		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			throw new KloxoRoundcubeException('cannot create ' . $dir);
		}
		// Prefer 8.3: Ubuntu Roundcube 1.6 polyfills array_first() which
		// fatals on PHP 8.5+ (native array_first).
		$candidates = [
			'/run/php/php8.3-fpm.sock',
			'/run/php/php8.4-fpm.sock',
			'/run/php/php8.5-fpm.sock',
			'/run/php/php8.6-fpm.sock',
			'/run/php/php-fpm.sock',
		];
		$target = '';
		foreach ($candidates as $c) {
			$pc = self::p($c);
			if (is_file($pc) || is_link($pc) || self::isSocket($pc)) {
				$target = $c;
				break;
			}
		}
		if ($target === '') {
			throw new KloxoRoundcubeException('no php-fpm socket found under /run/php');
		}
		$link = self::p(self::$sockLink);
		if (is_link($link) || is_file($link)) {
			@unlink($link);
		}
		if (!@symlink($target, $link)) {
			throw new KloxoRoundcubeException('cannot symlink ' . $link . ' -> ' . $target);
		}

		return $target;
	}

	private static function isSocket(string $path): bool
	{
		if (!file_exists($path)) {
			return false;
		}
		$t = @filetype($path);

		return $t === 'socket';
	}

	public static function ensureDbPassword(): string
	{
		$file = self::p(self::$passFile);
		$dir = dirname($file);
		if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
			throw new KloxoRoundcubeException('cannot create ' . $dir);
		}
		if (is_file($file)) {
			$p = trim((string) file_get_contents($file));
			if ($p !== '' && preg_match('/^[A-Za-z0-9]+$/', $p)) {
				return $p;
			}
		}
		$p = bin2hex(random_bytes(12));
		if (@file_put_contents($file, $p) === false) {
			throw new KloxoRoundcubeException('cannot write ' . $file);
		}
		@chmod($file, 0600);

		return $p;
	}

	public static function ensureDatabase(string $pass): void
	{
		if (!preg_match('/^[A-Za-z0-9]+$/', $pass)) {
			throw new KloxoRoundcubeException('invalid db password');
		}
		$sql = [
			'CREATE DATABASE IF NOT EXISTS roundcube CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
			"CREATE USER IF NOT EXISTS 'roundcube'@'localhost' IDENTIFIED BY '{$pass}'",
			"CREATE USER IF NOT EXISTS 'roundcube'@'127.0.0.1' IDENTIFIED BY '{$pass}'",
			"ALTER USER 'roundcube'@'localhost' IDENTIFIED BY '{$pass}'",
			"ALTER USER 'roundcube'@'127.0.0.1' IDENTIFIED BY '{$pass}'",
			"GRANT ALL PRIVILEGES ON roundcube.* TO 'roundcube'@'localhost'",
			"GRANT ALL PRIVILEGES ON roundcube.* TO 'roundcube'@'127.0.0.1'",
			'FLUSH PRIVILEGES',
		];
		foreach ($sql as $q) {
			$r = self::run(['mysql', '-N', '-e', $q]);
			if ($r['code'] !== 0) {
				throw new KloxoRoundcubeException('mysql failed: ' . trim($r['err'] . ' ' . $r['out']));
			}
		}
	}

	public static function aptInstall(): void
	{
		self::run(['debconf-set-selections'], "roundcube-core roundcube/dbconfig-install boolean false\n");
		self::run(['debconf-set-selections'], "roundcube-core roundcube/dbconfig-upgrade boolean false\n");
		$upd = self::run(['apt-get', 'update', '-qq']);
		if ($upd['code'] !== 0) {
			throw new KloxoRoundcubeException('apt-get update failed: ' . trim($upd['err'] . ' ' . $upd['out']));
		}
		$ins = self::run([
			'apt-get', 'install', '-y', '-o', 'DPkg::Lock::Timeout=600',
			'roundcube', 'roundcube-mysql', 'roundcube-plugins',
		]);
		if ($ins['code'] !== 0) {
			throw new KloxoRoundcubeException('apt-get install roundcube failed: ' . trim($ins['err'] . ' ' . $ins['out']));
		}
	}

	public static function linkDocroot(): string
	{
		$base = self::p(self::$webmailDir);
		if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
			throw new KloxoRoundcubeException('cannot create ' . $base);
		}
		$candidates = [
			'/var/lib/roundcube/public_html',
			'/usr/share/roundcube/public_html',
			'/usr/share/roundcube',
		];
		$src = '';
		foreach ($candidates as $c) {
			if (is_file(self::p($c) . '/index.php')) {
				$src = $c;
				break;
			}
		}
		if ($src === '') {
			throw new KloxoRoundcubeException('roundcube index.php not found after apt install');
		}
		$dest = $base . '/roundcube';
		if (is_link($dest) || is_file($dest) || is_dir($dest)) {
			if (is_link($dest) || is_file($dest)) {
				@unlink($dest);
			} else {
				self::run(['rm', '-rf', $dest]);
			}
		}
		if (!@symlink($src, $dest)) {
			throw new KloxoRoundcubeException('cannot symlink ' . $dest . ' -> ' . $src);
		}
		@chown($dest, 'www-data');
		@chgrp($dest, 'www-data');

		return $src;
	}

	public static function writeConfig(string $pass): void
	{
		if (!preg_match('/^[A-Za-z0-9]+$/', $pass)) {
			throw new KloxoRoundcubeException('invalid db password');
		}
		$dir = self::p('/etc/roundcube');
		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			throw new KloxoRoundcubeException('cannot create ' . $dir);
		}
		$des = bin2hex(random_bytes(12));
		$body = "<?php\n"
			. "// Generated by Kloxo CP. Local IMAP/SMTP and DB.\n"
			. "\$config['db_dsnw'] = 'mysql://roundcube:{$pass}@localhost/roundcube';\n"
			. "\$config['imap_host'] = 'ssl://127.0.0.1:993';\n"
			. "\$config['smtp_host'] = 'tls://127.0.0.1:587';\n"
			. "\$config['smtp_user'] = '%u';\n"
			. "\$config['smtp_pass'] = '%p';\n"
			. "\$config['product_name'] = 'Kloxo Webmail';\n"
			. "\$config['des_key'] = '{$des}';\n"
			. "\$config['imap_conn_options'] = array(\n"
			. "  'ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true),\n"
			. ");\n"
			. "\$config['smtp_conn_options'] = array(\n"
			. "  'ssl' => array('verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true),\n"
			. ");\n";
		$file = self::p(self::$configOverride);
		if (@file_put_contents($file, $body) === false) {
			throw new KloxoRoundcubeException('cannot write ' . $file);
		}
		@chmod($file, 0640);
		@chown($file, 'root');
		@chgrp($file, 'www-data');

		$main = self::p('/etc/roundcube/config.inc.php');
		if (!is_file($main)) {
			@file_put_contents($main, "<?php\ninclude __DIR__ . '/config.kloxo.inc.php';\n");
			@chmod($main, 0640);
			@chown($main, 'root');
			@chgrp($main, 'www-data');
		} else {
			$cur = (string) file_get_contents($main);
			if (!str_contains($cur, 'config.kloxo.inc.php')) {
				@file_put_contents($main, rtrim($cur) . "\ninclude(__DIR__ . '/config.kloxo.inc.php');\n");
			}
		}
	}

	public static function importSchemaIfNeeded(): void
	{
		$r = self::run([
			'mysql', '-N', '-e',
			"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='roundcube'",
		]);
		$n = (int) trim($r['out']);
		if ($n >= 5) {
			return;
		}
		$candidates = [
			'/usr/share/roundcube/SQL/mysql.initial.sql',
			'/usr/share/dbconfig-common/data/roundcube/install/mysql',
		];
		$schema = '';
		foreach ($candidates as $c) {
			if (is_file(self::p($c))) {
				$schema = self::p($c);
				break;
			}
		}
		if ($schema === '') {
			$glob = glob(self::p('/usr/share/roundcube/SQL/mysql') . '/*.sql') ?: [];
			if ($glob !== []) {
				$schema = $glob[0];
			}
		}
		if ($schema === '') {
			throw new KloxoRoundcubeException('roundcube SQL schema not found');
		}
		$sql = (string) file_get_contents($schema);
		$tmp = self::p('/tmp/kloxo-roundcube-schema.sql');
		@file_put_contents($tmp, $sql);
		$imp = self::run(['mysql', 'roundcube'], $sql);
		@unlink($tmp);
		if ($imp['code'] !== 0) {
			throw new KloxoRoundcubeException('schema import failed: ' . trim($imp['err'] . ' ' . $imp['out']));
		}
	}

	/**
	 * @param list<string> $argv
	 * @return array{code:int,out:string,err:string}
	 */
	private static function run(array $argv, ?string $stdin = null): array
	{
		if (self::$runCmd !== null) {
			$fn = self::$runCmd;

			return $fn($argv, $stdin);
		}
		$desc = [
			0 => $stdin !== null ? ['pipe', 'r'] : ['file', '/dev/null', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$proc = proc_open($argv, $desc, $pipes, null, [
			'DEBIAN_FRONTEND' => 'noninteractive',
			'PATH' => getenv('PATH') ?: '/usr/sbin:/usr/bin:/sbin:/bin',
		]);
		if (!is_resource($proc)) {
			return ['code' => 127, 'out' => '', 'err' => 'proc_open failed'];
		}
		if ($stdin !== null) {
			fwrite($pipes[0], $stdin);
			fclose($pipes[0]);
		}
		$out = stream_get_contents($pipes[1]) ?: '';
		$err = stream_get_contents($pipes[2]) ?: '';
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($proc);

		return ['code' => $code, 'out' => $out, 'err' => $err];
	}
}
