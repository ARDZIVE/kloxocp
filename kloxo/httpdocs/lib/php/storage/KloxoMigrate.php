<?php

declare(strict_types=1);

/**
 * Versioned SQL migrations tracked with PRAGMA user_version (an integer in the
 * SQLite header). Files are NNN_name.sql; NNN becomes the new user_version.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoMigrate
{
    /**
     * @return list<string> applied file names
     */
    public static function apply(PDO $pdo, string $dir): array
    {
        if (!is_dir($dir)) {
            throw new RuntimeException("migrations directory missing: {$dir}");
        }
        $files = glob($dir . '/[0-9][0-9][0-9]_*.sql') ?: [];
        sort($files, SORT_STRING);
        $current = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
        $applied = [];
        foreach ($files as $file) {
            $base = basename($file);
            if (preg_match('/^(\d{3})_/', $base, $m) !== 1) {
                continue;
            }
            $ver = (int) $m[1];
            if ($ver <= $current) {
                continue;
            }
            $sql = (string) file_get_contents($file);
            $pdo->beginTransaction();
            try {
                self::runScript($pdo, $sql);
                $pdo->exec('PRAGMA user_version = ' . $ver);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw new RuntimeException("migration {$base} failed: " . $e->getMessage(), 0, $e);
            }
            $applied[] = $base;
            $current = $ver;
        }

        return $applied;
    }

    public static function panelDir(?string $root = null): string
    {
        return self::migrationsRoot($root) . '/panel';
    }

    public static function statsDir(?string $root = null): string
    {
        return self::migrationsRoot($root) . '/stats';
    }

    public static function migrationsRoot(?string $root = null): string
    {
        if (is_string($root) && $root !== '') {
            return rtrim($root, '/') . '/file/storage/migrations';
        }
        $env = getenv('KLOXO_ROOT');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/') . '/file/storage/migrations';
        }

        return '/opt/kloxo/file/storage/migrations';
    }

    public static function userVersion(PDO $pdo): int
    {
        return (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    }

    private static function runScript(PDO $pdo, string $sql): void
    {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        $buf = '';
        foreach (explode(';', $sql) as $chunk) {
            $buf .= $chunk;
            $stmt = trim($buf);
            if ($stmt === '') {
                $buf = '';
                continue;
            }
            $pdo->exec($stmt);
            $buf = '';
        }
    }
}
