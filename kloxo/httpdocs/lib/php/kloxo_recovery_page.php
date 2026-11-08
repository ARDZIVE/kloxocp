<?php
declare(strict_types=1);

/**
 * Request handler of the recovery console (httpdocs/recovery/index.php).
 * Pure function of its inputs so it can be tested without a web server:
 * session data, the client address and the clock are passed in.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

require_once __DIR__ . '/kloxo_recovery.php';

final class KloxoRecoveryPage
{
    /**
     * @param array<string,mixed> $server
     * @param array<string,mixed> $get
     * @param array<string,mixed> $post
     * @param array<string,mixed> $session in/out
     * @return array{status:int,headers:list<string>,body:string,regenerate:bool,destroy:bool}
     */
    public static function handle(array $server, array $get, array $post, array &$session, string $ip, int $now): array
    {
        $out = ['status' => 200, 'headers' => self::baseHeaders(), 'body' => '', 'regenerate' => false, 'destroy' => false];

        $https = (string) ($server['HTTPS'] ?? '');
        if ($https === '' || strtolower($https) === 'off') {
            $out['status'] = 403;
            $out['body'] = self::page('Recovery console', '<p>Use the encrypted address: <code>https://&lt;server&gt;:7777/recovery/</code>. '
                . 'Passwords are never accepted over plain HTTP.</p>');

            return $out;
        }

        if (!KloxoRecovery::hasCredential()) {
            $out['body'] = self::page('Recovery console', '<p>No recovery password is set. On the server, as root, run:</p>'
                . '<pre>php /opt/kloxo/bin/kloxocp recovery-password --generate</pre>');

            return $out;
        }

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));

        if (!empty($session['auth'])) {
            if ($now - (int) ($session['last'] ?? 0) > KloxoRecovery::SESSION_IDLE) {
                $session = [];
                $out['destroy'] = true;
            } else {
                $session['last'] = $now;
            }
        }

        $authed = !empty($session['auth']);

        if ($method === 'POST' && ($post['do'] ?? '') === 'login' && !$authed) {
            return self::login($out, $post, $session, $ip, $now);
        }

        if (!$authed) {
            $out['body'] = self::loginForm('');

            return $out;
        }

        $csrf = (string) ($session['csrf'] ?? '');

        if ($method === 'POST') {
            $sent = (string) ($post['csrf'] ?? '');
            if ($csrf === '' || !hash_equals($csrf, $sent)) {
                $out['status'] = 403;
                $out['body'] = self::page('Recovery console', '<p>The form expired. <a href="/recovery/">Reload</a>.</p>');

                return $out;
            }
            $do = (string) ($post['do'] ?? '');
            if ($do === 'logout') {
                $session = [];
                $out['destroy'] = true;
                $out['status'] = 303;
                $out['headers'][] = 'Location: /recovery/';

                return $out;
            }
            if ($do === 'control') {
                $act = (string) ($post['act'] ?? '');
                $name = (string) ($post['unit'] ?? '');
                $session['flash'] = self::control($act, $name);
                $out['status'] = 303;
                $out['headers'][] = 'Location: /recovery/';

                return $out;
            }
            $out['status'] = 400;
            $out['body'] = self::page('Recovery console', '<p>Unknown request.</p>');

            return $out;
        }

        $journal = isset($get['journal']) ? (string) $get['journal'] : '';
        if ($journal !== '') {
            $out['body'] = self::journalPage($journal, $csrf);

            return $out;
        }

        $flash = (string) ($session['flash'] ?? '');
        unset($session['flash']);
        $out['body'] = self::overview($csrf, $flash);

        return $out;
    }

    /** @return list<string> */
    private static function baseHeaders(): array
    {
        return [
            'Content-Type: text/html; charset=utf-8',
            'Cache-Control: no-store',
            'X-Frame-Options: DENY',
            'X-Content-Type-Options: nosniff',
            'Referrer-Policy: no-referrer',
            "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'",
        ];
    }

    /**
     * @param array<string,mixed> $out
     * @param array<string,mixed> $post
     * @param array<string,mixed> $session
     * @return array{status:int,headers:list<string>,body:string,regenerate:bool,destroy:bool}
     */
    private static function login(array $out, array $post, array &$session, string $ip, int $now): array
    {
        $wait = KloxoRecovery::lockedFor($ip, null, $now);
        if ($wait > 0) {
            $out['status'] = 429;
            $out['body'] = self::loginForm('Too many failed attempts. Try again in ' . (int) ceil($wait / 60) . ' minute(s).');

            return $out;
        }
        $pw = (string) ($post['password'] ?? '');
        if ($pw !== '' && strlen($pw) <= 256 && KloxoRecovery::verifyCredential($pw)) {
            KloxoRecovery::clearFailures($ip);
            $session = ['auth' => true, 'last' => $now, 'csrf' => bin2hex(random_bytes(24))];
            $out['regenerate'] = true;
            $out['status'] = 303;
            $out['headers'][] = 'Location: /recovery/';

            return $out;
        }
        KloxoRecovery::recordFailure($ip, null, $now);
        KloxoAuthLog::failed('kloxo-recovery', '', $ip, KloxoRecovery::rootDir(), $now);
        $out['status'] = 401;
        $out['body'] = self::loginForm('Wrong password.');

        return $out;
    }

    private static function control(string $act, string $name): string
    {
        if (!KloxoRecovery::mayAct($name, $act)) {
            return 'Refused: ' . $act . ' ' . $name . ' is not allowed from the console.';
        }
        $r = KloxoRecovery::viaBackend('serverControl', [$act, $name]);
        if ($r === null) {
            return 'No answer from kloxo-backend. On the server run: systemctl restart kloxo-backend';
        }
        $state = (string) ($r['active'] ?? 'unknown');
        if (!empty($r['ok'])) {
            return ucfirst($act) . ' ' . $name . ': OK (' . $state . ').';
        }

        return ucfirst($act) . ' ' . $name . ' failed (' . $state . ')' . (($r['err'] ?? '') !== '' ? ': ' . $r['err'] : '.');
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function page(string $title, string $inner): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::h($title) . '</title><style>'
            . 'body{font:14px/1.45 system-ui,sans-serif;margin:0;background:#f4f1ea;color:#222}'
            . 'header{background:#a33;color:#fff;padding:.7em 1.2em;display:flex;justify-content:space-between;align-items:center}'
            . 'main{max-width:62em;margin:1.2em auto;padding:0 1em}'
            . 'table{border-collapse:collapse;width:100%;background:#fff}'
            . 'th,td{border:1px solid #ddd;padding:.4em .6em;text-align:left}th{background:#eee}'
            . '.ok{color:#176b2c;font-weight:600}.bad{color:#b00020;font-weight:600}.mute{color:#888}'
            . '.flash{background:#fff7d6;border:1px solid #e3d27a;padding:.6em .8em;margin:.8em 0}'
            . '.alert{background:#fde8e8;border:1px solid #e09a9a;padding:.6em .8em;margin:.8em 0}'
            . 'button{padding:.25em .6em;margin-right:.2em;cursor:pointer}form{display:inline;margin:0}'
            . 'pre{background:#111;color:#ddd;padding:.8em;overflow:auto;max-height:30em}'
            . 'input[type=password]{padding:.4em;width:16em}a{color:#134f8c}'
            . '</style></head><body><header><strong>Kloxo CP recovery console</strong><span>works without MariaDB, Nginx or Apache</span></header>'
            . '<main>' . $inner . '</main></body></html>';
    }

    private static function loginForm(string $msg): string
    {
        $m = $msg !== '' ? '<div class="alert">' . self::h($msg) . '</div>' : '';

        return self::page('Recovery console', '<h2>Recovery login</h2>' . $m
            . '<p>Use the recovery password printed at install time (not the panel password).</p>'
            . '<form method="post" action="/recovery/" autocomplete="off">'
            . '<input type="hidden" name="do" value="login">'
            . '<p><input type="password" name="password" autofocus required maxlength="256"> '
            . '<button type="submit">Log in</button></p></form>');
    }

    private static function bytes(float $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < 4) {
            $b /= 1024;
            $i++;
        }

        return sprintf('%.1f %s', $b, $u[$i]);
    }

    private static function system(): string
    {
        $rows = [];
        $free = @disk_free_space('/');
        $tot = @disk_total_space('/');
        if ($free !== false && $tot !== false && $tot > 0) {
            $rows[] = ['Disk /', self::bytes((float) $free) . ' free of ' . self::bytes((float) $tot)
                . ' (' . (int) round(100 * (1 - $free / $tot)) . '% used)'];
        }
        $mem = is_readable('/proc/meminfo') ? (string) @file_get_contents('/proc/meminfo') : '';
        if (preg_match('/MemTotal:\s+(\d+)/', $mem, $t) === 1 && preg_match('/MemAvailable:\s+(\d+)/', $mem, $a) === 1) {
            $rows[] = ['Memory', self::bytes((float) $a[1] * 1024) . ' available of ' . self::bytes((float) $t[1] * 1024)];
        }
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
        if (is_array($load)) {
            $rows[] = ['Load average', sprintf('%.2f %.2f %.2f', $load[0], $load[1], $load[2])];
        }
        $h = '<table>';
        foreach ($rows as $r) {
            $h .= '<tr><th>' . self::h($r[0]) . '</th><td>' . self::h($r[1]) . '</td></tr>';
        }

        return $h . '</table>';
    }

    /**
     * @param array{name:string,unit:string,installed:bool,active:string,sub:string,enabled:string,self:bool} $s
     * @param array{updated:int,units:array<string,array<string,mixed>>} $wd
     */
    private static function watchdogCell(array $s, array $wd, string $root): string
    {
        $unit = $s['unit'];
        if (KloxoWatchdog::isHeld($unit, $root)) {
            return '<span class="mute">held: stopped on purpose, not restarted</span>';
        }
        $u = $wd['units'][$unit] ?? null;
        if (is_array($u) && !empty($u['gave_up'])) {
            return '<span class="bad">gave up (keeps failing)</span>';
        }
        if (is_array($u) && isset($u['last'], $u['last_ts']) && in_array($u['last'], ['restarted', 'restart_failed'], true)) {
            return self::h(($u['last'] === 'restarted' ? 'restarted ' : 'restart failed ') . date('Y-m-d H:i', (int) $u['last_ts']));
        }

        return $s['enabled'] === 'enabled' ? '<span class="mute">watching</span>' : '';
    }

    private static function overview(string $csrf, string $flash): string
    {
        $states = KloxoRecovery::states();
        $db = null;
        foreach ($states as $s) {
            if ($s['name'] === 'mariadb') {
                $db = $s;
            }
        }
        $h = '<form method="post" action="/recovery/" style="float:right">'
            . '<input type="hidden" name="do" value="logout"><input type="hidden" name="csrf" value="' . self::h($csrf) . '">'
            . '<button type="submit">Log out</button></form><h2>Server applications</h2>';
        if ($flash !== '') {
            $h .= '<div class="flash">' . self::h($flash) . '</div>';
        }
        if ($db !== null && $db['installed'] && $db['active'] !== 'active') {
            $h .= '<div class="alert"><strong>MariaDB is ' . self::h($db['active']) . '.</strong> '
                . 'Customer databases, phpMyAdmin and mail lookups will not work until it is started. '
                . 'The panel itself stays up (SQLite). Use Start on the mariadb row, then check its log.</div>';
        }
        $root = KloxoRecovery::rootDir();
        $wd = KloxoWatchdog::readState($root);
        if (KloxoWatchdog::recipient($root) === '') {
            $h .= '<p class="bad">The watchdog has no admin email address, so it cannot mail you when it restarts a service. Set the contact email in the panel profile.</p>';
        }
        $h .= '<table><tr><th>Application</th><th>State</th><th>At boot</th><th>Watchdog</th><th>Actions</th><th>Log</th></tr>';
        foreach ($states as $s) {
            $n = self::h($s['name']);
            if (!$s['installed']) {
                $h .= '<tr class="mute"><td>' . $n . '</td><td colspan="5">not installed</td></tr>';
                continue;
            }
            $cls = $s['active'] === 'active' ? 'ok' : ($s['active'] === 'failed' ? 'bad' : 'mute');
            $h .= '<tr><td>' . $n . ($s['self'] ? ' <span class="mute">(panel)</span>' : '') . '</td>'
                . '<td class="' . $cls . '">' . self::h($s['active'] . ($s['sub'] !== '' ? ' / ' . $s['sub'] : '')) . '</td>'
                . '<td>' . self::h($s['enabled']) . '</td><td>' . self::watchdogCell($s, $wd, $root) . '</td><td>';
            foreach (['start', 'stop', 'restart'] as $act) {
                if (!KloxoRecovery::mayAct($s['name'], $act)) {
                    continue;
                }
                $h .= '<form method="post" action="/recovery/"><input type="hidden" name="do" value="control">'
                    . '<input type="hidden" name="csrf" value="' . self::h($csrf) . '">'
                    . '<input type="hidden" name="unit" value="' . $n . '"><input type="hidden" name="act" value="' . $act . '">'
                    . '<button type="submit">' . ucfirst($act) . '</button></form>';
            }
            $h .= '</td><td><a href="/recovery/?journal=' . rawurlencode($s['name']) . '">log</a></td></tr>';
        }
        $h .= '</table><h2>System</h2>' . self::system()
            . '<p class="mute">Panel units cannot be stopped or restarted here because that would end this page. '
            . 'Back to the <a href="/">panel</a>.</p>';

        return self::page('Recovery console', $h);
    }

    private static function journalPage(string $name, string $csrf): string
    {
        if (!KloxoRecovery::isKnown($name)) {
            return self::page('Recovery console', '<p>Unknown application.</p><p><a href="/recovery/">Back</a></p>');
        }
        $r = KloxoRecovery::viaBackend('serverJournal', [$name, 40]);
        $text = is_array($r) && isset($r['text']) ? (string) $r['text'] : 'No answer from kloxo-backend.';

        return self::page('Recovery console', '<p><a href="/recovery/">&larr; Back</a></p><h2>Log: ' . self::h($name)
            . '</h2><pre>' . self::h($text) . '</pre>');
    }
}
