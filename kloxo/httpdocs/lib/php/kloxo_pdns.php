<?php
declare(strict_types=1);

/**
 * PowerDNS helpers: Ubuntu /etc/powerdns vs RHEL /etc/pdns, BIND vs pdns units.
 * BIND stays the default running server; pdns is prepared and started only when
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
	if ($driver === 'pdns') {
		return 'pdns';
	}

	return $driver;
}

/** @return list<string> */
function kloxo_dns_other_units(string $active): array
{
	$all = array('bind', 'pdns');
	$out = array();
	foreach ($all as $d) {
		$u = kloxo_dns_unit($d);
		if ($u !== $active) {
			$out[] = $u;
		}
	}

	return array_values(array_unique($out));
}

function kloxo_pdns_root(?string $root = null): string
{
	if (is_string($root) && $root !== '') {
		return rtrim($root, '/');
	}
	$env = getenv('KLOXO_ROOT');
	if (is_string($env) && $env !== '') {
		return rtrim($env, '/');
	}

	return '/opt/kloxo';
}

function kloxo_pdns_pass_file(?string $root = null): string
{
	return kloxo_pdns_root($root) . '/etc/conf/pdns.pass';
}

function kloxo_pdns_conf_file(): string
{
	if (is_dir('/etc/powerdns/pdns.d')) {
		return '/etc/powerdns/pdns.d/zz-kloxo-gmysql.conf';
	}
	if (is_dir('/etc/pdns')) {
		return '/etc/pdns/pdns.conf';
	}

	return '/etc/powerdns/pdns.d/zz-kloxo-gmysql.conf';
}

/** @return array{user:string,pass:string,host:string,dbname:string} */
function kloxo_pdns_login(?string $root = null): array
{
	$pass = '';
	$file = kloxo_pdns_pass_file($root);
	if (is_readable($file)) {
		$pass = trim((string)file_get_contents($file));
	}

	return array(
		'user' => 'powerdns',
		'pass' => $pass,
		'host' => '127.0.0.1',
		'dbname' => 'powerdns',
	);
}

/** @param array<string,mixed> $input */
function kloxo_pdns_fill_input(array &$input, ?string $root = null): void
{
	$l = kloxo_pdns_login($root);
	$input['pdnsuser'] = $l['user'];
	$input['pdnspass'] = $l['pass'];
	$input['pdnshost'] = $l['host'];
	$input['pdnsdb'] = $l['dbname'];
}

function kloxo_pdns_connect(?string $root = null): ?mysqli
{
	if (!class_exists('mysqli')) {
		return null;
	}
	$l = kloxo_pdns_login($root);
	if ($l['pass'] === '') {
		return null;
	}
	mysqli_report(MYSQLI_REPORT_OFF);
	$conn = @new mysqli($l['host'], $l['user'], $l['pass'], $l['dbname']);
	if ($conn->connect_errno) {
		return null;
	}

	return $conn;
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
		foreach (array('bind', 'pdns') as $d) {
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
