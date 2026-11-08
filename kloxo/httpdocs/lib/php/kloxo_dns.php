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

function kloxo_dns_systemctl(string $action, string $unit): int
{
	if (!preg_match('/^[A-Za-z0-9_.@-]+$/', $unit)) {
		return 1;
	}
	if (!in_array($action, array('enable', 'disable', 'start', 'stop', 'restart'), true)) {
		return 1;
	}
	$cmd = array('systemctl', $action, $unit);
	if ($action === 'disable') {
		$cmd = array('systemctl', 'disable', '--now', $unit);
	} elseif ($action === 'enable') {
		$cmd = array('systemctl', 'enable', '--now', $unit);
	}
	$p = @proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	if (!is_resource($p)) {
		return 127;
	}
	fclose($pipes[0]);
	stream_get_contents($pipes[1]);
	stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);

	return proc_close($p);
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
