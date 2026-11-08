<?php

declare(strict_types=1);

/**
 * Incremental access-log aggregator. Safe to run twice: log_cursor stores
 * inode + byte offset. The panel UI reads domain_daily / domain_monthly only.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoStatsAgg
{
    public function __construct(
        private readonly PDO $stats,
        private readonly PDO $panel,
        private readonly string $logRoot,
        private readonly int $now,
    ) {
    }

    public static function create(?int $now = null): self
    {
        return new self(
            KloxoStore::stats(true),
            KloxoStore::panel(true),
            KloxoStore::logDir() . '/domains',
            $now ?? time()
        );
    }

    /**
     * @return array{domains:int, bytes:int, requests:int, rolled:int}
     */
    public function run(): array
    {
        $totalBytes = 0;
        $totalReq = 0;
        $domains = 0;
        $names = $this->domainNames();
        foreach ($names as $id => $name) {
            $log = $this->logRoot . '/' . $name . '/access.log';
            $got = $this->ingestLog((int) $id, $log);
            $totalBytes += $got['bytes'];
            $totalReq += $got['requests'];
            $this->recordDisk((int) $id);
            $domains++;
        }
        $rolled = $this->rollupAndPrune();

        return [
            'domains' => $domains,
            'bytes' => $totalBytes,
            'requests' => $totalReq,
            'rolled' => $rolled,
        ];
    }

    /** @return array<int,string> id => name */
    private function domainNames(): array
    {
        $out = [];
        foreach ($this->panel->query('SELECT id, name FROM domains') ?: [] as $row) {
            $out[(int) $row['id']] = (string) $row['name'];
        }

        return $out;
    }

    /**
     * Combined log: host ident user [date] "req" status bytes ...
     *
     * @return array{bytes:int, requests:int}
     */
    private function ingestLog(int $domainId, string $path): array
    {
        $bytes = 0;
        $requests = 0;
        if (!is_file($path)) {
            return ['bytes' => 0, 'requests' => 0];
        }
        $st = stat($path);
        if ($st === false) {
            return ['bytes' => 0, 'requests' => 0];
        }
        $inode = (int) $st['ino'];
        $size = (int) $st['size'];
        $cur = $this->cursor($path);
        $offset = ($cur !== null && $cur['inode'] === $inode) ? $cur['offset'] : 0;
        if ($offset > $size) {
            $offset = 0;
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return ['bytes' => 0, 'requests' => 0];
        }
        if ($offset > 0) {
            fseek($fh, $offset);
        }
        $byDay = [];
        while (($line = fgets($fh)) !== false) {
            $parsed = $this->parseCombined($line);
            if ($parsed === null) {
                continue;
            }
            $day = $parsed['day'];
            if (!isset($byDay[$day])) {
                $byDay[$day] = ['bytes' => 0, 'requests' => 0];
            }
            $byDay[$day]['bytes'] += $parsed['bytes'];
            $byDay[$day]['requests']++;
            $bytes += $parsed['bytes'];
            $requests++;
        }
        $newOffset = ftell($fh);
        fclose($fh);
        foreach ($byDay as $day => $sum) {
            $this->upsertDaily($domainId, (string) $day, $sum['bytes'], $sum['requests']);
        }
        $this->saveCursor($path, $inode, $newOffset === false ? $size : (int) $newOffset);

        return ['bytes' => $bytes, 'requests' => $requests];
    }

    /** @return array{day:string, bytes:int}|null */
    public static function parseCombined(string $line): ?array
    {
        if (preg_match('/\[(\d{2})\/(\w{3})\/(\d{4}):/', $line, $d) !== 1) {
            return null;
        }
        $months = [
            'Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04',
            'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08',
            'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12',
        ];
        $mon = $months[$d[2]] ?? null;
        if ($mon === null) {
            return null;
        }
        $day = $d[3] . '-' . $mon . '-' . $d[1];
        $bytes = 0;
        if (preg_match('/"\s+(\d{3})\s+(\d+|-)\s/', $line, $b) === 1 && $b[2] !== '-') {
            $bytes = (int) $b[2];
        }

        return ['day' => $day, 'bytes' => $bytes];
    }

    private function upsertDaily(int $domainId, string $day, int $bytes, int $requests): void
    {
        $stmt = $this->stats->prepare(
            'INSERT INTO domain_daily (domain_id, day, bytes, requests, disk_bytes)
             VALUES (:id, :day, :bytes, :req, 0)
             ON CONFLICT(domain_id, day) DO UPDATE SET
                bytes = bytes + excluded.bytes,
                requests = requests + excluded.requests'
        );
        $stmt->execute([
            ':id' => $domainId,
            ':day' => $day,
            ':bytes' => $bytes,
            ':req' => $requests,
        ]);
    }

    private function recordDisk(int $domainId): void
    {
        $day = gmdate('Y-m-d', $this->now);
        $root = '';
        $q = $this->panel->prepare('SELECT document_root FROM domains WHERE id = :id');
        $q->execute([':id' => $domainId]);
        $root = (string) $q->fetchColumn();
        $disk = 0;
        if ($root !== '' && is_dir($root)) {
            $disk = $this->dirSize($root);
        }
        $stmt = $this->stats->prepare(
            'INSERT INTO domain_daily (domain_id, day, bytes, requests, disk_bytes)
             VALUES (:id, :day, 0, 0, :disk)
             ON CONFLICT(domain_id, day) DO UPDATE SET disk_bytes = excluded.disk_bytes'
        );
        $stmt->execute([':id' => $domainId, ':day' => $day, ':disk' => $disk]);
    }

    private function dirSize(string $dir): int
    {
        $n = 0;
        $it = @scandir($dir);
        if (!is_array($it)) {
            return 0;
        }
        foreach ($it as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $p = $dir . '/' . $name;
            if (is_dir($p)) {
                $n += $this->dirSize($p);
            } elseif (is_file($p)) {
                $n += (int) filesize($p);
            }
        }

        return $n;
    }

    /** Rows older than 12 months move into domain_monthly and are deleted. */
    private function rollupAndPrune(): int
    {
        $cut = gmdate('Y-m-d', strtotime('-12 months', $this->now) ?: $this->now);
        $sel = $this->stats->prepare(
            'SELECT domain_id, substr(day, 1, 7) AS month,
                    SUM(bytes) AS bytes, SUM(requests) AS requests, SUM(disk_bytes) AS disk_bytes
             FROM domain_daily WHERE day < :cut
             GROUP BY domain_id, substr(day, 1, 7)'
        );
        $sel->execute([':cut' => $cut]);
        $rows = $sel->fetchAll();
        $ins = $this->stats->prepare(
            'INSERT INTO domain_monthly (domain_id, month, bytes, requests, disk_bytes)
             VALUES (:id, :month, :bytes, :req, :disk)
             ON CONFLICT(domain_id, month) DO UPDATE SET
                bytes = bytes + excluded.bytes,
                requests = requests + excluded.requests,
                disk_bytes = MAX(disk_bytes, excluded.disk_bytes)'
        );
        $this->stats->beginTransaction();
        try {
            foreach ($rows as $r) {
                $ins->execute([
                    ':id' => $r['domain_id'],
                    ':month' => $r['month'],
                    ':bytes' => $r['bytes'],
                    ':req' => $r['requests'],
                    ':disk' => $r['disk_bytes'],
                ]);
            }
            $del = $this->stats->prepare('DELETE FROM domain_daily WHERE day < :cut');
            $del->execute([':cut' => $cut]);
            $this->stats->commit();
        } catch (Throwable $e) {
            $this->stats->rollBack();
            throw $e;
        }

        return count($rows);
    }

    /** @return array{inode:int, offset:int}|null */
    private function cursor(string $path): ?array
    {
        $stmt = $this->stats->prepare('SELECT inode, offset FROM log_cursor WHERE path = :p');
        $stmt->execute([':p' => $path]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return ['inode' => (int) $row['inode'], 'offset' => (int) $row['offset']];
    }

    private function saveCursor(string $path, int $inode, int $offset): void
    {
        $stmt = $this->stats->prepare(
            'INSERT INTO log_cursor (path, inode, offset) VALUES (:p, :i, :o)
             ON CONFLICT(path) DO UPDATE SET inode = excluded.inode, offset = excluded.offset'
        );
        $stmt->execute([':p' => $path, ':i' => $inode, ':o' => $offset]);
    }
}
