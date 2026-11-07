<?php
declare(strict_types=1);

/**
 * One-line-per-failure log that Fail2Ban reads (filters kloxo-panel and
 * kloxo-recovery in file/fail2ban/filter.d).
 *
 * Format (fixed; the filters depend on it):
 *   2026-11-07 06:41:12 kloxo-panel login failed user="admin" from 203.0.113.9
 *   2026-11-07 06:41:12 kloxo-recovery login failed from 203.0.113.9
 *
 * The user name is attacker controlled, so it is cut down to a safe alphabet
 * before it is written. Without that, a name such as `x" from 198.51.100.1 `
 * could make Fail2Ban ban somebody else's address.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoAuthLog
{
    public static function path(?string $root = null): string
    {
        if ($root === null) {
            $env = getenv('KLOXO_ROOT');
            $root = (is_string($env) && $env !== '' && $env[0] === '/') ? rtrim($env, '/') : '/opt/kloxo';
        }

        return $root . '/log/auth-failures.log';
    }

    public static function cleanUser(string $user): string
    {
        $u = (string) preg_replace('/[^A-Za-z0-9._@-]/', '', $user);

        return substr($u, 0, 64);
    }

    /** Text of one log line, or '' when the source or address is not valid. */
    public static function line(string $source, string $user, string $ip, ?int $now = null): string
    {
        if (!in_array($source, ['kloxo-panel', 'kloxo-recovery'], true)
            || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        $when = date('Y-m-d H:i:s', $now ?? time());
        if ($source === 'kloxo-panel') {
            return $when . ' kloxo-panel login failed user="' . self::cleanUser($user) . '" from ' . $ip . "\n";
        }

        return $when . ' kloxo-recovery login failed from ' . $ip . "\n";
    }

    /** Append one failure. Never throws; a log problem must not break a login page. */
    public static function failed(string $source, string $user, string $ip, ?string $root = null, ?int $now = null): void
    {
        $line = self::line($source, $user, $ip, $now);
        if ($line === '') {
            return;
        }
        $f = self::path($root);
        if (!is_dir(dirname($f))) {
            return;
        }
        @file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
    }
}
