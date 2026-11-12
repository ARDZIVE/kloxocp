<?php
/**
 * Ensure a panel client has a Unix account before PHP-FPM / docroot work.
 * Add Client defers full sync; useradd must still happen before a domain
 * pool is validated (php-fpm -t: "cannot get uid for user").
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

declare(strict_types=1);

final class KloxoClientUnix
{
	/**
	 * Root-side: create the Unix user for client $nname if missing.
	 * Invoked via rl_exec_get from the panel (lxlabs) through kloxo-backend.
	 *
	 * @return array{ok:bool,euid:int,user?:string,created?:bool,error?:string}
	 */
	public static function ensure(string $nname): array
	{
		$euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : -1;
		if ($euid !== 0) {
			return ['ok' => false, 'euid' => $euid, 'error' => 'not root'];
		}

		$nname = trim($nname);
		if ($nname === '' || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $nname) !== 1) {
			return ['ok' => false, 'euid' => $euid, 'error' => 'invalid client name'];
		}

		if (!class_exists('Client', false)) {
			return ['ok' => false, 'euid' => $euid, 'error' => 'Client class missing'];
		}

		try {
			$client = new Client(null, null, $nname);
			$client->get();
		} catch (Throwable $e) {
			return ['ok' => false, 'euid' => $euid, 'error' => 'client load: ' . $e->getMessage()];
		}

		if (!isset($client->nname) || (string) $client->nname !== $nname) {
			return ['ok' => false, 'euid' => $euid, 'error' => 'client not found'];
		}

		$want = self::expectedUnixName($client);
		if ($want !== '' && function_exists('posix_getpwnam') && @posix_getpwnam($want)) {
			if (!isset($client->username) || (string) $client->username !== $want) {
				$client->username = $want;
				$client->dbaction = 'update';
				$client->metadbaction = 'writeonly';
				try {
					$client->write();
				} catch (Throwable $e) {
					// user exists; DB username update is best-effort
				}
			}

			return ['ok' => true, 'euid' => $euid, 'user' => $want, 'created' => false];
		}

		if (!class_exists('client__sync', false)) {
			$syncLib = dirname(__DIR__) . '/client/client__synclib.php';
			if (is_file($syncLib)) {
				include_once $syncLib;
			}
		}
		if (!class_exists('client__sync', false)) {
			return ['ok' => false, 'euid' => $euid, 'error' => 'client__sync missing'];
		}

		try {
			if (method_exists($client, 'createSyncClass')) {
				$client->createSyncClass();
			}
			$driver = isset($client->driverApp) && is_object($client->driverApp)
				? $client->driverApp
				: new client__sync(null, null, $nname);
			$driver->main = $client;
			if (!method_exists($driver, 'createUser')) {
				return ['ok' => false, 'euid' => $euid, 'error' => 'createUser missing'];
			}
			$ret = $driver->createUser();
			$user = is_array($ret) && isset($ret['__syncv_username'])
				? (string) $ret['__syncv_username']
				: (isset($client->username) ? (string) $client->username : $want);
			if ($user === '' || !function_exists('posix_getpwnam') || !@posix_getpwnam($user)) {
				return ['ok' => false, 'euid' => $euid, 'error' => 'useradd did not create ' . ($user !== '' ? $user : $want)];
			}
			$client->username = $user;
			$client->dbaction = 'update';
			$client->metadbaction = 'writeonly';
			try {
				$client->write();
			} catch (Throwable $e) {
				if (function_exists('log_log')) {
					log_log('error', 'client unix username write: ' . $e->getMessage());
				}
			}

			return ['ok' => true, 'euid' => $euid, 'user' => $user, 'created' => true];
		} catch (Throwable $e) {
			return ['ok' => false, 'euid' => $euid, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Root-side: ensure pool user $unix exists (lookup client by nname/username).
	 *
	 * @return array{ok:bool,euid:int,user?:string,created?:bool,error?:string}
	 */
	public static function ensureUnixName(string $unix): array
	{
		$euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : -1;
		if ($euid !== 0) {
			return ['ok' => false, 'euid' => $euid, 'error' => 'not root'];
		}
		$unix = trim($unix);
		if ($unix === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,31}$/', $unix) !== 1) {
			return ['ok' => false, 'euid' => $euid, 'error' => 'invalid unix name'];
		}
		if (function_exists('posix_getpwnam') && @posix_getpwnam($unix)) {
			return ['ok' => true, 'euid' => $euid, 'user' => $unix, 'created' => false];
		}

		// Prefer client.nname = unix (normal Add Client path).
		$got = self::ensure($unix);
		if (!empty($got['ok'])) {
			return $got;
		}

		// Fallback: find client whose username column matches.
		if (!class_exists('Sqlite', false)) {
			return $got;
		}
		try {
			$db = new Sqlite(null, 'client');
			$rows = $db->getRowsWhere("username = '" . str_replace("'", "''", $unix) . "'", array('nname'));
			if (is_array($rows) && isset($rows[0]['nname'])) {
				return self::ensure((string) $rows[0]['nname']);
			}
		} catch (Throwable $e) {
			return ['ok' => false, 'euid' => $euid, 'error' => $e->getMessage()];
		}

		return $got;
	}

	/** @param object $client */
	private static function expectedUnixName(object $client): string
	{
		$name = '';
		if (method_exists($client, 'getPathFromName')) {
			$got = $client->getPathFromName();
			if (is_string($got)) {
				$name = $got;
			}
		}
		if ($name === '' && isset($client->nname)) {
			$name = (string) $client->nname;
		}
		$name = str_replace(' ', '', $name);
		if ($name !== '' && is_numeric($name[0])) {
			$name = 'a' . $name;
		}

		return $name;
	}
}
