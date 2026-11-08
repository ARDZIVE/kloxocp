<?php

declare(strict_types=1);

/**
 * Nightly SQLite backup via VACUUM INTO (never raw cp: that can copy a torn
 * WAL snapshot). Keeps the last 7 files per database.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoSqliteBackup
{
    public const KEEP = 7;

    /**
     * @return list<string> new backup paths
     */
    public static function run(?string $day = null): array
    {
        $day = $day ?? gmdate('Ymd');
        if (preg_match('/^\d{8}$/', $day) !== 1) {
            throw new InvalidArgumentException('day must be YYYYMMDD');
        }
        KloxoStore::prepareDir(KloxoStore::backupDir());
        $out = [];
        foreach (['panel' => KloxoStore::panelPath(), 'stats' => KloxoStore::statsPath()] as $name => $src) {
            if (!is_file($src)) {
                continue;
            }
            $dest = KloxoStore::backupDir() . '/' . $name . '-' . $day . '.sqlite';
            // Fresh connection: VACUUM cannot run while the pooled handle still
            // has a statement open.
            $pdo = KloxoStore::connect($src, false, false);
            // VACUUM INTO takes a path literal (not a bound parameter on all SQLite builds).
            if (strpbrk($dest, "'\0") !== false) {
                throw new RuntimeException('unsafe backup path');
            }
            $pdo->exec("VACUUM INTO '" . $dest . "'");
            unset($pdo);
            KloxoStore::securePath($dest);
            $out[] = $dest;
            self::prune($name);
        }

        return $out;
    }

    private static function prune(string $name): void
    {
        $files = glob(KloxoStore::backupDir() . '/' . $name . '-*.sqlite') ?: [];
        rsort($files, SORT_STRING);
        foreach (array_slice($files, self::KEEP) as $old) {
            @unlink($old);
        }
    }
}
