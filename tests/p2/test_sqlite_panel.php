<?php
/**
 * The panel's own database is SQLite (etc/conf/kloxo.sqlite): Hiawatha + PHP stay
 * up with MariaDB and every other service stopped. Fake filesystem only; no MariaDB.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function check(bool $c, string $d, string $x = ''): void
{
	global $pass, $fail;
	if ($c) {
		$pass++;
		printf("  ok   %s\n", $d);
	} else {
		$fail++;
		printf("  FAIL %s\n", $d);
		if ($x !== '') {
			printf("       %s\n", $x);
		}
	}
}

$repo = dirname(__DIR__, 2);
$doc = $repo . '/kloxo/httpdocs';
require_once $doc . '/lib/php/kloxo_sqlite.php';

$dump = (string) file_get_contents($repo . '/kloxo/file/sql/db-structure-base.sql');
$stmts = KloxoSqlite::schemaStatements($dump);
check(count($stmts) >= 80, 'MySQL dump becomes SQLite CREATE TABLE/INDEX statements', (string) count($stmts));

$tmp = sys_get_temp_dir() . '/kloxo-sqlite-panel-' . getmypid();
@mkdir($tmp . '/etc/conf', 0755, true);
@mkdir($tmp . '/file', 0755, true);
@mkdir($tmp . '/httpdocs/lib/php', 0755, true);
symlink($repo . '/kloxo/file/sql', $tmp . '/file/sql');
copy($doc . '/lib/php/kloxo_sqlite.php', $tmp . '/httpdocs/lib/php/kloxo_sqlite.php');

$path = $tmp . '/etc/conf/kloxo.sqlite';
$pdo = KloxoSqlite::open($path, true);
$n = 0;
foreach ($stmts as $s) {
	$pdo->exec($s);
	$n++;
}
$tables = KloxoSqlite::tables($pdo);
check($n === count($stmts) && count($tables) >= 40
	&& in_array('client', $tables, true) && in_array('pserver', $tables, true),
	'schema applies on a new SQLite file (' . count($tables) . ' tables)');

$pdo->exec("INSERT INTO client (nname, password, cttype, cpstatus, status, syncserver) VALUES ('admin', 'x', 'admin', 'on', 'on', 'localhost')");
$got = $pdo->query("SELECT nname FROM client WHERE nname = 'admin'")->fetch(PDO::FETCH_ASSOC);
check(($got['nname'] ?? '') === 'admin', 'admin row round-trips without MariaDB');

$ign = (string) KloxoSqlite::translateQuery("INSERT IGNORE INTO t (nname) VALUES ('a')");
check(stripos($ign, 'insert or ignore into t') === 0, 'INSERT IGNORE becomes INSERT OR IGNORE', $ign);
check(KloxoSqlite::translateQuery("ALTER TABLE t MODIFY COLUMN x int") === null,
	'MySQL MODIFY COLUMN is skipped');
check(KloxoSqlite::convertLiterals("x = 'a\\'b'") === "x = 'a''b'",
	'MySQL backslash quotes become SQLite doubled quotes');

$cmd = array(PHP_BINARY, $repo . '/kloxo/bin/install/init-panel-db', '--root=' . $tmp, '--sqlite-only', '--admin-password=admin');
$p = proc_open($cmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$rc = proc_close($p);
check($rc === 0 && str_contains($out, 'panel db ready') && is_file($tmp . '/etc/conf/kloxo.sqlite'),
	'init-panel-db --sqlite-only creates the panel file without MariaDB', $out);

$live = KloxoSqlite::open($tmp . '/etc/conf/kloxo.sqlite');
check((int) $live->query("SELECT COUNT(*) FROM client WHERE nname = 'admin'")->fetchColumn() === 1
	&& (int) $live->query('SELECT COUNT(*) FROM general')->fetchColumn() >= 1
	&& (int) $live->query("SELECT COUNT(*) FROM pserver WHERE nname = 'localhost'")->fetchColumn() === 1
	&& (string) $live->query("SELECT dbadmin_name FROM dbadmin WHERE nname = 'mysql___localhost'")->fetchColumn() === 'kloxo_pma',
	'seeded admin, general, localhost and dbadmin rows');

putenv('KLOXO_ROOT=' . $tmp);
$_SERVER['DOCUMENT_ROOT'] = $tmp . '/httpdocs';
// The panel tree's include.php still points at the real program root via KLOXO_ROOT
// only after Sgbl constructs; use the real tree and point __var_dbf via a copy of conf.
@mkdir($repo . '/kloxo/etc/conf', 0755, true);

putenv('KLOXO_ROOT=' . $repo . '/kloxo');
$_SERVER['DOCUMENT_ROOT'] = $doc;
chdir($doc);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
include_once $doc . '/lib/html/include.php';
global $sgbl;
check(isset($sgbl) && $sgbl->__var_database_type === 'sqlite'
	&& str_contains((string) $sgbl->__var_dbf, 'kloxo.sqlite'),
	'Sgbl uses sqlite and etc/conf/kloxo.sqlite');

$prot = Service__Linux::protectedUnits();
check(in_array('hiawatha', $prot, true) && in_array('kloxo-web', $prot, true)
	&& in_array('sqlite3', $prot, true) && in_array('sqlite', $prot, true),
	'Hiawatha, kloxo-web and sqlite cannot be stopped from the panel');
check(Service__Linux::mayControlUnit('hiawatha', 'stop') === false
	&& Service__Linux::mayControlUnit('sqlite3', 'disable') === false
	&& Service__Linux::mayControlUnit('kloxo-web', 'restart') === false
	&& Service__Linux::mayControlUnit('hiawatha', 'start') === true,
	'stop/disable/restart of Hiawatha and sqlite is refused; start is allowed');

exec('rm -rf ' . escapeshellarg($tmp));
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
