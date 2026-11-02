<?php
declare(strict_types=1);

/**
 * phpMyAdmin sign-on: panel admin opens SQL Manager already connected
 * as MariaDB root@127.0.0.1 (full privileges). Credentials stay in
 * etc/conf/pma.pass (never in the URL). OS root@localhost stays unix_socket.
 */

function kloxo_pma_root(?string $root = null): string
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

function kloxo_pma_pass_file(?string $root = null): string
{
	return kloxo_pma_root($root) . '/etc/conf/pma.pass';
}

function kloxo_pma_kloxo_pass_file(?string $root = null): string
{
	return kloxo_pma_root($root) . '/etc/conf/kloxo.pass';
}

function kloxo_pma_url(): string
{
	return '/thirdparty/phpMyAdmin/';
}

/**
 * Dashboard / tile href for SQL Manager. Localhost stays a panel path;
 * a remote pserver uses that host's panel phpMyAdmin.
 */
function kloxo_pma_href(bool $local = true, string $server = ''): string
{
	$path = kloxo_pma_url();
	if ($local || $server === '' || $server === 'localhost') {
		return $path;
	}
	$fqdn = function_exists('getFQDNforServer') ? (string) getFQDNforServer($server) : $server;
	if ($fqdn === '' || preg_match('/^[A-Za-z0-9._-]+$/', $fqdn) !== 1) {
		return $path;
	}
	$ssl = function_exists('http_is_self_ssl') && http_is_self_ssl();
	$port = '7777';
	if (function_exists('get_kloxo_port')) {
		$got = (string) get_kloxo_port($ssl ? 'ssl' : 'nonssl');
		if (preg_match('/^[0-9]{2,5}$/', $got) === 1) {
			$port = $got;
		} elseif (!$ssl) {
			$port = '7778';
		}
	} elseif (!$ssl) {
		$port = '7778';
	}

	return ($ssl ? 'https://' : 'http://') . $fqdn . ':' . $port . $path;
}

/** @return array{user:string,pass:string,host:string} */
function kloxo_pma_login(?string $root = null): array
{
	$pass = '';
	$file = kloxo_pma_pass_file($root);
	if (is_readable($file)) {
		$pass = trim((string)file_get_contents($file));
	}

	return array(
		'user' => 'root',
		'pass' => $pass,
		'host' => '127.0.0.1',
	);
}

function kloxo_pma_cookie(string $suffix): string
{
	$k = 'kloxo-' . $suffix;
	$v = $_COOKIE[$k] ?? '';

	return is_string($v) ? $v : '';
}

/**
 * True when the Kloxo panel session cookie is a live admin client session.
 */
function kloxo_pma_panel_is_admin(?string $root = null): bool
{
	$class = kloxo_pma_cookie('classname');
	$name = kloxo_pma_cookie('clientname');
	$sid = kloxo_pma_cookie('session-id');
	if ($class !== 'client' || $name !== 'admin' || $sid === '' || strlen($sid) > 255) {
		return false;
	}
	if (!preg_match('/^[A-Za-z0-9._:-]+$/', $sid)) {
		return false;
	}

	$pdo = kloxo_pma_panel_pdo($root);
	if ($pdo === null) {
		return false;
	}
	try {
		$stmt = $pdo->prepare(
			'SELECT parent_clname, ip_address, last_access FROM ssession WHERE nname = ? LIMIT 1'
		);
		$stmt->execute([$sid]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
	} catch (PDOException $e) {
		return false;
	}
	if (!is_array($row) || ($row['parent_clname'] ?? '') !== 'client-admin') {
		return false;
	}
	$last = (int)($row['last_access'] ?? 0);
	if ($last > 0 && (time() - $last) > 86400) {
		return false;
	}
	$ip = (string)($row['ip_address'] ?? '');
	$remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
	if ($ip !== '' && $remote !== '' && $ip !== $remote) {
		return false;
	}

	return true;
}

function kloxo_pma_panel_pdo(?string $root = null): ?PDO
{
	$passFile = kloxo_pma_kloxo_pass_file($root);
	if (!is_readable($passFile)) {
		return null;
	}
	$pass = trim((string)file_get_contents($passFile));
	if ($pass === '') {
		return null;
	}
	$sockets = array(
		'/run/mysqld/mysqld.sock',
		'/run/mysqld/mariadb.sock',
		'/var/run/mysqld/mysqld.sock',
	);
	foreach ($sockets as $sock) {
		if (!file_exists($sock)) {
			continue;
		}
		try {
			return new PDO(
				'mysql:unix_socket=' . $sock . ';dbname=kloxo;charset=utf8mb4',
				'kloxo',
				$pass,
				array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
			);
		} catch (PDOException $e) {
			continue;
		}
	}

	return null;
}

function kloxo_pma_signon_redirect(?string $root = null): void
{
	$login = '/login/';
	if (!kloxo_pma_panel_is_admin($root)) {
		header('Location: ' . $login, true, 302);
		exit;
	}
	$cred = kloxo_pma_login($root);
	if ($cred['pass'] === '') {
		header('Location: ' . $login, true, 302);
		exit;
	}
	$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
		|| ((string)($_SERVER['SERVER_PORT'] ?? '') === '7777');
	session_name('KloxoPmaSignon');
	session_set_cookie_params(array(
		'lifetime' => 0,
		'path' => '/thirdparty/phpMyAdmin/',
		'secure' => $secure,
		'httponly' => true,
		'samesite' => 'Lax',
	));
	session_start();
	$_SESSION['PMA_single_signon_user'] = $cred['user'];
	$_SESSION['PMA_single_signon_password'] = $cred['pass'];
	$_SESSION['PMA_single_signon_host'] = $cred['host'];
	session_write_close();
	header('Location: index.php', true, 302);
	exit;
}
