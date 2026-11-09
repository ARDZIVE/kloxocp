<?php
declare(strict_types=1);

/**
 * Account backup/restore helpers for admin and client Backup Now / Restore.
 * Paths, MariaDB dump/restore, /var/vmail packs, archive extract, and the
 * background launch of bin/common/backup.php and restore.php. Argument-array
 * proc_open only (never a shell string).
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoBackupException extends RuntimeException
{
}

final class KloxoBackup
{
	/** Tests point every absolute path under this directory. Never set from input. */
	public static string $fsPrefix = '';

	/** @var callable|null fn(list<string> $argv): array{code:int,out:string} */
	public static $runCmd = null;

	/** Tests skip systemd-run and only record the php argv. */
	public static bool $launchDirect = false;

	public static function programRoot(): string
	{
		if (function_exists('kloxo_program_root')) {
			$r = kloxo_program_root();
			if (is_string($r) && $r !== '') {
				return rtrim($r, '/');
			}
		}

		return dirname(__DIR__, 3);
	}

	public static function phpBinary(): string
	{
		if (function_exists('kloxo_php_binary')) {
			$p = kloxo_php_binary();
			if (is_string($p) && $p !== '') {
				return $p;
			}
		}

		return defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : '/usr/bin/php';
	}

	public static function backupScript(): string
	{
		return self::programRoot() . '/bin/common/backup.php';
	}

	public static function restoreScript(): string
	{
		return self::programRoot() . '/bin/common/restore.php';
	}

	public static function programHome(): string
	{
		global $sgbl;
		if (is_object($sgbl) && isset($sgbl->__path_program_home)
			&& is_string($sgbl->__path_program_home) && $sgbl->__path_program_home !== '') {
			return rtrim($sgbl->__path_program_home, '/');
		}

		return '/home/kloxo';
	}

	public static function customerRoot(): string
	{
		global $sgbl;
		if (is_object($sgbl) && isset($sgbl->__path_customer_root)
			&& is_string($sgbl->__path_customer_root) && $sgbl->__path_customer_root !== '') {
			return rtrim((string) $sgbl->__path_customer_root, '/');
		}

		return '/home';
	}

	public static function mailRoot(): string
	{
		$p = self::p('/var/vmail');
		if (is_object($GLOBALS['sgbl'] ?? null) && isset($GLOBALS['sgbl']->__path_mail_data)
			&& is_string($GLOBALS['sgbl']->__path_mail_data) && $GLOBALS['sgbl']->__path_mail_data !== '') {
			return self::p(rtrim($GLOBALS['sgbl']->__path_mail_data, '/'));
		}

		return $p;
	}

	public static function backupDir(string $table, string $name): string
	{
		$table = self::token($table);
		$name = self::token($name, true);
		if ($table === '' || $name === '') {
			throw new KloxoBackupException('invalid backup directory');
		}

		return self::programHome() . '/' . $table . '/' . $name . '/__backup';
	}

	/**
	 * Directory listed by Backup / Restore → File Manager. Creates it and
	 * gives it to lxlabs so the panel can list and download archives.
	 */
	public static function ensureDir(string $table, string $name): string
	{
		$dir = self::p(self::backupDir($table, $name));
		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			throw new KloxoBackupException('could not create File Manager backup directory');
		}
		self::chownLxlabs($dir, 0755);

		return $dir;
	}

	/**
	 * Put an archive into the File Manager folder (same path Backup Now and
	 * the File Manager tab use). Moves if it was written elsewhere.
	 */
	public static function installFile(string $path, string $table, string $name): string
	{
		$dir = self::ensureDir($table, $name);
		$base = basename($path);
		if ($base === '' || $base === '.' || $base === '..' || str_contains($base, "\0")) {
			throw new KloxoBackupException('invalid backup filename');
		}
		$dest = $dir . '/' . $base;
		if (is_file($path)) {
			$srcReal = @realpath($path);
			$dstReal = is_file($dest) ? @realpath($dest) : false;
			if ($srcReal === false || $dstReal === false || $srcReal !== $dstReal) {
				if ($path !== $dest) {
					if (!@rename($path, $dest) && !@copy($path, $dest)) {
						throw new KloxoBackupException('could not store backup in File Manager');
					}
					if (is_file($path) && $path !== $dest) {
						@unlink($path);
					}
				}
			}
		}
		if (is_file($dest)) {
			self::chownLxlabs($dest, 0640);
		}

		return $dest;
	}

	public static function webGroup(): string
	{
		if (class_exists('KloxoIpaddr', false) && is_callable(array('KloxoIpaddr', 'isUbuntu'))
			&& KloxoIpaddr::isUbuntu()) {
			return 'www-data';
		}
		if (function_exists('posix_getgrnam') && @posix_getgrnam('www-data')) {
			return 'www-data';
		}

		return 'apache';
	}

	/** Basename fragment: letters, digits, dash, underscore, dot. */
	public static function safeName(string $name): string
	{
		$n = preg_replace('/[^A-Za-z0-9._-]/', '', $name) ?? '';
		$n = trim($n, '.-');

		return $n !== '' ? substr($n, 0, 80) : 'kloxo';
	}

	/**
	 * Client/domain names from etc/list/unbackup.lst (never back these up).
	 *
	 * @return list<string>
	 */
	public static function unbackupNames(): array
	{
		$root = self::programRoot();
		$file = $root . '/etc/list/unbackup.lst';
		if (!is_file($file)) {
			return array('backuper', 'rpms', 'excluder');
		}
		$c = trim((string) file_get_contents($file));
		if ($c === '') {
			return array();
		}
		$out = array();
		foreach (explode(',', $c) as $n) {
			$n = trim($n);
			if ($n !== '') {
				$out[] = $n;
			}
		}

		return $out;
	}

	public static function mailDomainDir(string $domain): string
	{
		$d = strtolower($domain);
		if (preg_match('/^[A-Za-z0-9.-]+$/', $d) !== 1 || $d === '' || str_contains($d, '..')) {
			throw new KloxoBackupException('invalid mail domain');
		}

		return rtrim(self::mailRoot(), '/') . '/' . $d;
	}

	/**
	 * @return list<string>
	 */
	public static function backupArgv(string $class, string $name, string $filePrefix, array $extra = array()): array
	{
		$class = self::token($class);
		$name = self::token($name, true);
		if ($class === '' || $name === '') {
			throw new KloxoBackupException('invalid backup class or name');
		}
		$argv = array(
			self::phpBinary(),
			self::backupScript(),
			'--class=' . $class,
			'--name=' . $name,
			'--v-backup_file_name=' . self::safeName($filePrefix),
		);
		foreach ($extra as $k => $v) {
			if (!is_string($k) || !str_starts_with($k, 'backupextra_')) {
				continue;
			}
			$key = self::token($k);
			if ($key === '') {
				continue;
			}
			$argv[] = '--v-' . $key . '=' . self::safeName((string) $v);
		}

		return $argv;
	}

	/**
	 * @param list<string> $accounts
	 * @return list<string>
	 */
	public static function restoreArgv(string $class, string $name, string $file, array $accounts = array()): array
	{
		$class = self::token($class);
		$name = self::token($name, true);
		if ($class === '' || $name === '' || $file === '' || str_contains($file, "\0")) {
			throw new KloxoBackupException('invalid restore class, name or file');
		}
		if ($accounts === array()) {
			$acc = 'all';
		} else {
			$parts = array();
			foreach ($accounts as $a) {
				$a = str_replace(array(';', "\n", "\r"), '', (string) $a);
				$a = str_replace('-', ':', $a);
				if ($a !== '') {
					$parts[] = $a;
				}
			}
			$acc = $parts !== array() ? implode(',', $parts) : 'all';
		}

		return array(
			self::phpBinary(),
			self::restoreScript(),
			'--class=' . $class,
			'--name=' . $name,
			'--restore',
			'--accounts=' . $acc,
			'--priority=low',
			$file,
		);
	}

	/**
	 * Backup Now: run backup.php and wait until it finishes.
	 *
	 * @param array<string,mixed> $extra
	 * @return array{code:int,out:string}
	 */
	public static function runBackup(string $class, string $name, string $filePrefix, array $extra = array()): array
	{
		return self::run(self::backupArgv($class, $name, $filePrefix, $extra));
	}

	/** Scheduled / background backup (cron). Backup Now must use runBackup(). */
	public static function launchBackup(string $class, string $name, string $filePrefix, array $extra = array()): void
	{
		self::launch(self::backupArgv($class, $name, $filePrefix, $extra), 'backup');
	}

	/**
	 * @param list<string> $accounts
	 */
	public static function launchRestore(string $class, string $name, string $file, array $accounts = array()): void
	{
		self::launch(self::restoreArgv($class, $name, $file, $accounts), 'restore');
	}

	/**
	 * @return list<string>
	 */
	public static function dumpArgv(string $mysqldump, string $dbname, string $user, string $pass): array
	{
		if (!self::dbIdent($dbname) || $mysqldump === '') {
			throw new KloxoBackupException('invalid mysqldump or database');
		}
		if ($user === '' || preg_match('/^[A-Za-z0-9_]+$/', $user) !== 1) {
			throw new KloxoBackupException('invalid dump user');
		}
		$argv = array(
			$mysqldump,
			'--add-drop-table',
			'--default-character-set=utf8mb4',
			'--single-transaction',
			'-u',
			$user,
		);
		if ($pass !== '') {
			$argv[] = '--password=' . $pass;
		}
		$argv[] = $dbname;

		return $argv;
	}

	/**
	 * @return list<string>
	 */
	public static function restoreDumpArgv(string $mysql, string $dbname, string $user, string $pass): array
	{
		if (!self::dbIdent($dbname) || $mysql === '') {
			throw new KloxoBackupException('invalid mysql client or database');
		}
		if ($user === '' || preg_match('/^[A-Za-z0-9_]+$/', $user) !== 1) {
			throw new KloxoBackupException('invalid restore user');
		}
		$argv = array($mysql, '--default-character-set=utf8mb4', '-u', $user);
		if ($pass !== '') {
			$argv[] = '--password=' . $pass;
		}
		$argv[] = $dbname;

		return $argv;
	}

	public static function dumpMysql(string $dbname, string $user, string $pass, string $outfile): void
	{
		$bin = self::firstExec(array('/usr/bin/mysqldump', '/usr/bin/mariadb-dump', 'mysqldump'));
		$argv = self::dumpArgv($bin !== '' ? $bin : 'mysqldump', $dbname, $user, $pass);
		$dir = dirname($outfile);
		if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
			@mkdir($dir, 0700, true);
		}
		$r = self::run($argv, $outfile);
		if (($r['code'] ?? 1) !== 0) {
			throw new KloxoBackupException('mysqldump failed for ' . $dbname);
		}
		if (!is_file($outfile) || filesize($outfile) === 0) {
			throw new KloxoBackupException('mysqldump wrote no data for ' . $dbname);
		}
	}

	public static function restoreMysql(string $dbname, string $user, string $pass, string $infile): void
	{
		if (!is_file($infile) || !is_readable($infile)) {
			throw new KloxoBackupException('mysql dump file missing');
		}
		$bin = self::firstExec(array('/usr/bin/mysql', '/usr/bin/mariadb', 'mysql'));
		$argv = self::restoreDumpArgv($bin !== '' ? $bin : 'mysql', $dbname, $user, $pass);
		$r = self::run($argv, null, $infile);
		if (($r['code'] ?? 1) !== 0) {
			throw new KloxoBackupException('mysql restore failed for ' . $dbname);
		}
	}

	/**
	 * Pack /var/vmail/<domain> into $backupdir as {domain}-mmail-any-{id}.tgz.
	 * Empty string means the domain has no mail tree (skip).
	 */
	public static function packMailDomain(string $domain, string $backupdir, string $id): string
	{
		$dir = self::mailDomainDir($domain);
		if (!is_dir($dir)) {
			return '';
		}
		$id = self::safeName($id);
		$d = strtolower($domain);
		$filename = $d . '-mmail-any-' . $id . '.tgz';
		if (!is_dir($backupdir)) {
			@mkdir($backupdir, 0700, true);
		}
		$out = rtrim($backupdir, '/') . '/' . $filename;
		$tar = self::firstExec(array('/usr/bin/tar', '/bin/tar', 'tar'));
		$argv = array(
			$tar !== '' ? $tar : 'tar',
			'-czf',
			$out,
			'-C',
			dirname($dir),
			basename($dir),
		);
		$r = self::run($argv);
		if (($r['code'] ?? 1) !== 0 || !is_file($out)) {
			throw new KloxoBackupException('could not pack mail for ' . $d);
		}

		return $filename;
	}

	public static function unpackMailDomain(string $domain, string $bdir, string $id): bool
	{
		$d = strtolower($domain);
		$want = $d . '-mmail-any-' . self::safeName($id);
		$archive = '';
		if (is_dir($bdir)) {
			foreach ((array) @scandir($bdir) as $f) {
				if (!is_string($f) || $f === '.' || $f === '..') {
					continue;
				}
				if (str_starts_with($f, $want . '.') || $f === $want . '.tgz' || $f === $want . '.tar') {
					$archive = rtrim($bdir, '/') . '/' . $f;
					break;
				}
			}
		}
		if ($archive === '' || !is_file($archive)) {
			return false;
		}
		$destRoot = rtrim(self::mailRoot(), '/');
		if (!is_dir($destRoot)) {
			@mkdir($destRoot, 0750, true);
		}
		self::extractArchive($archive, $destRoot);
		$dest = self::mailDomainDir($d);
		if (is_dir($dest) && function_exists('posix_getpwnam') && @posix_getpwnam('vmail')) {
			@chown($dest, 'vmail');
			@chgrp($dest, 'vmail');
		}

		return true;
	}

	/**
	 * True when an archive member path cannot escape $dest (no .., absolute,
	 * drive letter, NUL, or option-like names).
	 */
	public static function archiveMemberIsSafe(string $member): bool
	{
		if (str_contains($member, "\0")) {
			return false;
		}
		$m = str_replace('\\', '/', $member);
		$m = preg_replace('#^(?:\./)+#', '', $m) ?? $m;
		if ($m === '' || $m === '.' || $m === '..') {
			return false;
		}
		if ($m[0] === '/' || $m[0] === '-') {
			return false;
		}
		if (preg_match('#^[A-Za-z]:/#', $m) === 1) {
			return false;
		}
		foreach (explode('/', $m) as $part) {
			if ($part === '' && $m[0] === '/') {
				return false;
			}
			if ($part === '..') {
				return false;
			}
		}

		return true;
	}

	/**
	 * Restore metadata: PHP-serialized Remote/lxclass graph from kloxo.file.
	 * Rejects namespaced classes, Phar/C custom serializers, and internals.
	 *
	 * @return mixed
	 */
	public static function unserializeMetadata(string $raw)
	{
		if ($raw === '') {
			throw new KloxoBackupException('backup file corrupted');
		}
		$found = self::serializedClasses($raw);
		$allow = array();
		foreach ($found as $c) {
			if (($c['kind'] ?? '') !== 'O') {
				throw new KloxoBackupException('backup file corrupted');
			}
			$name = (string) ($c['name'] ?? '');
			if (!self::isAllowedBackupClass($name)) {
				throw new KloxoBackupException('backup file corrupted');
			}
			$allow[] = $name;
		}
		$opts = array('allowed_classes' => $allow === array() ? false : array_values(array_unique($allow)));
		$rem = @unserialize($raw, $opts);
		if ($rem === false && $raw !== serialize(false)) {
			throw new KloxoBackupException('backup file corrupted');
		}

		return $rem;
	}

	/**
	 * Extract a .tgz/.tar/.zip archive into $dest. Only members whose paths
	 * stay under $dest are extracted; glob members and symlink members are
	 * refused. $members limits the extract to those names (must themselves
	 * be safe).
	 *
	 * @param list<string> $members
	 */
	public static function extractArchive(string $archive, string $dest, array $members = array()): void
	{
		if (!is_file($archive) || $dest === '' || str_contains($dest, "\0")) {
			throw new KloxoBackupException('invalid archive or destination');
		}
		if (!is_dir($dest)) {
			@mkdir($dest, 0700, true);
		}
		if (!is_dir($dest)) {
			throw new KloxoBackupException('invalid archive or destination');
		}
		$listed = self::listArchiveMembers($archive);
		$safe = array();
		foreach ($listed as $entry) {
			$name = (string) ($entry['name'] ?? '');
			if (!self::archiveMemberIsSafe($name)) {
				throw new KloxoBackupException('archive contains an unsafe path');
			}
			if (!empty($entry['link'])) {
				continue;
			}
			$safe[] = $name;
		}
		$extract = $safe;
		if ($members !== array()) {
			$want = array();
			foreach ($members as $m) {
				$m = str_replace(array(';', "\n", "\r", "\0"), '', (string) $m);
				if ($m === '' || str_contains($m, '*') || !self::archiveMemberIsSafe($m)) {
					throw new KloxoBackupException('archive contains an unsafe path');
				}
				$want[] = $m;
			}
			$extract = array();
			foreach ($safe as $n) {
				if (in_array($n, $want, true) || in_array(preg_replace('#^(?:\./)+#', '', $n), $want, true)) {
					$extract[] = $n;
				}
			}
			if ($extract === array()) {
				throw new KloxoBackupException('could not untar ' . basename($archive));
			}
		}
		if ($extract === array()) {
			return;
		}
		$type = self::archiveType($archive);
		if ($type === 'zip') {
			$unzip = self::firstExec(array('/usr/bin/unzip', '/bin/unzip', 'unzip'));
			$argv = array($unzip !== '' ? $unzip : 'unzip', '-oq', $archive, '-d', $dest);
			foreach ($extract as $m) {
				$argv[] = $m;
			}
			$r = self::run($argv);
			if (($r['code'] ?? 1) !== 0) {
				throw new KloxoBackupException('could not unzip ' . basename($archive));
			}

			return;
		}
		$tar = self::firstExec(array('/usr/bin/tar', '/bin/tar', 'tar'));
		$argv = array($tar !== '' ? $tar : 'tar', '-x', '-C', $dest, '-f', $archive);
		if ($type === 'tgz') {
			array_splice($argv, 1, 0, '-z');
		}
		$argv[] = '--';
		foreach ($extract as $m) {
			$argv[] = $m;
		}
		$r = self::run($argv);
		if (($r['code'] ?? 1) !== 0) {
			throw new KloxoBackupException('could not untar ' . basename($archive));
		}
	}

	/**
	 * @return list<array{name:string,link:bool}>
	 */
	public static function listArchiveMembers(string $archive): array
	{
		$type = self::archiveType($archive);
		if ($type === 'zip') {
			return self::listZipMembers($archive);
		}

		return self::listTarMembers($archive, $type === 'tgz');
	}

	/** @return list<array{kind:string,name:string}> */
	public static function serializedClasses(string $raw): array
	{
		$out = array();
		$i = 0;
		$n = strlen($raw);
		while ($i < $n) {
			while ($i < $n && ($raw[$i] === '{' || $raw[$i] === '}' || $raw[$i] === ';' || $raw[$i] === ':')) {
				$i++;
			}
			if ($i >= $n) {
				break;
			}
			$t = $raw[$i];
			if ($t === 'N') {
				$i++;
				continue;
			}
			if ($t === 'R' || $t === 'r' || $t === 'i' || $t === 'd' || $t === 'b') {
				$semi = strpos($raw, ';', $i);
				if ($semi === false) {
					break;
				}
				$i = $semi + 1;
				continue;
			}
			if ($t === 's' && ($raw[$i + 1] ?? '') === ':') {
				if (preg_match('/\Gs:(\d+):"/', $raw, $m, 0, $i) !== 1) {
					throw new KloxoBackupException('backup file corrupted');
				}
				$len = (int) $m[1];
				$start = $i + strlen($m[0]);
				if ($start + $len > $n) {
					throw new KloxoBackupException('backup file corrupted');
				}
				$i = $start + $len;
				if ($i < $n && $raw[$i] === '"') {
					$i++;
				}
				continue;
			}
			if ($t === 'a' && preg_match('/\Ga:\d+:{/', $raw, $m, 0, $i) === 1) {
				$i += strlen($m[0]);
				continue;
			}
			if (($t === 'O' || $t === 'C' || $t === 'E') && ($raw[$i + 1] ?? '') === ':') {
				if (preg_match('/\G[OCE]:(\d+):"/', $raw, $m, 0, $i) !== 1) {
					throw new KloxoBackupException('backup file corrupted');
				}
				$len = (int) $m[1];
				$start = $i + strlen($m[0]);
				if ($start + $len > $n) {
					throw new KloxoBackupException('backup file corrupted');
				}
				$name = substr($raw, $start, $len);
				$i = $start + $len;
				if ($i < $n && $raw[$i] === '"') {
					$i++;
				}
				$out[] = array('kind' => $t, 'name' => $name);
				if ($t === 'C' || $t === 'E') {
					if (preg_match('/\G:(\d+):{/', $raw, $mm, 0, $i) === 1) {
						$pay = (int) $mm[1];
						$i += strlen($mm[0]) + $pay;
					}
				}
				continue;
			}
			$i++;
		}

		return $out;
	}

	public static function isAllowedBackupClass(string $name): bool
	{
		if ($name === 'Remote' || $name === 'stdClass') {
			return true;
		}
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
			return false;
		}
		$deny = array(
			'phar', 'pdo', 'sqlite3', 'simplexmlelement', 'splfileobject',
			'splfileinfo', 'directoryiterator', 'filesystemiterator',
			'soapclient', 'phpmailer', 'smtp', 'pop3', 'imagick',
		);

		return !in_array(strtolower($name), $deny, true);
	}

	/** @return list<array{name:string,link:bool}> */
	private static function listTarMembers(string $archive, bool $gzip): array
	{
		$tar = self::firstExec(array('/usr/bin/tar', '/bin/tar', 'tar'));
		$bin = $tar !== '' ? $tar : 'tar';
		$argv = array($bin, '-t', '-f', $archive);
		if ($gzip) {
			array_splice($argv, 1, 0, '-z');
		}
		$r = self::run($argv, null, null, true);
		if (($r['code'] ?? 1) !== 0) {
			throw new KloxoBackupException('could not list ' . basename($archive));
		}
		$names = array();
		foreach (preg_split("/\r\n|\n|\r/", (string) ($r['out'] ?? '')) as $line) {
			$name = trim($line);
			if ($name === '' || str_starts_with($name, 'tar:')) {
				continue;
			}
			$names[] = $name;
		}
		$links = array();
		$vArgv = $argv;
		$tPos = array_search('-t', $vArgv, true);
		if ($tPos !== false) {
			$vArgv[$tPos] = '-tv';
			$vr = self::run($vArgv, null, null, true);
			if (($vr['code'] ?? 1) === 0) {
				foreach (preg_split("/\r\n|\n|\r/", (string) ($vr['out'] ?? '')) as $line) {
					$line = trim($line);
					if ($line === '' || ($line[0] !== 'l' && $line[0] !== 'h')) {
						continue;
					}
					foreach ($names as $n) {
						if ($n !== '' && str_contains($line, $n)) {
							$links[$n] = true;
						}
					}
				}
			}
		}
		$out = array();
		foreach ($names as $name) {
			$out[] = array('name' => $name, 'link' => isset($links[$name]));
		}

		return $out;
	}

	/** @return list<array{name:string,link:bool}> */
	private static function listZipMembers(string $archive): array
	{
		if (class_exists('ZipArchive')) {
			$z = new ZipArchive();
			if ($z->open($archive) === true) {
				$out = array();
				for ($i = 0; $i < $z->numFiles; $i++) {
					$stat = $z->statIndex($i);
					if (!is_array($stat) || !isset($stat['name'])) {
						continue;
					}
					$name = str_replace('\\', '/', (string) $stat['name']);
					$mode = isset($stat['external']) ? (((int) $stat['external']) >> 16) : 0;
					$link = ($mode & 0120000) === 0120000;
					$out[] = array('name' => $name, 'link' => $link);
				}
				$z->close();

				return $out;
			}
		}
		$unzip = self::firstExec(array('/usr/bin/unzip', '/bin/unzip', 'unzip'));
		$r = self::run(array($unzip !== '' ? $unzip : 'unzip', '-Z1', $archive), null, null, true);
		if (($r['code'] ?? 1) !== 0) {
			throw new KloxoBackupException('could not list ' . basename($archive));
		}
		$out = array();
		foreach (preg_split("/\r\n|\n|\r/", (string) ($r['out'] ?? '')) as $line) {
			$name = str_replace('\\', '/', trim($line));
			if ($name === '') {
				continue;
			}
			$out[] = array('name' => $name, 'link' => false);
		}

		return $out;
	}

	public static function archiveType(string $file): string
	{
		$fh = @fopen($file, 'rb');
		if (!is_resource($fh)) {
			$ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));

			return $ext === 'zip' ? 'zip' : ($ext === 'tar' ? 'tar' : 'tgz');
		}
		$head = (string) fread($fh, 4);
		fclose($fh);
		if (str_starts_with($head, "PK")) {
			return 'zip';
		}
		if (strlen($head) >= 2 && $head[0] === "\x1f" && $head[1] === "\x8b") {
			return 'tgz';
		}

		return 'tar';
	}

	public static function dbIdent(string $name): bool
	{
		if (class_exists('KloxoMariadb', false) && is_callable(array('KloxoMariadb', 'ident'))) {
			return KloxoMariadb::ident($name);
		}

		return preg_match('/^[a-z0-9_]{1,64}$/', $name) === 1
			&& !in_array($name, array('mysql', 'information_schema', 'performance_schema', 'sys', 'kloxo'), true);
	}

	/** @param list<string> $cands */
	private static function firstExec(array $cands): string
	{
		foreach ($cands as $p) {
			if ($p !== '' && ($p[0] ?? '') === '/' && is_executable($p)) {
				return $p;
			}
			if ($p !== '' && ($p[0] ?? '') !== '/' && self::$runCmd !== null) {
				return $p;
			}
		}

		return $cands[0] ?? '';
	}

	/**
	 * @param list<string> $argv
	 * @return array{code:int,out:string}
	 */
	private static function run(array $argv, ?string $stdoutFile = null, ?string $stdinFile = null, bool $stdoutOnly = false): array
	{
		if (self::$runCmd !== null) {
			$r = (self::$runCmd)($argv);
			$code = (int) ($r['code'] ?? 1);
			$out = (string) ($r['out'] ?? '');
			if ($stdoutFile !== null && $stdoutFile !== '' && $code === 0) {
				@file_put_contents($stdoutFile, $out !== '' ? $out : "-- dump\n");
			}

			return array('code' => $code, 'out' => $out);
		}
		if ($argv === [] || !is_string($argv[0]) || $argv[0] === '') {
			return array('code' => 127, 'out' => 'empty command');
		}
		$spec = array(
			0 => $stdinFile !== null && is_file($stdinFile)
				? array('file', $stdinFile, 'r')
				: array('file', '/dev/null', 'r'),
			1 => $stdoutFile !== null && $stdoutFile !== ''
				? array('file', $stdoutFile, 'w')
				: array('pipe', 'w'),
			2 => array('pipe', 'w'),
		);
		$p = @proc_open($argv, $spec, $pipes);
		if (!is_resource($p)) {
			return array('code' => 127, 'out' => 'could not start ' . $argv[0]);
		}
		$err = '';
		$out = '';
		if (isset($pipes[1]) && is_resource($pipes[1])) {
			$out = (string) stream_get_contents($pipes[1]);
			fclose($pipes[1]);
		}
		if (isset($pipes[2]) && is_resource($pipes[2])) {
			$err = (string) stream_get_contents($pipes[2]);
			fclose($pipes[2]);
		}

		$combined = $stdoutOnly ? $out : ($out . "\n" . $err);

		return array('code' => proc_close($p), 'out' => trim($combined));
	}

	/**
	 * @param list<string> $argv
	 */
	private static function launch(array $argv, string $kind): void
	{
		if (self::$runCmd !== null || self::$launchDirect) {
			self::run($argv);

			return;
		}
		$unit = 'kloxo-' . $kind . '-' . (string) getmypid() . '-' . (string) time();
		$systemd = '/usr/bin/systemd-run';
		if (is_executable($systemd)) {
			$r = self::run(array_merge(array(
				$systemd,
				'--quiet',
				'--collect',
				'--no-block',
				'--unit=' . $unit,
			), $argv));
			if (($r['code'] ?? 1) === 0) {
				return;
			}
		}
		if (function_exists('lxshell_background')) {
			$rest = array_slice($argv, 1);
			lxshell_background($argv[0], ...$rest);

			return;
		}
		throw new KloxoBackupException('could not start ' . $kind);
	}

	private static function token(string $s, bool $allowDot = false): string
	{
		$pat = $allowDot ? '/^[A-Za-z0-9._-]+$/' : '/^[A-Za-z0-9_-]+$/';
		$s = trim($s);
		if ($s === '' || str_contains($s, '..') || preg_match($pat, $s) !== 1) {
			return '';
		}

		return $s;
	}

	private static function p(string $path): string
	{
		if (self::$fsPrefix !== '') {
			return rtrim(self::$fsPrefix, '/') . $path;
		}

		return $path;
	}

	private static function chownLxlabs(string $path, int $mode): void
	{
		@chmod($path, $mode);
		if (function_exists('posix_getpwnam') && @posix_getpwnam('lxlabs')) {
			@chown($path, 'lxlabs');
			@chgrp($path, 'lxlabs');
		}
	}
}
