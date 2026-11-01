<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/kloxo/httpdocs/lib/php/kloxo_phpver.php';

$fail = 0;
$ok = static function (string $d) { printf("  ok   %s\n", $d); };
$bad = static function (string $d) use (&$fail) { $fail++; printf("  FAIL %s\n", $d); };

if (KloxoPhpver::isDistroId('php8.3') && KloxoPhpver::isDistroId('php8.5') && KloxoPhpver::isDistroId('php')) {
    $ok('distro ids');
} else {
    $bad('distro ids');
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
exit($fail === 0 ? 0 : 1);
