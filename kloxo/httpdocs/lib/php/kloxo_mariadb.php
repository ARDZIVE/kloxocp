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
	 * True when a local MariaDB looks answerable within ~1s.
	 * Used to skip password sync instead of hanging the panel request.
	 */
	public static function isQuicklyReachable(): bool
	{
		if (self::$injected instanceof self) {
			return true;
		}
		foreach (array('/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock') as $sock) {
			if (is_string($sock) && $sock !== '' && file_exists($sock)) {
				return true;
			}
		}

		return self::tcpReady('127.0.0.1', 3306, 1.0);
	}

	/** TCP accept probe so PDO is not left waiting on a black-hole port. */
	private static function tcpReady(string $host, int $port, float $timeout = 1.0): bool
	{
		$errno = 0;
		$errstr = '';
		$fp = @stream_socket_client(
			'tcp://' . $host . ':' . $port,
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
	 * Admin connection: unix_socket as root first (kloxo-backend is root on
	 * Ubuntu), then kloxo_pma@127.0.0.1. Each attempt is capped so a wedged
	 * MariaDB cannot hold the password-change HTTP request for tens of seconds.
	 */
	public static function connectAdmin(string $user = '', string $pass = '', ?string $root = null): self
	{
		if (self::$injected instanceof self) {
			return self::$injected;
		}
		if (!extension_loaded('pdo_mysql')) {
			throw new KloxoMariadbException('pdo_mysql is not loaded');
		}

		$attempts = array();
		// Socket first: no TCP handshake hang when mysqld is down / half-dead.
		foreach (array('/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock') as $sock) {
			if (is_string($sock) && $sock !== '' && file_exists($sock)) {
				$attempts[] = array('mysql:unix_socket=' . $sock . ';charset=utf8mb4', 'root', '');
			}
		}
		$tcpOk = self::tcpReady('127.0.0.1', 3306, 1.0);
		if ($user !== '' && $pass !== '' && $tcpOk) {
			$attempts[] = array('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', $user, $pass);
		}
		if (!function_exists('kloxo_pma_login')) {
			$pma = dirname(__FILE__) . '/kloxo_pma.php';
			if (is_file($pma)) {
				include_once $pma;
			}
		}
		if ($tcpOk && function_exists('kloxo_pma_login')) {
			$cred = kloxo_pma_login($root);
			if (($cred['pass'] ?? '') !== '') {
				$attempts[] = array(
					'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
					(string) $cred['user'],
					(string) $cred['pass'],
				);
			}
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
					$errors[] = trim(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '');
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
