<?php
declare(strict_types=1);

/**
 * Run traceroute/tracepath with a validated host. No shell strings.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoTraceroute
{
    /** @var list<string> */
    private const BINS = [
        '/usr/bin/traceroute',
        '/usr/sbin/traceroute',
        '/bin/traceroute',
        '/usr/bin/tracepath',
        '/usr/sbin/tracepath',
    ];

    public static function safeHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }
        if (preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/', $host) === 1
            && strlen($host) <= 253) {
            return $host;
        }

        return '';
    }

    public static function clientHost(): string
    {
        $addr = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR']
            : '';
        $safe = self::safeHost($addr);
        if ($safe !== '') {
            return $safe;
        }

        return '127.0.0.1';
    }

    public static function findBinary(): string
    {
        foreach (self::BINS as $bin) {
            if (is_executable($bin)) {
                return $bin;
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    public static function run(string $host): array
    {
        $host = self::safeHost($host);
        if ($host === '') {
            return [];
        }
        $bin = self::findBinary();
        if ($bin === '') {
            return [];
        }
        $args = str_contains($bin, 'tracepath')
            ? [$bin, '-n', $host]
            : [$bin, '-q', '1', '-n', '-w', '2', '-m', '15', $host];
        $p = @proc_open($args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            return [];
        }
        stream_set_timeout($pipes[1], 20);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        if (!is_string($out) || $out === '') {
            return [];
        }
        $lines = preg_split('/\r\n|\n|\r/', $out);
        if (!is_array($lines)) {
            return [];
        }

        return array_values(array_filter($lines, static fn($l) => is_string($l) && $l !== ''));
    }
}
