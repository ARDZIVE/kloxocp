<?php

declare(strict_types=1);

/**
 * One PDO SQLite factory for panel.sqlite and stats.sqlite.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoStore
{
    public const PANEL_USER = 'lxlabs';
    public const BUSY_TIMEOUT_MS = 5000;

    /** @var array<string, PDO> */
    private static array $pool = [];

    public static function baseDir(): string
    {
        $env = getenv('KLOXO_VAR_LIB');
        if (is_string($env) && $env !== '' && !str_contains($env, "\0")) {
            return rtrim($env, '/');
        }

        return '/var/lib/kloxo';
    }

    public static function runDir(): string
    {
        $env = getenv('KLOXO_RUN_DIR');
        if (is_string($env) && $env !== '' && !str_contains($env, "\0")) {
            return rtrim($env, '/');
        }

        return '/var/run/kloxo';
    }

    public static function logDir(): string
    {
        $env = getenv('KLOXO_LOG_DIR');
        if (is_string($env) && $env !== '' && !str_contains($env, "\0")) {
            return rtrim($env, '/');
        }

        return '/var/log/kloxo';
    }

    public static function panelPath(): string
    {
        return self::baseDir() . '/panel.sqlite';
    }

    public static function statsPath(): string
    {
        return self::baseDir() . '/stats.sqlite';
    }

    public static function backupDir(): string
    {
        return self::baseDir() . '/backup';
    }

    public static function domainLogDir(string $domain): string
    {
        return self::logDir() . '/domains/' . $domain;
    }

    /** Panel config database. */
    public static function panel(bool $create = true): PDO
    {
        return self::connect(self::panelPath(), $create);
    }

    /** Stats database (never raw logs). */
    public static function stats(bool $create = true): PDO
    {
        return self::connect(self::statsPath(), $create);
    }

    public static function connect(string $path, bool $create = true, bool $reuse = true): PDO
    {
        if (str_contains($path, "\0") || str_contains($path, '..')) {
            throw new RuntimeException('invalid sqlite path');
        }
        if ($reuse && isset(self::$pool[$path]) && self::$pool[$path] instanceof PDO) {
            return self::$pool[$path];
        }
        if (!$create && !is_file($path)) {
            throw new RuntimeException("database {$path} does not exist");
        }
        self::prepareDir(dirname($path));
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::applyPragmas($pdo);
        self::securePath($path);
        if ($reuse) {
            self::$pool[$path] = $pdo;
        }

        return $pdo;
    }

    /**
     * journal_mode=WAL: readers do not block writers; -wal/-shm stay beside the
     * file (we chown them to the panel user so PHP-FPM and root can both write).
     * busy_timeout=5000: wait up to 5s on SQLITE_BUSY instead of failing at once.
     * foreign_keys=ON: SQLite defaults this off; ON DELETE CASCADE must actually run.
     * synchronous=NORMAL: durable enough with WAL, cheaper than FULL on every commit.
     */
    public static function applyPragmas(PDO $pdo): void
    {
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('IF', static fn ($c, $a, $b) => $c ? $a : $b, 3);
        }
    }

    public static function resetPool(): void
    {
        self::$pool = [];
    }

    public static function prepareDir(string $dir): void
    {
        if ($dir === '' || $dir === '/') {
            throw new RuntimeException('refusing to create filesystem root');
        }
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("cannot create {$dir}");
        }
        @chmod($dir, 0700);
        self::chownPanel($dir);
    }

    public static function securePath(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        @chmod($path, 0600);
        self::chownPanel($path);
        foreach ([$path . '-wal', $path . '-shm'] as $side) {
            if (is_file($side)) {
                @chmod($side, 0600);
                self::chownPanel($side);
            }
        }
    }

    private static function chownPanel(string $path): void
    {
        if (!function_exists('posix_getpwnam') || posix_geteuid() !== 0) {
            return;
        }
        $pw = posix_getpwnam(self::PANEL_USER);
        if (!is_array($pw)) {
            return;
        }
        @chown($path, (int) $pw['uid']);
        @chgrp($path, (int) $pw['gid']);
    }
}
