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
	return '/thirdparty/phpMyAdmin/kloxo-signon.php';
}

function kloxo_pma_index_url(): string
{
	return '/thirdparty/phpMyAdmin/index.php';
}

/** HTML attributes so SQL Manager opens the MySQL logon in a new tab. */
function kloxo_pma_link_attrs(): string
{
	return 'target="_blank" rel="noopener noreferrer"';
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
	if ($ip !== '' && $remote !== '' && !kloxo_pma_same_ip($ip, $remote)
		&& !kloxo_pma_is_loopback($remote) && !kloxo_pma_is_loopback($ip)) {
		return false;
	}

	return true;
}

function kloxo_pma_is_loopback(string $ip): bool
{
	$ip = strtolower(trim($ip));
	if ($ip === '127.0.0.1' || $ip === '::1' || $ip === '0:0:0:0:0:0:0:1') {
		return true;
	}

	return str_starts_with($ip, '127.') || str_starts_with($ip, '::ffff:127.');
}

function kloxo_pma_same_ip(string $a, string $b): bool
{
	if ($a === $b) {
		return true;
	}
	$na = @inet_pton($a);
	$nb = @inet_pton($b);
	if ($na === false || $nb === false) {
		return false;
	}
	if ($na === $nb) {
		return true;
	}
	if (strlen($na) === 4 && strlen($nb) === 16) {
		return $nb === ("\0\0\0\0\0\0\0\0\0\0\xff\xff" . $na);
	}
	if (strlen($nb) === 4 && strlen($na) === 16) {
		return $na === ("\0\0\0\0\0\0\0\0\0\0\xff\xff" . $nb);
	}

	return false;
}

/**
 * phpMyAdmin SignonScript: [MariaDB user, password] for a live panel admin.
 *
 * @return array{0:string,1:string}
 */
function kloxo_pma_signon_credentials(?string $root = null): array
{
	if (!kloxo_pma_panel_is_admin($root)) {
		return array('', '');
	}
	$cred = kloxo_pma_login($root);
	if ($cred['pass'] === '') {
		return array('', '');
	}

	return array($cred['user'], $cred['pass']);
}

/** @return array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string} */
function kloxo_pma_signon_cookie_params(): array
{
	return array(
		'lifetime' => 0,
		'path' => '/',
		'domain' => '',
		'secure' => kloxo_pma_request_is_https(),
		'httponly' => true,
		'samesite' => 'Lax',
	);
}

function kloxo_pma_request_is_https(): bool
{
	if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
		return true;
	}
	if ((string)($_SERVER['SERVER_PORT'] ?? '') === '7777') {
		return true;
	}

	return strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https';
}

function kloxo_pma_csrf_secret(?string $root = null): string
{
	$pma = '';
	$file = kloxo_pma_pass_file($root);
	if (is_readable($file)) {
		$pma = (string)file_get_contents($file);
	}
	$panel = '';
	$kloxo = kloxo_pma_kloxo_pass_file($root);
	if (is_readable($kloxo)) {
		$panel = (string)file_get_contents($kloxo);
	}

	return hash('sha256', 'kloxo-pma-csrf-v1|' . kloxo_pma_root($root) . '|' . $pma . '|' . $panel, true);
}

function kloxo_pma_csrf_issue(?string $root = null): string
{
	$ts = (string)time();

	return $ts . '.' . hash_hmac('sha256', $ts, kloxo_pma_csrf_secret($root));
}

function kloxo_pma_csrf_ok(string $token, ?string $root = null): bool
{
	if (preg_match('/^([0-9]{10})\.([a-f0-9]{64})$/', $token, $m) !== 1) {
		return false;
	}
	$ts = (int)$m[1];
	if (abs(time() - $ts) > 7200) {
		return false;
	}
	$expect = hash_hmac('sha256', (string)$ts, kloxo_pma_csrf_secret($root));

	return hash_equals($expect, $m[2]);
}

function kloxo_pma_emit_signon_cookie(string $id): void
{
	if ($id === '' || headers_sent()) {
		return;
	}
	$p = kloxo_pma_signon_cookie_params();
	setcookie('KloxoPmaSignon', $id, array(
		'expires' => 0,
		'path' => $p['path'],
		'domain' => $p['domain'],
		'secure' => $p['secure'],
		'httponly' => $p['httponly'],
		'samesite' => $p['samesite'],
	));
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

function kloxo_pma_signon_start(): void
{
	@ini_set('zlib.output_compression', '0');
	if (session_status() === PHP_SESSION_ACTIVE) {
		if (session_name() === 'KloxoPmaSignon') {
			return;
		}
		session_write_close();
	}
	session_name('KloxoPmaSignon');
	session_set_cookie_params(kloxo_pma_signon_cookie_params());
	session_start();
}

/**
 * MySQL logon (Kloxo-MR style): form first, then redirect into phpMyAdmin.
 */
function kloxo_pma_signon_page(?string $root = null): void
{
	$error = '';
	$user = 'root';
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
		$posted = kloxo_pma_signon_posted_user();
		if ($posted !== '') {
			$user = $posted;
		}
		$error = kloxo_pma_signon_submit($root);
		if ($error === '') {
			return;
		}
	}
	if (!headers_sent()) {
		header('Content-Type: text/html; charset=UTF-8');
		header('X-Frame-Options: SAMEORIGIN');
		header('Referrer-Policy: same-origin');
		header('Cache-Control: no-store, no-cache, must-revalidate');
	}
	echo kloxo_pma_signon_form_html($root, $error, $user);
	exit;
}

function kloxo_pma_signon_posted_user(): string
{
	$raw = $_POST['pma_user'] ?? '';
	$user = is_string($raw) ? trim($raw) : '';
	if ($user === '' || strlen($user) > 32 || preg_match('/^[A-Za-z0-9_]+$/', $user) !== 1) {
		return '';
	}

	return $user;
}

/** @return string empty on success (redirect sent); otherwise an error for the form */
function kloxo_pma_signon_submit(?string $root = null): string
{
	$posted = (string)($_POST['kloxo_pma_csrf'] ?? '');
	if (!kloxo_pma_csrf_ok($posted, $root)) {
		return 'Please try again.';
	}
	$user = kloxo_pma_signon_posted_user();
	if ($user === '') {
		return 'Enter a MySQL user name.';
	}
	$pass = $_POST['pma_pass'] ?? '';
	$pass = is_string($pass) ? $pass : '';
	if ($pass === '' && $user === 'root' && kloxo_pma_panel_is_admin($root)) {
		$cred = kloxo_pma_login($root);
		$pass = $cred['pass'];
	}
	if ($pass === '') {
		return 'Enter the MySQL password.';
	}
	kloxo_pma_signon_start();
	$_SESSION['PMA_single_signon_user'] = $user;
	$_SESSION['PMA_single_signon_password'] = $pass;
	$_SESSION['PMA_single_signon_host'] = '127.0.0.1';
	$_SESSION['PMA_single_signon_port'] = '3306';
	$sid = session_id();
	session_write_close();
	kloxo_pma_emit_signon_cookie((string)$sid);
	if (!headers_sent()) {
		header('Cache-Control: no-store');
		header('Location: ' . kloxo_pma_index_url(), true, 302);
	}
	exit;
}

function kloxo_pma_signon_form_html(?string $root = null, string $error = '', string $user = 'root'): string
{
	$esc = static function (string $s): string {
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	};
	$err = $error !== '' ? '<p class="err">' . $esc($error) . '</p>' : '';
	$u = $esc($user !== '' ? $user : 'root');
	$t = $esc(kloxo_pma_csrf_issue($root));
	$action = '';

	return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MySQL logon</title>
<style>
body{margin:0;background:#e5e5e5;font:14px/1.4 sans-serif;color:#222}
.box{width:360px;margin:12vh auto;background:#fff;border:1px solid #bdbdbd;padding:24px 28px}
h1{margin:0 0 16px;font-size:18px}
label{display:block;margin:10px 0 4px}
input[type=text],input[type=password]{width:100%;box-sizing:border-box;padding:6px 8px}
.row{margin-top:16px;text-align:right}
button{padding:6px 18px;cursor:pointer}
.err{color:#a40000;margin:0 0 12px}
.hint{color:#666;font-size:12px;margin-top:14px}
</style>
</head>
<body>
<form class="box" method="post" action="{$action}" autocomplete="off">
<h1>MySQL logon</h1>
{$err}
<input type="hidden" name="kloxo_pma_csrf" value="{$t}">
<label for="pma_user">Username</label>
<input id="pma_user" name="pma_user" type="text" value="{$u}" maxlength="32" required>
<label for="pma_pass">Password</label>
<input id="pma_pass" name="pma_pass" type="password" value="">
<div class="row"><button type="submit">Go</button></div>
<p class="hint">Logs in to phpMyAdmin on 127.0.0.1. Panel admin may leave the password blank to use the install root password.</p>
</form>
</body>
</html>
HTML;
}

function kloxo_pma_signon_redirect(?string $root = null): void
{
	kloxo_pma_signon_page($root);
}
