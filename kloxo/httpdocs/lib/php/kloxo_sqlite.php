<?php
declare(strict_types=1);

/**
 * The panel's own database is one SQLite file (etc/conf/kloxo.sqlite): the panel,
 * kloxo-backend and the CLI tools keep working with MariaDB, Nginx, Apache and every
 * other service stopped. MariaDB stays only for customer databases, mail accounts and
 * phpMyAdmin.
 *
 * This file is standalone (no legacy panel includes) so init-panel-db and the tests
 * can use it:
 *  - KloxoSqlite::open()            PDO connection with the pragmas the panel needs
 *  - KloxoSqlite::schemaStatements  the MySQL/MariaDB dump (db-structure-*.sql) as SQLite
 *  - KloxoSqlite::applyScript       run such a dump on a SQLite PDO (ALTER ... ADD COLUMN
 *                                   IF NOT EXISTS, CHANGE COLUMN IF EXISTS, MODIFY, AFTER)
 *  - KloxoSqlite::translateQuery    MySQL-isms of the panel's SQL strings
 *  - KloxoSqlite::copyFromMysql     one-time copy of an existing MariaDB panel database
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoSqlite
{
    /** Seconds a writer waits for another process (panel PHP, backend, CLI) to finish. */
    public const BUSY_TIMEOUT_MS = 30000;

    /** Table / column names the panel generates: never anything else in a statement. */
    public static function isIdent(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name) === 1;
    }

    /**
     * Open (and with $create, create) the panel database.
     *
     * journal_mode stays DELETE (not WAL): the file is used by the PHP-FPM user (lxlabs) and by
     * root (kloxo-backend, CLI); WAL's -wal/-shm files created by one of them would be
     * unwritable for the other. A rollback journal is created with the owner of the database
     * file and removed after every commit.
     */
    public static function open(string $path, bool $create = false): PDO
    {
        if (!$create && !is_file($path)) {
            throw new RuntimeException("panel database {$path} does not exist (run init-panel-db)");
        }
        if ($create) {
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new RuntimeException("cannot create {$dir}");
            }
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $pdo->exec('PRAGMA synchronous = FULL');
        // MariaDB's IF(cond, a, b) as used by the installer's UPDATE statements
        if (method_exists($pdo, 'sqliteCreateFunction')) {
            $pdo->sqliteCreateFunction('IF', static fn($c, $a, $b) => $c ? $a : $b, 3);
        }

        return $pdo;
    }

    /** Owner and mode of the database file: lxlabs (PHP-FPM) read/write, root via the group. */
    public static function secureFile(string $path, string $owner = 'lxlabs'): void
    {
        if (!is_file($path)) {
            return;
        }
        @chmod($path, 0660);
        $dir = dirname($path);
        if (function_exists('posix_getpwnam') && posix_getpwnam($owner) !== false) {
            @chown($path, $owner);
            @chgrp($path, $owner);
            // the rollback journal lives next to the database: the directory must be writable
            @chown($dir, $owner);
            @chgrp($dir, $owner);
            @chmod($dir, 0770);
        }
    }

    // ---------------------------------------------------------------- SQL dialect

    /**
     * Rewrite a panel SQL string for SQLite. Returns null for statements that have no meaning
     * there (MySQL column type changes: SQLite columns are untyped).
     */
    public static function translateQuery(string $sql): ?string
    {
        $t = ltrim($sql);
        if (preg_match('/^alter\s+table\s+\S+\s+(change|modify)\b/i', $t) === 1) {
            return null;
        }
        $t = self::convertLiterals($t);
        if (preg_match('/^insert\s+ignore\s+into\b/i', $t) === 1) {
            $t = (string) preg_replace('/^insert\s+ignore\s+into\b/i', 'insert or ignore into', $t);
        }

        return $t;
    }

    /**
     * The legacy panel builds SQL strings with MySQL escaping (\' \\ \n inside '...'). SQLite
     * has no backslash escapes: read each quoted literal the MySQL way and write it back with
     * doubled quotes, so the stored value is the one the code meant.
     */
    public static function convertLiterals(string $sql): string
    {
        if (strpos($sql, "'") === false) {
            return $sql;
        }
        static $map = ['0' => "\0", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1a", 'b' => "\x08"];
        $out = '';
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            $c = $sql[$i];
            if ($c === "`" || $c === '"') {
                $j = strpos($sql, $c, $i + 1);
                $j = $j === false ? $len - 1 : $j;
                $out .= substr($sql, $i, $j - $i + 1);
                $i = $j + 1;
                continue;
            }
            if ($c !== "'") {
                $out .= $c;
                $i++;
                continue;
            }
            $val = '';
            $i++;
            while ($i < $len) {
                $d = $sql[$i];
                if ($d === '\\' && $i + 1 < $len) {
                    $n = $sql[$i + 1];
                    if (isset($map[$n])) {
                        $val .= $map[$n];
                    } elseif ($n === '%' || $n === '_') {
                        $val .= '\\' . $n;
                    } else {
                        $val .= $n;
                    }
                    $i += 2;
                    continue;
                }
                if ($d === "'") {
                    if ($i + 1 < $len && $sql[$i + 1] === "'") {
                        $val .= "'";
                        $i += 2;
                        continue;
                    }
                    $i++;
                    break;
                }
                $val .= $d;
                $i++;
            }
            $out .= "'" . str_replace("'", "''", $val) . "'";
        }

        return $out;
    }

    // ---------------------------------------------------------------- schema

    /** @return list<string> statements of a MySQL dump, comments and session statements removed */
    public static function splitStatements(string $sql): array
    {
        $sql = (string) preg_replace('~/\*.*?\*/~s', '', $sql);
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);
        $out = [];
        $buf = '';
        $quote = '';
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($quote !== '') {
                $buf .= $c;
                if ($c === '\\' && $quote === "'" && $i + 1 < $len) {
                    $buf .= $sql[++$i];
                } elseif ($c === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $buf .= $c;
                continue;
            }
            if ($c === ';') {
                $s = trim($buf);
                if ($s !== '') {
                    $out[] = $s;
                }
                $buf = '';
                continue;
            }
            $buf .= $c;
        }
        $s = trim($buf);
        if ($s !== '') {
            $out[] = $s;
        }

        return array_values(array_filter($out, static function (string $s): bool {
            return preg_match('/^(CREATE\s+DATABASE|USE\s+|SET\s+)/i', $s) !== 1;
        }));
    }

    /** MySQL column type to a SQLite declaration (case-insensitive text like MySQL's default collation). */
    private static function columnType(string $type): string
    {
        $t = strtolower(trim($type));
        if (preg_match('/^(tiny|small|medium|big)?int(\(\d+\))?(\s+unsigned)?$/', $t) === 1) {
            return 'integer';
        }
        if (preg_match('/^(var)?char(\(\d+\))?$/', $t) === 1) {
            return $t . ' COLLATE NOCASE';
        }
        if (preg_match('/^(tiny|medium|long)?text$/', $t) === 1) {
            return 'text COLLATE NOCASE';
        }

        return $t;
    }

    /**
     * One MySQL column definition (backticks, DEFAULT NULL, AFTER x ...) as SQLite.
     */
    private static function columnDefinition(string $def): string
    {
        $def = trim($def);
        if (preg_match('/^`?([A-Za-z0-9_]+)`?\s+([A-Za-z]+(?:\(\d+(?:,\d+)?\))?(?:\s+unsigned)?)\s*(.*)$/is', $def, $m) !== 1) {
            throw new InvalidArgumentException('cannot parse column: ' . $def);
        }
        $name = $m[1];
        $type = self::columnType($m[2]);
        $rest = (string) $m[3];
        $rest = (string) preg_replace('/\bAFTER\s+`?[A-Za-z0-9_]+`?/i', '', $rest);
        $rest = (string) preg_replace('/\bAUTO_INCREMENT\b/i', '', $rest);
        $rest = (string) preg_replace('/\bCOMMENT\s+\'(?:[^\'\\\\]|\\\\.)*\'/i', '', $rest);
        $rest = (string) preg_replace('/\b(CHARACTER SET|CHARSET)\s+\w+/i', '', $rest);
        $rest = (string) preg_replace('/\bCOLLATE\s+\w+/i', '', $rest);
        // a bare NULL constraint before DEFAULT is allowed by MySQL, noise for us
        $rest = (string) preg_replace('/(^|\s)NULL(\s+DEFAULT\b)/i', '$1$2', $rest);
        $rest = trim((string) preg_replace('/\s+/', ' ', $rest));

        return $name . ' ' . $type . ($rest !== '' ? ' ' . $rest : '');
    }

    /**
     * CREATE TABLE of a MySQL dump as SQLite statements: the table, then one CREATE INDEX per
     * KEY (SQLite index names are database-wide, so unnamed keys are named after the table).
     *
     * @return list<string>
     */
    public static function createTable(string $stmt): array
    {
        if (preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s*\((.*)\)\s*[^()]*$/is', $stmt, $m) !== 1) {
            throw new InvalidArgumentException('cannot parse CREATE TABLE: ' . substr($stmt, 0, 80));
        }
        $table = $m[1];
        $defs = [];
        $indexes = [];
        // definitions are separated by commas outside parentheses
        $depth = 0;
        $cur = '';
        $parts = [];
        foreach (str_split($m[2]) as $c) {
            if ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            }
            if ($c === ',' && $depth === 0) {
                $parts[] = $cur;
                $cur = '';
                continue;
            }
            $cur .= $c;
        }
        $parts[] = $cur;
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (preg_match('/^PRIMARY\s+KEY\s*\((.*)\)$/is', $part, $k) === 1) {
                $defs[] = 'PRIMARY KEY (' . str_replace('`', '', $k[1]) . ')';
            } elseif (preg_match('/^(UNIQUE\s+)?(?:KEY|INDEX)\s*(?:`?([A-Za-z0-9_]+)`?)?\s*\((.*)\)$/is', $part, $k) === 1) {
                $cols = array_map(static fn(string $c): string => preg_replace('/\(\d+\)$/', '', trim(str_replace('`', '', $c))) ?? '', explode(',', $k[3]));
                $name = $k[2] !== '' ? $k[2] : 'idx_' . $table . '_' . implode('_', $cols);
                $indexes[] = 'CREATE ' . (trim($k[1]) !== '' ? 'UNIQUE ' : '') . 'INDEX IF NOT EXISTS ' . $name
                    . ' ON ' . $table . ' (' . implode(', ', $cols) . ')';
            } else {
                $defs[] = self::columnDefinition($part);
            }
        }

        return array_merge(['CREATE TABLE IF NOT EXISTS ' . $table . ' (' . implode(', ', $defs) . ')'], $indexes);
    }

    /**
     * Schema statements of a MySQL dump for SQLite (CREATE TABLE only).
     *
     * @return list<string>
     */
    public static function schemaStatements(string $mysqlDump): array
    {
        $out = [];
        foreach (self::splitStatements($mysqlDump) as $stmt) {
            if (preg_match('/^\s*CREATE\s+TABLE\b/i', $stmt) === 1) {
                foreach (self::createTable($stmt) as $s) {
                    $out[] = $s;
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    public static function columns(PDO $pdo, string $table): array
    {
        if (!self::isIdent($table)) {
            return [];
        }
        $cols = [];
        foreach ($pdo->query('PRAGMA table_info(' . $table . ')') ?: [] as $row) {
            $cols[] = (string) $row['name'];
        }

        return $cols;
    }

    /** @return list<string> */
    public static function tables(PDO $pdo): array
    {
        $out = [];
        foreach ($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") ?: [] as $row) {
            $out[] = (string) $row['name'];
        }

        return $out;
    }

    /**
     * Run a MySQL dump (db-structure-base.sql / db-structure-update.sql) on SQLite. Statements
     * MariaDB understood and SQLite does not are applied by hand:
     *   ALTER TABLE t ADD COLUMN [IF NOT EXISTS] c type [AFTER x]   add the column when missing
     *   ALTER TABLE t CHANGE COLUMN [IF EXISTS] old new type         rename when old exists and differs
     *   ALTER TABLE t MODIFY COLUMN ...                              nothing (SQLite columns are untyped)
     * Returns the error messages (empty = all good). Statements are independent: one failing
     * does not stop the others.
     *
     * @return list<string>
     */
    public static function applyScript(PDO $pdo, string $mysqlDump): array
    {
        $errors = [];
        foreach (self::splitStatements($mysqlDump) as $stmt) {
            try {
                self::applyStatement($pdo, $stmt);
            } catch (Throwable $e) {
                $errors[] = $e->getMessage() . ' [' . preg_replace('/\s+/', ' ', substr($stmt, 0, 90)) . ']';
            }
        }

        return $errors;
    }

    private static function applyStatement(PDO $pdo, string $stmt): void
    {
        if (preg_match('/^\s*CREATE\s+TABLE\b/i', $stmt) === 1) {
            foreach (self::createTable($stmt) as $s) {
                $pdo->exec($s);
            }

            return;
        }
        if (preg_match('/^\s*ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+(.*)$/is', $stmt, $m) === 1) {
            $table = $m[1];
            $rest = trim($m[2]);
            if (preg_match('/^ADD\s+(?:COLUMN\s+)?(IF\s+NOT\s+EXISTS\s+)?(.*)$/is', $rest, $a) === 1) {
                $def = self::columnDefinition($a[2]);
                $name = explode(' ', $def, 2)[0];
                if (!in_array($name, self::columns($pdo, $table), true)) {
                    $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $def);
                }

                return;
            }
            if (preg_match('/^CHANGE\s+(?:COLUMN\s+)?(?:IF\s+EXISTS\s+)?`?([A-Za-z0-9_]+)`?\s+`?([A-Za-z0-9_]+)`?\s/is', $rest . ' ', $c) === 1) {
                $cols = self::columns($pdo, $table);
                if ($c[1] !== $c[2] && in_array($c[1], $cols, true) && !in_array($c[2], $cols, true)) {
                    $pdo->exec('ALTER TABLE ' . $table . ' RENAME COLUMN ' . $c[1] . ' TO ' . $c[2]);
                }

                return;
            }
            if (preg_match('/^MODIFY\b/i', $rest) === 1) {
                return;
            }
            throw new InvalidArgumentException('unsupported ALTER TABLE');
        }
        $sql = self::translateQuery($stmt);
        if ($sql !== null) {
            $pdo->exec($sql);
        }
    }

    // ---------------------------------------------------------------- migration

    /**
     * Copy every table of an existing MariaDB panel database into this SQLite database
     * (tables created by schemaStatements first). Rows already present are kept (nname is the
     * key). Returns table => copied rows.
     *
     * @return array<string,int>
     */
    public static function copyFromMysql(PDO $mysql, PDO $sqlite): array
    {
        $done = [];
        $known = self::tables($sqlite);
        foreach ($mysql->query('SHOW TABLES') ?: [] as $row) {
            $table = (string) array_values($row)[0];
            if (!self::isIdent($table)) {
                continue;
            }
            if (!in_array($table, $known, true)) {
                continue;   // a table the new schema does not know (old Kloxo leftovers): not copied
            }
            $cols = array_values(array_intersect(self::columns($sqlite, $table), self::mysqlColumns($mysql, $table)));
            if ($cols === []) {
                continue;
            }
            $sel = $mysql->query('SELECT ' . implode(',', array_map(static fn($c) => '`' . $c . '`', $cols)) . ' FROM `' . $table . '`');
            $ins = $sqlite->prepare('INSERT OR IGNORE INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')');
            $n = 0;
            $sqlite->beginTransaction();
            try {
                foreach ($sel as $r) {
                    if (($r['nname'] ?? '') === '__dummy__dummy__') {
                        continue;
                    }
                    $ins->execute(array_map(static fn($c) => $r[$c], $cols));
                    $n++;
                }
                $sqlite->commit();
            } catch (Throwable $e) {
                $sqlite->rollBack();
                throw $e;
            }
            $done[$table] = $n;
        }

        return $done;
    }

    /** @return list<string> */
    private static function mysqlColumns(PDO $mysql, string $table): array
    {
        $out = [];
        foreach ($mysql->query('SHOW COLUMNS FROM `' . $table . '`') ?: [] as $r) {
            $out[] = (string) $r['Field'];
        }

        return $out;
    }
}
