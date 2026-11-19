<?php

declare(strict_types=1);

/**
 * Modern Backup / Restore console + chunked upload API (no live panel).
 */

$pass = 0;
$fail = 0;

function ok(string $m): void
{
	global $pass;
	$pass++;
	echo "  ok   $m\n";
}

function bad(string $m, string $extra = ''): void
{
	global $fail;
	$fail++;
	echo "  FAIL $m" . ($extra !== '' ? " ($extra)" : '') . "\n";
}

function check(bool $cond, string $m, string $extra = ''): void
{
	if ($cond) {
		ok($m);
	} else {
		bad($m, $extra);
	}
}

$repo = dirname(__DIR__, 2);
$doc = $repo . '/kloxo/httpdocs';
require_once $doc . '/lib/php/kloxo_backup_ui.php';

echo "== KloxoBackupUi constants and helpers\n";
check(KloxoBackupUi::MAX_BYTES === 10737418240, 'MAX_BYTES is 10 GiB');
check(KloxoBackupUi::CHUNK_BYTES === 4194304, 'CHUNK_BYTES is 4 MiB');
check(KloxoBackupUi::isArchiveName('foo.tgz')
	&& KloxoBackupUi::isArchiveName('a.tar.gz')
	&& KloxoBackupUi::isArchiveName('b.zip')
	&& !KloxoBackupUi::isArchiveName('evil.php'),
	'isArchiveName allows archives only');
check(KloxoBackupUi::safeFileName('backup.tgz') === 'backup.tgz', 'safeFileName keeps basename');
check(KloxoBackupUi::safeFileName('/tmp/../evil/backup.tgz') === 'backup.tgz',
	'safeFileName strips directories via basename');
try {
	KloxoBackupUi::safeFileName('');
	bad('safeFileName rejects empty name', 'no throw');
} catch (Throwable $e) {
	ok('safeFileName rejects empty name');
}

$tmp = sys_get_temp_dir() . '/kloxo-backup-ui-' . getmypid();
@mkdir($tmp, 0700, true);
file_put_contents($tmp . '/a.tgz', 'abc');
file_put_contents($tmp . '/skip.txt', 'nope');
file_put_contents($tmp . '/x.uploading.1', 'partial');
$list = KloxoBackupUi::listArchives($tmp);
check(count($list) === 1 && $list[0]['name'] === 'a.tgz' && $list[0]['size'] === 3,
	'listArchives returns only finished archives');

echo "== chunked upload assemble\n";
$init = KloxoBackupUi::uploadInit($tmp, 'chunked.tgz', 9);
check(isset($init['upload_id']) && $init['chunk_size'] === KloxoBackupUi::CHUNK_BYTES,
	'uploadInit returns id and chunk size');
$c1 = KloxoBackupUi::uploadChunk($tmp, $init['upload_id'], 0, 'abc');
$c2 = KloxoBackupUi::uploadChunk($tmp, $init['upload_id'], 1, 'def');
$c3 = KloxoBackupUi::uploadChunk($tmp, $init['upload_id'], 2, 'ghi');
check($c3['received'] === 9, 'uploadChunk accumulates bytes');
$done = KloxoBackupUi::uploadComplete($tmp, $init['upload_id']);
check($done['ok'] && $done['name'] === 'chunked.tgz' && $done['size'] === 9
	&& is_file($tmp . '/chunked.tgz') && (string) file_get_contents($tmp . '/chunked.tgz') === 'abcdefghi',
	'uploadComplete assembles chunks atomically');
check(!is_dir($tmp . '/.chunk.' . $init['upload_id']), 'chunk dir removed after complete');

try {
	KloxoBackupUi::uploadInit($tmp, 'huge.tgz', KloxoBackupUi::MAX_BYTES + 1);
	bad('uploadInit rejects oversize', 'no throw');
} catch (Throwable $e) {
	ok('uploadInit rejects oversize');
}

echo "== fetch URL validation\n";
try {
	KloxoBackupUi::fetchUrl($tmp, 'ftp://bad', true);
	bad('fetchUrl rejects non-http', 'no throw');
} catch (Throwable $e) {
	ok('fetchUrl rejects non-http');
}

echo "== source wiring\n";
$bak = (string) file_get_contents($doc . '/lib/html/lxbackuplib.php');
$api = (string) file_get_contents($doc . '/theme/lbin/backup-api.php');
$js = (string) file_get_contents($doc . '/theme/js/kloxo-backup-ui.js');
$css = (string) file_get_contents($doc . '/theme/css/kloxo-backup-ui.css');
$ui = (string) file_get_contents($doc . '/lib/php/kloxo_backup_ui.php');
check(str_contains($bak, 'function showRawPrint')
	&& str_contains($bak, 'KloxoBackupUi::renderConsole')
	&& str_contains($bak, "a=updateform&sa=settings")
	&& str_contains($bak, 'case "settings"')
	&& str_contains($bak, 'function updateSettings')
	&& str_contains($bak, "\$uflist['backup'] = null")
	&& str_contains($ui, 'KloxoBackup::backupDir')
	&& !str_contains($ui, "KloxoBackup::ensureDir('client'"),
	'lxbackup show uses console + classic forms; API path avoids ensureDir hang');
check(str_contains($api, "case 'upload_chunk'")
	&& str_contains($api, "case 'fetch_url'")
	&& str_contains($api, "case 'fetch_ftp'")
	&& str_contains($api, "case 'backup'")
	&& str_contains($api, "case 'restore'")
	&& str_contains($api, "case 'download'")
	&& str_contains($api, 'hash_equals'),
	'backup-api.php covers upload/fetch/backup/restore/download with CSRF');
check(str_contains($js, 'upload_init')
	&& str_contains($js, 'upload_chunk')
	&& str_contains($js, 'fetch_url')
	&& str_contains($js, 'fetch_ftp')
	&& str_contains($js, 'data-act="download"')
	&& str_contains($js, 'kbu-drop')
	&& str_contains($js, 'KloxoToast'),
	'kloxo-backup-ui.js drives chunked upload, download and toasts');
check(str_contains($css, '.kbu-drop')
	&& str_contains($css, '.kbu-progress-fill')
	&& str_contains($css, '#c44'),
	'kloxo-backup-ui.css matches panel colours');
check(str_contains($ui, '10737418240')
	&& str_contains($ui, 'From computer')
	&& str_contains($ui, 'From URL')
	&& str_contains($ui, 'From FTP'),
	'console HTML offers file / URL / FTP');
check(str_contains($bak, "a=updateform&sa=settings")
	&& str_contains($bak, "a=show&l[class]=ffile&l[nname]=/")
	&& str_contains($bak, "a=updateform&sa=upload"),
	'backupPropertyTabs keeps Settings, File Manager and Upload');

// cleanup
$it = new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS);
$ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
foreach ($ri as $f) {
	$p = $f->getPathname();
	$f->isDir() ? @rmdir($p) : @unlink($p);
}
@rmdir($tmp);

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
