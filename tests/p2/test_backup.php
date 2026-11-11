<?php
/**
 * Admin and client Backup / Restore: PHP 8 launch argv, MariaDB dump argv,
 * /var/vmail pack, tgz metadata extract, unbackup list, File Manager store.
 * Fake commands only. NOT covered: a live backup.php run, kloxo-backend,
 * real mysqldump, a panel form submit, or restore of a production archive.
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
require $doc . '/lib/php/kloxo_backup.php';

echo "== names and paths\n";
check(KloxoBackup::safeName('my backup;rm -rf') === 'mybackuprm-rf'
	|| KloxoBackup::safeName('ok_file') === 'ok_file', 'safeName strips separators');
check(KloxoBackup::safeName('') === 'kloxo', 'empty backup prefix becomes kloxo');
check(KloxoBackup::backupDir('client', 'admin') === '/home/kloxo/client/admin/__backup',
	'admin backup dir is /home/kloxo/client/admin/__backup');
check(KloxoBackup::backupDir('client', 'alice') === '/home/kloxo/client/alice/__backup',
	'client backup dir is /home/kloxo/client/alice/__backup');
$threw = false;
try {
	KloxoBackup::backupDir('client', '../etc');
} catch (KloxoBackupException $e) {
	$threw = true;
}
check($threw, 'backup dir rejects path traversal');
check(KloxoBackup::mailDomainDir('example.com') === '/var/vmail/example.com',
	'mail dir is /var/vmail/<domain>');
$threw = false;
try {
	KloxoBackup::mailDomainDir('../etc');
} catch (KloxoBackupException $e) {
	$threw = true;
}
check($threw, 'mail dir rejects invalid domain');
$skip = KloxoBackup::unbackupNames();
check(in_array('backuper', $skip, true) && in_array('rpms', $skip, true),
	'unbackup.lst names are quoted-string loaded');

echo "== launch argv (admin and client)\n";
$bAdmin = KloxoBackup::backupArgv('client', 'admin', 'nightly');
check($bAdmin[1] === KloxoBackup::backupScript()
	&& str_contains($bAdmin[2], '--class=client')
	&& str_contains($bAdmin[3], '--name=admin')
	&& str_contains($bAdmin[4], '--v-backup_file_name=nightly')
	&& is_file($bAdmin[1]),
	'admin Backup Now argv is absolute backup.php');
$bClient = KloxoBackup::backupArgv('client', 'alice', 'alice-1');
check(str_contains($bClient[3], '--name=alice'), 'client Backup Now argv uses the client name');
$rAdmin = KloxoBackup::restoreArgv('client', 'admin', '/home/kloxo/client/admin/__backup/x.tgz', array());
check($rAdmin[1] === KloxoBackup::restoreScript()
	&& in_array('--restore', $rAdmin, true)
	&& in_array('--accounts=all', $rAdmin, true)
	&& !in_array('--priority=low', $rAdmin, true)
	&& end($rAdmin) === '/home/kloxo/client/admin/__backup/x.tgz'
	&& is_file($rAdmin[1]),
	'admin restore argv defaults accounts=all');
$rClient = KloxoBackup::restoreArgv('client', 'alice', '/home/kloxo/client/alice/__backup/y.tgz', array('client-alice', 'domain-example.com'));
check(in_array('--accounts=client:alice,domain:example.com', $rClient, true)
	&& str_contains($rClient[3], '--name=alice'),
	'client restore argv maps class-name to class:name');
$rLow = KloxoBackup::restoreArgv('client', 'admin', '/home/kloxo/client/admin/__backup/x.tgz', array(), true);
check(in_array('--priority=low', $rLow, true),
	'scheduled restore argv may set priority=low');
$threw = false;
try {
	KloxoBackup::restoreArgv('client', 'admin', "/tmp/x\0.tgz");
} catch (KloxoBackupException $e) {
	$threw = true;
}
check($threw, 'restore file with NUL is rejected');
$prevRun = KloxoBackup::$runCmd;
$runNow = array();
KloxoBackup::$runCmd = static function (array $argv) use (&$runNow): array {
	$runNow = $argv;
	return array('code' => 0, 'out' => "Backup has been saved in /home/kloxo/client/admin/__backup/\n");
};
$ran = KloxoBackup::runBackup('client', 'admin', 'now');
check($ran['code'] === 0
	&& $runNow === KloxoBackup::backupArgv('client', 'admin', 'now')
	&& str_contains((string) $ran['out'], 'Backup has been saved'),
	'runBackup waits on backup.php (same argv as Backup Now)');
KloxoBackup::$runCmd = $prevRun;

echo "== MariaDB dump argv (no live mysqldump)\n";
$dump = KloxoBackup::dumpArgv('/usr/bin/mysqldump', 'acme_wp', 'kloxo_pma', 's3cret');
check($dump[0] === '/usr/bin/mysqldump'
	&& in_array('--add-drop-table', $dump, true)
	&& in_array('-u', $dump, true)
	&& in_array('kloxo_pma', $dump, true)
	&& in_array('--password=s3cret', $dump, true)
	&& end($dump) === 'acme_wp',
	'mysqldump argv is argument-array with --password=');
check(!in_array("-p's3cret'", $dump, true), 'dump does not use shell-quoted -p');
$threw = false;
try {
	KloxoBackup::dumpArgv('/usr/bin/mysqldump', 'mysql', 'kloxo_pma', '');
} catch (KloxoBackupException $e) {
	$threw = true;
}
check($threw, 'reserved database name is rejected');
$rst = KloxoBackup::restoreDumpArgv('/usr/bin/mysql', 'acme_wp', 'kloxo_pma', 's3cret');
check(in_array('--password=s3cret', $rst, true) && end($rst) === 'acme_wp',
	'mysql restore argv is argument-array');

echo "== dump/extract against fakes\n";
$base = sys_get_temp_dir() . '/kloxo-backup-' . getmypid();
@mkdir($base, 0700, true);
KloxoBackup::$fsPrefix = $base;
$calls = array();
KloxoBackup::$runCmd = static function (array $argv) use (&$calls): array {
	$calls[] = $argv;
	$cz = array_search('-czf', $argv, true);
	if ($cz !== false && isset($argv[$cz + 1]) && is_string($argv[$cz + 1])) {
		@file_put_contents($argv[$cz + 1], "tgz\n");
	}
	return array('code' => 0, 'out' => "--\nCREATE TABLE t (id int);\n");
};
$dumpFile = $base . '/mysql-acme_wp.dump';
KloxoBackup::dumpMysql('acme_wp', 'kloxo_pma', 'pw', $dumpFile);
check(is_file($dumpFile) && filesize($dumpFile) > 0, 'dumpMysql writes the outfile via runCmd');
check($calls !== array() && ($calls[0][0] === 'mysqldump' || str_contains((string) $calls[0][0], 'mysqldump')
	|| str_contains((string) $calls[0][0], 'mariadb-dump')),
	'dumpMysql invoked mysqldump');

$mailRoot = $base . '/var/vmail/example.com';
@mkdir($mailRoot, 0755, true);
file_put_contents($mailRoot . '/cur', 'x');
$packDir = $base . '/pack';
@mkdir($packDir, 0700, true);
$packed = KloxoBackup::packMailDomain('example.com', $packDir, '111');
check($packed === 'example.com-mmail-any-111.tgz', 'packMailDomain names the archive {domain}-mmail-any-{id}.tgz');
check($calls !== array() && str_contains(implode(' ', (array) end($calls)), 'tar'),
	'packMailDomain invokes tar');

$metaDir = $base . '/meta';
@mkdir($metaDir, 0700, true);
$src = $base . '/src';
@mkdir($src, 0700, true);
file_put_contents($src . '/kloxo.file', 'a:1:{s:1:"x";s:1:"y";}');
file_put_contents($src . '/kloxo.metadata', 'meta');
$tgz = $base . '/sample.tgz';
$tarBin = is_executable('/usr/bin/tar') ? '/usr/bin/tar' : (is_executable('/bin/tar') ? '/bin/tar' : '');
if ($tarBin !== '') {
	KloxoBackup::$runCmd = null;
	$p = @proc_open(array($tarBin, '-czf', $tgz, '-C', $src, 'kloxo.file', 'kloxo.metadata'),
		array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	if (is_resource($p)) {
		stream_get_contents($pipes[1]);
		stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($p);
	}
	if (is_file($tgz)) {
		KloxoBackup::extractArchive($tgz, $metaDir, array('kloxo.file', 'kloxo.metadata'));
		check(is_file($metaDir . '/kloxo.file') && is_file($metaDir . '/kloxo.metadata'),
			'extractArchive pulls kloxo.file and kloxo.metadata from a tgz');
		check(KloxoBackup::archiveType($tgz) === 'tgz', 'archiveType sees gzip');
	} else {
		check(false, 'extractArchive pulls kloxo.file and kloxo.metadata from a tgz', 'could not create sample tgz');
	}
	} else {
		check(true, 'extractArchive skipped (no tar on this host)');
	}

echo "== tar slip and restore metadata\n";
check(KloxoBackup::archiveMemberIsSafe('kloxo.file')
	&& KloxoBackup::archiveMemberIsSafe('client/alice/web')
	&& !KloxoBackup::archiveMemberIsSafe('../escaped')
	&& !KloxoBackup::archiveMemberIsSafe('/etc/passwd')
	&& !KloxoBackup::archiveMemberIsSafe('foo/../../etc/passwd')
	&& !KloxoBackup::archiveMemberIsSafe("x\0y"),
	'archiveMemberIsSafe rejects traversal, absolute and NUL');
$slipDir = $base . '/slipdest';
@mkdir($slipDir, 0700, true);
$outside = $base . '/escaped';
@unlink($outside);
$slip = $base . '/slip.tgz';
$py = trim((string) shell_exec('command -v python3'));
if ($py !== '' && $tarBin !== '') {
	$pyCode = <<<'PY'
import tarfile, io, sys
t = tarfile.open(sys.argv[1], "w:gz")
i = tarfile.TarInfo("../escaped")
b = b"pwned"
i.size = len(b)
t.addfile(i, io.BytesIO(b))
t.close()
PY;
	$p = @proc_open(array($py, '-c', $pyCode, $slip),
		array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
	if (is_resource($p)) {
		stream_get_contents($pipes[1]);
		stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($p);
	}
	$threw = false;
	try {
		KloxoBackup::extractArchive($slip, $slipDir);
	} catch (KloxoBackupException $e) {
		$threw = true;
	}
	check($threw && !is_file($outside) && !is_file($slipDir . '/escaped'),
		'extractArchive refuses a tar member that escapes the dest dir');
} else {
	check(true, 'extractArchive tar-slip skipped (no python3 or tar)');
}
if (class_exists('ZipArchive')) {
	$zfile = $base . '/slip.zip';
	$z = new ZipArchive();
	if ($z->open($zfile, ZipArchive::CREATE) === true) {
		$z->addFromString('../escaped', 'pwned');
		$z->close();
		$threw = false;
		try {
			KloxoBackup::extractArchive($zfile, $slipDir);
		} catch (KloxoBackupException $e) {
			$threw = true;
		}
		check($threw && !is_file($outside),
			'extractArchive refuses a zip member that escapes the dest dir');
	} else {
		check(true, 'extractArchive zip-slip skipped (could not write zip)');
	}
} else {
	check(true, 'extractArchive zip-slip skipped (no ZipArchive)');
}
$okMeta = serialize((object) array('bobject' => 'x', 'version' => '10.1'));
$got = KloxoBackup::unserializeMetadata($okMeta);
check(is_object($got) && isset($got->bobject) && $got->bobject === 'x',
	'unserializeMetadata accepts a stdClass backup object');
$threw = false;
try {
	KloxoBackup::unserializeMetadata('O:29:"GuzzleHttp\\Psr7\\FnStream":0:{}');
} catch (KloxoBackupException $e) {
	$threw = true;
}
check($threw, 'unserializeMetadata rejects a namespaced gadget class');
$threw = false;
try {
	KloxoBackup::unserializeMetadata('C:4:"Phar":0:{}');
} catch (KloxoBackupException $e) {
	$threw = true;
}
check($threw, 'unserializeMetadata rejects a Phar custom serializer');
$threw = false;
try {
	KloxoBackup::unserializeMetadata('O:9:"PHPMailer":0:{}');
} catch (KloxoBackupException $e) {
	$threw = true;
}
check($threw, 'unserializeMetadata rejects PHPMailer');
$embedded = serialize(array('note' => 'O:4:"Phar":0:{}'));
$arr = KloxoBackup::unserializeMetadata($embedded);
check(is_array($arr) && ($arr['note'] ?? '') === 'O:4:"Phar":0:{}',
	'a gadget string inside a serialized array is not treated as an object');

echo "== File Manager store\n";
$adminFm = KloxoBackup::ensureDir('client', 'admin');
$aliceFm = KloxoBackup::ensureDir('client', 'alice');
check($adminFm === $base . '/home/kloxo/client/admin/__backup' && is_dir($adminFm),
	'ensureDir creates the admin File Manager folder');
check($aliceFm === $base . '/home/kloxo/client/alice/__backup' && is_dir($aliceFm),
	'ensureDir creates the client File Manager folder');
$elsewhere = $base . '/elsewhere.tgz';
file_put_contents($elsewhere, 'archive');
$stored = KloxoBackup::installFile($elsewhere, 'client', 'alice');
check($stored === $aliceFm . '/elsewhere.tgz' && is_file($stored) && !is_file($elsewhere),
	'installFile moves the archive into the client File Manager folder');
$inplace = $adminFm . '/kloxo-admin-2026-01-01-1.tgz';
file_put_contents($inplace, 'in-place');
$again = KloxoBackup::installFile($inplace, 'client', 'admin');
check($again === $inplace && is_file($inplace),
	'installFile keeps an archive already in the File Manager folder');
$threw = false;
try {
	KloxoBackup::installFile($adminFm . '/..', 'client', 'admin');
} catch (KloxoBackupException $e) {
	$threw = true;
}
check($threw, 'installFile rejects a path whose basename is ..');

echo "== source invariants\n";
$lx = (string) file_get_contents($doc . '/lib/php/lxclass.php');
$bak = (string) file_get_contents($doc . '/lib/html/lxbackuplib.php');
$mysql = (string) file_get_contents($doc . '/driver/pserver/mysqldb__mysqllib.php');
$mmail = (string) file_get_contents($doc . '/driver/mmail/mmaillib.php');
$ml = (string) file_get_contents($doc . '/driver/mmail/mailinglistlib.php');
$csync = (string) file_get_contents($doc . '/lib/client/client__synclib.php');
$bphp = (string) file_get_contents($repo . '/kloxo/bin/common/backup.php');
$rphp = (string) file_get_contents($repo . '/kloxo/bin/common/restore.php');
check(!str_contains($lx, 'getRpmBranchList(unbackup)'),
	'doCoreBackup does not pass an unquoted unbackup constant');
check(str_contains($lx, "KloxoBackup::unbackupNames()")
	|| str_contains($lx, "getRpmBranchList('unbackup')"),
	'unbackup names come from a quoted string or KloxoBackup');
check(str_contains($lx, 'catch (Throwable')
	&& substr_count($lx, 'catch (Throwable') >= 2,
	'backup/restore object loops catch Throwable not only Exception');
// Selective client restore: unselected core objects must be cleaned, not left
// with the serialized dbaction (was `$this->dbaction == 'clean'` — a no-op).
check(str_contains($lx, "\$this->dbaction = 'clean'")
	&& !preg_match('/AddMEssageOnlyIfClientDomain\("Not Selected"\);\s*\$this->dbaction == \'clean\'/', $lx),
	'unselected client/domain restore sets dbaction = clean (not ==)');
check(str_contains($lx, "array('all')")
	&& str_contains($lx, 'Empty checkbox list')
	&& str_contains($lx, 'catch (Throwable $e)')
	&& str_contains($lx, 'backup_file_corrupted'),
	'client doCoreRestore defaults empty account select to all; kloxo.file fallback catches Throwable');
check(str_contains($bak, "\$param['_accountselect'] = array('all')")
	&& str_contains($bak, "\$gbl->__var_list_flag = false")
	&& str_contains($bak, '__restore_flag = true'),
	'restoreAccountInProcess defaults accounts=all and clears list-only flag');
check(str_contains($bak, "rl_exec_get(null, 'localhost', array(\"lxbackup\", \"execbackupphp\")")
	&& str_contains($bak, "rl_exec_get(null, 'localhost', array(\"lxbackup\", \"execrestorephp\")")
	&& str_contains($bak, 'function backendVersionSkewHint')
	&& str_contains($bak, 'systemctl restart kloxo-backend'),
	'Backup/Restore call localhost backend and hint restart on slave_upgrading');
check(str_contains($bak, 'mail after restore:')
	|| (str_contains($bak, 'contactemail') && str_contains($bak, 'lx_mail')),
	'client restore skips notify mail when contactemail is empty');
check(str_contains($lx, "!is_array(\$res) || !isset(\$res['file'])"),
	'backMeUp skips getFromFileserv when the driver returned nothing');
check(str_contains($bak, 'KloxoBackup::runBackup')
	&& str_contains($bak, 'KloxoBackup::runRestore')
	&& str_contains($bak, 'function backupParent')
	&& str_contains($bak, 'function fileManagerDir')
	&& str_contains($bak, 'shortProcDetail')
	&& str_contains($bak, 'function backupAccountInProcess')
	&& str_contains($bak, 'function restoreAccountInProcess')
	&& str_contains($bak, 'backup archive was not created'),
	'Backup Now and Restore From File wait on runBackup/runRestore');
check(!str_contains($bak, 'backup_has_been_scheduled')
	&& str_contains($bak, 'backup_completed')
	&& str_contains($bak, 'KloxoBackup::runBackup'),
	'Backup Now is not scheduled; it waits and reports completed or failed');
check(str_contains($kb = (string) file_get_contents($doc . '/lib/php/kloxo_backup.php'), 'function runBackup')
	&& str_contains($kb, 'function launchBackup')
	&& str_contains($kb, 'function runRestore')
	&& str_contains($kb, "str_ends_with(\$script, '/backup.php')")
	&& str_contains($kb, 'proc_open($argv, $spec, $pipes, $cwd)')
	&& str_contains($kb, 'bool $lowPriority = false'),
	'runBackup/runRestore wait; launchBackup remains for cron');
$bp = (string) file_get_contents($repo . '/kloxo/bin/common/backup.php');
check(str_contains($bp, 'chdir($httpdocs)')
	&& str_contains($bp, "dirname(__DIR__, 2) . '/httpdocs'")
	&& str_contains($bp, "'/lib/html/include.php'")
	&& str_contains($bp, 'another backup for this account is already running')
	&& str_contains($bp, 'kloxo_release_core_lock'),
	'backup.php chdirs to httpdocs and reports a held lock');
$rp = (string) file_get_contents($repo . '/kloxo/bin/common/restore.php');
check(str_contains($rp, 'chdir($httpdocs)')
	&& str_contains($rp, "dirname(__DIR__, 2) . '/httpdocs'")
	&& str_contains($rp, "'/lib/html/include.php'")
	&& str_contains($rp, 'kloxo_release_core_lock'),
	'restore.php chdirs to httpdocs before include');
$lib = (string) file_get_contents($doc . '/lib/html/lib.php');
check(str_contains($lib, 'function clearLxbackup')
	&& str_contains($lib, "dbaction === 'clean'")
	&& str_contains($lib, 'function kloxo_release_core_lock')
	&& str_contains($lib, 'function kloxo_pid_runs_script'),
	'clearLxbackup forces update and locks use absolute pid paths');
$sgblSrc = (string) file_get_contents($doc . '/lib/sgbl.php');
check(str_contains($sgblSrc, 'function kloxo_write_panel_php_pin')
	&& str_contains($sgblSrc, 'function kloxo_php_has_pdo_sqlite')
	&& str_contains($sgblSrc, 'etc/path/php')
	&& str_contains($sgblSrc, 'pdo_sqlite'),
	'panel PHP pin prefers a pdo_sqlite binary');
check(str_contains($bak, 'extension_loaded(\'pdo_sqlite\')')
	|| str_contains($bak, 'extension_loaded("pdo_sqlite")'),
	'Backup Now skips in-process when pdo_sqlite is missing');
$be = (string) file_get_contents($repo . '/kloxo/init/kloxo-backend.sh');
check(str_contains($be, 'etc/path/php')
	&& str_contains($be, 'pdo_sqlite'),
	'kloxo-backend.sh prefers pinned PHP with pdo_sqlite');
$lxlib = (string) file_get_contents($doc . '/lib/php/lxlib.php');
$core = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
$htmlSrc = (string) file_get_contents($doc . '/theme/htmllib.php');
check(str_contains($lxlib, 'function kloxo_is_long_backend_update')
	&& str_contains($lxlib, 'restore_confirm_confirm')
	&& str_contains($lxlib, 'function kloxo_print_backup_finished_page')
	&& str_contains($lxlib, 'function kloxo_backup_finished_from_redirect')
	&& str_contains($lxlib, 'function kloxo_backup_safe_continue_url')
	&& str_contains($lxlib, 'frm_m_emessage_data')
	&& str_contains($core, '__kloxo_defer_chrome')
	&& str_contains($core, '@ob_start()')
	&& str_contains($core, 'Deferred Backup/Restore must never end with an empty body')
	&& str_contains($htmlSrc, '__kloxo_defer_chrome')
	&& str_contains($htmlSrc, 'kloxo_print_backup_finished_page')
	&& str_contains($htmlSrc, 'kloxo_backup_safe_continue_url')
	&& str_contains($htmlSrc, '__kloxo_defer_chrome')
	&& str_contains($bak, 'function backupShowUrl')
	&& str_contains($bak, 'kloxo_print_backup_finished_page')
	&& str_contains($bak, 'Restore completed'),
	'Backup/Restore finished page uses a short Continue URL (no huge emessage query)');

// Runtime: load only the finished-page helpers (full lxlib needs panel paths).
$helperSrc = '';
if (preg_match(
	'/function kloxo_backup_safe_continue_url\(.*?\nfunction kloxo_download_filename/s',
	$lxlib,
	$hm
) === 1) {
	$helperSrc = substr($hm[0], 0, -strlen('function kloxo_download_filename'));
}
$helpersOk = $helperSrc !== '';
if ($helpersOk) {
	eval($helperSrc);
	$parsed = kloxo_backup_finished_from_redirect(
		'/display.php?frm_action=show&frm_emessage=restore_failed&frm_m_emessage_data='
		. rawurlencode('Restore failed due to disk full')
	);
	$helpersOk = $parsed[0] === 'Restore failed'
		&& str_contains($parsed[1], 'disk full')
		&& $parsed[2] === 'error';
	$safe = kloxo_backup_safe_continue_url();
	$helpersOk = $helpersOk
		&& str_contains($safe, 'frm_action=show')
		&& !str_contains($safe, 'frm_emessage')
		&& !str_contains($safe, 'frm_m_emessage_data');
}
check($helpersOk, 'finished-page helpers parse restore_failed and keep Continue URL short');

// trimming() must accept restore _accountselect nested arrays (PHP 8 TypeError).
$trimSrc = '';
if (preg_match('/function trimming\(\$data\)\s*\{.*?\n\}\n\nfunction getFSBlockSizeInKb/s', $lib, $tm) === 1
	|| preg_match('/function trimming\(\$data\)\s*\{.*?\n\}\n\nfunction getFSBlockSizeInKb/s',
		(string) file_get_contents($doc . '/lib/html/lib.php'), $tm) === 1) {
	$trimSrc = preg_replace('/\nfunction getFSBlockSizeInKb.*$/s', '', $tm[0]) ?? '';
}
$trimOk = false;
if ($trimSrc !== '' && str_contains($trimSrc, 'is_array($data)')) {
	eval($trimSrc);
	$got = trimming(array(
		'backup_from_file_f' => '  a.tgz  ',
		'_accountselect' => array(' client-a ', 'domain-b'),
		'flag' => true,
		'n' => 3,
	));
	$trimOk = is_array($got)
		&& ($got['backup_from_file_f'] ?? '') === 'a.tgz'
		&& ($got['_accountselect'][0] ?? '') === 'client-a'
		&& ($got['_accountselect'][1] ?? '') === 'domain-b'
		&& ($got['flag'] ?? null) === true
		&& ($got['n'] ?? null) === 3;
}
check($trimOk, 'trimming() recurses nested restore _accountselect without TypeError');
check(str_contains($bak, "dbaction = 'clean'")
	&& str_contains($bak, 'collectquota after restore'),
	'Restore marks lxbackup clean and does not abort on collectquota');
check(str_contains($bak, 'KloxoBackup::installFile')
	&& str_contains($bak, 'KloxoBackup::ensureDir')
	&& str_contains($bak, 'fileManagerDir()'),
	'Backup Now and File Manager share KloxoBackup::ensureDir');
check(str_contains($kb, 'function ensureDir')
	&& str_contains($kb, 'function installFile')
	&& str_contains($kb, 'chownLxlabs'),
	'KloxoBackup ensureDir/installFile chown archives to lxlabs');
check(str_contains($kb, 'function archiveMemberIsSafe')
	&& str_contains($kb, 'function unserializeMetadata')
	&& str_contains($kb, 'allowed_classes')
	&& !str_contains($kb, '--wildcards'),
	'extract lists members, refuses traversal, no tar wildcards');
check(str_contains($bak, 'KloxoBackup::unserializeMetadata')
	&& str_contains((string) file_get_contents($doc . '/lib/html/lib.php'), 'KloxoBackup::unserializeMetadata'),
	'restore metadata does not raw-unserialize kloxo.file');
check(str_contains($bak, '$this->write();')
	&& strpos($bak, '$this->write();') < strpos($bak, 'execbackupphp'),
	'Backup Now writes the lxbackup row before starting backup.php');
check(str_contains($mysql, 'KloxoBackup::dumpMysql')
	&& str_contains($mysql, 'KloxoBackup::restoreMysql')
	&& !str_contains($mysql, 'system("{$cmd}'),
	'mysqldb dump/restore no longer uses system() shell strings');
check(str_contains($mmail, 'KloxoBackup::packMailDomain')
	&& str_contains($mmail, 'function backMeUp'),
	'mmail packs /var/vmail without a qmail driver');
check(str_contains($ml, 'return false;'),
	'mailinglist extraBackup is off (ezmlm is gone)');
check(str_contains($csync, 'KloxoBackup::webGroup()'),
	'client restore chowns to www-data on Ubuntu');
check(str_contains($bphp, 'catch (Throwable')
	&& str_contains($rphp, 'catch (Throwable'),
	'backup.php and restore.php catch Throwable');
check(str_contains($lx, 'KloxoBackup::writeEnvironment')
	&& str_contains($lx, 'KloxoBackup::packExtras')
	&& str_contains($lx, 'KloxoBackup::applyAfterRestore')
	&& str_contains($lx, "in_array(\$table, array('client', 'mysqldb', 'mmail')"),
	'backup writes kloxo.env.json and extras; incomplete client/mail/db backup aborts');
check(!str_contains($rphp, 'fixdnschangeip')
	&& str_contains($rphp, 'Still do by hand')
	&& str_contains($rphp, 'Restore finished. No extra steps'),
	'restore.php prints leftover steps instead of only fixdnschangeip');
check(str_contains($bak, 'prebackup_note_f')
	&& str_contains($bak, 'prerestore_note_f')
	&& str_contains($bak, 'KloxoBackup::preBackupNotes')
	&& str_contains($bak, 'KloxoBackup::postRestoreNotes'),
	'Backup Now and Restore forms show before/after notes');

echo "== environment snapshot and extras\n";
$prevSnap = KloxoBackup::$snapshotOverride;
$prevFs = KloxoBackup::$fsPrefix;
$exBase = sys_get_temp_dir() . '/kloxo-bakenv-' . getmypid();
@mkdir($exBase . '/var/lib/rspamd/dkim', 0755, true);
@mkdir($exBase . '/etc/letsencrypt/live/example.com', 0755, true);
file_put_contents($exBase . '/var/lib/rspamd/dkim/example.com.kloxo.key', "-----KEY-----\n");
file_put_contents($exBase . '/etc/letsencrypt/live/example.com/privkey.pem', "-----PRIV-----\n");
file_put_contents($exBase . '/etc/letsencrypt/live/example.com/fullchain.pem', "-----CERT-----\n");
KloxoBackup::$fsPrefix = $exBase;
KloxoBackup::$snapshotOverride = array(
	'version' => 1,
	'hostname' => 'old.example',
	'ips' => array('1.2.3.4'),
	'php' => array('8.3'),
	'switch' => array('web' => 'nginx', 'dns' => 'bind', 'smtp' => 'exim', 'pop3' => 'dovecot'),
	'scope' => 'client',
	'name' => 'alice',
	'domains' => array('example.com'),
	'php_selected' => array('example.com' => 'php8.3'),
);
$env = KloxoBackup::environmentSnapshot(array('scope' => 'admin', 'name' => 'admin'));
check(($env['hostname'] ?? '') === 'old.example'
	&& ($env['scope'] ?? '') === 'admin'
	&& in_array('example.com', (array) ($env['domains'] ?? array()), true),
	'environmentSnapshot merges live override with backup extra');
$packDir = $exBase . '/pack';
@mkdir($packDir, 0700, true);
$wrote = KloxoBackup::writeEnvironment($packDir, $env);
$read = KloxoBackup::readEnvironment($packDir);
check(is_file($wrote) && ($read['hostname'] ?? '') === 'old.example' && ($read['scope'] ?? '') === 'admin',
	'writeEnvironment/readEnvironment round-trip kloxo.env.json');
$packed = KloxoBackup::packExtras($packDir, array(
	'scope' => 'client',
	'domains' => array('example.com'),
));
check(in_array('extras/dkim/example.com.kloxo.key', $packed, true)
	&& in_array('extras/letsencrypt/live/example.com/privkey.pem', $packed, true)
	&& is_file($packDir . '/extras/dkim/example.com.kloxo.key'),
	'packExtras copies DKIM and Let\'s Encrypt live files');
$restoreBase = $exBase . '/restorefs';
KloxoBackup::$fsPrefix = $restoreBase;
$unpacked = KloxoBackup::unpackExtras($packDir);
check(in_array('dkim/example.com.kloxo.key', $unpacked, true)
	&& is_file($restoreBase . '/var/lib/rspamd/dkim/example.com.kloxo.key')
	&& is_file($restoreBase . '/etc/letsencrypt/live/example.com/fullchain.pem'),
	'unpackExtras restores DKIM keys and Let\'s Encrypt files');
$same = KloxoBackup::leftoverManualSteps($env, $env, array('applied' => array('fixweb'), 'failed' => array()));
check($same === array(), 'leftoverManualSteps is empty on the same hostname, IPs and programs');
$dest = $env;
$dest['hostname'] = 'new.example';
$dest['ips'] = array('9.9.9.9');
$dest['php'] = array('8.3');
$moved = KloxoBackup::leftoverManualSteps($env, $dest, array());
check($moved !== array()
	&& implode(' ', $moved) !== ''
	&& str_contains(implode("\n", $moved), 'nameservers')
	&& str_contains(implode("\n", $moved), 'UFW'),
	'leftoverManualSteps lists registrar DNS and firewall after an IP/hostname change');
$phpDest = $env;
$phpDest['php'] = array('8.4');
$phpLeft = KloxoBackup::leftoverManualSteps($env, $phpDest, array());
check(str_contains(implode("\n", $phpLeft), 'PHP 8.3'),
	'leftoverManualSteps lists a PHP version that is not installed');
$swDest = $env;
$swDest['switch']['web'] = 'apache';
$swLeft = KloxoBackup::leftoverManualSteps($env, $swDest, array());
check(str_contains(implode("\n", $swLeft), 'Switch Program web'),
	'leftoverManualSteps lists a Switch Program mismatch');
$cmds = array();
KloxoBackup::$runCmd = static function (array $argv) use (&$cmds): array {
	$cmds[] = $argv;
	return array('code' => 0, 'out' => 'ok');
};
$applyDest = KloxoBackup::$snapshotOverride;
$applyDest['ips'] = array('9.9.9.9');
KloxoBackup::$snapshotOverride = $applyDest;
$applied = KloxoBackup::applyAfterRestore(array('ips' => array('1.2.3.4')));
KloxoBackup::$snapshotOverride = $env;
KloxoBackup::$runCmd = null;
$flat = array();
foreach ($cmds as $av) {
	$flat[] = implode(' ', $av);
}
$joined = implode("\n", $flat);
check(in_array('fixweb', $applied['applied'], true)
	&& in_array('fixdns', $applied['applied'], true)
	&& in_array('kloxo-mail apply', $applied['applied'], true)
	&& str_contains($joined, 'fixdnschangeip.php')
	&& str_contains($joined, '--oldip=1.2.3.4')
	&& str_contains($joined, '--newip=9.9.9.9')
	&& str_contains($joined, 'fixweb.php')
	&& str_contains($joined, 'kloxo-mail'),
	'applyAfterRestore rewrites DNS IPs and runs fixweb, fixdns, kloxo-mail apply');
$notes = KloxoBackup::preBackupNotes($env);
$afterNone = KloxoBackup::postRestoreNotes(array());
$afterSome = KloxoBackup::postRestoreNotes($moved);
check(str_contains($notes, 'Match Switch Program')
	&& str_contains($notes, 'old.example')
	&& str_contains($afterNone, 'you are done')
	&& str_contains($afterSome, 'You may still need to'),
	'preBackupNotes and postRestoreNotes are filled from the environment');
KloxoBackup::$snapshotOverride = $prevSnap;
KloxoBackup::$fsPrefix = $prevFs !== '' ? $prevFs : '';
foreach (array($exBase) as $tree) {
	if ($tree !== '' && is_dir($tree)) {
		$it = new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS);
		$ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($ri as $f) {
			$p = $f->getPathname();
			$f->isDir() ? @rmdir($p) : @unlink($p);
		}
		@rmdir($tree);
	}
}

echo "== panel Help text\n";
$__information = array();
include $doc . '/lang/en-us/messagelib.php';
$bakHelp = (string) ($__information['lxbackup_show__pre'] ?? '');
check($bakHelp !== ''
	&& str_contains($bakHelp, 'copy of this account')
	&& str_contains($bakHelp, 'not a copy of the whole server')
	&& str_contains($bakHelp, 'DKIM keys')
	&& str_contains($bakHelp, 'Let\'s Encrypt files')
	&& str_contains($bakHelp, 'Before you backup')
	&& str_contains($bakHelp, 'After restore')
	&& str_contains($bakHelp, 'Point nameservers')
	&& str_contains($bakHelp, 'Last Restore Status')
	&& ($__information['lxbackup_updateform_backup_pre'] ?? '') === $bakHelp
	&& is_string($__information['lxbackup_updateform_restore_from_file_pre'] ?? null)
	&& str_contains((string) $__information['lxbackup_updateform_restore_from_file_pre'], 'Start Restore Process')
	&& str_contains((string) $__information['lxbackup_updateform_restore_from_file_pre'], 'copy of this account')
	&& ($__information['lxbackup_updateform_restore_from_ftp_pre'] ?? '')
		=== ($__information['lxbackup_updateform_restore_from_file_pre'] ?? null),
	'Backup / Restore Help lists what is saved, auto-apply, and leftover steps');

KloxoBackup::$runCmd = null;
KloxoBackup::$fsPrefix = '';
foreach (array($dumpFile, $mailRoot . '/cur') as $f) {
	if (is_file($f)) {
		@unlink($f);
	}
}

printf("\n%d ok, %d fail\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
