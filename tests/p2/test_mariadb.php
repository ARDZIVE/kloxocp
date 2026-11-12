<?php
/**
 * Add Database must create a real MariaDB database (CREATE DATABASE +
 * CREATE USER + GRANT). Fake connection only: KloxoMariadb with no PDO
 * records SQL. NOT covered: a live MariaDB, kloxo-backend, the panel form.
 * php-cli only.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function check(bool $cond, string $d, string $x = ''): void
{
	global $pass, $fail;
	if ($cond) {
		$pass++;
		printf("  ok   %s\n", $d);
		return;
	}
	$fail++;
	printf("  FAIL %s\n", $d);
	if ($x !== '') {
		printf("       %s\n", $x);
	}
}

$repo = dirname(__DIR__, 2);
$doc = $repo . '/kloxo/httpdocs';
require $doc . '/lib/php/kloxo_mariadb.php';

echo "== identifiers\n";
check(KloxoMariadb::ident('acme_wp') && KloxoMariadb::ident('abcd_blog'), 'valid database names');
check(!KloxoMariadb::ident('mysql') && !KloxoMariadb::ident('information_schema')
	&& !KloxoMariadb::ident('kloxo'), 'reserved database names rejected');
check(!KloxoMariadb::ident('acme;drop') && !KloxoMariadb::ident("acme`x")
	&& !KloxoMariadb::ident('Acme') && !KloxoMariadb::ident('../x'),
	'injection and mixed case database names rejected');
check(KloxoMariadb::userIdent('acme_wp') && !KloxoMariadb::userIdent('root')
	&& !KloxoMariadb::userIdent('kloxo_pma'), 'reserved users rejected');
check(KloxoMariadb::quoteIdent('acme_wp') === '`acme_wp`', 'database is backtick-quoted');
check(KloxoMariadb::quoteUser('acme_wp', 'localhost') === "'acme_wp'@'localhost'",
	'user@host is quoted');
$threw = false;
try {
	KloxoMariadb::quoteUser('acme_wp', 'evil.example');
} catch (KloxoMariadbException $e) {
	$threw = true;
}
check($threw, 'unknown host is rejected');

echo "== SQL (no live MariaDB)\n";
$mdb = new KloxoMariadb(null);
$mdb->createDatabase('acme_wp', 'acme_wp', 'Secret12');
$sql = array_map(static fn (array $row): string => $row[0], $mdb->log);
$joined = implode("\n", $sql);
check(str_contains($joined, 'CREATE DATABASE IF NOT EXISTS `acme_wp`')
	&& str_contains($joined, 'utf8mb4'), 'CREATE DATABASE utf8mb4');
check(str_contains($joined, "CREATE USER IF NOT EXISTS 'acme_wp'@'localhost' IDENTIFIED BY 'Secret12'")
	&& str_contains($joined, "CREATE USER IF NOT EXISTS 'acme_wp'@'127.0.0.1' IDENTIFIED BY 'Secret12'")
	&& str_contains($joined, "CREATE USER IF NOT EXISTS 'acme_wp'@'%' IDENTIFIED BY 'Secret12'"),
	'CREATE USER for localhost, 127.0.0.1 and %');
check(str_contains($joined, "ALTER USER 'acme_wp'@'localhost' IDENTIFIED BY 'Secret12'"),
	'ALTER USER sets the password (no GRANT IDENTIFIED BY)');
check(str_contains($joined, 'GRANT ALL PRIVILEGES ON `acme_wp`.* TO')
	&& str_contains($joined, 'FLUSH PRIVILEGES'), 'GRANT on the new database and FLUSH');
$grantHasIdent = false;
$identHasPlaceholder = false;
foreach ($sql as $line) {
	if (str_starts_with($line, 'GRANT ') && str_contains($line, 'IDENTIFIED BY')) {
		$grantHasIdent = true;
	}
	if (str_contains($line, 'IDENTIFIED BY ?')) {
		$identHasPlaceholder = true;
	}
}
check(!$grantHasIdent, 'GRANT does not use IDENTIFIED BY');
check(!$identHasPlaceholder && KloxoMariadb::quoteString("a'b\\c") === "'a''b\\\\c'",
	'IDENTIFIED BY quotes the password (MariaDB rejects native ?)');

$prog = new KloxoMariadb(null);
$prog->setProgramAccountPassword('AbCdEf1234', 'kloxo');
$progSql = implode("\n", array_map(static fn (array $row): string => $row[0], $prog->log));
check(str_contains($progSql, "ALTER USER 'kloxo'@'localhost' IDENTIFIED BY 'AbCdEf1234'")
	&& str_contains($progSql, "ALTER USER 'kloxo'@'127.0.0.1' IDENTIFIED BY 'AbCdEf1234'")
	&& str_contains($progSql, 'FLUSH PRIVILEGES')
	&& !str_contains($progSql, "'kloxo'@'%'"),
	'setProgramAccountPassword ALTERs kloxo@localhost/127.0.0.1 only');
$progBad = false;
try {
	$prog->setProgramAccountPassword('x', 'kloxo;drop');
} catch (KloxoMariadbException $e) {
	$progBad = true;
}
check($progBad, 'setProgramAccountPassword rejects a bad program user');

$sync = (string) file_get_contents($doc . '/lib/client/client__synclib.php');
check(str_contains($sync, 'setProgramAccountPassword')
	&& str_contains($sync, 'KloxoMariadb::connectAdmin')
	&& str_contains($sync, 'isQuicklyReachable')
	&& str_contains($sync, '/usr/bin/timeout')
	&& !preg_match('/echo\s+[\'"]set\s+Password/i', $sync)
	&& !preg_match('/\|\s*mysql\s+-u/', $sync),
	'changeAdminPass skips unreachable MariaDB; usermod is time-capped');

$mdbSrc = (string) file_get_contents($doc . '/lib/php/kloxo_mariadb.php');
check(str_contains($mdbSrc, 'function isQuicklyReachable')
	&& str_contains($mdbSrc, 'function streamReady')
	&& str_contains($mdbSrc, 'function liveUnixSockets')
	&& str_contains($mdbSrc, 'function diagnose')
	&& str_contains($mdbSrc, 'unix://')
	&& str_contains($mdbSrc, 'isRootProcess')
	&& str_contains($mdbSrc, 'kloxo_pma@127.0.0.1')
	&& preg_match('/ATTR_TIMEOUT\s*=>\s*2/', $mdbSrc) === 1,
	'connectAdmin probes sockets, prefers kloxo_pma when not root, 2s timeout');
check(KloxoMariadb::isQuicklyReachable() === true || KloxoMariadb::isQuicklyReachable() === false,
	'isQuicklyReachable returns a bool without hanging');
$diag = KloxoMariadb::diagnose('/tmp/kloxo-no-such-root');
check(is_array($diag) && array_key_exists('ok', $diag) && array_key_exists('ms', $diag)
	&& array_key_exists('reachable', $diag) && is_int($diag['ms']) && $diag['ms'] < 5000,
	'diagnose returns a timed report without hanging');
$chk = $repo . '/kloxo/bin/install/check-mariadb';
check(is_file($chk) && str_contains((string) file_get_contents($chk), 'KloxoMariadb::diagnose'),
	'check-mariadb CLI uses diagnose');

$clientLib = (string) file_get_contents($doc . '/lib/client/clientlib.php');
$lxclient = (string) file_get_contents($doc . '/lib/client/lxclient.php');
$mailLib = (string) file_get_contents($doc . '/lib/html/lib.php');
check(str_contains($clientLib, "subaction === 'password'")
	&& str_contains($clientLib, "nname === 'admin'")
	&& str_contains($clientLib, 'return false'),
	'admin password update skips kloxo-backend sync');
check(str_contains($lxclient, 'isQuicklyReachable')
	&& str_contains($lxclient, 'setProgramAccountPassword')
	&& str_contains($lxclient, 'kloxo_finish_local_redirect')
	&& str_contains($lxclient, 'kloxo_skip_was_now'),
	'admin password postUpdate finishes redirect without backend was()');
check(str_contains($mailLib, 'sendmail still running after write')
	&& str_contains($mailLib, 'proc_terminate'),
	'lx_mail terminates a lingering sendmail instead of blocking forever');

$bad = new KloxoMariadb(null);
$badName = false;
try {
	$bad->createDatabase('mysql', 'acme_wp', 'Secret12');
} catch (KloxoMariadbException $e) {
	$badName = true;
}
check($badName && $bad->log === array(), 'creating mysql is refused before any SQL');

$drop = new KloxoMariadb(null);
$drop->dropDatabase('acme_wp', 'acme_wp');
$dropSql = implode("\n", array_map(static fn (array $row): string => $row[0], $drop->log));
check(str_contains($dropSql, 'DROP DATABASE IF EXISTS `acme_wp`')
	&& str_contains($dropSql, "DROP USER IF EXISTS 'acme_wp'@'localhost'"),
	'DROP DATABASE and DROP USER');

echo "== driver\n";
putenv('KLOXO_ROOT=' . $repo . '/kloxo');
$_SERVER['DOCUMENT_ROOT'] = $doc;
chdir($doc);
error_reporting(E_ALL & ~E_DEPRECATED);
include_once $doc . '/lib/html/include.php';
global $login;
$login = new Client(null, 'localhost', 'admin');
$login->nname = 'admin';
$login->cttype = 'admin';
$GLOBALS['login'] = $login;

KloxoMariadb::$injected = new KloxoMariadb(null);
$drvErr = '';
$addSql = '';
try {
	$drv = new Mysqldb__mysql(null, 'localhost', 'acme_wp');
	$drv->main = new stdClass();
	$drv->main->dbname = 'acme_wp';
	$drv->main->username = 'acme_wp';
	$drv->main->dbpassword = 'Secret12';
	$drv->main->__var_dbadmin = 'kloxo_pma';
	$drv->main->__var_dbpassword = 'pma-secret';
	$drv->dbactionAdd();
	$addSql = implode("\n", array_map(static fn (array $row): string => $row[0], KloxoMariadb::$injected->log));
} catch (Throwable $e) {
	$drvErr = $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
}
check($drvErr === '' && str_contains($addSql, 'CREATE DATABASE IF NOT EXISTS `acme_wp`')
	&& str_contains($addSql, "CREATE USER IF NOT EXISTS 'acme_wp'@'localhost'"),
	'Add Database driver issues CREATE DATABASE and CREATE USER', $drvErr);

$load = databasecore::loadExtension('mysql');
$src = (string) file_get_contents($doc . '/driver/pserver/ddatabaselib.php');
$drvSrc = (string) file_get_contents($doc . '/driver/pserver/mysqldb__mysqllib.php');
$loadPos = strpos($src, 'static function loadExtension');
$loadFn = $loadPos !== false ? substr($src, $loadPos, 600) : '';
check($load === true, 'loadExtension(mysql) is true when mysqli or pdo_mysql is loaded');
check(str_contains($loadFn, "extension_loaded('mysqli')") && !str_contains($loadFn, 'exit'),
	'loadExtension no longer exits the backend');
check(str_contains($drvSrc, 'KloxoMariadb')
	&& !str_contains($drvSrc, 'GRANT ALL on {$this->main->dbname}')
	&& !preg_match('/PASSWORD\s*\(/', $drvSrc),
	'mysqldb driver uses KloxoMariadb, not GRANT IDENTIFIED BY or PASSWORD()');
KloxoMariadb::$injected = null;

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
