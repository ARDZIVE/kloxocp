<?php
declare(strict_types=1);

/**
 * Non-interactive apt-get install for Switch Program drivers (Ubuntu).
 * Argument-array process execution only; package names are validated.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoApt
{
    /** @return list<string> */
    public static function validPackages(array $packages): array
    {
        $out = [];
        foreach ($packages as $pkg) {
            $pkg = (string) $pkg;
            if ($pkg === '' || preg_match('/^[a-z0-9][a-z0-9.+_-]*$/', $pkg) !== 1) {
                throw new InvalidArgumentException('invalid_package_name');
            }
            $out[] = $pkg;
        }
        if ($out === []) {
            throw new InvalidArgumentException('invalid_package_name');
        }

        return $out;
    }

    public static function aptBinary(): string
    {
        if (defined('KLOXO_APT_TEST_BIN') && is_string(KLOXO_APT_TEST_BIN)
            && is_executable(KLOXO_APT_TEST_BIN)) {
            return KLOXO_APT_TEST_BIN;
        }
        foreach (['/usr/bin/apt-get', '/usr/sbin/apt-get'] as $p) {
            if (is_executable($p)) {
                return $p;
            }
        }

        return '';
    }

    /**
     * @param list<string> $argv
     * @return array{code:int, out:string}
     */
    private static function run(array $argv): array
    {
        $env = [
            'DEBIAN_FRONTEND' => 'noninteractive',
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'LC_ALL' => 'C',
        ];
        $proc = @proc_open(
            $argv,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        if (!is_resource($proc)) {
            return ['code' => 127, 'out' => 'could not start ' . $argv[0]];
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => proc_close($proc), 'out' => trim($out . "\n" . $err)];
    }

    /** Last meaningful line of apt output (E: ... preferred), single line, bounded. */
    public static function lastError(string $out): string
    {
        $lines = preg_split('/\R/', trim($out)) ?: [];
        $pick = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, 'E:')) {
                $pick = $line;
                break;
            }
            $pick = $line;
        }
        $pick = preg_replace('/[^\x20-\x7e]/', '', $pick) ?? '';

        return substr($pick, 0, 200);
    }

    /**
     * Install packages. Retries once after `apt-get update` (stale lists on
     * fresh images). Waits for the dpkg lock (unattended-upgrades).
     *
     * @param list<string> $packages
     * @return array{ok:bool, out:string, error:string}
     */
    public static function install(array $packages): array
    {
        $packages = self::validPackages($packages);
        $bin = self::aptBinary();
        if ($bin === '') {
            return ['ok' => false, 'out' => '', 'error' => 'apt-get not found'];
        }
        $base = [$bin, '-y', '-o', 'DPkg::Lock::Timeout=300', '-o', 'Dpkg::Options::=--force-confold'];
        $argv = array_merge($base, ['install'], $packages);
        $r = self::run($argv);
        if ($r['code'] !== 0) {
            self::run(array_merge($base, ['update']));
            $r = self::run($argv);
        }
        if ($r['code'] !== 0) {
            return ['ok' => false, 'out' => $r['out'], 'error' => self::lastError($r['out'])];
        }

        return ['ok' => true, 'out' => $r['out'], 'error' => ''];
    }

    /**
     * Remove packages (config kept). Best effort: removing something that is
     * not installed succeeds, and callers decide whether a failure matters.
     *
     * @param list<string> $packages
     * @return array{ok:bool, out:string, error:string}
     */
    public static function remove(array $packages): array
    {
        $packages = self::validPackages($packages);
        $bin = self::aptBinary();
        if ($bin === '') {
            return ['ok' => false, 'out' => '', 'error' => 'apt-get not found'];
        }
        $base = [$bin, '-y', '-o', 'DPkg::Lock::Timeout=300', '-o', 'Dpkg::Options::=--force-confold'];
        $r = self::run(array_merge($base, ['remove'], $packages));
        if ($r['code'] !== 0) {
            return ['ok' => false, 'out' => $r['out'], 'error' => self::lastError($r['out'])];
        }

        return ['ok' => true, 'out' => $r['out'], 'error' => ''];
    }

    /**
     * Install or throw lxException "Install failed: <packages>: <apt error>".
     *
     * @param list<string> $packages
     */
    public static function installOrThrow(array $packages): void
    {
        global $login;

        $r = self::install($packages);
        if ($r['ok']) {
            return;
        }
        $base = (is_object($login) && method_exists($login, 'getThrow'))
            ? $login->getThrow('install_failed')
            : 'Install failed';
        if (is_array($base)) {
            $base = (string) ($base[0] ?? 'Install failed');
        }
        $msg = $base . ': ' . implode(' ', $packages);
        if ($r['error'] !== '') {
            $msg .= ' (' . $r['error'] . ')';
        }

        throw new lxException($msg, '', implode(' ', $packages));
    }
}
