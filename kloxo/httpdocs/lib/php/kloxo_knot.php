<?php
declare(strict_types=1);

/**
 * Knot DNS (authoritative) for Kloxo CP: zone-file and knot.conf renderers plus a
 * safe installer (render, validate with Knot's own checkers, install atomically,
 * reload, roll back on failure).
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3. See LICENSE.txt.
 *
 * - Zones are plain RFC 1035 master files in /var/lib/knot/zones/<domain>.zone,
 *   checked with `kzonecheck` before they replace the live file.
 * - /etc/knot/knot.conf is generated; it is checked with `knotc -c <file> conf-check`.
 * - Knot listens on explicit addresses, never on the 0.0.0.0 wildcard: on Ubuntu
 *   systemd-resolved owns 127.0.0.53/127.0.0.54 port 53 and a wildcard bind fails
 *   with "Address already in use".
 * - No shell strings: every command is an argument array.
 *
 * Every path can be moved below a prefix (`base` option) so the tests run in a
 * temporary directory.
 */

final class KloxoKnotException extends RuntimeException
{
}

final class KloxoKnot
{
	public const CONF = '/etc/knot/knot.conf';
	public const ZONE_DIR = '/var/lib/knot/zones';
	public const UNIT = 'knot';
	/** Ubuntu 24.04+: kzonecheck lives in knot-dnssecutils, not knot-dnsutils. */
	public const PACKAGES = ['knot', 'knot-dnsutils', 'knot-dnssecutils'];

	/** Set when a zone file changed and the server has not been reloaded yet. */
	public static bool $pendingReload = false;

	// ------------------------------------------------------------------ names

	/** A registrable zone name: lower-case LDH labels, at least one dot not required. */
	public static function isDomain(string $d): bool
	{
		if ($d === '' || strlen($d) > 253 || $d !== strtolower($d)) {
			return false;
		}
		foreach (explode('.', $d) as $l) {
			if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $l) !== 1) {
				return false;
			}
		}

		return true;
	}

	/** One owner label: LDH plus underscore (SRV/TXT service names) and a lone `*`. */
	public static function isLabel(string $l): bool
	{
		return $l === '*' || preg_match('/^[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?$/', $l) === 1;
	}

	/** A host name with no trailing dot (target of NS/MX/CNAME/SRV). */
	public static function isHost(string $h): bool
	{
		if ($h === '' || strlen($h) > 253) {
			return false;
		}
		foreach (explode('.', $h) as $l) {
			if (preg_match('/^[a-z0-9_]([a-z0-9_-]{0,61}[a-z0-9_])?$/', $l) !== 1) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Owner name as an absolute name with a trailing dot. '', `__base__` and the
	 * domain itself are the apex; `__base__` inside a name is replaced; anything
	 * else is relative to the domain.
	 */
	public static function owner(string $host, string $domain): ?string
	{
		$h = strtolower(trim($host, " \t."));
		if ($h === '' || $h === '__base__' || $h === $domain) {
			return $domain . '.';
		}
		$h = str_replace('__base__', $domain, $h);
		if ($h === $domain) {
			return $domain . '.';
		}
		$abs = str_ends_with($h, '.' . $domain) ? $h : $h . '.' . $domain;
		foreach (explode('.', substr($abs, 0, -strlen($domain) - 1)) as $l) {
			if (!self::isLabel($l)) {
				return null;
			}
		}

		return $abs . '.';
	}

	/**
	 * Target of NS/MX/CNAME/SRV as an absolute name. A trailing dot, the domain, a
	 * name below it, or any name containing a dot is taken as absolute; a single
	 * label is a name inside the zone.
	 */
	public static function target(string $value, string $domain): ?string
	{
		$v = strtolower(trim($value));
		$dot = str_ends_with($v, '.');
		$v = trim($v, '.');
		if ($v === '' || $v === '__base__') {
			return $domain . '.';
		}
		$v = str_replace('__base__', $domain, $v);
		if (!$dot && !str_contains($v, '.')) {
			$v .= '.' . $domain;
		}

		return self::isHost($v) ? $v . '.' : null;
	}

	// ------------------------------------------------------------------ zone

	/** SOA rname from an e-mail address: admin@example.com -> admin.example.com. */
	public static function rname(string $email, string $domain): string
	{
		$e = strtolower(trim($email));
		if ($e === '' || preg_match('/[\s"\'\\\\;()]/', $e) === 1) {
			$e = 'admin@' . $domain;
		}
		$at = strrpos($e, '@');
		if ($at === false) {
			$local = 'admin';
			$host = trim($e, '.');
		} else {
			$local = substr($e, 0, $at);
			$host = trim(substr($e, $at + 1), '.');
		}
		$local = str_replace('.', '\\.', $local);
		if ($local === '' || !self::isHost(str_replace('\\.', '', $local)) || !self::isHost($host)) {
			return 'admin.' . $domain . '.';
		}

		return $local . '.' . $host . '.';
	}

	/** TXT-style character strings: quoted, escaped, split in chunks of 255 bytes. */
	public static function quote(string $s): string
	{
		$s = str_replace(["\r", "\n"], ' ', $s);
		$parts = [];
		foreach (str_split($s, 255) ?: [''] as $chunk) {
			$parts[] = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $chunk) . '"';
		}

		return implode(' ', $parts);
	}

	private static function intIn(mixed $v, int $min, int $max, int $default): ?int
	{
		if ($v === null || $v === '' || $v === false) {
			return $default;
		}
		if (!is_scalar($v) || preg_match('/^\d{1,10}$/', (string)$v) !== 1) {
			return null;
		}
		$n = (int)$v;

		return ($n >= $min && $n <= $max) ? $n : null;
	}

	/**
	 * Render a zone file.
	 *
	 * @param array<string,mixed> $z domain, ttl, soa_ns, hostmaster, serial, refresh, retry,
	 *                               expire, minimum, min_serial, records (list of arrays with
	 *                               ttype, hostname, param, priority, port, weight, flag, tag)
	 * @return array{text:string,warnings:list<string>,serial:int}
	 */
	public static function renderZone(array $z): array
	{
		$domain = strtolower(trim((string)($z['domain'] ?? '')));
		if (!self::isDomain($domain)) {
			throw new KloxoKnotException('invalid zone name');
		}
		$warn = [];
		$ttl = self::intIn($z['ttl'] ?? null, 1, 2147483647, 3600) ?? 3600;
		$refresh = self::intIn($z['refresh'] ?? null, 1, 2147483647, 3600) ?? 3600;
		$retry = self::intIn($z['retry'] ?? null, 1, 2147483647, 1800) ?? 1800;
		$expire = self::intIn($z['expire'] ?? null, 1, 2147483647, 604800) ?? 604800;
		$minimum = self::intIn($z['minimum'] ?? null, 1, 2147483647, 3600) ?? 3600;

		$serial = self::intIn($z['serial'] ?? null, 1, 4294967295, 0) ?? 0;
		if ($serial === 0) {
			$serial = (int)gmdate('Ymd') * 100 + 1;
		}
		$min = self::intIn($z['min_serial'] ?? null, 0, 4294967295, 0) ?? 0;
		if ($serial < $min) {
			$serial = $min;
		}
		if ($serial > 4294967295) {
			$serial = 1;
		}

		$records = [];
		foreach ((array)($z['records'] ?? []) as $r) {
			if (is_object($r)) {
				$r = get_object_vars($r);
			}
			if (is_array($r)) {
				$records[] = $r;
			}
		}

		// NS first: SOA mname and the mandatory apex NS come from it.
		$nsTargets = [];
		foreach ($records as $r) {
			if (strtolower((string)($r['ttype'] ?? '')) !== 'ns') {
				continue;
			}
			$t = self::target((string)($r['param'] ?? ''), $domain);
			if ($t !== null) {
				$nsTargets[] = $t;
			}
		}
		$soaNs = self::target((string)($z['soa_ns'] ?? ''), $domain);
		if (trim((string)($z['soa_ns'] ?? '')) === '' || $soaNs === null) {
			$soaNs = $nsTargets[0] ?? ('ns1.' . $domain . '.');
		}

		$lines = [];
		$typesAt = [];
		$cnameAt = [];
		$add = static function (string $owner, string $type, string $rdata) use (&$lines, &$typesAt): void {
			$lines[] = sprintf('%s IN %s %s', $owner, $type, $rdata);
			$typesAt[$owner][$type] = true;
		};

		$hasApexNs = false;
		foreach ($records as $i => $r) {
			$type = strtolower((string)($r['ttype'] ?? ''));
			$host = (string)($r['hostname'] ?? '');
			$param = trim((string)($r['param'] ?? ''));
			$label = $type . ' #' . ($i + 1);

			switch ($type) {
				case 'ns':
					$value = strtolower(trim($param, ". \t"));
					$own = ($host !== '' && strtolower(trim($host, '. ')) === $value)
						? $domain . '.' : self::owner($host, $domain);
					$t = self::target($param, $domain);
					if ($own === null || $t === null) {
						$warn[] = "skipped $label: invalid name";
						break;
					}
					if ($own === $domain . '.') {
						$hasApexNs = true;
					}
					$add($own, 'NS', $t);
					break;

				case 'mx':
					$own = self::owner($host, $domain);
					$t = self::target($param, $domain);
					$pri = self::intIn($r['priority'] ?? null, 0, 65535, 10);
					if ($own === null || $t === null || $pri === null) {
						$warn[] = "skipped $label: invalid value";
						break;
					}
					$add($own, 'MX', $pri . ' ' . $t);
					break;

				case 'a':
					$own = self::owner($host, $domain);
					if ($own === null || filter_var($param, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
						$warn[] = "skipped $label: invalid IPv4 address";
						break;
					}
					$add($own, 'A', $param);
					break;

				case 'aaaa':
					$own = self::owner($host, $domain);
					if ($own === null || filter_var($param, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
						$warn[] = "skipped $label: invalid IPv6 address";
						break;
					}
					$add($own, 'AAAA', strtolower($param));
					break;

				case 'cn':
				case 'cname':
				case 'fcname':
					$own = self::owner($host, $domain);
					$t = $type === 'fcname'
						? self::target(str_contains($param, '.') ? rtrim($param, '.') . '.' : $param, $domain)
						: self::target($param, $domain);
					if ($own === null || $t === null || $own === $domain . '.') {
						$warn[] = "skipped $label: invalid CNAME (the apex cannot be a CNAME)";
						break;
					}
					$cnameAt[$own][] = $t;
					break;

				case 'txt':
					if ($param === '') {
						break;
					}
					$own = self::owner($host, $domain);
					if ($own === null || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $param) === 1) {
						$warn[] = "skipped $label: invalid TXT";
						break;
					}
					$param = str_replace(['<%domain%>', '__base__'], $domain, $param);
					$add($own, 'TXT', self::quote($param));
					break;

				case 'caa':
					$own = self::owner($host, $domain);
					$flag = self::intIn($r['flag'] ?? null, 0, 255, 0);
					$tag = strtolower(trim((string)($r['tag'] ?? '')));
					if ($param === '' || $own === null || $flag === null
						|| !in_array($tag, ['issue', 'issuewild', 'iodef'], true)) {
						$warn[] = "skipped $label: invalid CAA";
						break;
					}
					$add($own, 'CAA', $flag . ' ' . $tag . ' ' . self::quote($param));
					break;

				case 'srv':
					$own = self::owner($host, $domain);
					$t = self::target($param, $domain);
					$pri = self::intIn($r['priority'] ?? null, 0, 65535, 0);
					$wt = self::intIn($r['weight'] ?? null, 0, 65535, 0);
					$port = self::intIn($r['port'] ?? null, 0, 65535, -1);
					if ($param === '' || $own === null || $t === null || $pri === null || $wt === null
						|| $port === null || $port < 0) {
						$warn[] = "skipped $label: invalid SRV";
						break;
					}
					$add($own, 'SRV', "$pri $wt $port $t");
					break;

				default:
					$warn[] = "skipped $label: unsupported record type '$type'";
			}
		}

		// A CNAME may not share its name with any other record, nor repeat.
		foreach ($cnameAt as $own => $targets) {
			if (isset($typesAt[$own])) {
				$warn[] = "skipped CNAME $own: other records exist at that name";
				continue;
			}
			$add($own, 'CNAME', $targets[0]);
			if (count($targets) > 1) {
				$warn[] = "skipped extra CNAME at $own";
			}
		}

		if (!$hasApexNs) {
			$lines[] = $domain . '. IN NS ' . $soaNs;
			$warn[] = 'no apex NS record: added ' . $soaNs;
		}

		$out = [];
		$out[] = '; Generated by Kloxo CP. Do not edit: changes are overwritten.';
		$out[] = '$ORIGIN ' . $domain . '.';
		$out[] = '$TTL ' . $ttl;
		$out[] = sprintf(
			'%s. IN SOA %s %s ( %d %d %d %d %d )',
			$domain,
			$soaNs,
			self::rname((string)($z['hostmaster'] ?? ''), $domain),
			$serial,
			$refresh,
			$retry,
			$expire,
			$minimum
		);
		sort($lines, SORT_STRING);
		foreach ($lines as $l) {
			$out[] = $l;
		}

		return ['text' => implode("\n", $out) . "\n", 'warnings' => $warn, 'serial' => $serial];
	}

	/** Zone text without the serial, to tell a real change from a re-render. */
	public static function withoutSerial(string $text): string
	{
		return (string)preg_replace('/(\bSOA\s+\S+\s+\S+\s+\(\s*)\d+/', '$1#', $text);
	}

	public static function serialOf(string $text): int
	{
		return preg_match('/\bSOA\s+\S+\s+\S+\s+\(\s*(\d+)/', $text, $m) === 1 ? (int)$m[1] : 0;
	}

	// ------------------------------------------------------------------ conf

	/**
	 * Render knot.conf.
	 *
	 * @param array<string,mixed> $o listen (list of IPs), masters (list of zone names with a file),
	 *                               slaves (list of "zone:master-ip"), transfer (list of IPs of other
	 *                               name servers: allowed to transfer, notified), zone_dir
	 */
	public static function renderConf(array $o): string
	{
		$zoneDir = (string)($o['zone_dir'] ?? self::ZONE_DIR);
		if ($zoneDir === '' || $zoneDir[0] !== '/' || preg_match('/[\s"\\\\]/', $zoneDir) === 1) {
			throw new KloxoKnotException('invalid zone directory');
		}

		$listen = [];
		foreach ((array)($o['listen'] ?? []) as $ip) {
			$ip = strtolower(trim((string)$ip));
			if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
				$listen[$ip] = true;
			}
		}
		if ($listen === []) {
			$listen['127.0.0.1'] = true;
		}

		$ips = static function (mixed $list): array {
			$out = [];
			foreach ((array)$list as $ip) {
				$ip = strtolower(trim((string)$ip));
				if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
					$out[$ip] = true;
				}
			}

			return array_keys($out);
		};
		// Do not notify or accept transfers from ourselves.
		$transfer = array_values(array_diff($ips($o['transfer'] ?? []), array_keys($listen), ['127.0.0.1', '::1']));

		$masters = [];
		foreach ((array)($o['masters'] ?? []) as $d) {
			$d = strtolower(trim((string)$d));
			if (self::isDomain($d)) {
				$masters[$d] = true;
			}
		}
		ksort($masters);

		$slaves = [];
		foreach ((array)($o['slaves'] ?? []) as $s) {
			$p = explode(':', (string)$s, 2);
			$d = strtolower(trim($p[0]));
			$ip = strtolower(trim($p[1] ?? ''));
			if (self::isDomain($d) && !isset($masters[$d]) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
				$slaves[$d] = $ip;
			}
		}
		ksort($slaves);

		$c = [];
		$c[] = '# Generated by Kloxo CP. Do not edit: changes are overwritten.';
		$c[] = 'server:';
		$c[] = '    rundir: "/run/knot"';
		$c[] = '    user: knot:knot';
		$c[] = '    listen: [ ' . implode(', ', array_map(static fn (string $ip): string => $ip . '@53', array_keys($listen))) . ' ]';
		$c[] = '';
		$c[] = 'log:';
		$c[] = '  - target: syslog';
		$c[] = '    any: info';
		$c[] = '';
		$c[] = 'database:';
		$c[] = '    storage: "/var/lib/knot"';
		$c[] = '';

		$acl = [];
		$remote = [];
		if ($transfer !== []) {
			$acl[] = ['kloxo_transfer', $transfer, 'transfer'];
			foreach ($transfer as $i => $ip) {
				$remote[] = ['peer_' . ($i + 1), $ip];
			}
		}
		$masterIds = [];
		$n = 0;
		foreach (array_unique(array_values($slaves)) as $ip) {
			$n++;
			$masterIds[$ip] = 'master_' . $n;
			$remote[] = ['master_' . $n, $ip];
			$acl[] = ['notify_master_' . $n, [$ip], 'notify'];
		}

		if ($acl !== []) {
			$c[] = 'acl:';
			foreach ($acl as [$id, $addr, $action]) {
				$c[] = '  - id: ' . $id;
				$c[] = '    address: [ ' . implode(', ', $addr) . ' ]';
				$c[] = '    action: ' . $action;
			}
			$c[] = '';
		}
		if ($remote !== []) {
			$c[] = 'remote:';
			foreach ($remote as [$id, $addr]) {
				$c[] = '  - id: ' . $id;
				$c[] = '    address: ' . $addr . '@53';
			}
			$c[] = '';
		}

		$c[] = 'template:';
		$c[] = '  - id: default';
		$c[] = '    storage: "' . $zoneDir . '"';
		$c[] = '    file: "%s.zone"';
		$c[] = '    semantic-checks: on';
		$c[] = '';

		if ($masters !== [] || $slaves !== []) {
			$c[] = 'zone:';
			$peerIds = [];
			foreach ($remote as [$id]) {
				if (str_starts_with($id, 'peer_')) {
					$peerIds[] = $id;
				}
			}
			foreach (array_keys($masters) as $d) {
				$c[] = '  - domain: ' . $d;
				if ($transfer !== []) {
					$c[] = '    acl: [ kloxo_transfer ]';
					$c[] = '    notify: [ ' . implode(', ', $peerIds) . ' ]';
				}
			}
			foreach ($slaves as $d => $ip) {
				$id = $masterIds[$ip];
				$c[] = '  - domain: ' . $d;
				$c[] = '    master: ' . $id;
				$c[] = '    acl: [ notify_' . $id . ' ]';
			}
		}

		return implode("\n", $c) . "\n";
	}

	// ------------------------------------------------------------------ system

	/** @return array{rc:int,out:string,err:string} */
	public static function run(array $cmd, int $timeout = 30): array
	{
		$p = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($p)) {
			return ['rc' => 127, 'out' => '', 'err' => 'cannot execute ' . (string)($cmd[0] ?? '')];
		}
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$out = '';
		$err = '';
		$deadline = microtime(true) + $timeout;
		while (true) {
			$out .= (string)stream_get_contents($pipes[1]);
			$err .= (string)stream_get_contents($pipes[2]);
			$st = proc_get_status($p);
			if (!$st['running']) {
				break;
			}
			if (microtime(true) > $deadline) {
				proc_terminate($p, 9);
				$err .= ' timed out';
				break;
			}
			usleep(20000);
		}
		$out .= (string)stream_get_contents($pipes[1]);
		$err .= (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($p);
		if (isset($st) && !$st['running'] && $st['exitcode'] >= 0) {
			$code = (int)$st['exitcode'];
		}

		return ['rc' => $code, 'out' => $out, 'err' => $err];
	}

	/** Absolute path of a Knot tool, '' when it is not installed. */
	public static function bin(string $name, array $opt = []): string
	{
		$dirs = isset($opt['bindirs']) && is_array($opt['bindirs'])
			? $opt['bindirs'] : ['/usr/sbin', '/usr/bin', '/sbin', '/bin'];
		foreach ($dirs as $d) {
			$f = rtrim((string)$d, '/') . '/' . $name;
			if (is_file($f) && is_executable($f)) {
				return $f;
			}
		}

		return '';
	}

	public static function isActive(array $opt = []): bool
	{
		$sc = self::bin('systemctl', $opt);
		if ($sc === '') {
			return false;
		}

		return self::run([$sc, 'is-active', '--quiet', self::UNIT], 10)['rc'] === 0;
	}

	private static function path(string $p, array $opt): string
	{
		return rtrim((string)($opt['base'] ?? ''), '/') . $p;
	}

	/** @return list<string> */
	public static function serverAddresses(): array
	{
		$out = ['127.0.0.1'];
		$ip = self::bin('ip');
		if ($ip === '') {
			return $out;
		}
		foreach (['-4', '-6'] as $fam) {
			$r = self::run([$ip, $fam, '-o', 'addr', 'show', 'scope', 'global'], 10);
			if ($r['rc'] !== 0) {
				continue;
			}
			foreach (preg_split('/\R/', $r['out']) ?: [] as $l) {
				if (preg_match('/\binet6?\s+([0-9a-fA-F:.]+)\//', $l, $m) === 1
					&& filter_var($m[1], FILTER_VALIDATE_IP) !== false) {
					$out[] = strtolower($m[1]);
				}
			}
		}

		return array_values(array_unique($out));
	}

	private static function atomicWrite(string $file, string $text, int $mode, ?string $group = null): void
	{
		$dir = dirname($file);
		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			throw new KloxoKnotException("cannot create $dir");
		}
		$tmp = $file . '.new.' . getmypid();
		if (file_put_contents($tmp, $text) === false) {
			throw new KloxoKnotException("cannot write $tmp");
		}
		@chmod($tmp, $mode);
		if ($group !== null && function_exists('posix_getgrnam') && posix_getgrnam($group) !== false) {
			@chgrp($tmp, $group);
		}
		if (!@rename($tmp, $file)) {
			@unlink($tmp);
			throw new KloxoKnotException("cannot install $file");
		}
	}

	/** Zone directory: knot must be able to read it and, for slave zones, write to it. */
	public static function ensureZoneDir(string $dir): void
	{
		if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
			throw new KloxoKnotException("cannot create $dir");
		}
		if (function_exists('posix_getpwnam') && posix_getpwnam('knot') !== false) {
			@chown($dir, 'knot');
			@chgrp($dir, 'knot');
			@chmod($dir, 0750);
		}
	}

	/**
	 * Check a zone text with kzonecheck, then install it atomically. The serial is
	 * raised above the one in the live file; an unchanged zone is left alone.
	 * The server is not reloaded here (see applyConf / reload).
	 *
	 * @param array<string,mixed> $zone as for renderZone
	 * @param array<string,mixed> $opt  base, bindirs
	 * @return array{changed:bool,warnings:list<string>}
	 */
	public static function installZone(array $zone, array $opt = []): array
	{
		$domain = strtolower(trim((string)($zone['domain'] ?? '')));
		if (!self::isDomain($domain)) {
			throw new KloxoKnotException('invalid zone name');
		}
		$dir = self::path(self::ZONE_DIR, $opt);
		$file = $dir . '/' . $domain . '.zone';

		$old = is_file($file) ? (string)file_get_contents($file) : '';
		if ($old !== '') {
			$zone['min_serial'] = self::serialOf($old) + 1;
		}
		$r = self::renderZone($zone);
		if ($old !== '' && self::withoutSerial($old) === self::withoutSerial($r['text'])) {
			return ['changed' => false, 'warnings' => $r['warnings']];
		}

		$chk = self::bin('kzonecheck', $opt);
		if ($chk === '') {
			throw new KloxoKnotException('kzonecheck not found (apt-get install knot-dnssecutils)');
		}
		self::ensureZoneDir($dir);
		$tmp = $dir . '/.' . $domain . '.check.' . getmypid();
		if (file_put_contents($tmp, $r['text']) === false) {
			throw new KloxoKnotException("cannot write $tmp");
		}
		$res = self::run([$chk, '-o', $domain, $tmp], 30);
		@unlink($tmp);
		if ($res['rc'] !== 0) {
			throw new KloxoKnotException("zone $domain failed kzonecheck: " . trim($res['err'] . ' ' . $res['out']));
		}

		self::atomicWrite($file, $r['text'], 0640, 'knot');
		self::$pendingReload = true;

		return ['changed' => true, 'warnings' => $r['warnings']];
	}

	/**
	 * Render knot.conf for the given zones, check it with `knotc conf-check`, install
	 * it atomically, delete zone files of zones that are gone and reload Knot when it
	 * is running. On a failed reload the previous knot.conf is put back.
	 *
	 * @param array<string,mixed> $o as for renderConf, plus optional base, bindirs
	 * @return array{changed:bool,reloaded:bool,masters:list<string>}
	 */
	public static function applyConf(array $o, array $opt = []): array
	{
		$dir = self::path(self::ZONE_DIR, $opt);
		$conf = self::path(self::CONF, $opt);

		// Only zones that have a file can be served.
		$have = [];
		foreach ((array)($o['masters'] ?? []) as $d) {
			$d = strtolower(trim((string)$d));
			if (self::isDomain($d) && is_file($dir . '/' . $d . '.zone')) {
				$have[$d] = true;
			}
		}
		$o['masters'] = array_keys($have);
		$o['zone_dir'] = self::ZONE_DIR;
		$text = self::renderConf($o);

		$kc = self::bin('knotc', $opt);
		if ($kc === '') {
			throw new KloxoKnotException('knotc not found (apt-get install knot)');
		}
		$tmp = $conf . '.check.' . getmypid();
		self::atomicWrite($tmp, str_replace(self::ZONE_DIR, $dir, $text), 0640);
		$res = self::run([$kc, '-c', $tmp, 'conf-check'], 30);
		@unlink($tmp);
		if ($res['rc'] !== 0) {
			throw new KloxoKnotException('knot.conf failed conf-check: ' . trim($res['err'] . ' ' . $res['out']));
		}

		$old = is_file($conf) ? (string)file_get_contents($conf) : '';
		$changed = ($old !== $text);

		// Zone files nobody lists any more (deleted domains).
		$keep = $have;
		foreach ((array)($o['slaves'] ?? []) as $s) {
			$keep[strtolower(trim(explode(':', (string)$s, 2)[0]))] = true;
		}
		$gone = false;
		foreach (glob($dir . '/*.zone') ?: [] as $f) {
			if (!isset($keep[basename($f, '.zone')])) {
				@unlink($f);
				$gone = true;
			}
		}

		if ($changed) {
			if ($old !== '') {
				@copy($conf, $conf . '.kloxo-bak');
			}
			self::atomicWrite($conf, $text, 0640, 'knot');
		}

		$reloaded = false;
		if (($changed || $gone || self::$pendingReload) && self::isActive($opt)) {
			$rl = self::run([$kc, 'reload'], 60);
			if ($rl['rc'] !== 0) {
				if ($changed) {
					if ($old !== '') {
						@rename($conf . '.kloxo-bak', $conf);
					} else {
						@unlink($conf);
					}
					self::run([$kc, 'reload'], 60);
				}
				throw new KloxoKnotException('knotc reload failed, previous knot.conf restored: '
					. trim($rl['err'] . ' ' . $rl['out']));
			}
			$reloaded = true;
		}
		self::$pendingReload = false;

		return ['changed' => $changed, 'reloaded' => $reloaded, 'masters' => array_keys($have)];
	}
}
