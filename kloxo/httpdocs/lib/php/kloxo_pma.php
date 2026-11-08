<?php
declare(strict_types=1);

/**
 * phpMyAdmin sign-on: SQL Manager (target=_blank) opens kloxo-signon.php.
 * Admin dashboard SQL Manager: a live panel-admin session is signed in as
 * MariaDB kloxo_pma (ALL PRIVILEGES, 127.0.0.1). A client (or admin) click
 * on a specific database uses ?db=<nname>: sign-on is that row's username
 * and password, phpMyAdmin only_db is that database, never kloxo_pma.
 * Credentials stay in the panel store / pma.pass (never in the URL).
 * root@localhost stays unix_socket, so root cannot be used over TCP.
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

/** HTML attributes so SQL Manager opens phpMyAdmin in a new browser tab. */
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

/** True when $name is a safe MariaDB / panel mysqldb nname (not a system schema). */
function kloxo_pma_db_ident(string $name): bool
{
	if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $name) !== 1) {
		return false;
	}
	$lower = strtolower($name);

	return !in_array($lower, array(
		'mysql', 'information_schema', 'performance_schema', 'sys', 'kloxo',
		'phpmyadmin', 'root', 'kloxo_pma',
	), true);
}

/** mysqldb nname from ?db= / POST (empty when missing or unsafe). */
function kloxo_pma_request_db(): string
{
	$raw = $_GET['db'] ?? $_POST['kloxo_pma_db'] ?? '';
	$name = is_string($raw) ? trim($raw) : '';

	return kloxo_pma_db_ident($name) ? $name : '';
}

/**
 * Per-database SQL Manager href (client list/show icon). Sign-on looks up
 * that mysqldb row and logs in as its MariaDB user, not kloxo_pma.
 */
function kloxo_pma_db_href(string $nname, bool $local = true, string $server = ''): string
{
	$base = kloxo_pma_href($local, $server);
	if (!kloxo_pma_db_ident($nname)) {
		return $base;
	}
	$sep = str_contains($base, '?') ? '&' : '?';

	return $base . $sep . 'db=' . rawurlencode($nname);
}

/** SQL Manager dashboard tile is admin-only (home Domain, Basic, domain show). */
function kloxo_pma_login_is_admin(): bool
{
	global $login;

	return is_object($login) && method_exists($login, 'isAdmin') && $login->isAdmin();
}

/**
 * Dashboard tile for SQL Manager (phpMyAdmin in a new tab).
 * Admin Domain section and pserver Basic; not shown to clients.
 */
function kloxo_pma_action_object(bool $local = true, string $server = 'localhost'): object
{
	$payload = array(
		'url' => kloxo_pma_href($local, $server),
		'purl' => 'c=mysqldb&a=updateform&sa=phpmyadmin',
		'target' => kloxo_pma_link_attrs(),
	);
	if (function_exists('create_simpleObject')) {
		return create_simpleObject($payload);
	}

	return (object) $payload;
}

/** @return array{user:string,pass:string,host:string} */
function kloxo_pma_login(?string $root = null): array
{
	$pass = '';
	$file = kloxo_pma_pass_file($root);
	if (is_readable($file)) {
		$pass = trim((string)file_get_contents($file));
	}

	// Not `root`: root@localhost is unix_socket on Ubuntu, and a TCP connection to
	// 127.0.0.1 resolves to host `localhost`, so it matches that account and fails
	// with error 1698 whatever the password. kloxo_pma has ALL PRIVILEGES and this
	// password on both @localhost and @127.0.0.1 (init-panel-db).
	return array(
		'user' => 'kloxo_pma',
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
 * The panel stores a login session as JSON in <root>/session/<id>
 * (Ssession is transient; its `ssession` table is not used). Fall back to
 * that table only if the file is missing.
 *
 * @return array<string,mixed>|null
 */
function kloxo_pma_session_row(string $sid, ?string $root = null): ?array
{
	$file = kloxo_pma_root($root) . '/session/' . $sid;
	if (is_file($file) && is_readable($file)) {
		$raw = @file_get_contents($file);
		$row = is_string($raw) ? json_decode($raw, true) : null;
		if (is_array($row)) {
			return $row;
		}
	}

	$pdo = kloxo_pma_panel_pdo($root);
	if ($pdo === null) {
		return null;
	}
	try {
		$stmt = $pdo->prepare(
			'SELECT parent_clname, ip_address, last_access FROM ssession WHERE nname = ? LIMIT 1'
		);
		$stmt->execute([$sid]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
	} catch (PDOException $e) {
		return null;
	}

	return is_array($row) ? $row : null;
}

function kloxo_pma_client_name_ok(string $name): bool
{
	return $name !== '' && strlen($name) <= 64
		&& preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) === 1;
}

/**
 * Live panel client session from the Kloxo cookies + session file.
 *
 * @return array{name:string,clname:string,admin:bool}|null
 */
function kloxo_pma_panel_session(?string $root = null): ?array
{
	$class = kloxo_pma_cookie('classname');
	$name = kloxo_pma_cookie('clientname');
	$sid = kloxo_pma_cookie('session-id');
	if ($class !== 'client' || !kloxo_pma_client_name_ok($name) || $sid === '' || strlen($sid) > 255) {
		return null;
	}
	if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $sid)) {
		return null;
	}

	$row = kloxo_pma_session_row($sid, $root);
	$clname = 'client-' . $name;
	if (!is_array($row) || ($row['parent_clname'] ?? '') !== $clname) {
		return null;
	}
	$last = (int)($row['last_access'] ?? 0);
	if ($last > 0 && (time() - $last) > 86400) {
		return null;
	}
	$ip = (string)($row['ip_address'] ?? '');
	$remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
	if ($ip !== '' && $remote !== '' && !kloxo_pma_same_ip($ip, $remote)
		&& !kloxo_pma_is_loopback($remote) && !kloxo_pma_is_loopback($ip)) {
		return null;
	}

	return array(
		'name' => $name,
		'clname' => $clname,
		'admin' => ($name === 'admin'),
	);
}

/**
 * True when the Kloxo panel session cookie is a live admin client session.
 */
function kloxo_pma_panel_is_admin(?string $root = null): bool
{
	$sess = kloxo_pma_panel_session($root);

	return is_array($sess) && !empty($sess['admin']);
}

function kloxo_pma_sqlite_path(?string $root = null): string
{
	$env = getenv('KLOXO_VAR_LIB');
	if (is_string($env) && $env !== '' && !str_contains($env, "\0")) {
		return rtrim($env, '/') . '/panel.sqlite';
	}
	$inRoot = kloxo_pma_root($root) . '/var/lib/kloxo/panel.sqlite';
	if (is_file($inRoot)) {
		return $inRoot;
	}

	return '/var/lib/kloxo/panel.sqlite';
}

function kloxo_pma_sqlite(?string $root = null): ?PDO
{
	$path = kloxo_pma_sqlite_path($root);
	if ($path === '' || str_contains($path, "\0") || !is_file($path) || !is_readable($path)) {
		return null;
	}
	try {
		$pdo = new PDO('sqlite:' . $path, null, null, array(
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false,
		));
		$pdo->exec('PRAGMA busy_timeout = 3000');
		$pdo->exec('PRAGMA query_only = ON');
	} catch (PDOException $e) {
		return null;
	}

	return $pdo;
}

/**
 * @return array{nname:string,dbname:string,username:string,dbpassword:string,parent_clname:string,parent_cmlist:string}|null
 */
function kloxo_pma_mysqldb_row(string $nname, ?string $root = null): ?array
{
	if (!kloxo_pma_db_ident($nname)) {
		return null;
	}
	$pdo = kloxo_pma_sqlite($root);
	if ($pdo === null) {
		return null;
	}
	try {
		$stmt = $pdo->prepare(
			'SELECT nname, dbname, username, dbpassword, parent_clname, parent_cmlist
			 FROM mysqldb WHERE nname = ? LIMIT 1'
		);
		$stmt->execute([$nname]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
	} catch (PDOException $e) {
		return null;
	}
	if (!is_array($row)) {
		return null;
	}
	$dbname = (string)($row['dbname'] ?? '');
	$user = (string)($row['username'] ?? '');
	$pass = (string)($row['dbpassword'] ?? '');
	if (!kloxo_pma_db_ident($dbname)
		|| preg_match('/^[A-Za-z0-9_]{1,32}$/', $user) !== 1
		|| in_array(strtolower($user), array('root', 'kloxo_pma', 'kloxo', 'mysql', 'debian-sys-maint', 'mariadb.sys'), true)
		|| $pass === '' || str_contains($pass, "\0") || strlen($pass) > 128) {
		return null;
	}

	return array(
		'nname' => (string)($row['nname'] ?? $nname),
		'dbname' => $dbname,
		'username' => $user,
		'dbpassword' => $pass,
		'parent_clname' => (string)($row['parent_clname'] ?? ''),
		'parent_cmlist' => (string)($row['parent_cmlist'] ?? ''),
	);
}

/**
 * @param array{name:string,clname:string,admin:bool} $session
 * @param array{parent_clname:string,parent_cmlist:string} $db
 */
function kloxo_pma_session_owns_db(array $session, array $db): bool
{
	if (!empty($session['admin'])) {
		return true;
	}
	$cl = (string)($session['clname'] ?? '');
	if ($cl === '' || !str_starts_with($cl, 'client-')) {
		return false;
	}
	if ((string)($db['parent_clname'] ?? '') === $cl) {
		return true;
	}
	$cm = (string)($db['parent_cmlist'] ?? '');
	if ($cm === '') {
		return false;
	}

	return str_contains($cm, ',' . $cl . ',') || $cm === $cl;
}

/**
 * MariaDB login for the selected mysqldb when the panel session owns it.
 * Never returns kloxo_pma / root.
 *
 * @return array{user:string,pass:string,host:string,dbname:string}|null
 */
function kloxo_pma_db_login(string $nname, ?string $root = null): ?array
{
	$sess = kloxo_pma_panel_session($root);
	if ($sess === null) {
		return null;
	}
	$row = kloxo_pma_mysqldb_row($nname, $root);
	if ($row === null || !kloxo_pma_session_owns_db($sess, $row)) {
		return null;
	}

	return array(
		'user' => $row['username'],
		'pass' => $row['dbpassword'],
		'host' => '127.0.0.1',
		'dbname' => $row['dbname'],
	);
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

/** False on a cross-site GET so SQL Manager SSO is not CSRF'd. */
function kloxo_pma_same_origin_nav(): bool
{
	$site = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
	if ($site === 'cross-site') {
		return false;
	}
	$ref = (string)($_SERVER['HTTP_REFERER'] ?? '');
	if ($ref === '') {
		return true;
	}
	$p = parse_url($ref);
	if (!is_array($p) || empty($p['host'])) {
		return false;
	}
	$https = kloxo_pma_request_is_https();
	$reqHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
	if ($reqHost === '') {
		return false;
	}
	$reqName = explode(':', $reqHost, 2)[0];
	$reqPort = (int)($_SERVER['SERVER_PORT'] ?? 0);
	if ($reqPort === 0) {
		$hostPort = explode(':', $reqHost, 2);
		$reqPort = isset($hostPort[1]) ? (int)$hostPort[1] : ($https ? 443 : 80);
	}
	$refName = strtolower((string)$p['host']);
	$refHttps = ($p['scheme'] ?? '') === 'https';
	$refPort = isset($p['port']) ? (int)$p['port'] : ($refHttps ? 443 : 80);

	return $reqName === $refName && $reqPort === $refPort && $https === $refHttps;
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
 * Try the MariaDB login before handing it to phpMyAdmin. phpMyAdmin answers a
 * rejected login by redirecting back to SignonURL, which for a panel admin
 * signs in again: an endless redirect ("page not redirected properly").
 *
 * @return string empty when the login works (or cannot be tested), else a message
 */
function kloxo_pma_check_login(string $user, string $pass, string $host = '127.0.0.1'): string
{
	if (!extension_loaded('pdo_mysql')) {
		return '';
	}
	try {
		new PDO(
			'mysql:host=' . ($host !== '' ? $host : '127.0.0.1') . ';port=3306;charset=utf8mb4',
			$user,
			$pass,
			array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 4)
		);
	} catch (PDOException $e) {
		$msg = trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '');

		return 'MariaDB refused the login for ' . $user . '@' . $host . ': ' . $msg;
	}

	return '';
}

/** Last sign-on failure text (shown on the MySQL logon form). */
function kloxo_pma_last_error(?string $set = null): string
{
	static $err = '';
	if ($set !== null) {
		$err = $set;
	}

	return $err;
}

function kloxo_pma_sso_marker_name(): string
{
	return 'KloxoPmaSsoAt';
}

/**
 * @return string error text when the login check failed (nothing is redirected);
 *                otherwise this function redirects and exits
 */
function kloxo_pma_write_signon_and_redirect(string $user, string $pass, string $host = '127.0.0.1', string $onlyDb = ''): string
{
	if ($onlyDb !== '') {
		if (!kloxo_pma_db_ident($onlyDb)) {
			return 'You can only open your own database.';
		}
		if ($user === 'kloxo_pma' || $user === 'root' || strtolower($user) === 'kloxo') {
			return 'This database must be opened as its own user.';
		}
	}
	$bad = kloxo_pma_check_login($user, $pass, $host);
	if ($bad !== '') {
		return $bad;
	}
	kloxo_pma_signon_start();
	if (function_exists('session_regenerate_id')) {
		@session_regenerate_id(true);
	}
	$_SESSION['PMA_single_signon_user'] = $user;
	$_SESSION['PMA_single_signon_password'] = $pass;
	$_SESSION['PMA_single_signon_host'] = $host !== '' ? $host : '127.0.0.1';
	$_SESSION['PMA_single_signon_port'] = '3306';
	unset($_SESSION['PMA_single_signon_error_message']);
	if ($onlyDb !== '') {
		$_SESSION['PMA_single_signon_cfgupdate'] = array('only_db' => $onlyDb);
	} else {
		unset($_SESSION['PMA_single_signon_cfgupdate']);
	}
	$sid = session_id();
	session_write_close();
	kloxo_pma_emit_signon_cookie((string)$sid);
	if (!headers_sent()) {
		$p = kloxo_pma_signon_cookie_params();
		setcookie(kloxo_pma_sso_marker_name(), (string)time(), array(
			'expires' => time() + 10,
			'path' => '/',
			'secure' => $p['secure'],
			'httponly' => true,
			'samesite' => 'Lax',
		));
		header('Cache-Control: no-store');
		$loc = kloxo_pma_index_url();
		if ($onlyDb !== '') {
			$loc .= '?db=' . rawurlencode($onlyDb);
		}
		header('Location: ' . $loc, true, 302);
	}
	exit;
}

/**
 * True when we sent this browser to phpMyAdmin a moment ago and it came back
 * here: phpMyAdmin rejected the sign-on. Returns the reason to show, or ''.
 */
function kloxo_pma_bounced_back(): string
{
	$name = kloxo_pma_sso_marker_name();
	$at = isset($_COOKIE[$name]) && is_string($_COOKIE[$name]) ? (int)$_COOKIE[$name] : 0;
	// A rejected sign-on bounces back within milliseconds; keep the window short so
	// a deliberate second click on SQL Manager still signs in.
	if ($at <= 0 || (time() - $at) > 5) {
		return '';
	}
	if (!headers_sent()) {
		setcookie($name, '', array('expires' => time() - 3600, 'path' => '/'));
	}
	$why = '';
	if (isset($_COOKIE['KloxoPmaSignon']) && is_string($_COOKIE['KloxoPmaSignon'])) {
		kloxo_pma_signon_start();
		$m = $_SESSION['PMA_single_signon_error_message'] ?? '';
		session_write_close();
		if (is_string($m) && $m !== '') {
			$why = trim(html_entity_decode(strip_tags($m), ENT_QUOTES, 'UTF-8'));
		}
	}

	return $why !== '' ? 'phpMyAdmin refused the sign-on: ' . $why
		: 'phpMyAdmin did not accept the sign-on session. Check that cookies are allowed for this site.';
}

/**
 * Panel admin: one click from SQL Manager (new tab) into phpMyAdmin.
 * Returns false when there is no live admin session or no pma.pass.
 * A ?db= click must not use kloxo_pma (that is kloxo_pma_try_db_sso).
 */
function kloxo_pma_try_admin_sso(?string $root = null): bool
{
	if (kloxo_pma_request_db() !== '') {
		return false;
	}
	if (!kloxo_pma_same_origin_nav() || !kloxo_pma_panel_is_admin($root)) {
		return false;
	}
	$cred = kloxo_pma_login($root);
	if ($cred['user'] === '' || $cred['pass'] === '') {
		return false;
	}
	$err = kloxo_pma_write_signon_and_redirect($cred['user'], $cred['pass'], $cred['host']);
	kloxo_pma_last_error($err);

	return false;
}

/**
 * Client (or admin) click on one mysqldb: sign on as that database's user
 * with phpMyAdmin only_db. Never kloxo_pma.
 */
function kloxo_pma_try_db_sso(?string $root = null): bool
{
	$nname = kloxo_pma_request_db();
	if ($nname === '') {
		return false;
	}
	if (!kloxo_pma_same_origin_nav()) {
		kloxo_pma_last_error('Open this database from the panel.');

		return false;
	}
	$cred = kloxo_pma_db_login($nname, $root);
	if ($cred === null) {
		kloxo_pma_last_error('You can only open your own database.');

		return false;
	}
	kloxo_pma_last_db_user($cred['user']);
	$err = kloxo_pma_write_signon_and_redirect(
		$cred['user'],
		$cred['pass'],
		$cred['host'],
		$cred['dbname']
	);
	kloxo_pma_last_error($err);

	return false;
}

function kloxo_pma_last_db_user(?string $set = null): string
{
	static $user = '';
	if ($set !== null) {
		$user = $set;
	}

	return $user;
}

/**
 * GET: per-database SSO (?db=) as that MariaDB user, else admin kloxo_pma.
 * Otherwise MySQL logon, then redirect.
 */
function kloxo_pma_signon_page(?string $root = null): void
{
	$error = '';
	$user = 'kloxo_pma';
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
		$posted = kloxo_pma_signon_posted_user();
		if ($posted !== '') {
			$user = $posted;
		}
		$error = kloxo_pma_signon_submit($root);
		if ($error === '') {
			return;
		}
	} else {
		$bounce = kloxo_pma_bounced_back();
		if ($bounce !== '') {
			// Do not sign in again: that is the redirect loop.
			$error = $bounce;
		} elseif (kloxo_pma_request_db() !== '') {
			kloxo_pma_try_db_sso($root);
			$error = kloxo_pma_last_error();
			$as = kloxo_pma_last_db_user();
			if ($as !== '') {
				$user = $as;
			} else {
				$user = '';
			}
		} else {
			kloxo_pma_try_admin_sso($root);
			$error = kloxo_pma_last_error();
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
	$db = kloxo_pma_request_db();
	if ($db !== '') {
		$want = kloxo_pma_db_login($db, $root);
		if ($want === null) {
			return 'You can only open your own database.';
		}
		$pass = $_POST['pma_pass'] ?? '';
		$pass = is_string($pass) ? $pass : '';
		if ($pass === '') {
			$pass = $want['pass'];
		}

		return kloxo_pma_write_signon_and_redirect(
			$want['user'],
			$pass,
			$want['host'],
			$want['dbname']
		);
	}
	$user = kloxo_pma_signon_posted_user();
	if ($user === '') {
		return 'Enter a MySQL user name.';
	}
	if ($user === 'root' || $user === 'kloxo_pma') {
		if (!kloxo_pma_panel_is_admin($root)) {
			return 'That account is not available.';
		}
	}
	$pass = $_POST['pma_pass'] ?? '';
	$pass = is_string($pass) ? $pass : '';
	if ($pass === '' && ($user === 'root' || $user === 'kloxo_pma') && kloxo_pma_panel_is_admin($root)) {
		// root@localhost is unix_socket (no TCP password login): use kloxo_pma.
		$cred = kloxo_pma_login($root);
		$user = $cred['user'];
		$pass = $cred['pass'];
	}
	if ($pass === '') {
		return 'Enter the MySQL password.';
	}
	return kloxo_pma_write_signon_and_redirect($user, $pass, '127.0.0.1');
}

function kloxo_pma_signon_form_html(?string $root = null, string $error = '', string $user = 'kloxo_pma'): string
{
	$esc = static function (string $s): string {
		return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	};
	$err = $error !== '' ? '<p class="err">' . $esc($error) . '</p>' : '';
	$db = kloxo_pma_request_db();
	$prefill = $user;
	if ($db !== '' && ($prefill === 'kloxo_pma' || $prefill === 'root')) {
		$prefill = '';
	}
	$u = $esc($prefill);
	$t = $esc(kloxo_pma_csrf_issue($root));
	$action = $db !== '' ? ('?db=' . rawurlencode($db)) : '';
	$hiddenDb = $db !== '' ? '<input type="hidden" name="kloxo_pma_db" value="' . $esc($db) . '">' : '';
	$hint = $db !== ''
		? 'This opens only the database you selected, as its own MariaDB user (not the panel admin account).'
		: 'Panel admin is signed in automatically from SQL Manager. This form is for a MySQL user on 127.0.0.1; admin may leave the password blank to sign in as the install-time kloxo_pma account (full privileges).';
	$userReq = $db !== '' ? '' : ' required';

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
{$hiddenDb}
<label for="pma_user">Username</label>
<input id="pma_user" name="pma_user" type="text" value="{$u}" maxlength="32"{$userReq}>
<label for="pma_pass">Password</label>
<input id="pma_pass" name="pma_pass" type="password" value="">
<div class="row"><button type="submit">Go</button></div>
<p class="hint">{$hint}</p>
</form>
</body>
</html>
HTML;
}

function kloxo_pma_signon_redirect(?string $root = null): void
{
	kloxo_pma_signon_page($root);
}
