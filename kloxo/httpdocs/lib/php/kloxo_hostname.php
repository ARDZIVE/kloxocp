<?php
declare(strict_types=1);

/**
 * Server hostname (FQDN) for the panel: validate, read current, apply to the OS.
 *
 * Sets the systemd hostname, /etc/hostname, /etc/mailname, and the 127.0.1.1
 * line in /etc/hosts. Must run as root (kloxo-backend).
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoHostname
{
	/** @var null|bool Test hook for the root check. */
	public static $assumeRoot = null;

	/** @var null|string Prefix for /etc paths in tests (empty = real system). */
	public static $rootOverride = null;

	/** @var null|callable(list<string>):array{code:int,out:string,err:string} */
	public static $runHook = null;

	public static function isValidFqdn(string $host): bool
	{
		$host = self::normalize($host);
		if ($host === '' || $host === 'localhost' || $host === 'localhost.localdomain') {
			return false;
		}
		if (filter_var($host, FILTER_VALIDATE_IP)) {
			return false;
		}
		if (class_exists('KloxoBackup') && is_callable(array('KloxoBackup', 'isMailHostname'))) {
			return KloxoBackup::isMailHostname($host);
		}
		if (function_exists('kloxo_mail_setup_hostname_ok')) {
			return kloxo_mail_setup_hostname_ok($host);
		}

		return preg_match(
			'/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9-]{1,59})$/',
			$host
		) === 1;
	}

	public static function normalize(string $host): string
	{
		$host = strtolower(trim($host));
		$host = rtrim($host, '.');

		return $host;
	}

	/** Best current FQDN from panel DB / OS files / gethostname. */
	public static function current(): string
	{
		foreach (array(self::path('/etc/mailname'), self::path('/etc/hostname')) as $f) {
			if (!is_readable($f)) {
				continue;
			}
			$raw = @file_get_contents($f);
			if (!is_string($raw)) {
				continue;
			}
			$line = self::normalize(strtok($raw, "\r\n") ?: '');
			if (self::isValidFqdn($line)) {
				return $line;
			}
		}
		if (function_exists('gethostname')) {
			$g = self::normalize((string) gethostname());
			if (self::isValidFqdn($g)) {
				return $g;
			}
		}

		return '';
	}

	/**
	 * Apply a dotted FQDN on this host.
	 *
	 * @throws RuntimeException when not root, invalid, or apply fails
	 */
	public static function apply(string $fqdn): void
	{
		$fqdn = self::normalize($fqdn);
		if (!self::isValidFqdn($fqdn)) {
			throw new RuntimeException('Hostname must be a dotted FQDN (for example server.example.com)');
		}
		if (!self::isRoot()) {
			throw new RuntimeException('Changing the hostname requires root (kloxo-backend)');
		}

		$etcHost = self::path('/etc/hostname');
		$etcMail = self::path('/etc/mailname');
		$etcHosts = self::path('/etc/hosts');
		$body = $fqdn . "\n";

		if (@file_put_contents($etcHost, $body) === false) {
			throw new RuntimeException('Could not write /etc/hostname');
		}
		@file_put_contents($etcMail, $body);

		$ctl = self::run(array('hostnamectl', 'set-hostname', $fqdn));
		if ($ctl['code'] !== 0) {
			$hn = self::run(array('hostname', $fqdn));
			if ($hn['code'] !== 0) {
				throw new RuntimeException(
					'Could not set system hostname: '
					. trim($ctl['err'] !== '' ? $ctl['err'] : $hn['err'])
				);
			}
		}

		self::updateHostsFile($etcHosts, $fqdn);
	}

	/**
	 * Keep a 127.0.1.1 line for the short name + FQDN (Debian/Ubuntu style).
	 */
	public static function updateHostsFile(string $hostsPath, string $fqdn): void
	{
		$fqdn = self::normalize($fqdn);
		if (!self::isValidFqdn($fqdn) || $hostsPath === '') {
			return;
		}
		$short = (string) strtok($fqdn, '.');
		if ($short === '' || $short === $fqdn) {
			$short = $fqdn;
		}
		$line = "127.0.1.1\t{$fqdn}\t{$short}";

		$raw = is_readable($hostsPath) ? (string) file_get_contents($hostsPath) : '';
		$lines = preg_split("/\r\n|\n|\r/", $raw) ?: array();
		$out = array();
		$replaced = false;
		foreach ($lines as $l) {
			if (preg_match('/^\s*127\.0\.1\.1\b/', $l) === 1) {
				if (!$replaced) {
					$out[] = $line;
					$replaced = true;
				}
				continue;
			}
			$out[] = $l;
		}
		if (!$replaced) {
			// Insert after the last 127.0.0.1 line when present.
			$inserted = false;
			$tmp = array();
			foreach ($out as $l) {
				$tmp[] = $l;
				if (!$inserted && preg_match('/^\s*127\.0\.0\.1\b/', $l) === 1) {
					$tmp[] = $line;
					$inserted = true;
				}
			}
			$out = $inserted ? $tmp : array_merge($out, array($line));
		}
		$text = implode("\n", $out);
		if ($text !== '' && substr($text, -1) !== "\n") {
			$text .= "\n";
		}
		@file_put_contents($hostsPath, $text);
	}

	private static function path(string $abs): string
	{
		$root = self::$rootOverride;
		if (!is_string($root) || $root === '') {
			return $abs;
		}

		return rtrim($root, '/') . $abs;
	}

	private static function isRoot(): bool
	{
		if (is_bool(self::$assumeRoot)) {
			return self::$assumeRoot;
		}

		return function_exists('posix_geteuid') && posix_geteuid() === 0;
	}

	/**
	 * @param list<string> $argv
	 * @return array{code:int,out:string,err:string}
	 */
	private static function run(array $argv): array
	{
		if (is_callable(self::$runHook)) {
			$got = (self::$runHook)($argv);

			return is_array($got) ? array(
				'code' => (int) ($got['code'] ?? 1),
				'out' => (string) ($got['out'] ?? ''),
				'err' => (string) ($got['err'] ?? ''),
			) : array('code' => 1, 'out' => '', 'err' => 'hook failed');
		}

		$path = getenv('KLOXO_HOSTNAME_PATH');
		if (!is_string($path) || $path === '') {
			$path = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
		}
		if (isset($argv[0]) && is_string($argv[0]) && strpos($argv[0], '/') === false) {
			foreach (explode(':', $path) as $dir) {
				$cand = $dir . '/' . $argv[0];
				if ($dir !== '' && is_file($cand) && is_executable($cand)) {
					$argv[0] = $cand;
					break;
				}
			}
		}
		$pipes = array();
		$p = @proc_open(
			$argv,
			array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
			$pipes,
			null,
			array('PATH' => $path, 'LC_ALL' => 'C', 'SYSTEMD_PAGER' => '')
		);
		if (!is_resource($p)) {
			return array('code' => 127, 'out' => '', 'err' => 'proc_open failed');
		}
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		return array(
			'code' => proc_close($p),
			'out' => is_string($out) ? $out : '',
			'err' => is_string($err) ? $err : '',
		);
	}
}
