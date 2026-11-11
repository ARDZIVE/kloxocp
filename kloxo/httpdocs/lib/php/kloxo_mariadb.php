<?php
declare(strict_types=1);

/**
 * Customer MariaDB databases (Add Database in the panel).
 * Identifiers are validated then backtick-quoted. IDENTIFIED BY cannot use
 * native prepared `?` (MariaDB 1064 near '?'); passwords are PDO::quote'd.
 * MariaDB 10.4+ (Ubuntu 24.04 / 26.x): CREATE USER + ALTER USER + GRANT,
 * never GRANT ... IDENTIFIED BY or PASSWORD().
 */

class KloxoMariadbException extends RuntimeException
{
}

final class KloxoMariadb
{
	/** @var list<string> */
	public const USER_HOSTS = array('localhost', '127.0.0.1', '%');

	/** @var list<string> */
	private const RESERVED_DB = array(
		'mysql', 'information_schema', 'performance_schema', 'sys', 'kloxo',
	);

	/** @var list<string> */
	private const RESERVED_USER = array(
		'root', 'mysql', 'kloxo_pma', 'kloxo', 'debian-sys-maint', 'mariadb.sys',
	);

	/** Set in tests so the driver does not open a live MariaDB. */
	public static ?self $injected = null;

	/** @var list<array{0:string,1:list<mixed>}> */
	public array $log = array();

	private ?PDO $pdo;

	public function __construct(?PDO $pdo = null)
	{
		$this->pdo = $pdo;
	}

	public static function ident(string $name): bool
	{
		return preg_match('/^[a-z0-9_]{1,63}[a-z0-9]$/', $name) === 1
			&& !in_array($name, self::RESERVED_DB, true);
	}

	public static function userIdent(string $name): bool
	{
		return preg_match('/^[a-zA-Z0-9_]{1,32}$/', $name) === 1
			&& !in_array(strtolower($name), self::RESERVED_USER, true);
	}

	public static function quoteIdent(string $name): string
	{
		if (!self::ident($name) && !self::userIdent($name)) {
			throw new KloxoMariadbException('Invalid MariaDB identifier');
		}

		return '`' . str_replace('`', '``', $name) . '`';
	}

	public static function quoteUser(string $user, string $host): string
	{
		if (!self::userIdent($user) || !in_array($host, self::USER_HOSTS, true)) {
			throw new KloxoMariadbException('Invalid MariaDB user or host');
		}

		return "'" . str_replace("'", "''", $user) . "'@'" . str_replace("'", "''", $host) . "'";
	}

	/** SQL string literal. Native prepares cannot bind IDENTIFIED BY. */
	public static function quoteString(string $value): string
	{
		if (str_contains($value, "\0")) {
			throw new KloxoMariadbException('Invalid database password');
		}

		return "'" . str_replace(array('\\', "'"), array('\\\\', "''"), $value) . "'";
	}

	/**
	 * True when a local MariaDB answers within ~1s.
	 * file_exists on the socket is NOT enough: a stale sock after mysqld dies
	 * still exists and PDO can hang on it for a long time.
	 */
	public static function isQuicklyReachable(): bool
	{
		if (self::$injected instanceof self) {
			return true;
		}
		foreach (self::liveUnixSockets() as $sock) {
			return true;
		}

		return self::streamReady('tcp://127.0.0.1:3306', 1.0);
	}

	/** True when this process is root (kloxo-backend); panel FPM is lxlabs. */
	private static function isRootProcess(): bool
	{
		return function_exists('posix_geteuid') && (int) posix_geteuid() === 0;
	}

	/**
	 * Unix sockets that actually accept a connection (mysqld is up).
	 *
	 * @return list<string>
	 */
	private static function liveUnixSockets(): array
	{
		$live = array();
		foreach (array('/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock') as $sock) {
			if (!is_string($sock) || $sock === '' || !file_exists($sock)) {
				continue;
			}
			if (self::streamReady('unix://' . $sock, 1.0)) {
				$live[] = $sock;
			}
		}

		return $live;
	}

	/** Accept probe so PDO is never pointed at a black-hole sock/port. */
	private static function streamReady(string $target, float $timeout = 1.0): bool
	{
		$errno = 0;
		$errstr = '';
		$fp = @stream_socket_client(
			$target,
			$errno,
			$errstr,
			max($timeout, 0.2)
		);
		if (is_resource($fp)) {
			fclose($fp);

			return true;
		}

		return false;
	}

	/**
	 * Probe + login report for doctor / operators (never hangs more than a few seconds).
	 *
	 * @return array{
	 *   reachable:bool,
	 *   tcp:bool,
	 *   sockets:list<string>,
	 *   pma_pass:bool,
	 *   as_root:bool,
	 *   ok:bool,
	 *   via:string,
	 *   ms:int,
	 *   error:string
	 * }
	 */
	public static function diagnose(?string $root = null): array
	{
		$t0 = microtime(true);
		$out = array(
			'reachable' => false,
			'tcp' => false,
			'sockets' => array(),
			'pma_pass' => false,
			'as_root' => self::isRootProcess(),
			'ok' => false,
			'via' => '',
			'ms' => 0,
			'error' => '',
		);
		if (self::$injected instanceof self) {
			$out['reachable'] = true;
			$out['ok'] = true;
			$out['via'] = 'injected';
			$out['ms'] = (int) round((microtime(true) - $t0) * 1000);

			return $out;
		}
		$out['sockets'] = self::liveUnixSockets();
		$out['tcp'] = self::streamReady('tcp://127.0.0.1:3306', 1.0);
		$out['reachable'] = $out['sockets'] !== array() || $out['tcp'];
		if (!function_exists('kloxo_pma_login')) {
			$pma = dirname(__FILE__) . '/kloxo_pma.php';
			if (is_file($pma)) {
				include_once $pma;
			}
		}
		if (function_exists('kloxo_pma_pass_file')) {
			$pf = kloxo_pma_pass_file($root);
			$out['pma_pass'] = is_readable($pf) && trim((string) @file_get_contents($pf)) !== '';
		}
		if (!$out['reachable']) {
			$out['error'] = 'MariaDB not accepting connections on socket or 127.0.0.1:3306';
			$out['ms'] = (int) round((microtime(true) - $t0) * 1000);

			return $out;
		}
		try {
			self::connectAdmin('', '', $root);
			$out['ok'] = true;
			$out['via'] = 'connected';
		} catch (Throwable $e) {
			$out['error'] = $e->getMessage();
		}
		$out['ms'] = (int) round((microtime(true) - $t0) * 1000);

		return $out;
	}

	/**
	 * Admin connection.
	 * - root (kloxo-backend): live unix_socket as root, then kloxo_pma@127.0.0.1
	 * - panel FPM (lxlabs): kloxo_pma@127.0.0.1 only — root@localhost is
	 *   unix_socket auth and fails for non-root (error 1698); never try it.
	 */
	public static function connectAdmin(string $user = '', string $pass = '', ?string $root = null): self
	{
		if (self::$injected instanceof self) {
			return self::$injected;
		}
		if (!extension_loaded('pdo_mysql')) {
			throw new KloxoMariadbException('pdo_mysql is not loaded');
		}

		$asRoot = self::isRootProcess();
		$socks = self::liveUnixSockets();
		$tcpOk = self::streamReady('tcp://127.0.0.1:3306', 1.0);
		if ($socks === array() && !$tcpOk) {
			throw new KloxoMariadbException('Could not connect to MariaDB: server not reachable');
		}

		if (!function_exists('kloxo_pma_login')) {
			$pma = dirname(__FILE__) . '/kloxo_pma.php';
			if (is_file($pma)) {
				include_once $pma;
			}
		}

		/** @var list<array{0:string,1:string,2:string,3:string}> dsn, user, pass, label */
		$attempts = array();
		if ($asRoot) {
			foreach ($socks as $sock) {
				$attempts[] = array(
					'mysql:unix_socket=' . $sock . ';charset=utf8mb4',
					'root',
					'',
					'root@unix_socket',
				);
			}
		}
		if ($user !== '' && $pass !== '' && $tcpOk) {
			$attempts[] = array(
				'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
				$user,
				$pass,
				$user . '@127.0.0.1',
			);
		}
		$pmaPass = '';
		if (function_exists('kloxo_pma_login')) {
			$cred = kloxo_pma_login($root);
			$pmaPass = (string) ($cred['pass'] ?? '');
			if ($pmaPass !== '' && $tcpOk) {
				$attempts[] = array(
					'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
					(string) ($cred['user'] ?? 'kloxo_pma'),
					$pmaPass,
					'kloxo_pma@127.0.0.1',
				);
			}
		}
		// Non-root + socket only (skip-networking): use socket as kloxo_pma if we
		// have the password (MariaDB maps host as localhost for unix socket).
		if (!$asRoot && !$tcpOk && $socks !== array() && $pmaPass !== '' && function_exists('kloxo_pma_login')) {
			$cred = kloxo_pma_login($root);
			foreach ($socks as $sock) {
				$attempts[] = array(
					'mysql:unix_socket=' . $sock . ';charset=utf8mb4',
					(string) ($cred['user'] ?? 'kloxo_pma'),
					$pmaPass,
					'kloxo_pma@unix_socket',
				);
			}
		}

		if ($attempts === array()) {
			$why = !$tcpOk && $socks === array()
				? 'server not reachable'
				: ($pmaPass === ''
					? 'no pma.pass (and not root for unix_socket)'
					: 'no usable login method');
			throw new KloxoMariadbException('Could not connect to MariaDB: ' . $why);
		}

		$errors = array();
		$seen = array();
		$prevSock = ini_get('default_socket_timeout');
		@ini_set('default_socket_timeout', '2');
		try {
			foreach ($attempts as $a) {
				$key = $a[0] . "\0" . $a[1];
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				try {
					$pdo = new PDO($a[0], $a[1], $a[2], array(
						PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
						PDO::ATTR_TIMEOUT => 2,
						PDO::ATTR_EMULATE_PREPARES => false,
					));

					return new self($pdo);
				} catch (PDOException $e) {
					$errors[] = $a[3] . ': ' . trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '');
				}
			}
		} finally {
			if ($prevSock !== false) {
				@ini_set('default_socket_timeout', (string) $prevSock);
			}
		}

		throw new KloxoMariadbException(
			'Could not connect to MariaDB: ' . ($errors !== array() ? implode('; ', $errors) : 'no admin login tried')
		);
	}

	public function createDatabase(string $dbname, string $username, string $password, ?string $primaryUser = null): void
	{
		if (!self::ident($dbname)) {
			throw new KloxoMariadbException('Invalid database name');
		}
		if (!self::userIdent($username)) {
			throw new KloxoMariadbException('Invalid database user');
		}
		if ($password === '' || strlen($password) > 64) {
			throw new KloxoMariadbException('Invalid database password');
		}
		$db = self::quoteIdent($dbname);
		$this->run("CREATE DATABASE IF NOT EXISTS {$db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
		$this->ensureUser($username, $password);
		$this->grantDatabase($dbname, $username);
		if (is_string($primaryUser) && $primaryUser !== '' && $primaryUser !== $username
			&& self::userIdent($primaryUser)) {
			$this->grantDatabase($dbname, $primaryUser);
		}
		$this->run('FLUSH PRIVILEGES');
	}

	public function dropDatabase(string $dbname, string $username): void
	{
		if (!self::ident($dbname)) {
			throw new KloxoMariadbException('Invalid database name');
		}
		$db = self::quoteIdent($dbname);
		$this->run("DROP DATABASE IF EXISTS {$db}");
		if (self::userIdent($username)) {
			foreach (self::USER_HOSTS as $host) {
				$this->run('DROP USER IF EXISTS ' . self::quoteUser($username, $host));
			}
		}
		$this->run('FLUSH PRIVILEGES');
	}

	public function setPassword(string $username, string $password): void
	{
		if (!self::userIdent($username)) {
			throw new KloxoMariadbException('Invalid database user');
		}
		if ($password === '' || strlen($password) > 64) {
			throw new KloxoMariadbException('Invalid database password');
		}
		$q = $this->quotePassword($password);
		foreach (self::USER_HOSTS as $host) {
			$this->run('ALTER USER ' . self::quoteUser($username, $host) . ' IDENTIFIED BY ' . $q);
		}
		$this->run('FLUSH PRIVILEGES');
	}

	/**
	 * Update the panel MariaDB account (`kloxo` / program name). That name is
	 * reserved for customer CREATE USER, so setPassword() cannot be used.
	 * Hosts are localhost + 127.0.0.1 only (no %).
	 */
	public function setProgramAccountPassword(string $password, string $user = 'kloxo'): void
	{
		if (preg_match('/^[A-Za-z0-9_]{1,32}$/', $user) !== 1) {
			throw new KloxoMariadbException('Invalid program database user');
		}
		if ($password === '' || strlen($password) > 64) {
			throw new KloxoMariadbException('Invalid database password');
		}
		$q = $this->quotePassword($password);
		foreach (array('localhost', '127.0.0.1') as $host) {
			$uh = "'" . str_replace("'", "''", $user) . "'@'" . str_replace("'", "''", $host) . "'";
			$this->run('ALTER USER ' . $uh . ' IDENTIFIED BY ' . $q);
		}
		$this->run('FLUSH PRIVILEGES');
	}

	public function dropUser(string $username): void
	{
		if (!self::userIdent($username)) {
			throw new KloxoMariadbException('Invalid database user');
		}
		foreach (self::USER_HOSTS as $host) {
			$this->run('DROP USER IF EXISTS ' . self::quoteUser($username, $host));
		}
		$this->run('FLUSH PRIVILEGES');
	}

	public function flushPrivileges(): void
	{
		$this->run('FLUSH PRIVILEGES');
	}

	public function grantDatabase(string $dbname, string $username): void
	{
		if (!self::ident($dbname) || !self::userIdent($username)) {
			throw new KloxoMariadbException('Invalid database name or user');
		}
		$db = self::quoteIdent($dbname);
		foreach (self::USER_HOSTS as $host) {
			$this->run('GRANT ALL PRIVILEGES ON ' . $db . '.* TO ' . self::quoteUser($username, $host));
		}
	}

	public function ensureUser(string $username, string $password): void
	{
		if (!self::userIdent($username)) {
			throw new KloxoMariadbException('Invalid database user');
		}
		if ($password === '' || strlen($password) > 64) {
			throw new KloxoMariadbException('Invalid database password');
		}
		$q = $this->quotePassword($password);
		foreach (self::USER_HOSTS as $host) {
			$uh = self::quoteUser($username, $host);
			$this->run('CREATE USER IF NOT EXISTS ' . $uh . ' IDENTIFIED BY ' . $q);
			$this->run('ALTER USER ' . $uh . ' IDENTIFIED BY ' . $q);
		}
	}

	private function quotePassword(string $password): string
	{
		if (str_contains($password, "\0")) {
			throw new KloxoMariadbException('Invalid database password');
		}
		if ($this->pdo instanceof PDO) {
			$q = $this->pdo->quote($password);
			if (!is_string($q) || $q === '' || !str_contains($q, "'")) {
				throw new KloxoMariadbException('Could not quote the database password');
			}

			return $q;
		}

		return self::quoteString($password);
	}

	/** @param list<mixed> $params */
	private function run(string $sql, array $params = array()): void
	{
		$this->log[] = array($sql, $params);
		if ($this->pdo === null) {
			return;
		}
		try {
			if ($params === array()) {
				$this->pdo->exec($sql);

				return;
			}
			$stmt = $this->pdo->prepare($sql);
			$stmt->execute($params);
		} catch (PDOException $e) {
			throw new KloxoMariadbException(trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? ''), 0, $e);
		}
	}
}
