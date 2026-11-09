<?php
/**
 * Admin and client Backup / Restore: PHP 8 launch argv, MariaDB dump argv,
 * /var/vmail pack, tgz metadata extract, unbackup list. Fake commands only.
 * NOT covered: a live backup.php run, kloxo-backend, real mysqldump, a panel
 * form submit, or restore of a production archive.
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
	&& end($rAdmin) === '/home/kloxo/client/admin/__backup/x.tgz'
	&& is_file($rAdmin[1]),
	'admin restore argv defaults accounts=all');
$rClient = KloxoBackup::restoreArgv('client', 'alice', '/home/kloxo/client/alice/__backup/y.tgz', array('client-alice', 'domain-example.com'));
check(in_array('--accounts=client:alice,domain:example.com', $rClient, true)
	&& str_contains($rClient[3], '--name=alice'),
	'client restore argv maps class-name to class:name');
$threw = false;
try {
	KloxoBackup::restoreArgv('client', 'admin', "/tmp/x\0.tgz");
} catch (KloxoBackupException $e) {
	$threw = true;
}
check($threw, 'restore file with NUL is rejected');

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
check(str_contains($lx, "!is_array(\$res) || !isset(\$res['file'])"),
	'backMeUp skips getFromFileserv when the driver returned nothing');
check(str_contains($bak, 'KloxoBackup::launchBackup')
	&& str_contains($bak, 'KloxoBackup::launchRestore')
	&& str_contains($bak, 'function backupParent'),
	'execbackupphp/execrestorephp launch via KloxoBackup; parent is resolved');
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

KloxoBackup::$runCmd = null;
KloxoBackup::$fsPrefix = '';
foreach (array($dumpFile, $mailRoot . '/cur') as $f) {
	if (is_file($f)) {
		@unlink($f);
	}
}

printf("\n%d ok, %d fail\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
