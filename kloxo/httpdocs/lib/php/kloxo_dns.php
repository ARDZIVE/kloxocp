<?php
declare(strict_types=1);

/**
 * DNS server helpers: BIND vs Knot DNS units. BIND stays the default running
 * server; Knot is installed and prepared (install-knot) and started only when
 * Switch Program selects it.
 */

function kloxo_dns_unit(string $driver): string
{
	if ($driver === 'bind') {
		foreach (array(
			'/lib/systemd/system/bind9.service',
			'/usr/lib/systemd/system/bind9.service',
			'/etc/init.d/bind9',
		) as $u) {
			if (is_file($u)) {
				return 'bind9';
			}
		}

		return 'named';
	}
	if ($driver === 'knot') {
		return 'knot';
	}

	return $driver;
}

/** @return list<string> */
function kloxo_dns_other_units(string $active): array
{
	$all = array('bind', 'knot');
	$out = array();
	foreach ($all as $d) {
		$u = kloxo_dns_unit($d);
		if ($u !== $active) {
			$out[] = $u;
		}
	}

	return array_values(array_unique($out));
}

/**
 * The real unit behind an alias. On Debian/Ubuntu bind9.service is an alias of
 * named.service, and systemd refuses `enable`/`disable` on an alias ("Refusing to
 * operate on alias name or linked unit file"), so `systemctl disable --now bind9`
 * did nothing and BIND stayed enabled and running after DNS was set to none.
 * `systemctl show -p Id` names the real unit. Falls back to $unit.
 */
function kloxo_dns_canonical_unit(string $unit): string
{
	[$rc, $out] = kloxo_dns_exec(array('systemctl', 'show', '-p', 'Id', '--value', $unit));
	$id = trim($out);
	if ($rc === 0 && $id !== '' && preg_match('/^[A-Za-z0-9_.@-]+$/', $id) === 1) {
		return $id;
	}

	return $unit;
}

/** @return array{0:int,1:string} exit code and stdout */
function kloxo_dns_exec(array $cmd): array
{
	// proc_open looks the program up in this process's PATH, which os_set_path() rewrites;
	// KLOXO_DNS_PATH is a test hook, the default is the standard system path.
	$path = getenv('KLOXO_DNS_PATH');
	if (!is_string($path) || $path === '') {
		$path = getenv('PATH');
		$path = (is_string($path) && $path !== '') ? $path : '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin';
	}
	if (strpos($cmd[0], '/') === false) {
		foreach (explode(':', $path) as $dir) {
			if ($dir !== '' && is_file($dir . '/' . $cmd[0]) && is_executable($dir . '/' . $cmd[0])) {
				$cmd[0] = $dir . '/' . $cmd[0];
				break;
			}
		}
	}
	$p = @proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	if (!is_resource($p)) {
		return array(127, '');
	}
	fclose($pipes[0]);
	$out = (string) stream_get_contents($pipes[1]);
	stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	return array(proc_close($p), $out);
}

function kloxo_dns_systemctl(string $action, string $unit): int
{
	if (!preg_match('/^[A-Za-z0-9_.@-]+$/', $unit)) {
		return 1;
	}
	if (!in_array($action, array('enable', 'disable', 'start', 'stop', 'restart'), true)) {
		return 1;
	}
	// enable/disable must name the real unit, never an alias (bind9 -> named)
	$target = in_array($action, array('enable', 'disable'), true) ? kloxo_dns_canonical_unit($unit) : $unit;
	$cmd = array('systemctl', $action, $target);
	if ($action === 'disable') {
		// stop first: it works through an alias too, and survives a failing disable
		kloxo_dns_exec(array('systemctl', 'stop', $unit));
		$cmd = array('systemctl', 'disable', '--now', $target);
	} elseif ($action === 'enable') {
		$cmd = array('systemctl', 'enable', '--now', $target);
	}
	[$rc] = kloxo_dns_exec($cmd);

	return $rc;
}

function kloxo_dns_switch(string $driver): void
{
	if ($driver === 'none' || $driver === '') {
		foreach (array('bind', 'knot') as $d) {
			kloxo_dns_systemctl('disable', kloxo_dns_unit($d));
		}

		return;
	}
	$active = kloxo_dns_unit($driver);
	foreach (kloxo_dns_other_units($active) as $u) {
		kloxo_dns_systemctl('disable', $u);
	}
	kloxo_dns_systemctl('enable', $active);
}
