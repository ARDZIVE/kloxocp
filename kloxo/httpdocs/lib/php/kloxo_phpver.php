<?php
declare(strict_types=1);

/**
 * Discover PHP versions the panel can list on Ubuntu (apt) and leftover
 * CentOS /opt/*m trees. The installable catalog and Sury repo probe live here
 * so version numbers are not scattered. Extra series are queued as root
 * via queueInstall / removeVersions (kloxo-backend GET; the panel user
 * cannot apt). Pools are KloxoPhpfpm.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoPhpver
{
    /** @var list<string> newest first; used only to detect what is already installed */
    private const DISTRO_SERIES = [
        '8.6', '8.5', '8.4', '8.3', '8.2', '8.1', '8.0', '7.4', '7.3', '7.2', '7.1', '7.0', '5.6',
    ];

    /**
     * Versions Webserver Configure / install-site-php may install.
     * Extra series come from packages.sury.org (not ppa:ondrej/php).
     *
     * @var list<string>
     */
    private const INSTALLABLE_SERIES = ['8.6', '8.5', '8.4', '8.3', '8.2', '8.1', '7.4', '5.6'];

    /** phpX.Y-* packages installed with each series (cli/fpm/common plus extensions). */
    public const DEFAULT_EXTENSIONS = [
        'cli', 'fpm', 'common', 'mysql', 'xml', 'mbstring', 'curl', 'zip', 'gd', 'intl', 'bcmath', 'opcache', 'soap',
    ];

    public const SURY_URI = 'https://packages.sury.org/php/';
    public const SURY_KEY_URL = 'https://packages.sury.org/php/apt.gpg';

    /** @return list<string> ids such as php8.3, php8.5, php73m */
    public static function installedIds(): array
    {
        $ids = [];
        foreach (self::installed() as $row) {
            $ids[] = $row['id'];
        }

        return $ids;
    }

    /** @return list<string> installed ids that have a php-fpmX.Y binary */
    public static function fpmIds(): array
    {
        $ids = [];
        foreach (self::installed() as $row) {
            if (($row['fpm'] ?? '') !== '') {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }

    /**
     * @return list<array{id:string,bin:string,fpm:string,kind:string}>
     */
    public static function installed(): array
    {
        $out = [];
        $seen = [];
        foreach (self::DISTRO_SERIES as $ver) {
            $cli = '/usr/bin/php' . $ver;
            $fpm = '/usr/sbin/php-fpm' . $ver;
            $fpmDir = '/etc/php/' . $ver . '/fpm';
            if (!self::isExec($cli) && !self::isExec($fpm) && !is_dir($fpmDir)) {
                continue;
            }
            $id = 'php' . $ver;
            $seen[$id] = true;
            $out[] = [
                'id' => $id,
                'bin' => self::isExec($cli) ? $cli : '',
                'fpm' => self::isExec($fpm) ? $fpm : '',
                'kind' => 'distro',
            ];
        }
        if (self::isExec('/usr/bin/php')) {
            $ver = self::cliMajorMinor('/usr/bin/php');
            $id = $ver !== '' ? 'php' . $ver : 'php';
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $out[] = [
                    'id' => $id,
                    'bin' => '/usr/bin/php',
                    'fpm' => '',
                    'kind' => 'distro',
                ];
            }
        }
        if (!self::isUbuntuHost()) {
            foreach (glob('/opt/*m/usr/bin/php') ?: [] as $bin) {
                if (!is_string($bin) || !self::isExec($bin)) {
                    continue;
                }
                $id = str_replace(['/opt/', '/usr/bin/php'], '', $bin);
                if ($id === '' || isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $out[] = [
                    'id' => $id,
                    'bin' => $bin,
                    'fpm' => '',
                    'kind' => 'optm',
                ];
            }
        }

        return $out;
    }

    /** @return list<string> php8.3, php7.4, php5.6, … that this page can install */
    public static function catalogIds(): array
    {
        $out = [];
        foreach (self::INSTALLABLE_SERIES as $ver) {
            $out[] = 'php' . $ver;
        }

        return $out;
    }

    /** @return list<string> X.Y series install-site-php accepts */
    public static function installableSeries(): array
    {
        return self::INSTALLABLE_SERIES;
    }

    /**
     * Test hooks (null in production): fn(string $series): bool for apt, fn(): bool for Sury.
     *
     * @var callable|null
     */
    public static $aptProbe = null;
    /** @var callable|null */
    public static $suryProbe = null;
    /** @var callable|null kept so older tests can inject a Sury answer */
    public static $ppaProbe = null;
    /** @var callable|null fn(list<string> $argv): int for update-alternatives */
    public static $alternativesRun = null;
    /** @var int|null test: pretend posix_geteuid() */
    public static $euidProbe = null;
    /** @var string|null test: pretend program root */
    public static $programRootProbe = null;
    /** @var callable|null fn(list<string> $argv): int for systemd-run */
    public static $runCmd = null;
    /** @var array<string,bool> */
    private static array $aptMemo = [];

    private static function programRoot(): string
    {
        if (is_string(self::$programRootProbe) && self::$programRootProbe !== '') {
            return rtrim(self::$programRootProbe, '/');
        }
        global $sgbl;
        if (isset($sgbl) && is_object($sgbl) && !empty($sgbl->__path_program_root)) {
            return rtrim((string) $sgbl->__path_program_root, '/');
        }

        return dirname(__DIR__, 3);
    }

    private static function effectiveUid(): int
    {
        if (self::$euidProbe !== null) {
            return (int) self::$euidProbe;
        }

        return function_exists('posix_geteuid') ? (int) posix_geteuid() : -1;
    }

    /** Ubuntu release codename (resolute, noble, ...), or ''. */
    public static function codename(): string
    {
        $f = '/etc/os-release';
        if (!is_readable($f)) {
            return '';
        }
        foreach (file($f, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (preg_match('/^VERSION_CODENAME="?([a-z]+)"?$/', $line, $m) === 1) {
                return $m[1];
            }
        }

        return '';
    }

    /** Does apt know a candidate for phpX.Y-fpm (distro or an enabled PPA)? */
    public static function aptHas(string $series): bool
    {
        if (preg_match('/^\d+\.\d+$/', $series) !== 1) {
            return false;
        }
        if (self::$aptProbe !== null) {
            return (bool) (self::$aptProbe)($series);
        }
        if (isset(self::$aptMemo[$series])) {
            return self::$aptMemo[$series];
        }
        $bin = '/usr/bin/apt-cache';
        $has = false;
        if (is_executable($bin)) {
            $p = @proc_open([$bin, 'policy', 'php' . $series . '-fpm'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
            if (is_resource($p)) {
                $out = (string) stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                proc_close($p);
                $has = preg_match('/Candidate:\s*(?!\(none\))\S+/', $out) === 1;
            }
        }
        self::$aptMemo[$series] = $has;

        return $has;
    }

    /**
     * Does packages.sury.org/php publish a Release file for this Ubuntu
     * codename? Cached a day in etc/conf/sitephp-sury.json.
     */
    public static function suryPublished(): bool
    {
        if (self::$suryProbe !== null) {
            return (bool) (self::$suryProbe)();
        }
        if (self::$ppaProbe !== null) {
            return (bool) (self::$ppaProbe)();
        }
        $c = self::codename();
        if ($c === '') {
            return false;
        }
        $cache = self::programRoot() . '/etc/conf/sitephp-sury.json';
        $old = null;
        if (is_file($cache)) {
            $j = json_decode((string) @file_get_contents($cache), true);
            if (is_array($j) && ($j['codename'] ?? '') === $c && isset($j['ok'], $j['at'])) {
                if (time() - (int) $j['at'] < 86400) {
                    return (bool) $j['ok'];
                }
                $old = (bool) $j['ok'];
            }
        }
        $url = self::SURY_URI . 'dists/' . rawurlencode($c) . '/Release';
        $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 5, 'ignore_errors' => true]]);
        $r = @file_get_contents($url, false, $ctx);
        $hdr = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);
        $reached = $r !== false || $hdr !== [];
        if (!$reached) {
            return $old ?? false;
        }
        $ok = isset($hdr[0]) && preg_match('~^HTTP/\S+\s+2\d\d~', (string) $hdr[0]) === 1;
        $dir = dirname($cache);
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($cache, json_encode(['codename' => $c, 'ok' => $ok, 'at' => time()]), LOCK_EX);
        }

        return $ok;
    }

    /** @deprecated use suryPublished(); kept as an alias for older tests */
    public static function ppaPublished(): bool
    {
        return self::suryPublished();
    }

    /** Can this series be installed here (distro/enabled repo, or Sury for this release)? */
    public static function installable(string $series): bool
    {
        return self::aptHas($series) || self::suryPublished();
    }

    /** @return list<string> catalog ids not yet installed and installable on this release */
    public static function availableToInstall(): array
    {
        $have = array_fill_keys(self::installedIds(), true);
        $out = [];
        foreach (self::catalogIds() as $id) {
            if (isset($have[$id])) {
                continue;
            }
            if (self::installable(self::idToSeries($id))) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /** @return array{at:int,state:string,message:string}|null last install-site-php run */
    public static function lastInstall(?string $file = null): ?array
    {
        $f = $file ?? (self::programRoot() . '/log/install-site-php.status');
        if (!is_file($f) || !is_readable($f)) {
            return null;
        }
        $line = trim((string) @file_get_contents($f));
        if (preg_match('/^(\d{9,11})\|(running|ok|partial|failed)\|(.*)$/', $line, $m) !== 1) {
            return null;
        }
        $msg = trim((string) preg_replace('/[^\x20-\x7e]/', '?', $m[3]));
        $msg = str_replace(['<', '>', '&', '"', "'"], '', $msg);

        return ['at' => (int) $m[1], 'state' => $m[2], 'message' => substr($msg, 0, 300)];
    }

    /**
     * Text for Multiple PHP Install: why nothing is offered, and how the last attempt went.
     */
    public static function installNote(?string $statusFile = null): string
    {
        $parts = [];
        $notInstalled = [];
        $have = array_fill_keys(self::installedIds(), true);
        foreach (self::catalogIds() as $id) {
            if (!isset($have[$id])) {
                $notInstalled[] = $id;
            }
        }
        if ($notInstalled !== [] && self::availableToInstall() === []) {
            $c = self::codename();
            $parts[] = 'The other versions (' . implode(', ', $notInstalled) . ') cannot be installed on this server'
                . ($c !== '' ? ' (Ubuntu ' . $c . ')' : '') . ': Ubuntu does not ship them and packages.sury.org/php has no packages for this release.';
        }
        $last = self::lastInstall($statusFile);
        if ($last !== null) {
            $label = ['running' => 'still running', 'ok' => 'finished', 'partial' => 'partly done', 'failed' => 'failed'][$last['state']];
            $parts[] = 'Last install attempt ' . $label . ' (' . gmdate('Y-m-d H:i', $last['at']) . ' UTC): ' . $last['message'] . '.';
        }

        return implode(' ', $parts);
    }

    /**
     * Paths for a queued install-site-php job.
     * Job/lock live under program log (not /tmp): kloxo-backend.service
     * sets PrivateTmp=true, so a /tmp script is invisible to systemd-run.
     *
     * @return array{root:string,script:string,logDir:string,job:string,lock:string,log:string}
     */
    public static function sitePhpInstallPaths(string $root): array
    {
        $root = rtrim($root, '/');
        $logDir = $root . '/log';

        return [
            'root' => $root,
            'script' => $root . '/bin/install/install-site-php',
            'logDir' => $logDir,
            'job' => $logDir . '/kloxo-site-php-install.sh',
            'lock' => $logDir . '/kloxo-site-php-install.lock',
            'log' => $logDir . '/install-site-php.log',
        ];
    }

    /**
     * Build the wrapper script body. trap uses a double-quoted body so
     * escapeshellarg() quotes stay valid (single-quoted trap bodies break).
     */
    public static function sitePhpInstallWrap(
        string $script,
        string $root,
        string $versArg,
        string $log,
        string $lock,
        string $job
    ): string {
        foreach ([$script, $root, $log, $lock, $job] as $p) {
            if ($p === '' || strpos($p, "\n") !== false || strpos($p, "\0") !== false) {
                return '';
            }
        }
        if (preg_match('/^[0-9.]+(,[0-9.]+)*$/', $versArg) !== 1) {
            return '';
        }

        return "#!/bin/bash\nset -euo pipefail\n"
            . 'trap "rm -f -- ' . escapeshellarg($lock) . ' ' . escapeshellarg($job) . '" EXIT' . "\n"
            . '/bin/bash ' . escapeshellarg($script)
            . ' --root=' . escapeshellarg($root)
            . ' --versions=' . escapeshellarg($versArg)
            . ' >>' . escapeshellarg($log) . " 2>&1\n";
    }

    public static function requestPath(): string
    {
        return self::programRoot() . '/log/sitephp.request';
    }

    /**
     * Panel (lxlabs) drops a validated job for root to run. kloxo-backend's
     * 30s loop and kloxo-watchdog pick this up when GET to 7776 cannot.
     *
     * @param mixed $payload install: list of X.Y; remove: list of phpX.Y
     * @return array{ok:bool,error?:string,path?:string}
     */
    public static function dropRequest(string $op, $payload): array
    {
        if ($op !== 'install' && $op !== 'remove') {
            return ['ok' => false, 'error' => 'bad op'];
        }
        $body = ['op' => $op, 'at' => time()];
        if ($op === 'install') {
            $vers = [];
            $allow = array_fill_keys(self::installableSeries(), true);
            if (is_array($payload)) {
                foreach ($payload as $s) {
                    $s = (string) $s;
                    if (preg_match('/^\d+\.\d+$/', $s) === 1 && isset($allow[$s])) {
                        $vers[] = $s;
                    }
                }
            }
            $vers = array_values(array_unique($vers));
            if ($vers === []) {
                return ['ok' => false, 'error' => 'no versions'];
            }
            $body['versions'] = $vers;
        } else {
            $built = self::packagesToRemove($payload);
            if (($built['error'] ?? '') !== '') {
                return ['ok' => false, 'error' => $built['error']];
            }
            if (!is_array($payload) || $payload === []) {
                return ['ok' => false, 'error' => 'no packages'];
            }
            $ids = [];
            foreach ($payload as $id) {
                $id = (string) $id;
                if (preg_match('/^php\d+\.\d+$/', $id) === 1) {
                    $ids[] = $id;
                } elseif (preg_match('/^\d+\.\d+$/', $id) === 1) {
                    $ids[] = 'php' . $id;
                }
            }
            $ids = array_values(array_unique($ids));
            if ($ids === []) {
                return ['ok' => false, 'error' => 'no packages'];
            }
            $body['ids'] = $ids;
        }
        $path = self::requestPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'error' => 'could not create ' . $dir];
        }
        $running = $path . '.running';
        foreach ([$path, $running] as $p) {
            if (is_file($p)) {
                $age = time() - (int) @filemtime($p);
                if ($age >= 0 && $age < 1800) {
                    return ['ok' => false, 'error' => 'other install running'];
                }
                @unlink($p);
            }
        }
        $tmp = $path . '.tmp';
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || @file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'could not write ' . $path];
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return ['ok' => false, 'error' => 'could not write ' . $path];
        }

        return ['ok' => true, 'path' => $path];
    }

    /**
     * Root: consume sitephp.request and queueInstall / queueRemove.
     *
     * @return array{ok:bool,euid?:int,error?:string,skipped?:bool}
     */
    public static function runDroppedRequest(): array
    {
        $euid = self::effectiveUid();
        if ($euid !== 0) {
            return ['ok' => false, 'euid' => $euid, 'error' => 'not root'];
        }
        $path = self::requestPath();
        $running = $path . '.running';
        if (is_file($running)) {
            $age = time() - (int) @filemtime($running);
            if ($age >= 0 && $age < 1800) {
                return ['ok' => false, 'euid' => 0, 'error' => 'other install running'];
            }
            @unlink($running);
        }
        if (!is_file($path)) {
            return ['ok' => true, 'euid' => 0, 'skipped' => true];
        }
        if (!@rename($path, $running)) {
            return ['ok' => false, 'euid' => 0, 'error' => 'could not claim request'];
        }
        $raw = (string) @file_get_contents($running);
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['op'])) {
            @unlink($running);

            return ['ok' => false, 'euid' => 0, 'error' => 'bad request'];
        }
        $op = (string) $data['op'];
        if ($op === 'install') {
            $got = self::queueInstall($data['versions'] ?? []);
        } elseif ($op === 'remove') {
            $got = self::queueRemove($data['ids'] ?? []);
        } else {
            @unlink($running);

            return ['ok' => false, 'euid' => 0, 'error' => 'bad op'];
        }
        @unlink($running);

        return $got;
    }

    /**
     * Queue install-site-php as root (kloxo-backend GET). Panel PHP-FPM is
     * lxlabs and cannot systemd-run or apt.
     *
     * @param mixed $vers list of X.Y series
     * @return array{ok:bool,euid?:int,error?:string}
     */
    public static function queueInstall($vers): array
    {
        $euid = self::effectiveUid();
        if ($euid !== 0) {
            return ['ok' => false, 'euid' => $euid, 'error' => 'not root'];
        }
        $clean = [];
        if (is_array($vers)) {
            $allow = array_fill_keys(self::installableSeries(), true);
            foreach ($vers as $s) {
                $s = (string) $s;
                if (preg_match('/^\d+\.\d+$/', $s) === 1 && isset($allow[$s])) {
                    $clean[] = $s;
                }
            }
        }
        $clean = array_values(array_unique($clean));
        if ($clean === []) {
            return ['ok' => false, 'euid' => 0, 'error' => 'no versions'];
        }
        $root = self::programRoot();
        if ($root === '' || strpos($root, '..') !== false || strpos($root, "\n") !== false) {
            return ['ok' => false, 'euid' => 0, 'error' => 'file_not_found:' . $root];
        }
        $paths = self::sitePhpInstallPaths($root);
        $script = $paths['script'];
        if (!is_file($script)) {
            return ['ok' => false, 'euid' => 0, 'error' => 'file_not_found:' . $script];
        }
        $versArg = implode(',', $clean);
        $job = $paths['job'];
        $lock = $paths['lock'];
        $log = $paths['log'];
        if (self::$programRootProbe === null) {
            @unlink('/tmp/kloxo-site-php-install.lock');
        }
        if (!is_dir($paths['logDir']) && !@mkdir($paths['logDir'], 0755, true) && !is_dir($paths['logDir'])) {
            return ['ok' => false, 'euid' => 0, 'error' => 'could not create ' . $paths['logDir']];
        }
        if (is_file($lock)) {
            $age = time() - (int) @filemtime($lock);
            if ($age >= 0 && $age < 1800) {
                return ['ok' => false, 'euid' => 0, 'error' => 'other install running'];
            }
            @unlink($lock);
            @unlink($job);
        }
        $wrap = self::sitePhpInstallWrap($script, $paths['root'], $versArg, $log, $lock, $job);
        if ($wrap === '' || @file_put_contents($job, $wrap) === false) {
            return ['ok' => false, 'euid' => 0, 'error' => 'could not write ' . $job];
        }
        @chmod($job, 0700);
        if (@file_put_contents($lock, (string) time()) === false) {
            @unlink($job);

            return ['ok' => false, 'euid' => 0, 'error' => 'could not write ' . $lock];
        }

        $run = self::startPhpJob($job);
        if (empty($run['ok'])) {
            @unlink($job);
            @unlink($lock);
        }

        return $run;
    }

    /**
     * Wrapper that purges phpX.Y-fpm and phpX.Y-cli (so the version leaves
     * Multiple PHP Remove). Package names must already be validated.
     *
     * @param list<string> $packages
     */
    public static function sitePhpRemoveWrap(array $packages, string $log, string $lock, string $job): string
    {
        $pkgs = [];
        foreach ($packages as $p) {
            $p = (string) $p;
            if (preg_match('/^php\d+\.\d+-(fpm|cli)$/', $p) !== 1) {
                return '';
            }
            $pkgs[] = $p;
        }
        $pkgs = array_values(array_unique($pkgs));
        if ($pkgs === []) {
            return '';
        }
        foreach ([$log, $lock, $job] as $p) {
            if ($p === '' || strpos($p, "\n") !== false || strpos($p, "\0") !== false) {
                return '';
            }
        }
        $cmd = '/usr/bin/apt-get -y -o DPkg::Lock::Timeout=300 -o Dpkg::Options::=--force-confold purge';
        foreach ($pkgs as $p) {
            $cmd .= ' ' . escapeshellarg($p);
        }

        return "#!/bin/bash\nset -euo pipefail\n"
            . "export DEBIAN_FRONTEND=noninteractive\n"
            . 'trap "rm -f -- ' . escapeshellarg($lock) . ' ' . escapeshellarg($job) . '" EXIT' . "\n"
            . $cmd . ' >>' . escapeshellarg($log) . " 2>&1\n";
    }

    /**
     * Queue apt purge of phpX.Y-fpm/cli as root (kloxo-backend GET).
     *
     * @param mixed $ids phpX.Y ids
     * @return array{ok:bool,euid?:int,error?:string}
     */
    public static function queueRemove($ids): array
    {
        $euid = self::effectiveUid();
        if ($euid !== 0) {
            return ['ok' => false, 'euid' => $euid, 'error' => 'not root'];
        }
        $built = self::packagesToRemove($ids);
        if (($built['error'] ?? '') !== '') {
            return ['ok' => false, 'euid' => 0, 'error' => $built['error']];
        }
        $pkgs = $built['packages'] ?? [];
        if ($pkgs === []) {
            return ['ok' => false, 'euid' => 0, 'error' => 'no packages'];
        }
        $root = self::programRoot();
        if ($root === '' || strpos($root, '..') !== false || strpos($root, "\n") !== false) {
            return ['ok' => false, 'euid' => 0, 'error' => 'file_not_found:' . $root];
        }
        $logDir = $root . '/log';
        $job = $logDir . '/kloxo-site-php-remove.sh';
        $lock = $logDir . '/kloxo-site-php-remove.lock';
        $log = $logDir . '/install-site-php.log';
        $installLock = $logDir . '/kloxo-site-php-install.lock';
        if (!is_dir($logDir) && !@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
            return ['ok' => false, 'euid' => 0, 'error' => 'could not create ' . $logDir];
        }
        foreach ([$lock, $installLock] as $l) {
            if (is_file($l)) {
                $age = time() - (int) @filemtime($l);
                if ($age >= 0 && $age < 1800) {
                    return ['ok' => false, 'euid' => 0, 'error' => 'other install running'];
                }
                @unlink($l);
            }
        }
        $wrap = self::sitePhpRemoveWrap($pkgs, $log, $lock, $job);
        if ($wrap === '' || @file_put_contents($job, $wrap) === false) {
            return ['ok' => false, 'euid' => 0, 'error' => 'could not write ' . $job];
        }
        @chmod($job, 0700);
        if (@file_put_contents($lock, (string) time()) === false) {
            @unlink($job);

            return ['ok' => false, 'euid' => 0, 'error' => 'could not write ' . $lock];
        }
        $run = self::startPhpJob($job);
        if (empty($run['ok'])) {
            @unlink($job);
            @unlink($lock);
        }

        return $run;
    }

    /**
     * phpX.Y-fpm and phpX.Y-cli for each requested id (so installed() drops it).
     *
     * @param mixed $ids
     * @return array{packages:list<string>,error:string}
     */
    public static function packagesToRemove($ids): array
    {
        $pkgs = [];
        $active = self::activeId();
        if (!is_array($ids)) {
            return ['packages' => [], 'error' => 'no packages'];
        }
        foreach ($ids as $v) {
            $id = (string) $v;
            if ($id === $active) {
                return ['packages' => [], 'error' => 'php already in use'];
            }
            $ver = self::idToSeries($id);
            if ($ver === '' && preg_match('/^\d+\.\d+$/', $id) === 1) {
                $ver = $id;
            }
            if ($ver !== '' && preg_match('/^\d+\.\d+$/', $ver) === 1) {
                $pkgs[] = 'php' . $ver . '-fpm';
                $pkgs[] = 'php' . $ver . '-cli';
            }
        }
        $pkgs = array_values(array_unique($pkgs));

        return ['packages' => $pkgs, 'error' => $pkgs === [] ? 'no packages' : ''];
    }

    /**
     * Remove phpX.Y-fpm as root (kloxo-backend GET). Panel PHP-FPM is lxlabs.
     *
     * @param mixed $ids phpX.Y ids
     * @return array{ok:bool,euid?:int,error?:string,packages?:list<string>}
     */
    public static function removeVersions($ids): array
    {
        $euid = self::effectiveUid();
        if ($euid !== 0) {
            return ['ok' => false, 'euid' => $euid, 'error' => 'not root'];
        }
        $built = self::packagesToRemove($ids);
        if (($built['error'] ?? '') !== '') {
            return ['ok' => false, 'euid' => 0, 'error' => $built['error']];
        }
        $pkgs = $built['packages'] ?? [];
        if ($pkgs === []) {
            return ['ok' => false, 'euid' => 0, 'error' => 'no packages'];
        }
        if (!class_exists('KloxoApt', false)) {
            $apt = __DIR__ . '/kloxo_apt.php';
            if (is_readable($apt)) {
                include_once $apt;
            }
        }
        if (!class_exists('KloxoApt', false)) {
            return ['ok' => false, 'euid' => 0, 'error' => 'KloxoApt missing'];
        }
        $r = KloxoApt::remove($pkgs);
        $r['euid'] = 0;
        $r['packages'] = $pkgs;

        return $r;
    }

    /**
     * Dual-listbox / select options: id is both key and label (not 0,1,2).
     *
     * @param list<string> $ids
     * @return array<string,string>
     */
    public static function asSelectList(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (!is_string($id) || $id === '') {
                continue;
            }
            $out[$id] = $id;
        }

        return $out;
    }

    /**
     * Installed phpX.Y ids the Multiple PHP Remove list can offer.
     *
     * @return list<string>
     */
    public static function removableIds(): array
    {
        $ids = [];
        foreach (self::installedIds() as $id) {
            if (self::isDistroId($id) && $id !== 'php') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Catalog phpX.Y ids from a dual-listbox POST (comma string or array).
     *
     * @param mixed $v
     * @return list<string>
     */
    public static function parseRequestedIds($v): array
    {
        $raw = [];
        if (is_array($v)) {
            foreach ($v as $p) {
                if (is_string($p) || is_numeric($p)) {
                    $raw[] = trim((string)$p);
                }
            }
        } elseif (is_string($v) && trim($v) !== '') {
            foreach (explode(',', str_replace(' ', '', $v)) as $p) {
                $raw[] = trim($p);
            }
        }
        $out = [];
        foreach ($raw as $p) {
            if ($p === '') {
                continue;
            }
            $id = '';
            if (preg_match('/^php(\d+\.\d+)$/', $p, $m) === 1) {
                $id = 'php' . $m[1];
            } elseif (preg_match('/^(\d+\.\d+)$/', $p, $m) === 1) {
                $id = 'php' . $m[1];
            }
            if ($id === '' || !self::isDistroId($id)) {
                continue;
            }
            $out[$id] = $id;
        }

        return array_values($out);
    }

    /** PHP Used dropdown: installed distro ids, never "--PHP Branch--". */
    public static function phpUsedChoices(): array
    {
        $ids = [];
        foreach (self::installedIds() as $id) {
            if (self::isDistroId($id)) {
                $ids[] = $id;
            }
        }
        $act = self::activeId();
        if ($ids === []) {
            return [$act];
        }
        if (!in_array($act, $ids, true)) {
            array_unshift($ids, $act);
        }

        return $ids;
    }

    /** Newest installed distro id (php8.5, php8.3, …), or php. */
    public static function defaultId(): string
    {
        $ids = self::installedIds();

        return $ids[0] ?? 'php';
    }

    public static function flagDirectory(): string
    {
        $a = dirname(__DIR__, 3) . '/etc/flag';
        if (is_dir($a)) {
            return $a;
        }
        if (is_dir('../etc/flag')) {
            return '../etc/flag';
        }

        return $a;
    }

    /** Server default from use_php*.flg, else defaultId(). */
    public static function activeId(): string
    {
        $dir = self::flagDirectory();
        foreach (glob($dir . '/use_php*.flg') ?: [] as $f) {
            if (!is_string($f)) {
                continue;
            }
            $id = substr(basename($f, '.flg'), 4);
            if (is_string($id) && self::isDistroId($id)) {
                return $id;
            }
        }

        return self::defaultId();
    }

    public static function setActiveId(string $id): bool
    {
        if ($id === '--PHP Branch--' || $id === '--PHP Used--' || str_contains(strtolower($id), 'php used')) {
            $id = self::defaultId();
        }
        if (!self::isDistroId($id)) {
            return false;
        }
        $dir = self::flagDirectory();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        foreach (glob($dir . '/use_php*.flg') ?: [] as $f) {
            if (is_string($f) && is_file($f)) {
                @unlink($f);
            }
        }
        $ok = @file_put_contents($dir . '/use_' . $id . '.flg', '') !== false;
        @touch($dir . '/enablemultiplephp.flg');

        return $ok;
    }

    public static function isDistroId(string $id): bool
    {
        return $id === 'php' || (bool)preg_match('/^php\d+\.\d+$/', $id);
    }

    /**
     * Ubuntu php-fpm module ini rows for the PHP Module Status list.
     *
     * @return list<array{nname:string,modulename:string,target:string,type:string,status:string,fullfile:string}>
     */
    public static function distroModuleRows(string $syncserver): array
    {
        $sync = preg_replace('/[^A-Za-z0-9._-]/', '', $syncserver);
        if ($sync === '') {
            $sync = 'localhost';
        }
        $rows = [];
        $seen = [];
        foreach (self::installed() as $php) {
            if ($php['kind'] !== 'distro') {
                continue;
            }
            $ver = self::idToSeries($php['id']);
            if ($ver === '') {
                continue;
            }
            $seen[$ver] = true;
            $rows = array_merge($rows, self::rowsForSeries($php['id'], $ver, $sync));
        }
        foreach (glob('/etc/php/*/mods-available', GLOB_ONLYDIR) ?: [] as $avail) {
            if (!is_string($avail)) {
                continue;
            }
            $ver = basename(dirname($avail));
            if (preg_match('/^\d+\.\d+$/', $ver) !== 1 || isset($seen[$ver])) {
                continue;
            }
            $seen[$ver] = true;
            $rows = array_merge($rows, self::rowsForSeries('php' . $ver, $ver, $sync));
        }

        return $rows;
    }

    /**
     * @return list<array{nname:string,modulename:string,target:string,type:string,status:string,fullfile:string}>
     */
    private static function rowsForSeries(string $target, string $series, string $sync): array
    {
        $avail = '/etc/php/' . $series . '/mods-available';
        if (!is_dir($avail)) {
            return [];
        }
        $enabled = self::enabledModNames($series);
        $rows = [];
        foreach (scandir($avail) ?: [] as $fn) {
            if (!is_string($fn) || !str_ends_with($fn, '.ini')) {
                continue;
            }
            $name = substr($fn, 0, -4);
            if ($name === '' || preg_match('/^[A-Za-z0-9._-]+$/', $name) !== 1) {
                continue;
            }
            $rows[] = [
                'nname' => $target . '-' . $name . '___' . $sync,
                'modulename' => $name,
                'target' => $target,
                'type' => 'php-branch',
                'status' => isset($enabled[$name]) ? 'on' : 'off',
                'fullfile' => $avail . '/' . $fn,
            ];
        }

        return $rows;
    }

    /**
     * Build the same rows from directory listings (for tests; no disk walk).
     *
     * @param list<string> $availableBasenames
     * @param list<string> $enabledBasenames
     * @return list<array{nname:string,modulename:string,target:string,type:string,status:string,fullfile:string}>
     */
    public static function parseModListing(string $target, string $availDir, array $availableBasenames, array $enabledBasenames, string $syncserver = 'localhost'): array
    {
        $on = [];
        foreach ($enabledBasenames as $b) {
            $n = self::iniBaseName($b);
            if ($n !== '') {
                $on[$n] = true;
            }
        }
        $rows = [];
        foreach ($availableBasenames as $b) {
            $name = self::iniBaseName($b);
            if ($name === '') {
                continue;
            }
            $rows[] = [
                'nname' => $target . '-' . $name . '___' . $syncserver,
                'modulename' => $name,
                'target' => $target,
                'type' => 'php-branch',
                'status' => isset($on[$name]) ? 'on' : 'off',
                'fullfile' => rtrim($availDir, '/') . '/' . $name . '.ini',
            ];
        }

        return $rows;
    }

    public static function idToSeries(string $id): string
    {
        if (preg_match('/^php(\d+\.\d+)$/', $id, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    /** @return array<string,true> */
    public static function enabledModNames(string $series): array
    {
        $on = [];
        foreach (['fpm', 'cli', 'cgi'] as $sapi) {
            $dir = '/etc/php/' . $series . '/' . $sapi . '/conf.d';
            if (!is_dir($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $fn) {
                $n = self::iniBaseName(is_string($fn) ? $fn : '');
                if ($n !== '') {
                    $on[$n] = true;
                }
            }
        }

        return $on;
    }

    public static function iniBaseName(string $file): string
    {
        $b = basename($file);
        if (!str_ends_with($b, '.ini')) {
            return '';
        }
        $b = substr($b, 0, -4);
        if (preg_match('/^\d+-([A-Za-z0-9._-]+)$/', $b, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^[A-Za-z0-9._-]+$/', $b) === 1) {
            return $b;
        }

        return '';
    }

    public static function cliMajorMinor(string $bin): string
    {
        if (!self::isExec($bin)) {
            return '';
        }
        $p = @proc_open([$bin, '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            return '';
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        $out = is_string($out) ? trim($out) : '';
        if (preg_match('/^\d+\.\d+$/', $out) !== 1) {
            return '';
        }

        return $out;
    }

    public static function isUbuntuHost(): bool
    {
        if (!class_exists('KloxoIpaddr', false)) {
            $f = __DIR__ . '/kloxo_ipaddr.php';
            if (is_readable($f)) {
                include_once $f;
            }
        }
        if (class_exists('KloxoIpaddr', false)) {
            return KloxoIpaddr::isUbuntu();
        }
        $t = is_readable('/etc/os-release') ? (string) @file_get_contents('/etc/os-release') : '';

        return (bool) preg_match('/^ID=ubuntu/m', $t);
    }

    public static function cliBin(string $id): string
    {
        $ver = self::idToSeries($id);
        if ($ver === '') {
            return '/usr/bin/php';
        }
        $p = '/usr/bin/php' . $ver;

        return self::isExec($p) ? $p : '/usr/bin/php';
    }

    public static function fpmBin(string $id): string
    {
        $ver = self::idToSeries($id);

        return $ver !== '' ? '/usr/sbin/php-fpm' . $ver : '';
    }

    public static function fpmUnit(string $id): string
    {
        $ver = self::idToSeries($id);

        return $ver !== '' ? 'php' . $ver . '-fpm' : '';
    }

    /**
     * Point Debian update-alternatives at this series (php, phar, php-config, phpize).
     * Missing binaries are skipped. Returns false only when the CLI binary itself is missing.
     */
    public static function setDefaultCli(string $id): bool
    {
        $ver = self::idToSeries($id);
        if ($ver === '') {
            return false;
        }
        $php = '/usr/bin/php' . $ver;
        if (!self::isExec($php) && self::$alternativesRun === null) {
            return false;
        }
        $map = [
            'php' => $php,
            'phar' => '/usr/bin/phar' . $ver,
            'phar.phar' => '/usr/bin/phar.phar' . $ver,
            'php-config' => '/usr/bin/php-config' . $ver,
            'phpize' => '/usr/bin/phpize' . $ver,
        ];
        $ok = true;
        foreach ($map as $name => $path) {
            if ($name !== 'php' && !self::isExec($path) && self::$alternativesRun === null) {
                continue;
            }
            if (self::runAlternatives(['--set', $name, $path]) !== 0 && $name === 'php') {
                $ok = false;
            }
        }

        return $ok;
    }

    /** @return array{ok:bool,euid?:int,error?:string} */
    private static function startPhpJob(string $job): array
    {
        $started = false;
        $systemd = '/usr/bin/systemd-run';
        $canSystemd = self::$runCmd !== null || is_executable($systemd);
        if ($canSystemd) {
            $code = self::runArgv([
                $systemd,
                '--no-block',
                '--collect',
                '--unit=kloxo-site-php-' . (string) getmypid() . '-' . (string) time(),
                '/bin/bash',
                $job,
            ]);
            $started = ($code === 0);
        }
        if (!$started) {
            if (function_exists('lxshell_background')) {
                lxshell_background('bash', $job);
            } else {
                return ['ok' => false, 'euid' => 0, 'error' => 'could not start job'];
            }
        }

        return ['ok' => true, 'euid' => 0];
    }

    /** @param list<string> $cmd */
    private static function runArgv(array $cmd): int
    {
        if (self::$runCmd !== null) {
            return (int) (self::$runCmd)($cmd);
        }
        if ($cmd === [] || !is_string($cmd[0]) || $cmd[0] === '') {
            return 127;
        }
        $bin = $cmd[0];
        if ($bin[0] !== '/') {
            return 127;
        }
        if (!is_executable($bin)) {
            return 127;
        }
        $p = @proc_open($cmd, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($p)) {
            return 127;
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($p);
    }

    /** @param list<string> $args */
    private static function runAlternatives(array $args): int
    {
        if (self::$alternativesRun !== null) {
            return (int) (self::$alternativesRun)($args);
        }
        $bin = '/usr/bin/update-alternatives';
        if (!is_executable($bin)) {
            $bin = '/usr/sbin/update-alternatives';
        }
        if (!is_executable($bin)) {
            return 127;
        }
        $argv = array_merge([$bin], $args);
        $p = @proc_open($argv, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($p)) {
            return 127;
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($p);
    }

    private static function isExec(string $path): bool
    {
        return $path !== '' && is_executable($path);
    }
}
