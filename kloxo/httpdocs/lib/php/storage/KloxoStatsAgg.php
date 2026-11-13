<?php

declare(strict_types=1);

/**
 * Incremental access-log aggregator. Safe to run twice: log_cursor stores
 * inode + byte offset. The panel UI reads aggregated tables only — never raw
 * logs. Discovers domains from the modern `domains` table, the legacy
 * `domain` table, and log directories.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoStatsAgg
{
    private const PATH_CAP = 80;
    private const REF_CAP = 40;
    private const VISITOR_RETENTION_DAYS = 40;

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
            $got = ['bytes' => 0, 'requests' => 0];
            foreach ($this->logPathsFor($name) as $log) {
                $part = $this->ingestLog((int) $id, $log);
                $got['bytes'] += $part['bytes'];
                $got['requests'] += $part['requests'];
            }
            $totalBytes += $got['bytes'];
            $totalReq += $got['requests'];
            $this->recordDisk((int) $id, $name);
            $domains++;
        }
        $rolled = $this->rollupAndPrune();
        $this->pruneVisitors();

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
        if ($this->tableExists($this->panel, 'domains')) {
            foreach ($this->panel->query('SELECT id, name FROM domains') ?: [] as $row) {
                $name = strtolower(trim((string) $row['name']));
                if ($name !== '') {
                    $out[(int) $row['id']] = $name;
                }
            }
        }
        if ($this->tableExists($this->panel, 'domain')) {
            foreach ($this->panel->query('SELECT nname FROM domain') ?: [] as $row) {
                $name = strtolower(trim((string) $row['nname']));
                if ($name === '' || in_array($name, $out, true)) {
                    continue;
                }
                $out[$this->identityId($name)] = $name;
            }
        }
        foreach ($this->scanLogDirs() as $name) {
            if (in_array($name, $out, true)) {
                continue;
            }
            $out[$this->identityId($name)] = $name;
        }

        return $out;
    }

    /** @return list<string> */
    private function scanLogDirs(): array
    {
        $out = [];
        if (!is_dir($this->logRoot)) {
            return $out;
        }
        $it = @scandir($this->logRoot);
        if (!is_array($it)) {
            return $out;
        }
        foreach ($it as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $name = strtolower($name);
            if ($name === '' || !is_dir($this->logRoot . '/' . $name)) {
                continue;
            }
            if (preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $name) !== 1) {
                continue;
            }
            $out[] = $name;
        }

        return $out;
    }

    /** @return list<string> existing log file paths for a domain */
    private function logPathsFor(string $name): array
    {
        $candidates = [
            $this->logRoot . '/' . $name . '/access.log',
            '/home/httpd/' . $name . '/stats/' . $name . '-custom_log',
            '/home/kloxo/httpd/' . $name . '/stats/' . $name . '-custom_log',
        ];
        $out = [];
        foreach ($candidates as $p) {
            if (is_file($p) && is_readable($p)) {
                $out[] = $p;
            }
        }

        return $out;
    }

    private function identityId(string $name): int
    {
        $sel = $this->stats->prepare('SELECT id FROM domain_identity WHERE name = :n');
        $sel->execute([':n' => $name]);
        $id = $sel->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        // High ids avoid colliding with small panel domains.id values in mixed DBs.
        $this->stats->prepare(
            'INSERT INTO domain_identity (name) VALUES (:n)'
        )->execute([':n' => $name]);
        $newId = (int) $this->stats->lastInsertId();
        if ($newId < 1000000) {
            // Remap into a high range once, then update PK is awkward — use offset ids.
            // Prefer: delete and reinsert with explicit id.
            $want = 1000000 + $newId;
            $this->stats->prepare('DELETE FROM domain_identity WHERE id = :i')->execute([':i' => $newId]);
            $this->stats->prepare(
                'INSERT INTO domain_identity (id, name) VALUES (:i, :n)'
            )->execute([':i' => $want, ':n' => $name]);

            return $want;
        }

        return $newId;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $st = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :n LIMIT 1"
        );
        $st->execute([':n' => $table]);

        return (bool) $st->fetchColumn();
    }

    /**
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
        $paths = [];
        $refs = [];
        $status = [];
        $ua = [];
        $visitors = [];

        while (($line = fgets($fh)) !== false) {
            $parsed = self::parseCombined($line);
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

            $p = $parsed['path'];
            if ($p !== '') {
                if (!isset($paths[$day][$p])) {
                    $paths[$day][$p] = ['hits' => 0, 'bytes' => 0];
                }
                $paths[$day][$p]['hits']++;
                $paths[$day][$p]['bytes'] += $parsed['bytes'];
            }
            $host = $parsed['ref_host'];
            if ($host !== '') {
                if (!isset($refs[$day][$host])) {
                    $refs[$day][$host] = 0;
                }
                $refs[$day][$host]++;
            }
            $code = $parsed['status'];
            if (!isset($status[$day][$code])) {
                $status[$day][$code] = 0;
            }
            $status[$day][$code]++;
            $family = $parsed['ua_family'];
            if (!isset($ua[$day][$family])) {
                $ua[$day][$family] = 0;
            }
            $ua[$day][$family]++;
            $tok = $parsed['visitor'];
            if ($tok !== '') {
                $visitors[$day][$tok] = true;
            }
        }
        $newOffset = ftell($fh);
        fclose($fh);

        $this->stats->beginTransaction();
        try {
            foreach ($byDay as $day => $sum) {
                $this->upsertDaily($domainId, (string) $day, $sum['bytes'], $sum['requests']);
            }
            foreach ($paths as $day => $map) {
                $this->upsertTopPaths($domainId, (string) $day, $map);
            }
            foreach ($refs as $day => $map) {
                $this->upsertTopRefs($domainId, (string) $day, $map);
            }
            foreach ($status as $day => $map) {
                foreach ($map as $code => $hits) {
                    $this->upsertStatus($domainId, (string) $day, (int) $code, (int) $hits);
                }
            }
            foreach ($ua as $day => $map) {
                foreach ($map as $family => $hits) {
                    $this->upsertUa($domainId, (string) $day, (string) $family, (int) $hits);
                }
            }
            foreach ($visitors as $day => $set) {
                $this->upsertVisitors($domainId, (string) $day, array_keys($set));
            }
            $this->saveCursor($path, $inode, $newOffset === false ? $size : (int) $newOffset);
            $this->stats->commit();
        } catch (Throwable $e) {
            $this->stats->rollBack();
            throw $e;
        }

        return ['bytes' => $bytes, 'requests' => $requests];
    }

    /**
     * Combined / nginx main log line.
     *
     * @return array{
     *   day:string, bytes:int, status:int, path:string,
     *   ref_host:string, ua_family:string, visitor:string
     * }|null
     */
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

        $ip = '';
        if (preg_match('/^(\S+)\s/', $line, $im) === 1) {
            $ip = $im[1];
        }

        $status = 0;
        $bytes = 0;
        if (preg_match('/"\s+(\d{3})\s+(\d+|-)\s/', $line, $b) === 1) {
            $status = (int) $b[1];
            if ($b[2] !== '-') {
                $bytes = (int) $b[2];
            }
        }

        $path = '';
        if (preg_match('/"(?:GET|POST|HEAD|PUT|DELETE|OPTIONS|PATCH)\s+(\S+)/', $line, $pm) === 1) {
            $path = self::normalizePath($pm[1]);
        }

        $refHost = '';
        $ua = '';
        if (preg_match('/"\s+\d{3}\s+(?:\d+|-)\s+"([^"]*)"\s+"([^"]*)"/', $line, $rm) === 1) {
            $refHost = self::referrerHost($rm[1]);
            $ua = $rm[2];
        }

        return [
            'day' => $day,
            'bytes' => $bytes,
            'status' => $status,
            'path' => $path,
            'ref_host' => $refHost,
            'ua_family' => self::uaFamily($ua),
            'visitor' => self::visitorToken($ip, $day),
        ];
    }

    public static function normalizePath(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-') {
            return '';
        }
        $q = strpos($raw, '?');
        if ($q !== false) {
            $raw = substr($raw, 0, $q);
        }
        if (strlen($raw) > 200) {
            $raw = substr($raw, 0, 200);
        }
        // Block path traversal noise in the aggregate key.
        $raw = str_replace(["\0", '\\'], '', $raw);

        return $raw === '' ? '/' : $raw;
    }

    public static function referrerHost(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '' || $ref === '-') {
            return '';
        }
        $parts = @parse_url($ref);
        if (!is_array($parts) || !isset($parts['host']) || !is_string($parts['host'])) {
            return '';
        }
        $host = strtolower($parts['host']);
        if (strlen($host) > 120) {
            $host = substr($host, 0, 120);
        }

        return $host;
    }

    public static function uaFamily(string $ua): string
    {
        $ua = strtolower($ua);
        if ($ua === '' || $ua === '-') {
            return 'unknown';
        }
        foreach ([
            'bot', 'spider', 'crawl', 'slurp', 'bingpreview', 'facebookexternalhit',
            'wget', 'curl', 'python-requests', 'go-http-client', 'libwww',
        ] as $b) {
            if (str_contains($ua, $b)) {
                return 'bot';
            }
        }
        if (str_contains($ua, 'edg/') || str_contains($ua, 'edge/')) {
            return 'edge';
        }
        if (str_contains($ua, 'chrome/') && !str_contains($ua, 'chromium')) {
            return 'chrome';
        }
        if (str_contains($ua, 'firefox/')) {
            return 'firefox';
        }
        if (str_contains($ua, 'safari/') && !str_contains($ua, 'chrome/')) {
            return 'safari';
        }
        if (str_contains($ua, 'msie') || str_contains($ua, 'trident/')) {
            return 'ie';
        }
        if (str_contains($ua, 'opera') || str_contains($ua, 'opr/')) {
            return 'opera';
        }

        return 'other';
    }

    public static function visitorToken(string $ip, string $day): string
    {
        $ip = trim($ip);
        if ($ip === '' || $ip === '-') {
            return '';
        }
        // Daily salt so tokens are not a stable cross-day fingerprint.
        return substr(hash('sha256', $day . '|' . $ip . '|kloxo-stats'), 0, 20);
    }

    private function upsertDaily(int $domainId, string $day, int $bytes, int $requests): void
    {
        $stmt = $this->stats->prepare(
            'INSERT INTO domain_daily (domain_id, day, bytes, requests, disk_bytes, visitors)
             VALUES (:id, :day, :bytes, :req, 0, 0)
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

    /**
     * @param array<string,array{hits:int,bytes:int}> $map
     */
    private function upsertTopPaths(int $domainId, string $day, array $map): void
    {
        uasort($map, static fn ($a, $b) => $b['hits'] <=> $a['hits']);
        $map = array_slice($map, 0, self::PATH_CAP, true);
        $stmt = $this->stats->prepare(
            'INSERT INTO path_daily (domain_id, day, path, hits, bytes)
             VALUES (:id, :day, :path, :hits, :bytes)
             ON CONFLICT(domain_id, day, path) DO UPDATE SET
                hits = hits + excluded.hits,
                bytes = bytes + excluded.bytes'
        );
        foreach ($map as $path => $sum) {
            $stmt->execute([
                ':id' => $domainId,
                ':day' => $day,
                ':path' => $path,
                ':hits' => $sum['hits'],
                ':bytes' => $sum['bytes'],
            ]);
        }
    }

    /** @param array<string,int> $map */
    private function upsertTopRefs(int $domainId, string $day, array $map): void
    {
        arsort($map);
        $map = array_slice($map, 0, self::REF_CAP, true);
        $stmt = $this->stats->prepare(
            'INSERT INTO referrer_daily (domain_id, day, host, hits)
             VALUES (:id, :day, :host, :hits)
             ON CONFLICT(domain_id, day, host) DO UPDATE SET
                hits = hits + excluded.hits'
        );
        foreach ($map as $host => $hits) {
            $stmt->execute([
                ':id' => $domainId,
                ':day' => $day,
                ':host' => $host,
                ':hits' => $hits,
            ]);
        }
    }

    private function upsertStatus(int $domainId, string $day, int $code, int $hits): void
    {
        $stmt = $this->stats->prepare(
            'INSERT INTO status_daily (domain_id, day, status, hits)
             VALUES (:id, :day, :st, :hits)
             ON CONFLICT(domain_id, day, status) DO UPDATE SET
                hits = hits + excluded.hits'
        );
        $stmt->execute([
            ':id' => $domainId,
            ':day' => $day,
            ':st' => $code,
            ':hits' => $hits,
        ]);
    }

    private function upsertUa(int $domainId, string $day, string $family, int $hits): void
    {
        $stmt = $this->stats->prepare(
            'INSERT INTO ua_daily (domain_id, day, family, hits)
             VALUES (:id, :day, :fam, :hits)
             ON CONFLICT(domain_id, day, family) DO UPDATE SET
                hits = hits + excluded.hits'
        );
        $stmt->execute([
            ':id' => $domainId,
            ':day' => $day,
            ':fam' => $family,
            ':hits' => $hits,
        ]);
    }

    /** @param list<string> $tokens */
    private function upsertVisitors(int $domainId, string $day, array $tokens): void
    {
        if ($tokens === []) {
            return;
        }
        $ins = $this->stats->prepare(
            'INSERT OR IGNORE INTO visitor_seen (domain_id, day, token)
             VALUES (:id, :day, :tok)'
        );
        foreach ($tokens as $tok) {
            if (!is_string($tok) || $tok === '') {
                continue;
            }
            $ins->execute([':id' => $domainId, ':day' => $day, ':tok' => $tok]);
        }
        $cnt = $this->stats->prepare(
            'SELECT COUNT(*) FROM visitor_seen WHERE domain_id = :id AND day = :day'
        );
        $cnt->execute([':id' => $domainId, ':day' => $day]);
        $n = (int) $cnt->fetchColumn();
        $upd = $this->stats->prepare(
            'INSERT INTO domain_daily (domain_id, day, bytes, requests, disk_bytes, visitors)
             VALUES (:id, :day, 0, 0, 0, :v)
             ON CONFLICT(domain_id, day) DO UPDATE SET visitors = excluded.visitors'
        );
        $upd->execute([':id' => $domainId, ':day' => $day, ':v' => $n]);
    }

    private function recordDisk(int $domainId, string $name): void
    {
        $day = gmdate('Y-m-d', $this->now);
        $root = '';
        if ($this->tableExists($this->panel, 'domains')) {
            $q = $this->panel->prepare('SELECT document_root FROM domains WHERE id = :id OR name = :n');
            $q->execute([':id' => $domainId, ':n' => $name]);
            $root = (string) $q->fetchColumn();
        }
        if ($root === '') {
            foreach ([
                '/home/' . $name,
                '/home/httpd/' . $name,
                '/home/kloxo/httpd/' . $name,
            ] as $guess) {
                if (is_dir($guess)) {
                    $root = $guess;
                    break;
                }
            }
        }
        $disk = 0;
        if ($root !== '' && is_dir($root)) {
            $disk = $this->dirSize($root);
        }
        $stmt = $this->stats->prepare(
            'INSERT INTO domain_daily (domain_id, day, bytes, requests, disk_bytes, visitors)
             VALUES (:id, :day, 0, 0, :disk, 0)
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

    private function rollupAndPrune(): int
    {
        $cut = gmdate('Y-m-d', strtotime('-12 months', $this->now) ?: $this->now);
        $sel = $this->stats->prepare(
            'SELECT domain_id, substr(day, 1, 7) AS month,
                    SUM(bytes) AS bytes, SUM(requests) AS requests,
                    SUM(disk_bytes) AS disk_bytes, SUM(visitors) AS visitors
             FROM domain_daily WHERE day < :cut
             GROUP BY domain_id, substr(day, 1, 7)'
        );
        $sel->execute([':cut' => $cut]);
        $rows = $sel->fetchAll();
        $ins = $this->stats->prepare(
            'INSERT INTO domain_monthly (domain_id, month, bytes, requests, disk_bytes, visitors)
             VALUES (:id, :month, :bytes, :req, :disk, :v)
             ON CONFLICT(domain_id, month) DO UPDATE SET
                bytes = bytes + excluded.bytes,
                requests = requests + excluded.requests,
                disk_bytes = MAX(disk_bytes, excluded.disk_bytes),
                visitors = visitors + excluded.visitors'
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
                    ':v' => $r['visitors'] ?? 0,
                ]);
            }
            $del = $this->stats->prepare('DELETE FROM domain_daily WHERE day < :cut');
            $del->execute([':cut' => $cut]);
            foreach (['path_daily', 'referrer_daily', 'status_daily', 'ua_daily'] as $t) {
                $this->stats->prepare("DELETE FROM {$t} WHERE day < :cut")->execute([':cut' => $cut]);
            }
            $this->stats->commit();
        } catch (Throwable $e) {
            $this->stats->rollBack();
            throw $e;
        }

        return count($rows);
    }

    private function pruneVisitors(): void
    {
        $cut = gmdate('Y-m-d', strtotime('-' . self::VISITOR_RETENTION_DAYS . ' days', $this->now) ?: $this->now);
        $this->stats->prepare('DELETE FROM visitor_seen WHERE day < :cut')->execute([':cut' => $cut]);
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
