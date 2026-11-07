<?php
declare(strict_types=1);

/**
 * Discover PHP versions the panel can list on Ubuntu (apt) and leftover
 * CentOS /opt/*m trees. Does not install or switch PHP.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoPhpver
{
    /** @var list<string> newest first; 5.6 and 7.4 are EOL site runtimes */
    private const DISTRO_SERIES = [
        '8.6', '8.5', '8.4', '8.3', '8.2', '8.1', '8.0', '7.4', '7.3', '7.2', '7.1', '7.0', '5.6',
    ];

    /** Versions Webserver Configure can install (install-site-php / ondrej). */
    private const INSTALLABLE_SERIES = ['8.6', '8.5', '8.4', '8.3', '7.4', '5.6'];

    /** @return list<string> ids such as php8.3, php8.5, php73m */
    public static function installedIds(): array
    {
        $ids = [];
        foreach (self::installed() as $row) {
            $ids[] = $row['id'];
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
     * Test hooks (null in production): fn(string $series): bool for apt, fn(): bool for the PPA.
     *
     * @var callable|null
     */
    public static $aptProbe = null;
    /** @var callable|null */
    public static $ppaProbe = null;
    /** @var array<string,bool> */
    private static array $aptMemo = [];

    private static function programRoot(): string
    {
        return dirname(__DIR__, 3);
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
     * Does ppa:ondrej/php publish packages for this Ubuntu release? The answer is
     * cached for a day in etc/conf/sitephp-ppa.json (a new release often has none for weeks).
     */
    public static function ppaPublished(): bool
    {
        if (self::$ppaProbe !== null) {
            return (bool) (self::$ppaProbe)();
        }
        $c = self::codename();
        if ($c === '') {
            return false;
        }
        $cache = self::programRoot() . '/etc/conf/sitephp-ppa.json';
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
        $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 5, 'ignore_errors' => true]]);
        $r = @file_get_contents('https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/' . $c . '/Release', false, $ctx);
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

    /** Can this series be installed here (distro/enabled repo, or a PPA that serves this release)? */
    public static function installable(string $series): bool
    {
        return self::aptHas($series) || self::ppaPublished();
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
                . ($c !== '' ? ' (Ubuntu ' . $c . ')' : '') . ': Ubuntu does not ship them and ppa:ondrej/php has no packages for this release yet.';
        }
        $last = self::lastInstall($statusFile);
        if ($last !== null) {
            $label = ['running' => 'still running', 'ok' => 'finished', 'partial' => 'partly done', 'failed' => 'failed'][$last['state']];
            $parts[] = 'Last install attempt ' . $label . ' (' . gmdate('Y-m-d H:i', $last['at']) . ' UTC): ' . $last['message'] . '.';
        }

        return implode(' ', $parts);
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

    private static function isExec(string $path): bool
    {
        return $path !== '' && is_executable($path);
    }
}
