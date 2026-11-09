<?php
declare(strict_types=1);

/**
 * Site-web helpers: Ubuntu apache2 vs RHEL httpd, nginx vs Apache units.
 * Nginx stays the default running server; apache2 is prepared and started
 * only when Switch Program selects apache. Both front PHP-FPM (no mod_php).
 */

function kloxo_web_unit(string $driver): string
{
	if ($driver === 'apache') {
		foreach (array(
			'/lib/systemd/system/apache2.service',
			'/usr/lib/systemd/system/apache2.service',
			'/etc/init.d/apache2',
		) as $u) {
			if (is_file($u)) {
				return 'apache2';
			}
		}

		return 'httpd';
	}
	if ($driver === 'nginx') {
		return 'nginx';
	}

	return $driver;
}

/** @return list<string> */
function kloxo_web_other_units(string $active): array
{
	$all = array('nginx', 'apache');
	$out = array();
	foreach ($all as $d) {
		$u = kloxo_web_unit($d);
		if ($u !== $active) {
			$out[] = $u;
		}
	}

	return array_values(array_unique($out));
}

function kloxo_web_systemctl(string $action, string $unit): int
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

function kloxo_web_switch(string $driver): void
{
	if ($driver === 'none' || $driver === '') {
		foreach (array('nginx', 'apache') as $d) {
			kloxo_web_systemctl('disable', kloxo_web_unit($d));
		}

		return;
	}
	$active = kloxo_web_unit($driver);
	foreach (kloxo_web_other_units($active) as $u) {
		kloxo_web_systemctl('disable', $u);
	}
	kloxo_web_systemctl('enable', $active);
}

function kloxo_apache_token(string $s): string
{
	return (string)preg_replace('/[^A-Za-z0-9._*-]+/', '', $s);
}

function kloxo_fpm_sock(string $phpselected, string $user, string $domain = ''): string
{
	$phpfpm = __DIR__ . '/kloxo_phpfpm.php';
	$phpver = __DIR__ . '/kloxo_phpver.php';
	if (!class_exists('KloxoPhpfpm', false) && is_readable($phpfpm)) {
		include_once $phpver;
		include_once $phpfpm;
	}
	if (class_exists('KloxoPhpfpm', false)) {
		try {
			if ($domain !== '') {
				return KloxoPhpfpm::socketPath($phpselected, $user, $domain);
			}
			$series = '';
			if (class_exists('KloxoPhpver', false)) {
				$id = KloxoPhpver::isDistroId($phpselected) ? $phpselected : KloxoPhpver::activeId();
				$series = KloxoPhpver::idToSeries($id);
			}
			if ($series !== '') {
				return KloxoPhpfpm::distroSocket($series);
			}
		} catch (Throwable $e) {
		}
	}
	$php = kloxo_apache_token($phpselected !== '' ? $phpselected : 'php');
	$u = kloxo_apache_token($user);
	if ($domain !== '') {
		$d = kloxo_apache_token($domain);

		return '/run/php/' . $d . '-' . $php . '.sock';
	}

	return '/opt/configs/php-fpm/sock/' . $php . '-' . $u . '.sock';
}

function kloxo_apache_sock(string $phpselected, string $user, string $domain = ''): string
{
	return kloxo_fpm_sock($phpselected, $user, $domain);
}
