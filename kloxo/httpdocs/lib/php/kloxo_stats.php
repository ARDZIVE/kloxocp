<?php
declare(strict_types=1);

/**
 * Domain visitor stats for the panel UI. Reads stats.sqlite only (never raw logs).
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoStats
{
    /**
     * Resolve stats domain_id for a hostname.
     */
    public static function domainId(PDO $stats, PDO $panel, string $name): ?int
    {
        $name = strtolower(trim($name));
        if ($name === '') {
            return null;
        }
        if (self::tableExists($panel, 'domains')) {
            $st = $panel->prepare('SELECT id FROM domains WHERE name = :n COLLATE NOCASE');
            $st->execute([':n' => $name]);
            $id = $st->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }
        if (self::tableExists($stats, 'domain_identity')) {
            $st = $stats->prepare('SELECT id FROM domain_identity WHERE name = :n');
            $st->execute([':n' => $name]);
            $id = $st->fetchColumn();
            if ($id !== false) {
                return (int) $id;
            }
        }

        return null;
    }

    /**
     * @return array{
     *   domain:string, days:int, from:string, to:string,
     *   totals:array{bytes:int,requests:int,visitors:int,disk_bytes:int},
     *   series:list<array{day:string,bytes:int,requests:int,visitors:int}>,
     *   paths:list<array{path:string,hits:int,bytes:int}>,
     *   referrers:list<array{host:string,hits:int}>,
     *   status:list<array{status:int,hits:int}>,
     *   ua:list<array{family:string,hits:int}>,
     *   empty:bool
     * }
     */
    public static function dashboard(string $domain, int $days = 30, ?PDO $stats = null, ?PDO $panel = null): array
    {
        $domain = strtolower(trim($domain));
        $days = max(1, min(366, $days));
        $stats ??= KloxoStore::stats(true);
        $panel ??= KloxoStore::panel(true);
        $to = gmdate('Y-m-d');
        $from = gmdate('Y-m-d', strtotime('-' . ($days - 1) . ' days') ?: time());
        $empty = [
            'domain' => $domain,
            'days' => $days,
            'from' => $from,
            'to' => $to,
            'totals' => ['bytes' => 0, 'requests' => 0, 'visitors' => 0, 'disk_bytes' => 0],
            'series' => [],
            'paths' => [],
            'referrers' => [],
            'status' => [],
            'ua' => [],
            'empty' => true,
        ];
        $id = self::domainId($stats, $panel, $domain);
        if ($id === null) {
            return $empty;
        }

        $seriesStmt = $stats->prepare(
            'SELECT day, bytes, requests, visitors, disk_bytes
             FROM domain_daily
             WHERE domain_id = :id AND day >= :from AND day <= :to
             ORDER BY day ASC'
        );
        $seriesStmt->execute([':id' => $id, ':from' => $from, ':to' => $to]);
        $series = [];
        $totals = ['bytes' => 0, 'requests' => 0, 'visitors' => 0, 'disk_bytes' => 0];
        foreach ($seriesStmt->fetchAll() ?: [] as $row) {
            $series[] = [
                'day' => (string) $row['day'],
                'bytes' => (int) $row['bytes'],
                'requests' => (int) $row['requests'],
                'visitors' => (int) ($row['visitors'] ?? 0),
            ];
            $totals['bytes'] += (int) $row['bytes'];
            $totals['requests'] += (int) $row['requests'];
            $totals['visitors'] += (int) ($row['visitors'] ?? 0);
            $totals['disk_bytes'] = max($totals['disk_bytes'], (int) ($row['disk_bytes'] ?? 0));
        }

        $paths = self::topPaths($stats, $id, $from, $to, 25);
        $refs = self::topReferrers($stats, $id, $from, $to, 20);
        $status = self::statusBreakdown($stats, $id, $from, $to);
        $ua = self::uaBreakdown($stats, $id, $from, $to);

        return [
            'domain' => $domain,
            'days' => $days,
            'from' => $from,
            'to' => $to,
            'totals' => $totals,
            'series' => $series,
            'paths' => $paths,
            'referrers' => $refs,
            'status' => $status,
            'ua' => $ua,
            'empty' => $series === [] && $totals['requests'] === 0,
        ];
    }

    /**
     * @return list<array{path:string,hits:int,bytes:int}>
     */
    public static function topPaths(PDO $stats, int $id, string $from, string $to, int $limit = 25): array
    {
        if (!self::tableExists($stats, 'path_daily')) {
            return [];
        }
        $st = $stats->prepare(
            'SELECT path, SUM(hits) AS hits, SUM(bytes) AS bytes
             FROM path_daily
             WHERE domain_id = :id AND day >= :from AND day <= :to
             GROUP BY path
             ORDER BY hits DESC
             LIMIT ' . (int) $limit
        );
        $st->execute([':id' => $id, ':from' => $from, ':to' => $to]);
        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $out[] = [
                'path' => (string) $row['path'],
                'hits' => (int) $row['hits'],
                'bytes' => (int) $row['bytes'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array{host:string,hits:int}>
     */
    public static function topReferrers(PDO $stats, int $id, string $from, string $to, int $limit = 20): array
    {
        if (!self::tableExists($stats, 'referrer_daily')) {
            return [];
        }
        $st = $stats->prepare(
            'SELECT host, SUM(hits) AS hits
             FROM referrer_daily
             WHERE domain_id = :id AND day >= :from AND day <= :to
             GROUP BY host
             ORDER BY hits DESC
             LIMIT ' . (int) $limit
        );
        $st->execute([':id' => $id, ':from' => $from, ':to' => $to]);
        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $out[] = ['host' => (string) $row['host'], 'hits' => (int) $row['hits']];
        }

        return $out;
    }

    /**
     * @return list<array{status:int,hits:int}>
     */
    public static function statusBreakdown(PDO $stats, int $id, string $from, string $to): array
    {
        if (!self::tableExists($stats, 'status_daily')) {
            return [];
        }
        $st = $stats->prepare(
            'SELECT status, SUM(hits) AS hits
             FROM status_daily
             WHERE domain_id = :id AND day >= :from AND day <= :to
             GROUP BY status
             ORDER BY hits DESC'
        );
        $st->execute([':id' => $id, ':from' => $from, ':to' => $to]);
        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $out[] = ['status' => (int) $row['status'], 'hits' => (int) $row['hits']];
        }

        return $out;
    }

    /**
     * @return list<array{family:string,hits:int}>
     */
    public static function uaBreakdown(PDO $stats, int $id, string $from, string $to): array
    {
        if (!self::tableExists($stats, 'ua_daily')) {
            return [];
        }
        $st = $stats->prepare(
            'SELECT family, SUM(hits) AS hits
             FROM ua_daily
             WHERE domain_id = :id AND day >= :from AND day <= :to
             GROUP BY family
             ORDER BY hits DESC'
        );
        $st->execute([':id' => $id, ':from' => $from, ':to' => $to]);
        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $out[] = ['family' => (string) $row['family'], 'hits' => (int) $row['hits']];
        }

        return $out;
    }

    public static function formatBytes(int $n): string
    {
        $n = max(0, $n);
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $n;
        while ($v >= 1024 && $i < count($u) - 1) {
            $v /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $v : number_format($v, $v >= 10 ? 1 : 2)) . ' ' . $u[$i];
    }

    public static function formatInt(int $n): string
    {
        return number_format(max(0, $n));
    }

    /**
     * Self-contained dashboard HTML (panel chrome already around it).
     *
     * @param array<string,mixed> $data from dashboard()
     */
    public static function renderHtml(array $data, string $rangeBaseUrl = ''): string
    {
        $domain = htmlspecialchars((string) ($data['domain'] ?? ''), ENT_QUOTES, 'UTF-8');
        $days = (int) ($data['days'] ?? 30);
        $from = htmlspecialchars((string) ($data['from'] ?? ''), ENT_QUOTES, 'UTF-8');
        $to = htmlspecialchars((string) ($data['to'] ?? ''), ENT_QUOTES, 'UTF-8');
        $totals = is_array($data['totals'] ?? null) ? $data['totals'] : [];
        $series = is_array($data['series'] ?? null) ? $data['series'] : [];
        $paths = is_array($data['paths'] ?? null) ? $data['paths'] : [];
        $refs = is_array($data['referrers'] ?? null) ? $data['referrers'] : [];
        $status = is_array($data['status'] ?? null) ? $data['status'] : [];
        $ua = is_array($data['ua'] ?? null) ? $data['ua'] : [];
        $empty = !empty($data['empty']);

        $req = self::formatInt((int) ($totals['requests'] ?? 0));
        $bytes = self::formatBytes((int) ($totals['bytes'] ?? 0));
        $vis = self::formatInt((int) ($totals['visitors'] ?? 0));
        $disk = self::formatBytes((int) ($totals['disk_bytes'] ?? 0));

        $ranges = [7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '12 months'];
        $rangeLinks = '';
        foreach ($ranges as $d => $label) {
            $active = $d === $days ? ' kloxo-stats-range-on' : '';
            if ($rangeBaseUrl !== '') {
                $href = htmlspecialchars($rangeBaseUrl . (str_contains($rangeBaseUrl, '?') ? '&' : '?')
                    . 'frm_stats_days=' . $d, ENT_QUOTES, 'UTF-8');
                $rangeLinks .= '<a class="kloxo-stats-range' . $active . '" href="' . $href . '">'
                    . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
            } else {
                $rangeLinks .= '<span class="kloxo-stats-range' . $active . '">'
                    . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
            }
        }

        $chart = self::svgChart($series);
        $pathRows = self::tableRows($paths, static function ($r): array {
            return [
                htmlspecialchars((string) ($r['path'] ?? ''), ENT_QUOTES, 'UTF-8'),
                self::formatInt((int) ($r['hits'] ?? 0)),
                self::formatBytes((int) ($r['bytes'] ?? 0)),
            ];
        });
        $refRows = self::tableRows($refs, static function ($r): array {
            return [
                htmlspecialchars((string) ($r['host'] ?? ''), ENT_QUOTES, 'UTF-8'),
                self::formatInt((int) ($r['hits'] ?? 0)),
            ];
        });
        $stRows = self::tableRows($status, static function ($r): array {
            return [
                (string) (int) ($r['status'] ?? 0),
                self::formatInt((int) ($r['hits'] ?? 0)),
            ];
        });
        $uaRows = self::tableRows($ua, static function ($r): array {
            return [
                htmlspecialchars((string) ($r['family'] ?? ''), ENT_QUOTES, 'UTF-8'),
                self::formatInt((int) ($r['hits'] ?? 0)),
            ];
        });

        $emptyMsg = $empty
            ? '<p class="kloxo-stats-empty">No traffic recorded yet for this range. '
            . 'Stats refresh every few minutes from the domain access log '
            . '(<code>kloxo-stats</code>). Ensure the site is receiving visits and '
            . 'logging is enabled.</p>'
            : '';

        return <<<HTML
<style type="text/css">
.kloxo-stats{font-family:Georgia,"Times New Roman",serif;color:#2c1810;margin:0 0 1.5em}
.kloxo-stats h2{font-size:1.35rem;margin:0 0 .35em;font-weight:700;color:#7a1f1f}
.kloxo-stats .kloxo-stats-sub{margin:0 0 1em;font-size:.9rem;color:#5a4638}
.kloxo-stats-ranges{display:flex;flex-wrap:wrap;gap:.4em;margin:0 0 1em}
.kloxo-stats-range{display:inline-block;padding:.35em .75em;background:#f3e6d4;border:1px solid #c9a882;color:#5a2a1a;text-decoration:none;font-size:.85rem}
.kloxo-stats-range-on,.kloxo-stats-range:hover{background:#7a1f1f;color:#fff;border-color:#7a1f1f}
.kloxo-stats-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75em;margin:0 0 1.25em}
.kloxo-stats-card{background:#fffaf3;border:1px solid #d4b896;padding:.75em .9em}
.kloxo-stats-card .lbl{font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;color:#7a6352;margin:0 0 .25em}
.kloxo-stats-card .val{font-size:1.35rem;font-weight:700;color:#1a120c;margin:0;font-variant-numeric:tabular-nums}
.kloxo-stats-chart{background:#fff;border:1px solid #d4b896;padding:.75em;margin:0 0 1.25em}
.kloxo-stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:1em}
@media (max-width:800px){.kloxo-stats-grid{grid-template-columns:1fr}}
.kloxo-stats-panel{background:#fff;border:1px solid #d4b896;padding:.75em}
.kloxo-stats-panel h3{margin:0 0 .6em;font-size:1rem;color:#7a1f1f;border-bottom:2px solid #2d6a3e;padding-bottom:.3em}
.kloxo-stats table{width:100%;border-collapse:collapse;font-size:.85rem;font-family:ui-monospace,Menlo,Consolas,monospace}
.kloxo-stats th,.kloxo-stats td{text-align:left;padding:.35em .4em;border-bottom:1px solid #ead9c4}
.kloxo-stats th{color:#5a4638;font-weight:600;font-family:Georgia,serif}
.kloxo-stats td.num{text-align:right;font-variant-numeric:tabular-nums}
.kloxo-stats-empty{background:#fff8e8;border:1px solid #e0c48a;padding:.75em 1em;margin:0 0 1em}
.kloxo-stats-note{font-size:.8rem;color:#7a6352;margin:1em 0 0}
</style>
<div class="kloxo-stats">
  <h2>Visitor Stats — {$domain}</h2>
  <p class="kloxo-stats-sub">{$from} → {$to} · privacy-preserving (visitor IPs are hashed daily, never stored)</p>
  <div class="kloxo-stats-ranges">{$rangeLinks}</div>
  {$emptyMsg}
  <div class="kloxo-stats-cards">
    <div class="kloxo-stats-card"><p class="lbl">Requests</p><p class="val">{$req}</p></div>
    <div class="kloxo-stats-card"><p class="lbl">Bandwidth</p><p class="val">{$bytes}</p></div>
    <div class="kloxo-stats-card"><p class="lbl">Visitors</p><p class="val">{$vis}</p></div>
    <div class="kloxo-stats-card"><p class="lbl">Disk (docroot)</p><p class="val">{$disk}</p></div>
  </div>
  <div class="kloxo-stats-chart">{$chart}</div>
  <div class="kloxo-stats-grid">
    <div class="kloxo-stats-panel">
      <h3>Top pages</h3>
      <table><thead><tr><th>Path</th><th class="num">Hits</th><th class="num">Bytes</th></tr></thead>
      <tbody>{$pathRows}</tbody></table>
    </div>
    <div class="kloxo-stats-panel">
      <h3>Referrers</h3>
      <table><thead><tr><th>Host</th><th class="num">Hits</th></tr></thead>
      <tbody>{$refRows}</tbody></table>
    </div>
    <div class="kloxo-stats-panel">
      <h3>Status codes</h3>
      <table><thead><tr><th>Code</th><th class="num">Hits</th></tr></thead>
      <tbody>{$stRows}</tbody></table>
    </div>
    <div class="kloxo-stats-panel">
      <h3>Browsers / bots</h3>
      <table><thead><tr><th>Family</th><th class="num">Hits</th></tr></thead>
      <tbody>{$uaRows}</tbody></table>
    </div>
  </div>
  <p class="kloxo-stats-note">Aggregated by <code>kloxo-stats</code> into <code>stats.sqlite</code>. Raw logs stay on disk under <code>/var/log/kloxo/domains/</code> (and the legacy custom_log). Better than Awstats: in-panel, no CGI, hashed visitors, status and bot split.</p>
</div>
HTML;
    }

    /**
     * @param list<array{day:string,bytes:int,requests:int,visitors?:int}> $series
     */
    public static function svgChart(array $series): string
    {
        if ($series === []) {
            return '<p class="kloxo-stats-sub">No daily series yet.</p>';
        }
        $w = 640;
        $h = 160;
        $pad = 28;
        $max = 1;
        foreach ($series as $p) {
            $max = max($max, (int) ($p['requests'] ?? 0));
        }
        $n = count($series);
        $bw = ($w - 2 * $pad) / max(1, $n);
        $bars = '';
        foreach ($series as $i => $p) {
            $req = (int) ($p['requests'] ?? 0);
            $bh = ($h - 2 * $pad) * ($req / $max);
            $x = $pad + $i * $bw + $bw * 0.15;
            $y = $h - $pad - $bh;
            $bw2 = max(1, $bw * 0.7);
            $title = htmlspecialchars(
                (string) ($p['day'] ?? '') . ': ' . self::formatInt($req) . ' requests',
                ENT_QUOTES,
                'UTF-8'
            );
            $bars .= '<rect x="' . round($x, 2) . '" y="' . round($y, 2) . '" width="'
                . round($bw2, 2) . '" height="' . round(max(0.5, $bh), 2)
                . '" fill="#2d6a3e"><title>' . $title . '</title></rect>';
        }
        $axis = '<line x1="' . $pad . '" y1="' . ($h - $pad) . '" x2="' . ($w - $pad)
            . '" y2="' . ($h - $pad) . '" stroke="#c9a882" stroke-width="1"/>';
        $label = htmlspecialchars('Requests / day (max ' . self::formatInt($max) . ')', ENT_QUOTES, 'UTF-8');

        return '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" height="' . $h
            . '" role="img" aria-label="Daily requests chart">'
            . $axis . $bars
            . '<text x="' . $pad . '" y="16" fill="#5a4638" font-size="12" font-family="Georgia,serif">'
            . $label . '</text></svg>';
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):list<string> $map
     */
    private static function tableRows(array $rows, callable $map): string
    {
        if ($rows === []) {
            return '<tr><td colspan="3">—</td></tr>';
        }
        $html = '';
        foreach ($rows as $r) {
            if (!is_array($r)) {
                continue;
            }
            $cols = $map($r);
            $html .= '<tr>';
            foreach ($cols as $i => $c) {
                $cls = $i > 0 ? ' class="num"' : '';
                $html .= '<td' . $cls . '>' . $c . '</td>';
            }
            $html .= '</tr>';
        }

        return $html !== '' ? $html : '<tr><td colspan="3">—</td></tr>';
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $st = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :n LIMIT 1"
        );
        $st->execute([':n' => $table]);

        return (bool) $st->fetchColumn();
    }
}
