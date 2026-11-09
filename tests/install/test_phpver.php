<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/kloxo/httpdocs/lib/php/kloxo_phpver.php';

$fail = 0;
$ok = static function (string $d) { printf("  ok   %s\n", $d); };
$bad = static function (string $d) use (&$fail) { $fail++; printf("  FAIL %s\n", $d); };

if (KloxoPhpver::isDistroId('php8.3') && KloxoPhpver::isDistroId('php8.5') && KloxoPhpver::isDistroId('php')
    && KloxoPhpver::isDistroId('php5.6') && KloxoPhpver::isDistroId('php7.4')) {
    $ok('distro ids');
} else {
    $bad('distro ids');
}
$cat = KloxoPhpver::catalogIds();
if (in_array('php5.6', $cat, true) && in_array('php7.4', $cat, true) && in_array('php8.3', $cat, true)
    && in_array('php8.1', $cat, true) && in_array('php8.2', $cat, true) && in_array('php8.4', $cat, true)
    && in_array('php8.5', $cat, true) && !in_array('php52m', $cat, true)) {
    $ok('catalogIds is Ubuntu phpX.Y not php52m');
} else {
    $bad('catalogIds is Ubuntu phpX.Y not php52m');
}
$avail = KloxoPhpver::availableToInstall();
if (is_array($avail) && !in_array('m', $avail, true) && !in_array('php52m', $avail, true)) {
    $ok('availableToInstall is not a CentOS leftover');
} else {
    $bad('availableToInstall is not a CentOS leftover');
}
$used = KloxoPhpver::phpUsedChoices();
if (is_array($used) && $used !== [] && !in_array('--PHP Branch--', $used, true)) {
    $ok('phpUsedChoices has no --PHP Branch-- placeholder');
} else {
    $bad('phpUsedChoices has no --PHP Branch-- placeholder');
}
if (!KloxoPhpver::isDistroId('php73m') && !KloxoPhpver::isDistroId('php54u')) {
    $ok('legacy optm ids are not distro');
} else {
    $bad('legacy optm ids are not distro');
}

$ids = KloxoPhpver::installedIds();
if (is_array($ids)) {
    $ok('installedIds returns a list');
} else {
    $bad('installedIds returns a list');
}
$def = KloxoPhpver::defaultId();
if (is_string($def) && $def !== '' && KloxoPhpver::isDistroId($def)) {
    $ok("defaultId is the newest installed ($def)");
} else {
    $bad('defaultId is the newest installed');
}
$act = KloxoPhpver::activeId();
if (is_string($act) && KloxoPhpver::isDistroId($act)) {
    $ok("activeId is a distro id ($act)");
} else {
    $bad('activeId is a distro id');
}

$php = '/usr/bin/php';
if (is_executable($php)) {
    $mm = KloxoPhpver::cliMajorMinor($php);
    if (preg_match('/^\d+\.\d+$/', $mm) === 1) {
        $ok("cliMajorMinor on this host ($mm)");
    } else {
        $bad('cliMajorMinor on this host');
    }
} else {
    $ok('cliMajorMinor skipped (no /usr/bin/php here)');
}

if (KloxoPhpver::iniBaseName('20-imap.ini') === 'imap' && KloxoPhpver::iniBaseName('imagick.ini') === 'imagick') {
    $ok('iniBaseName strips conf.d prefix');
} else {
    $bad('iniBaseName strips conf.d prefix');
}

$sel = KloxoPhpver::asSelectList(['php8.5', 'php7.4']);
if (($sel['php8.5'] ?? '') === 'php8.5' && ($sel['php7.4'] ?? '') === 'php7.4' && !isset($sel[0])) {
    $ok('asSelectList uses phpX.Y keys not 0,1,2');
} else {
    $bad('asSelectList uses phpX.Y keys not 0,1,2');
}

$parsed = KloxoPhpver::parseRequestedIds('php8.5, 8.4,php7.4,0,php52m,nope');
if ($parsed === ['php8.5', 'php8.4', 'php7.4']) {
    $ok('parseRequestedIds keeps catalog phpX.Y and drops junk');
} else {
    $bad('parseRequestedIds keeps catalog phpX.Y and drops junk');
}
$parsedArr = KloxoPhpver::parseRequestedIds(['php5.6', '', 'php8.3']);
if ($parsedArr === ['php5.6', 'php8.3']) {
    $ok('parseRequestedIds accepts an array of ids');
} else {
    $bad('parseRequestedIds accepts an array of ids');
}
if (KloxoPhpver::parseRequestedIds('') === [] && KloxoPhpver::parseRequestedIds(null) === []) {
    $ok('parseRequestedIds empty is none selected');
} else {
    $bad('parseRequestedIds empty is none selected');
}

$rem = KloxoPhpver::removableIds();
if (is_array($rem) && !in_array('php52m', $rem, true) && !in_array('--PHP Branch--', $rem, true)) {
    $ok('removableIds is installed phpX.Y not php52m');
} else {
    $bad('removableIds is installed phpX.Y not php52m');
}

$series = KloxoPhpver::installableSeries();
if (in_array('5.6', $series, true) && in_array('7.4', $series, true) && in_array('8.1', $series, true)
    && in_array('8.2', $series, true) && in_array('8.3', $series, true) && !in_array('5.2', $series, true)) {
    $ok('installableSeries matches install-site-php');
} else {
    $bad('installableSeries matches install-site-php');
}
if (KloxoPhpver::DEFAULT_EXTENSIONS !== [] && in_array('fpm', KloxoPhpver::DEFAULT_EXTENSIONS, true)
    && in_array('cli', KloxoPhpver::DEFAULT_EXTENSIONS, true) && in_array('common', KloxoPhpver::DEFAULT_EXTENSIONS, true)
    && in_array('sqlite3', KloxoPhpver::DEFAULT_EXTENSIONS, true)
    && str_contains(KloxoPhpver::SURY_URI, 'packages.sury.org/php')) {
    $ok('registry lists default extensions and the Sury URI');
} else {
    $bad('registry lists default extensions and the Sury URI');
}

$rows = KloxoPhpver::parseModListing(
    'php8.3',
    '/etc/php/8.3/mods-available',
    ['imap.ini', 'simplexml.ini', 'imagick.ini'],
    ['20-imap.ini', '20-simplexml.ini'],
    'localhost'
);
$by = [];
foreach ($rows as $r) {
    $by[$r['modulename']] = $r;
}
if (
    count($rows) === 3
    && ($by['imap']['status'] ?? '') === 'on'
    && ($by['simplexml']['status'] ?? '') === 'on'
    && ($by['imagick']['status'] ?? '') === 'off'
    && ($by['imap']['target'] ?? '') === 'php8.3'
    && ($by['imap']['type'] ?? '') === 'php-branch'
    && ($by['imap']['fullfile'] ?? '') === '/etc/php/8.3/mods-available/imap.ini'
    && ($by['imap']['nname'] ?? '') === 'php8.3-imap___localhost'
) {
    $ok('parseModListing matches Ubuntu mods-available');
} else {
    $bad('parseModListing matches Ubuntu mods-available');
}

$live = KloxoPhpver::distroModuleRows('localhost');
if (is_array($live)) {
    $ok('distroModuleRows returns a list');
} else {
    $bad('distroModuleRows returns a list');
}

echo $fail === 0 ? "phpver tests ok\n" : "phpver tests failed\n";
// ---- install availability (probes injected; nothing touches apt or the network)
KloxoPhpver::$aptProbe = static fn(string $v): bool => $v === '8.5';
KloxoPhpver::$suryProbe = static fn(): bool => false;
$a = KloxoPhpver::availableToInstall();
if (!in_array('php8.4', $a, true) && !in_array('php7.4', $a, true) && !in_array('php5.6', $a, true)) {
    $ok('no Sury for this release: versions apt cannot supply are not offered');
} else {
    $bad('no Sury for this release: versions apt cannot supply are not offered');
}
KloxoPhpver::$suryProbe = static fn(): bool => true;
$a = KloxoPhpver::availableToInstall();
if (in_array('php5.6', $a, true) && in_array('php7.4', $a, true) && in_array('php8.4', $a, true)
    && in_array('php8.1', $a, true) && count(array_diff($a, KloxoPhpver::catalogIds())) === 0) {
    $ok('Sury publishes for this release: catalog versions are offered');
} else {
    $bad('Sury publishes for this release: catalog versions are offered');
}
KloxoPhpver::$suryProbe = static fn(): bool => false;
$tmpStatus = sys_get_temp_dir() . '/kloxo-phpver-status-' . getmypid();
file_put_contents($tmpStatus, "1794031272|failed|php8.4 not installed: <b>no</b> packages\n");
$l = KloxoPhpver::lastInstall($tmpStatus);
if ($l !== null && $l['state'] === 'failed' && $l['at'] === 1794031272 && !str_contains($l['message'], '<')
    && str_contains(KloxoPhpver::installNote($tmpStatus), 'Last install attempt failed')) {
    $ok('last install status is parsed, cleaned and shown');
} else {
    $bad('last install status is parsed, cleaned and shown');
}
file_put_contents($tmpStatus, "garbage\n");
if (KloxoPhpver::lastInstall($tmpStatus) === null && KloxoPhpver::lastInstall($tmpStatus . '.none') === null) {
    $ok('a missing or malformed status file is ignored');
} else {
    $bad('a missing or malformed status file is ignored');
}
file_put_contents($tmpStatus, "1794031272|queued|install 8.4\n");
$q = KloxoPhpver::lastInstall($tmpStatus);
if ($q !== null && $q['state'] === 'queued' && str_contains(KloxoPhpver::installNote($tmpStatus), 'queued')) {
    $ok('queued status is parsed for the live progress UI');
} else {
    $bad('queued status is parsed for the live progress UI');
}
@unlink($tmpStatus);
KloxoPhpver::$aptProbe = null;
KloxoPhpver::$suryProbe = null;
KloxoPhpver::$ppaProbe = null;

KloxoPhpver::$euidProbe = 1000;
$notRoot = KloxoPhpver::queueInstall(['8.1']);
$rmNotRoot = KloxoPhpver::queueRemove(['php8.1']);
if (($notRoot['ok'] ?? true) === false && ($notRoot['error'] ?? '') === 'not root'
	&& ($rmNotRoot['ok'] ?? true) === false && ($rmNotRoot['error'] ?? '') === 'not root') {
	$ok('queueInstall/queueRemove refuse when not root');
} else {
	$bad('queueInstall/queueRemove refuse when not root');
}

$tmpRoot = sys_get_temp_dir() . '/kloxo-phpver-job-' . getmypid();
@mkdir($tmpRoot . '/bin/install', 0755, true);
@mkdir($tmpRoot . '/log', 0755, true);
file_put_contents($tmpRoot . '/bin/install/install-site-php', "#!/bin/bash\n");
$sysCalls = [];
KloxoPhpver::$euidProbe = 0;
KloxoPhpver::$programRootProbe = $tmpRoot;
KloxoPhpver::$runCmd = static function (array $argv) use (&$sysCalls): int {
	$sysCalls[] = $argv;

	return 0;
};
$queued = KloxoPhpver::queueInstall(['8.1', '8.1', 'nope']);
$jobFile = $tmpRoot . '/log/kloxo-site-php-install.sh';
$lockFile = $tmpRoot . '/log/kloxo-site-php-install.lock';
$jobBody = is_file($jobFile) ? (string) file_get_contents($jobFile) : '';
$sysBin = (string) ($sysCalls[0][0] ?? '');
$sysJob = (string) ($sysCalls[0][5] ?? '');
if (($queued['ok'] ?? false) === true
	&& is_file($jobFile) && is_file($lockFile)
	&& str_contains($jobBody, '--versions=' . escapeshellarg('8.1'))
	&& $sysBin === '/usr/bin/systemd-run' && $sysJob === $jobFile) {
	$ok('queueInstall as root writes a PrivateTmp-safe job and systemd-run');
} else {
	$bad('queueInstall as root writes a PrivateTmp-safe job and systemd-run');
}
$empty = KloxoPhpver::queueInstall([]);
if (($empty['ok'] ?? true) === false && ($empty['error'] ?? '') === 'no versions') {
	$ok('queueInstall rejects an empty version list');
} else {
	$bad('queueInstall rejects an empty version list');
}

$aptBin = $tmpRoot . '/fake-apt-get';
file_put_contents($aptBin, "#!/bin/sh\necho apt-ok\nexit 0\n");
@chmod($aptBin, 0755);
if (!defined('KLOXO_APT_TEST_BIN')) {
	define('KLOXO_APT_TEST_BIN', $aptBin);
}
require_once $root . '/kloxo/httpdocs/lib/php/kloxo_apt.php';
$removed = KloxoPhpver::removeVersions(['php8.1', '8.2']);
if (($removed['ok'] ?? false) === true
	&& isset($removed['packages'])
	&& $removed['packages'] === ['php8.1-fpm', 'php8.1-cli', 'php8.2-fpm', 'php8.2-cli']) {
	$ok('removeVersions as root apt-removes phpX.Y-fpm and phpX.Y-cli');
} else {
	$bad('removeVersions as root apt-removes phpX.Y-fpm and phpX.Y-cli');
}

@unlink($jobFile);
@unlink($lockFile);
$sysCalls = [];
$qrm = KloxoPhpver::queueRemove(['php8.1']);
$rmJob = $tmpRoot . '/log/kloxo-site-php-remove.sh';
$rmBody = is_file($rmJob) ? (string) file_get_contents($rmJob) : '';
if (($qrm['ok'] ?? false) === true
	&& str_contains($rmBody, 'purge')
	&& str_contains($rmBody, escapeshellarg('php8.1-fpm'))
	&& str_contains($rmBody, escapeshellarg('php8.1-cli'))
	&& ($sysCalls[0][0] ?? '') === '/usr/bin/systemd-run') {
	$ok('queueRemove as root writes a purge job and systemd-run');
} else {
	$bad('queueRemove as root writes a purge job and systemd-run');
}

KloxoPhpver::$euidProbe = 1000;
@unlink($tmpRoot . '/log/kloxo-site-php-remove.lock');
@unlink($tmpRoot . '/log/kloxo-site-php-install.lock');
$dropped = KloxoPhpver::dropRequest('install', ['8.2']);
$reqPath = $tmpRoot . '/log/sitephp.request';
$asUser = KloxoPhpver::runDroppedRequest();
if (($dropped['ok'] ?? false) === true && is_file($reqPath)
	&& str_contains((string) file_get_contents($reqPath), '"8.2"')
	&& ($asUser['ok'] ?? true) === false && ($asUser['error'] ?? '') === 'not root'
	&& is_file($reqPath)) {
	$ok('dropRequest writes a job the panel user cannot run');
} else {
	$bad('dropRequest writes a job the panel user cannot run');
}
KloxoPhpver::$euidProbe = 0;
$asRoot = KloxoPhpver::runDroppedRequest();
if (($asRoot['ok'] ?? false) === true && !is_file($reqPath)
	&& is_file($tmpRoot . '/log/kloxo-site-php-install.sh')) {
	$ok('runDroppedRequest as root consumes the request and queues install');
} else {
	$bad('runDroppedRequest as root consumes the request and queues install');
}

if (KloxoPhpver::normalizePhpId('8.4') === 'php8.4' && KloxoPhpver::normalizePhpId('php8.1') === 'php8.1'
	&& KloxoPhpver::normalizePhpId('nope') === '') {
	$ok('normalizePhpId accepts phpX.Y and X.Y');
} else {
	$bad('normalizePhpId accepts phpX.Y and X.Y');
}
$jobFile = $tmpRoot . '/log/sitephp.jobs';
KloxoPhpver::writeJobs([
	['id' => '8.4', 'state' => 'running', 'pct' => 40, 'action' => 'Installing php8.4-fpm'],
	['id' => 'php8.1', 'state' => 'queued', 'pct' => 5, 'action' => 'Waiting'],
], $jobFile);
$readJobs = KloxoPhpver::readJobs($jobFile);
if (isset($readJobs[0]['id'], $readJobs[1]['id'])
	&& $readJobs[0]['id'] === 'php8.4' && $readJobs[0]['pct'] === 40
	&& $readJobs[1]['id'] === 'php8.1' && $readJobs[1]['state'] === 'queued') {
	$ok('writeJobs/readJobs store per-version bars');
} else {
	$bad('writeJobs/readJobs store per-version bars');
}

$liveDir = $tmpRoot . '/liveprog';
@mkdir($liveDir . '/log', 0755, true);
file_put_contents($liveDir . '/log/install-site-php.status', "1794032000|running|installing 8.4\n");
file_put_contents($liveDir . '/log/sitephp.jobs', "php8.4|running|50|Installing php8.4-fpm\n");
file_put_contents($liveDir . '/log/kloxo-site-php-install.lock', (string) time());
$live = KloxoPhpver::liveProgress($liveDir);
if (($live['ok'] ?? false) === true && ($live['busy'] ?? false) === true
	&& ($live['state'] ?? '') === 'running'
	&& isset($live['jobs'][0]['id']) && $live['jobs'][0]['id'] === 'php8.4'
	&& (int) ($live['jobs'][0]['pct'] ?? 0) === 50
	&& is_array($live['install'] ?? null) && is_array($live['remove'] ?? null)) {
	$ok('liveProgress returns per-version jobs and current install/remove lists');
} else {
	$bad('liveProgress returns per-version jobs and current install/remove lists');
}
file_put_contents($liveDir . '/log/sitephp.request', json_encode(['op' => 'remove', 'ids' => ['php8.1']]) . "\n");
@unlink($liveDir . '/log/kloxo-site-php-install.lock');
$queuedLive = KloxoPhpver::liveProgress($liveDir);
if (($queuedLive['state'] ?? '') === 'queued' && ($queuedLive['op'] ?? '') === 'remove'
	&& ($queuedLive['busy'] ?? false) === true) {
	$ok('liveProgress reports a dropped remove request as queued');
} else {
	$bad('liveProgress reports a dropped remove request as queued');
}
@unlink($liveDir . '/log/sitephp.request');
file_put_contents($liveDir . '/log/install-site-php.status', "1794032100|ok|removed php8.1\n");
file_put_contents($liveDir . '/log/sitephp.jobs', "php8.1|queued|5|Waiting to start removal\n");
$doneLive = KloxoPhpver::liveProgress($liveDir);
if (($doneLive['busy'] ?? true) === false
	&& isset($doneLive['jobs'][0]['pct']) && (int) $doneLive['jobs'][0]['pct'] === 100
	&& ($doneLive['jobs'][0]['state'] ?? '') === 'ok'
	&& str_contains((string) ($doneLive['jobs'][0]['action'] ?? ''), 'Removed')) {
	$ok('liveProgress finishes a leftover queued bar after the job is done');
} else {
	$bad('liveProgress finishes a leftover queued bar after the job is done');
}
@unlink($liveDir . '/log/install-site-php.status');
@unlink($liveDir . '/log/sitephp.jobs');
@rmdir($liveDir . '/log');
@rmdir($liveDir);

$rmWrapLive = KloxoPhpver::sitePhpRemoveWrap(
	['php8.1-fpm', 'php8.1-cli'],
	$tmpRoot . '/log/install-site-php.log',
	$tmpRoot . '/log/kloxo-site-php-remove.lock',
	$tmpRoot . '/log/kloxo-site-php-remove.sh'
);
if (str_contains($rmWrapLive, 'set_job')
	&& str_contains($rmWrapLive, 'sitephp.jobs')
	&& str_contains($rmWrapLive, escapeshellarg('php8.1-fpm'))
	&& str_contains($rmWrapLive, 'stdbuf -oL -eL')) {
	$ok('remove wrapper updates per-version jobs and line-buffers apt');
} else {
	$bad('remove wrapper updates per-version jobs and line-buffers apt');
}

KloxoPhpver::$euidProbe = null;
KloxoPhpver::$programRootProbe = null;
KloxoPhpver::$runCmd = null;
@unlink($rmJob);
@unlink($tmpRoot . '/log/kloxo-site-php-remove.lock');
@unlink($tmpRoot . '/log/kloxo-site-php-install.sh');
@unlink($tmpRoot . '/log/kloxo-site-php-install.lock');
@unlink($tmpRoot . '/log/sitephp.request');
@unlink($tmpRoot . '/log/sitephp.request.running');
@unlink($tmpRoot . '/log/install-site-php.log');
@unlink($tmpRoot . '/log/install-site-php.status');
@unlink($tmpRoot . '/log/sitephp.jobs');
@unlink($tmpRoot . '/bin/install/install-site-php');
@unlink($aptBin);
@rmdir($tmpRoot . '/bin/install');
@rmdir($tmpRoot . '/bin');
@rmdir($tmpRoot . '/log');
@rmdir($tmpRoot);

exit($fail === 0 ? 0 : 1);
