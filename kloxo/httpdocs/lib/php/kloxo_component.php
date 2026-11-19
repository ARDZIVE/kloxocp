<?php
declare(strict_types=1);

/**
 * Component Info rows for Ubuntu: apt packages plus source-built Hiawatha.
 * Status is installed vs not installed (same green/red meaning as Kloxo-MR).
 * Package aliases match what install.sh / Switch Program actually apt-install
 * (e.g. exim4-daemon-heavy, not the empty exim4 metapackage name alone).
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoComponent
{
    /** @return list<string> */
    private static function phpSeries(): array
    {
        $f = __DIR__ . '/kloxo_phpver.php';
        if (!class_exists('KloxoPhpver', false) && is_readable($f)) {
            include_once $f;
        }
        if (class_exists('KloxoPhpver', false)) {
            return KloxoPhpver::installableSeries();
        }

        return ['8.5', '8.4', '8.3', '8.2', '8.1', '7.4', '5.6'];
    }

    /**
     * Packages the panel manages. Optional stack peers (Postfix, Apache, Knot)
     * stay listed so Switch Program choices show on/off. Dead options
     * (postgresql, vsftpd, proftpd, mysql-server) are not listed.
     *
     * @return list<array{name:string,type:string,kind:string,packages?:list<string>}>
     */
    public static function catalog(): array
    {
        $rows = [
            ['name' => 'mariadb-server', 'type' => 'database', 'kind' => 'deb',
                'packages' => ['mariadb-server', 'mariadb-server-10.11', 'mariadb-server-11.4', 'mariadb-server-11.8']],
            ['name' => 'redis-server', 'type' => 'database', 'kind' => 'deb'],
            // Panel DB is SQLite; CLI package or the shared library counts.
            ['name' => 'sqlite3', 'type' => 'database', 'kind' => 'deb',
                'packages' => ['sqlite3', 'libsqlite3-0']],
            ['name' => 'bind9', 'type' => 'dns', 'kind' => 'deb'],
            ['name' => 'knot', 'type' => 'dns', 'kind' => 'deb'],
            ['name' => 'nginx', 'type' => 'web', 'kind' => 'deb'],
            ['name' => 'apache2', 'type' => 'web', 'kind' => 'deb'],
            ['name' => 'hiawatha', 'type' => 'web', 'kind' => 'hiawatha'],
            // install.sh / smtp__exim install exim4-daemon-heavy.
            ['name' => 'exim4', 'type' => 'smtp', 'kind' => 'deb',
                'packages' => ['exim4-daemon-heavy', 'exim4-daemon-light', 'exim4', 'exim4-base']],
            ['name' => 'postfix', 'type' => 'smtp', 'kind' => 'deb'],
            ['name' => 'dovecot-core', 'type' => 'pop', 'kind' => 'deb'],
            ['name' => 'dovecot-imapd', 'type' => 'pop', 'kind' => 'deb'],
            ['name' => 'dovecot-pop3d', 'type' => 'pop', 'kind' => 'deb'],
            ['name' => 'rspamd', 'type' => 'spam', 'kind' => 'deb'],
            ['name' => 'pure-ftpd', 'type' => 'ftp', 'kind' => 'deb'],
            ['name' => 'fail2ban', 'type' => 'security', 'kind' => 'deb'],
            ['name' => 'ufw', 'type' => 'security', 'kind' => 'deb'],
            ['name' => 'imagemagick', 'type' => 'utility', 'kind' => 'deb',
                'packages' => ['imagemagick', 'imagemagick-7.q16', 'imagemagick-6.q16']],
        ];
        foreach (self::phpSeries() as $ver) {
            $rows[] = ['name' => 'php' . $ver . '-cli', 'type' => 'php-branch', 'kind' => 'deb'];
            $rows[] = ['name' => 'php' . $ver . '-fpm', 'type' => 'php-branch', 'kind' => 'deb'];
        }

        return $rows;
    }

    /**
     * @return list<array{nname:string,componentname:string,version:string,status:string,type:string}>
     */
    public static function listVersion(string $syncserver): array
    {
        $sync = self::safeSync($syncserver);
        $deb = self::queryDebInstalled(self::debQueryNames());

        return self::rowsFromStatus($sync, $deb, self::hiawathaStatus());
    }

    /**
     * @param array<string, array{version:string, installed:bool}> $deb
     * @param array{version:string, installed:bool}|null $hiawatha
     * @return list<array{nname:string,componentname:string,version:string,status:string,type:string}>
     */
    public static function rowsFromStatus(string $syncserver, array $deb, ?array $hiawatha = null): array
    {
        $sync = self::safeSync($syncserver);
        $out = [];
        foreach (self::catalog() as $item) {
            $name = $item['name'];
            if ($item['kind'] === 'hiawatha') {
                $st = is_array($hiawatha) ? $hiawatha : ['version' => '', 'installed' => false];
                $pkgLabel = 'hiawatha';
            } else {
                $picked = self::pickInstalled($item, $deb);
                $st = $picked['status'];
                $pkgLabel = $picked['package'];
            }
            $on = !empty($st['installed']);
            $ver = trim((string) ($st['version'] ?? ''));
            $out[] = [
                'nname' => $name . '___' . $sync,
                'componentname' => $name,
                'version' => $on && $ver !== ''
                    ? $pkgLabel . '-' . $ver
                    : 'package ' . $name . ' is not installed',
                'status' => $on ? 'on' : 'off',
                'type' => $item['type'],
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return strcasecmp($a['componentname'], $b['componentname']);
        });

        return $out;
    }

    /**
     * @param array{name:string,type:string,kind:string,packages?:list<string>} $item
     * @param array<string, array{version:string, installed:bool}> $deb
     * @return array{package:string, status:array{version:string, installed:bool}}
     */
    public static function pickInstalled(array $item, array $deb): array
    {
        $name = (string) ($item['name'] ?? '');
        $cands = self::packagesFor($item);
        foreach ($cands as $pkg) {
            if (!empty($deb[$pkg]['installed'])) {
                return [
                    'package' => $pkg,
                    'status' => [
                        'version' => (string) ($deb[$pkg]['version'] ?? ''),
                        'installed' => true,
                    ],
                ];
            }
        }

        return [
            'package' => $name,
            'status' => ['version' => '', 'installed' => false],
        ];
    }

    /**
     * @param array{name?:string,packages?:list<string>} $item
     * @return list<string>
     */
    public static function packagesFor(array $item): array
    {
        $name = isset($item['name']) ? (string) $item['name'] : '';
        $pkgs = [];
        if (isset($item['packages']) && is_array($item['packages'])) {
            foreach ($item['packages'] as $p) {
                if (is_string($p) && $p !== '' && preg_match('/^[A-Za-z0-9.+-]+$/', $p) === 1) {
                    $pkgs[] = $p;
                }
            }
        }
        if ($pkgs === [] && $name !== '' && preg_match('/^[A-Za-z0-9.+-]+$/', $name) === 1) {
            $pkgs[] = $name;
        }

        return array_values(array_unique($pkgs));
    }

    /**
     * @return array<string, array{version:string, installed:bool}>
     */
    public static function parseQuery(string $out): array
    {
        $map = [];
        foreach (preg_split('/\R/', $out) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) < 3) {
                continue;
            }
            $name = $parts[0];
            if ($name === '' || preg_match('/^[A-Za-z0-9.+-]+$/', $name) !== 1) {
                continue;
            }
            $version = $parts[1];
            $st = strtolower($parts[2]);
            // dpkg db:Status-Status — installed is on; config-files / not-installed are off.
            $on = ($st === 'installed');
            $map[$name] = [
                'version' => $on ? $version : '',
                'installed' => $on,
            ];
        }

        return $map;
    }

    public static function parseHiawathaVersion(string $out): string
    {
        if (preg_match('/Hiawatha\s+v?([0-9]+(?:\.[0-9]+)*)/i', $out, $m) === 1) {
            return $m[1];
        }
        $line = trim($out);
        if ($line !== '' && strlen($line) < 80 && preg_match('/^[A-Za-z0-9 ._+-]+$/', $line) === 1) {
            return $line;
        }

        return '';
    }

    public static function detailedInfo(string $name): string
    {
        $pkg = self::packageFromNname($name);
        foreach (self::catalog() as $item) {
            if ($item['name'] !== $pkg) {
                continue;
            }
            if ($item['kind'] === 'hiawatha') {
                $st = self::hiawathaStatus();
                if (!$st['installed']) {
                    return "package hiawatha is not installed\n";
                }

                return "Package: hiawatha\nStatus: installed (panel binary)\nVersion: {$st['version']}\n"
                    . 'Binary: ' . self::hiawathaBinary() . "\n";
            }
            $deb = self::queryDebInstalled(self::packagesFor($item));
            $picked = self::pickInstalled($item, $deb);
            if (empty($picked['status']['installed'])) {
                $tried = implode(', ', self::packagesFor($item));

                return "package {$pkg} is not installed (checked: {$tried})\n";
            }
            $real = $picked['package'];
            $raw = self::run(['/usr/bin/dpkg-query', '-s', $real]);
            if (trim($raw) === '') {
                return "Package: {$real}\nStatus: installed\nVersion: {$picked['status']['version']}\n";
            }

            return $raw;
        }

        return "package {$pkg} is not installed\n";
    }

    public static function packageFromNname(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }
        if (str_contains($name, '___')) {
            $name = explode('___', $name, 2)[0];
        }
        if (preg_match('/^[A-Za-z0-9.+-]+$/', $name) !== 1) {
            return '';
        }

        return $name;
    }

    /** @return list<string> */
    private static function debQueryNames(): array
    {
        $names = [];
        foreach (self::catalog() as $item) {
            if (($item['kind'] ?? '') !== 'deb') {
                continue;
            }
            foreach (self::packagesFor($item) as $p) {
                $names[] = $p;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param list<string> $names
     * @return array<string, array{version:string, installed:bool}>
     */
    private static function queryDebInstalled(array $names): array
    {
        $bin = '/usr/bin/dpkg-query';
        if ($names === [] || !is_executable($bin)) {
            return [];
        }
        $fmt = '${Package}' . "\t" . '${Version}' . "\t" . '${db:Status-Status}' . "\n";
        $cmd = array_merge([$bin, '-W', '-f=' . $fmt], $names);

        return self::parseQuery(self::run($cmd));
    }

    /** @return array{version:string, installed:bool} */
    private static function hiawathaStatus(): array
    {
        $bin = self::hiawathaBinary();
        if ($bin === '') {
            return ['version' => '', 'installed' => false];
        }
        $ver = self::parseHiawathaVersion(self::run([$bin, '-v']));
        if ($ver === '') {
            $ver = '12.4';
        }

        return ['version' => $ver, 'installed' => true];
    }

    private static function hiawathaBinary(): string
    {
        $root = getenv('KLOXO_ROOT');
        $root = is_string($root) && $root !== '' ? rtrim($root, '/') : '/opt/kloxo';
        foreach ([$root . '/init/kloxo-hiawatha', '/usr/sbin/hiawatha', '/usr/local/sbin/hiawatha'] as $p) {
            if (is_executable($p)) {
                return $p;
            }
        }

        return '';
    }

    private static function safeSync(string $sync): string
    {
        $sync = preg_replace('/[^A-Za-z0-9._-]/', '', $sync) ?? '';

        return $sync === '' ? 'localhost' : $sync;
    }

    /** @param list<string> $cmd */
    private static function run(array $cmd): string
    {
        if ($cmd === [] || !is_executable($cmd[0])) {
            return '';
        }
        $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            return '';
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);

        return is_string($out) ? $out : '';
    }
}
