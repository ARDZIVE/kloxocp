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
    /** @var list<string> */
    private const DISTRO_SERIES = ['8.5', '8.4', '8.3', '8.2', '8.1'];

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
            if (!self::isExec($cli) && !self::isExec($fpm)) {
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
