<?php
declare(strict_types=1);

/**
 * Turning a Switch Program mail program off (Exim, Postfix, Dovecot, SpamAssassin).
 *
 * turnOff(): stop, disable (and the unit's .socket companion), then READ the boot
 * state back; if the unit is still enabled, try the SysV links and, for the
 * programs that stay installed (Dovecot, SpamAssassin), raise an error that names
 * what systemd reports instead of leaving a green Sb icon without a word.
 *
 * Retiring the MTA that Switch Program replaces (Exim <-> Postfix).
 *
 * `systemctl disable` before the package is removed only removes the native
 * unit's boot link. `apt-get remove` keeps conffiles, so the SysV script
 * /etc/init.d/<mta> and its /etc/rc*.d links stay; once the native unit file is
 * gone systemd generates a unit from that script and reports it enabled, so the
 * old MTA kept a green Sb (boot) icon on the Services list. After the removal
 * the units are therefore daemon-reloaded and disabled again (that goes through
 * systemd-sysv-install now), and the rc links are removed if it is still on.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoMta
{
	/** Unit names a Switch Program MTA can be known by. */
	public static function unitsFor(string $mta): array
	{
		switch ($mta) {
			case 'exim':
				return array('exim4', 'exim');
			case 'postfix':
				return array('postfix');
		}

		return array();
	}

	/**
	 * Argument-array run with a fixed PATH (lxshell_return resets PATH, so a test
	 * double cannot be put in front). KLOXO_MTA_PATH is a test hook.
	 */
	private static function run(array $argv): int
	{
		$path = getenv('KLOXO_MTA_PATH');
		if (!is_string($path) || $path === '') {
			$path = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
		}
		// proc_open looks the program up in THIS process's PATH (os_set_path() changes it),
		// so resolve it against $path here.
		if (is_string($argv[0]) && strpos($argv[0], '/') === false) {
			foreach (explode(':', $path) as $dir) {
				if ($dir !== '' && is_file($dir . '/' . $argv[0]) && is_executable($dir . '/' . $argv[0])) {
					$argv[0] = $dir . '/' . $argv[0];
					break;
				}
			}
		}
		$p = @proc_open($argv, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
			$pipes, null, array('PATH' => $path, 'LC_ALL' => 'C', 'SYSTEMD_PAGER' => ''));
		if (!is_resource($p)) {
			return 127;
		}
		stream_get_contents($pipes[1]);
		stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		return proc_close($p);
	}

	/** Test hook: true/false overrides the root check. */
	public static $assumeRoot = null;

	private static function isRoot(): bool
	{
		if (is_bool(self::$assumeRoot)) {
			return self::$assumeRoot;
		}

		return !function_exists('posix_geteuid') || posix_geteuid() === 0;
	}

	/**
	 * Stop and disable $units and check that none is enabled at boot any more.
	 *
	 * @param bool $fatal throw (true) or only log (false) when a unit is still enabled
	 */
	public static function turnOff(array $units, bool $fatal = true): void
	{
		if (!self::isRoot()) {
			throw new lxException('Switch Program must run as root (kloxo-backend did not answer, so systemctl would run as the panel user and silently do nothing)', '', 'switchprogram');
		}
		$still = array();
		foreach ($units as $unit) {
			$unit = (string) $unit;
			if (!self::safe($unit)) {
				continue;
			}
			// the .socket companion (Dovecot ships one) would start the service again
			foreach (array($unit . '.socket', $unit) as $u) {
				self::run(array('systemctl', 'stop', $u));
				// disable refuses an alias name: use the real unit's Id when systemd reports one
				$real = (class_exists('Service__Linux') && Service__Linux::isSafeUnit($u)) ? Service__Linux::systemdShow($u, 'Id') : '';
				self::run(array('systemctl', 'disable', ($real !== '' && self::safe($real)) ? $real : $u));
			}
			if (class_exists('Service__Linux') && Service__Linux::bootStateOn($unit)) {
				self::run(array('update-rc.d', '-f', $unit, 'remove'));
				self::run(array('systemctl', 'daemon-reload'));
				if (Service__Linux::bootStateOn($unit)) {
					$still[] = $unit . ' (UnitFileState=' . Service__Linux::systemdShow($unit, 'UnitFileState')
						. ', SourcePath=' . Service__Linux::systemdShow($unit, 'SourcePath') . ')';
				}
			}
		}
		if ($still === array()) {
			return;
		}
		$msg = 'Could not turn off at boot: ' . implode(', ', $still);
		if (function_exists('log_log')) {
			log_log('switchprogram', $msg);
		}
		if ($fatal) {
			throw new lxException($msg, '', 'switchprogram');
		}
	}

	private static function safe(string $unit): bool
	{
		return preg_match('/^[A-Za-z0-9_.+-]+$/', $unit) === 1;
	}

	/** Before the package removal: stop it and drop its boot link. */
	public static function retire(array $units): void
	{
		foreach ($units as $unit) {
			$unit = (string) $unit;
			if (!self::safe($unit)) {
				continue;
			}
			self::run(array('systemctl', 'stop', $unit));
			self::run(array('systemctl', 'disable', $unit));
		}
	}

	/**
	 * After the package removal: whatever systemd still lists for the old MTA
	 * (leftover SysV script) must not start at boot.
	 */
	public static function settle(array $units): void
	{
		self::run(array('systemctl', 'daemon-reload'));
		foreach ($units as $unit) {
			$unit = (string) $unit;
			if (!self::safe($unit)) {
				continue;
			}
			self::run(array('systemctl', 'disable', $unit));
			self::run(array('systemctl', 'reset-failed', $unit));
			if (class_exists('Service__Linux') && Service__Linux::isSafeUnit($unit)
				&& Service__Linux::bootStateOn($unit)) {
				// still enabled: the SysV rc links are all that is left
				self::run(array('update-rc.d', '-f', $unit, 'remove'));
				self::run(array('systemctl', 'daemon-reload'));
			}
		}
	}
}
