<?php

declare(strict_types=1);

/**
 * Storage layer: migrations, WAL factory, transactional domain delete,
 * Hiawatha validate-or-keep-old, incremental stats, VACUUM INTO keep-7.
 * Temp dirs only; fake hiawatha; no /var/lib writes.
 */

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
require_once $repo . '/kloxo/httpdocs/lib/php/storage/bootstrap.php';

$tmp = sys_get_temp_dir() . '/kloxo-storage-' . getmypid();
@mkdir($tmp, 0700, true);
$data = $tmp . '/var';
$run = $tmp . '/run';
$log = $tmp . '/log';
$live = $tmp . '/init';
$root = $tmp . '/opt';
@mkdir($data, 0700, true);
@mkdir($run, 0700, true);
@mkdir($log . '/domains/example.test', 0700, true);
@mkdir($live, 0700, true);
@mkdir($root . '/log', 0700, true);
@mkdir($tmp . '/doc', 0700, true);
file_put_contents($tmp . '/doc/index.php', "<?php echo 'ok';\n");

putenv('KLOXO_VAR_LIB=' . $data);
putenv('KLOXO_RUN_DIR=' . $run);
putenv('KLOXO_LOG_DIR=' . $log);
putenv('KLOXO_ROOT=' . $root);
putenv('KLOXO_REGEN_DEBOUNCE=0');
KloxoStore::resetPool();

foreach ([
    $repo . '/kloxo/httpdocs/lib/php/storage/KloxoStore.php',
    $repo . '/kloxo/bin/storage/kloxo-migrate',
    $repo . '/kloxo/bin/storage/kloxo-stats',
    $repo . '/kloxo/bin/storage/kloxo-backup-sqlite',
    $repo . '/kloxo/bin/storage/kloxo-regen',
] as $f) {
    $out = [];
    $rc = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($f) . ' 2>&1', $out, $rc);
    check($rc === 0, 'php -l ' . basename($f), implode("\n", $out));
}

$panelApplied = KloxoMigrate::apply(KloxoStore::panel(true), KloxoMigrate::panelDir($repo . '/kloxo'));
$statsApplied = KloxoMigrate::apply(KloxoStore::stats(true), KloxoMigrate::statsDir($repo . '/kloxo'));
check($panelApplied === ['001_init.sql'] && KloxoMigrate::userVersion(KloxoStore::panel()) === 1,
    'panel 001_init.sql applied; user_version=1', implode(',', $panelApplied));
check($statsApplied === ['001_init.sql'] && KloxoMigrate::userVersion(KloxoStore::stats()) === 1,
    'stats 001_init.sql applied; user_version=1', implode(',', $statsApplied));
$again = KloxoMigrate::apply(KloxoStore::panel(), KloxoMigrate::panelDir($repo . '/kloxo'));
check($again === [], 'second migrate is a no-op');

$pdo = KloxoStore::panel();
$mode = strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn());
$fk = (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn();
$busy = (int) $pdo->query('PRAGMA busy_timeout')->fetchColumn();
$sync = (int) $pdo->query('PRAGMA synchronous')->fetchColumn();
check($mode === 'wal' && $fk === 1 && $busy === 5000 && $sync === 1,
    'WAL, foreign_keys=ON, busy_timeout=5000, synchronous=NORMAL',
    "mode={$mode} fk={$fk} busy={$busy} sync={$sync}");
check(is_dir($data) && (fileperms($data) & 0777) === 0750, 'database directory mode 0750 (FPM/lxlabs readable)');
check(is_file(KloxoStore::panelPath()) && (fileperms(KloxoStore::panelPath()) & 0777) === 0600,
    'panel.sqlite mode 0600');

$db = new KloxoPanelDb($pdo);
$uid = $db->addUser('alice', 'hash', 'client');
$keepId = $db->addDomain($uid, 'keep.test', $tmp . '/doc');
$domId = $db->addDomain($uid, 'example.test', $tmp . '/doc');
$db->addDns($domId, 'A', '@', '127.0.0.1');
$db->addDns($domId, 'MX', '@', 'mail.example.test', 3600, 10);
$db->addMail($domId, 'postmaster', 'hash');
$db->addVhost($domId, $tmp . '/doc', 'index.php');
$db->addVhost($keepId, $tmp . '/doc', 'index.php');
$counts = $db->deleteDomain('example.test');
check($counts['domain'] === 1 && $counts['dns'] === 2 && $counts['mail'] === 1 && $counts['vhosts'] === 1,
    'deleteDomain removes dns, mail and vhost rows', json_encode($counts) ?: '');
$left = (int) $pdo->query('SELECT COUNT(*) FROM domains WHERE name = \'example.test\'')->fetchColumn();
$orphans = (int) $pdo->query('SELECT COUNT(*) FROM dns_records')->fetchColumn()
    + (int) $pdo->query('SELECT COUNT(*) FROM mail_accounts')->fetchColumn();
$kept = (int) $pdo->query('SELECT COUNT(*) FROM domains WHERE name = \'keep.test\'')->fetchColumn();
$keptV = (int) $pdo->query('SELECT COUNT(*) FROM vhosts')->fetchColumn();
check($left === 0 && $orphans === 0 && $kept === 1 && $keptV === 1,
    'transactional delete leaves other domains; no orphan dns/mail');

$pdo->beginTransaction();
$pdo->exec('INSERT INTO domains (user_id, name, document_root, php_version, active, created_at)
    VALUES (1, \'rollback.test\', \'/tmp/x\', \'\', 1, 0)');
$pdo->rollBack();
check((int) $pdo->query('SELECT COUNT(*) FROM domains WHERE name = \'rollback.test\'')->fetchColumn() === 0,
    'rolled-back domain insert is not visible');

$fake = $tmp . '/hiawatha';
file_put_contents($fake, <<<'SH'
#!/bin/sh
dir=""
while [ $# -gt 0 ]; do
  if [ "$1" = "-c" ]; then
    shift
    dir="$1"
  else
    shift
  fi
done
if grep -q BAD "$dir/hiawatha.conf" 2>/dev/null; then
  echo "syntax error: BAD" >&2
  exit 1
fi
exit 0
SH
);
chmod($fake, 0755);
$tpl = $live . '/hiawatha.conf.base';
file_put_contents($tpl, "ServerId = lxlabs\nMimetypeConfig = hiawatha-mimetype.conf\n"
    . "Binding { BindingId = http; Port = 7778 }\nHostname = 127.0.0.1\n"
    . "WebsiteRoot = /tmp\nStartFile = index.html\n");
file_put_contents($live . '/hiawatha.conf', "OLD-LIVE\n");
file_put_contents($live . '/hiawatha-mimetype.conf', "text/plain txt\n");

$regen = new KloxoRegen($live, $tpl, $fake, $run . '/regen.lock', $db, '');
$ok = $regen->run();
$liveTxt = (string) file_get_contents($live . '/hiawatha.conf');
check($ok['ok'] === true && $ok['written'] === true && str_contains($liveTxt, 'Hostname = keep.test'),
    'valid config is renamed into place', json_encode($ok) . "\n" . $liveTxt);
check(is_file($run . '/regen.lock'), 'exclusive flock file regen.lock exists');

file_put_contents($tpl, "BAD\nServerId = lxlabs\nMimetypeConfig = hiawatha-mimetype.conf\n"
    . "Binding { BindingId = http; Port = 7778 }\nHostname = 127.0.0.1\n"
    . "WebsiteRoot = /tmp\nStartFile = index.html\n");
$before = (string) file_get_contents($live . '/hiawatha.conf');
$bad = $regen->run();
$after = (string) file_get_contents($live . '/hiawatha.conf');
check($bad['ok'] === false && $after === $before && str_contains($bad['error'], 'BAD'),
    'invalid config keeps the old file and returns failure', json_encode($bad) ?: '');
check(str_contains($after, 'Hostname = keep.test') && !str_contains($after, 'BAD'),
    'live hiawatha.conf still the last valid render');

$line = "127.0.0.1 - - [08/Nov/2026:12:00:00 +0000] \"GET / HTTP/1.1\" 200 1234 \"-\" \"-\"\n"
    . "127.0.0.1 - - [08/Nov/2026:12:00:01 +0000] \"GET /a HTTP/1.1\" 200 10 \"-\" \"-\"\n";
$access = $log . '/domains/keep.test/access.log';
@mkdir(dirname($access), 0700, true);
file_put_contents($access, $line);
$now = strtotime('2026-11-08 12:00:00 UTC') ?: time();
$agg = new KloxoStatsAgg(KloxoStore::stats(), KloxoStore::panel(), $log . '/domains', $now);
$first = $agg->run();
check($first['requests'] === 2 && $first['bytes'] === 1244,
    'aggregator sums bytes and requests', json_encode($first) ?: '');
$second = $agg->run();
check($second['requests'] === 0 && $second['bytes'] === 0,
    'second run is a no-op (inode/offset cursor)', json_encode($second) ?: '');
$day = KloxoStore::stats()->prepare('SELECT bytes, requests FROM domain_daily WHERE domain_id = :id AND day = :d');
$day->execute([':id' => $keepId, ':d' => '2026-11-08']);
$row = $day->fetch();
check(is_array($row) && (int) $row['bytes'] === 1244 && (int) $row['requests'] === 2,
    'domain_daily upsert matches the log', json_encode($row) ?: '');

$old = KloxoStore::stats()->prepare(
    'INSERT INTO domain_daily (domain_id, day, bytes, requests, disk_bytes) VALUES (:id, :day, 50, 3, 0)'
);
$old->execute([':id' => $keepId, ':day' => '2025-10-01']);
$rolled = $agg->run();
$month = KloxoStore::stats()->prepare('SELECT bytes, requests FROM domain_monthly WHERE domain_id = :id AND month = :m');
$month->execute([':id' => $keepId, ':m' => '2025-10']);
$mrow = $month->fetch();
$oldLeft = (int) KloxoStore::stats()->query(
    "SELECT COUNT(*) FROM domain_daily WHERE day = '2025-10-01'"
)->fetchColumn();
check(is_array($mrow) && (int) $mrow['bytes'] === 50 && $oldLeft === 0 && $rolled['rolled'] >= 1,
    'daily rows older than 12 months roll into domain_monthly', json_encode($mrow) . " rolled={$rolled['rolled']}");

$tables = KloxoStore::stats()->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
check(!in_array('access_log', $tables, true) && in_array('domain_daily', $tables, true)
    && in_array('log_cursor', $tables, true),
    'stats db has no raw logs');
check(is_file($repo . '/kloxo/file/logrotate/kloxo-domains')
    && str_contains((string) file_get_contents($repo . '/kloxo/file/logrotate/kloxo-domains'), 'access.log'),
    'logrotate config for per-domain logs');

for ($i = 1; $i <= 8; $i++) {
    $n = sprintf('%s/backup/panel-202001%02d.sqlite', $data, $i);
    @mkdir($data . '/backup', 0700, true);
    file_put_contents($n, 'old');
}
$backs = KloxoSqliteBackup::run('20261108');
check($backs !== [] && is_file($backs[0]), 'VACUUM INTO wrote a backup', json_encode($backs) ?: '');
$leftFiles = glob($data . '/backup/panel-*.sqlite') ?: [];
rsort($leftFiles, SORT_STRING);
check(count($leftFiles) === 7 && str_contains($leftFiles[0], '20261108')
    && !is_file($data . '/backup/panel-20200101.sqlite'),
    'nightly backups keep the last 7', implode(',', array_map('basename', $leftFiles)));

$readme = (string) file_get_contents($repo . '/kloxo/httpdocs/lib/php/storage/README.md');
check(str_contains($readme, 'panel.sqlite') && str_contains($readme, 'stats.sqlite')
    && str_contains($readme, 'VACUUM INTO'),
    'storage README names both databases and VACUUM INTO');

KloxoStore::resetPool();
exec('rm -rf ' . escapeshellarg($tmp));
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
