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
	if ($driver === 'nginx') {
		kloxo_nginx_ubuntu_prepare();
	}
}

/**
 * Ubuntu/Debian nginx keeps distro nginx.conf + sites-enabled/default.
 * Without Kloxo includes, Host headers fall through to /var/www/html
 * (often the leftover "Apache2 Ubuntu Default Page") even though nginx is
 * the selected web driver. Copy templates, install a conf.d include, and
 * drop the Ubuntu default site. Does not replace /etc/nginx/nginx.conf.
 */
function kloxo_nginx_ubuntu_prepare(?string $root = null): void
{
	if (!is_file('/etc/debian_version') && !is_dir('/etc/nginx')) {
		return;
	}
	$root = $root !== null && $root !== ''
		? rtrim($root, '/')
		: (string)(getenv('KLOXO_ROOT') ?: '/opt/kloxo');
	$src = $root . '/file/nginx';
	$dst = '/opt/configs/nginx';
	if (is_dir($src)) {
		if (!is_dir($dst)) {
			@mkdir($dst, 0755, true);
		}
		$p = @proc_open(
			array('cp', '-a', $src . '/.', $dst . '/'),
			array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
			$pipes
		);
		if (is_resource($p)) {
			fclose($pipes[0]);
			stream_get_contents($pipes[1]);
			stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			proc_close($p);
		}
	}
	foreach (array(
		$dst . '/conf/domains',
		$dst . '/conf/defaults',
		$dst . '/conf/customs',
		'/etc/nginx/conf.d',
	) as $d) {
		if (!is_dir($d)) {
			@mkdir($d, 0755, true);
		}
	}
	$must = $dst . '/conf/customs/_must-exists_.conf';
	if (!is_file($must)) {
		@file_put_contents($must, "## placeholder\n");
	}
	$inc = "/etc/nginx/conf.d/zz-kloxo.conf";
	$body = "include /opt/configs/nginx/conf/defaults/*.conf;\n"
		. "include /opt/configs/nginx/conf/domains/*.conf;\n"
		. "include /opt/configs/nginx/conf/customs/*.conf;\n";
	$cur = is_readable($inc) ? (string)@file_get_contents($inc) : '';
	if ($cur !== $body) {
		@file_put_contents($inc, $body);
	}
	foreach (array(
		'/etc/nginx/sites-enabled/default',
		'/etc/nginx/sites-enabled/000-default',
	) as $def) {
		if (is_link($def) || is_file($def)) {
			@unlink($def);
		}
	}
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
