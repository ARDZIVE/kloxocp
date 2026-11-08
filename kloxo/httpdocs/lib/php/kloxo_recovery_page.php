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
        $state = in_array($act, ['enable', 'disable'], true)
            ? (string) ($r['enabled'] ?? $r['active'] ?? 'unknown')
            : (string) ($r['active'] ?? 'unknown');
        $verb = $act === 'enable' ? 'Enable at boot' : ucfirst($act);
        if (!empty($r['ok'])) {
            $note = trim((string) ($r['retired'] ?? ''));

            return ($note !== '' ? ucfirst($note) . '. ' : '') . $verb . ' ' . $name . ': OK (' . $state . ').';
        }

        return $verb . ' ' . $name . ' failed (' . $state . ')' . (($r['err'] ?? '') !== '' ? ': ' . $r['err'] : '.');
    }

    private static function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function css(): string
    {
        return <<<'CSS'
:root{--red:#a33;--green:#3d8f4c;--bg:#efe8dc;--paper:#fff;--ink:#1c1c1c;--muted:#6a645c;--line:#e4ddd2;--ok:#176b2c;--bad:#b00020}
*{box-sizing:border-box}
html,body{margin:0;min-height:100%}
body{font:15px/1.45 "Segoe UI",system-ui,sans-serif;background:var(--bg);color:var(--ink)}
header{background:var(--red);color:#fff;padding:.85em 1.4em;display:flex;justify-content:space-between;align-items:center;gap:1em;flex-wrap:wrap}
.brand{display:flex;flex-direction:column;gap:.1em;min-width:0}
.brand strong{font-size:1.05rem;letter-spacing:.02em}
.brand-sub{font-size:.78rem;opacity:.85;font-weight:500}
.head-right{display:flex;align-items:center;gap:.75em;margin-left:auto;flex-shrink:0}
.tagline{font-size:.82rem;opacity:.9;white-space:nowrap}
.accent{height:6px;background:var(--green)}
main{width:100%;max-width:76em;margin:1.4em auto 2.4em;padding:0 1.2em}
body.login main{max-width:28em;margin-top:3.5em}
h2{font-size:1.15rem;margin:0 0 .8em;font-weight:650}
.toolbar{display:flex;justify-content:space-between;align-items:baseline;gap:1em;margin-bottom:.6em}
.card{background:var(--paper);border:1px solid var(--line);border-radius:8px;box-shadow:0 1px 2px rgba(40,20,0,.06);overflow:hidden;width:100%;max-width:100%}
.card-pad{padding:1.35em 1.4em 1.5em}
.card-login h2{margin-bottom:.35em}
.hint{color:var(--muted);margin:.2em 0 1.1em}
label{display:block;font-size:.82rem;font-weight:600;margin:0 0 .35em;color:var(--muted)}
input[type=password]{width:100%;padding:.55em .7em;border:1px solid #cfc7ba;border-radius:6px;font:inherit;background:#fffdf8}
input[type=password]:focus{outline:2px solid #c48a8a;outline-offset:1px;border-color:var(--red)}
.login-row{display:flex;flex-direction:column;gap:.7em}
table{border-collapse:collapse;width:100%;background:var(--paper)}
th,td{padding:.5em .65em;text-align:left;vertical-align:middle;border-bottom:1px solid var(--line)}
th:last-child,td:last-child{width:1%;white-space:nowrap}
th{background:#f6efe6;font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:700}
tbody tr:hover{background:#fffaf3}
tr.mute td{color:var(--muted)}
.wrap{overflow-x:auto;border-radius:8px;max-width:100%}
.name{white-space:nowrap}
.pill{display:inline-block;padding:.12em .55em;border-radius:999px;font-size:.78rem;font-weight:650;white-space:nowrap}
.pill-ok,.ok{color:var(--ok)}.pill-ok{background:#e6f5ea}
.pill-bad,.bad{color:var(--bad)}.pill-bad{background:#fde8e8}
.pill-mute,.mute{color:var(--muted)}.pill-mute{background:#eeeae4}
.flash,.alert,.note{border-radius:8px;padding:.75em .95em;margin:0 0 .9em;overflow-wrap:anywhere}
.flash{background:#fff7d6;border:1px solid #e3d27a}
.alert{background:#fde8e8;border:1px solid #e09a9a}
.note{background:#f3eee6;border:1px solid var(--line);color:var(--muted);font-size:.92rem}
.actions{display:flex;flex-wrap:wrap;gap:.35em;align-items:center}
form{display:inline;margin:0}
.btn{appearance:none;font:inherit;font-size:.82rem;font-weight:600;padding:.32em .7em;border-radius:6px;border:1px solid #cfc7ba;background:#fff;color:var(--ink);cursor:pointer;white-space:nowrap}
.btn:hover{background:#f6efe6}
.btn-primary{background:var(--red);border-color:#8e2a2a;color:#fff;padding:.55em .9em;width:100%}
.btn-primary:hover{background:#8e2a2a}
.btn-start{border-color:#9dceaa;color:var(--ok);background:#f3faf5}
.btn-stop{border-color:#e4b0b0;color:var(--bad);background:#fff6f6}
.btn-restart{border-color:#cfc7ba}
.btn-boot{border-color:#b9c7e0;color:#1f4f86;background:#f4f7fc}
.btn-logout{background:transparent;border-color:rgba(255,255,255,.45);color:#fff}
.btn-logout:hover{background:rgba(255,255,255,.12)}
.btn-log{display:inline-block;font-size:.82rem;font-weight:600;padding:.32em .7em;border-radius:6px;border:1px solid #cfc7ba;background:#fff;color:var(--ink);text-decoration:none}
.btn-log:hover{background:#f6efe6}
a{color:#134f8c}
.sys{display:grid;grid-template-columns:repeat(auto-fit,minmax(14em,1fr));gap:.7em;margin:.4em 0 1.2em}
.sys-card{background:var(--paper);border:1px solid var(--line);border-radius:8px;padding:.85em 1em}
.sys-k{font-size:.72rem;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);font-weight:700}
.sys-v{margin-top:.25em;font-weight:600}
pre{background:#1b1b1b;color:#e6e2d8;padding:1em;overflow:auto;max-height:36em;margin:0;font:12px/1.45 ui-monospace,Menlo,monospace}
.foot{color:var(--muted);font-size:.88rem;margin:1.1em 0 0}
.back{display:inline-block;margin:0 0 .8em;font-weight:600}
.tag{font-size:.75rem;color:var(--muted);font-weight:600;margin-left:.35em}
@media (max-width:960px){.tagline{display:none}th:nth-child(4),td:nth-child(4),th:nth-child(5),td:nth-child(5){display:none}}
@media (max-width:720px){header{padding:.7em 1em}}
CSS;
    }

    /**
     * @param array{kind?:string,logout?:string} $opts
     */
    private static function page(string $title, string $inner, array $opts = []): string
    {
        $kind = (string) ($opts['kind'] ?? 'app');
        $logout = (string) ($opts['logout'] ?? '');
        $bodyClass = $kind === 'login' ? ' class="login"' : '';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::h($title) . '</title><style>' . self::css() . '</style></head>'
            . '<body' . $bodyClass . '><header><div class="brand"><strong>Kloxo CP recovery console</strong>'
            . '<span class="brand-sub">Restore services when the panel or a daemon is down</span></div>'
            . '<div class="head-right"><span class="tagline">Works without MariaDB, Nginx or Apache</span>'
            . $logout . '</div></header><div class="accent"></div><main>' . $inner . '</main></body></html>';
    }

    private static function loginForm(string $msg): string
    {
        $m = $msg !== '' ? '<div class="alert">' . self::h($msg) . '</div>' : '';

        return self::page('Recovery console', '<div class="card card-login card-pad"><h2>Recovery login</h2>' . $m
            . '<p class="hint">Use the recovery password printed at install time (not the panel password).</p>'
            . '<form method="post" action="/recovery/" autocomplete="off" class="login-row">'
            . '<input type="hidden" name="do" value="login">'
            . '<div><label for="password">Recovery password</label>'
            . '<input type="password" id="password" name="password" autofocus required maxlength="256"></div>'
            . '<button type="submit" class="btn btn-primary">Log in</button></form></div>',
            ['kind' => 'login']);
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
        if ($rows === []) {
            return '';
        }
        $h = '<div class="sys">';
        foreach ($rows as $r) {
            $h .= '<div class="sys-card"><div class="sys-k">' . self::h($r[0]) . '</div>'
                . '<div class="sys-v">' . self::h($r[1]) . '</div></div>';
        }

        return $h . '</div>';
    }

    private static function logoutForm(string $csrf): string
    {
        return '<form method="post" action="/recovery/">'
            . '<input type="hidden" name="do" value="logout"><input type="hidden" name="csrf" value="' . self::h($csrf) . '">'
            . '<button type="submit" class="btn btn-logout">Log out</button></form>';
    }

    private static function actButton(string $csrf, string $name, string $act, string $label, string $class): string
    {
        return '<form method="post" action="/recovery/"><input type="hidden" name="do" value="control">'
            . '<input type="hidden" name="csrf" value="' . self::h($csrf) . '">'
            . '<input type="hidden" name="unit" value="' . $name . '"><input type="hidden" name="act" value="' . $act . '">'
            . '<button type="submit" class="' . $class . '">' . self::h($label) . '</button></form>';
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
        $h = '<div class="toolbar"><h2>Server applications</h2></div>';
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
            $h .= '<p class="note bad">The watchdog has no admin email address, so it cannot mail you when it restarts a service. Set the contact email in the panel profile.</p>';
        }
        $h .= '<div class="card wrap"><table><thead><tr><th>Application</th><th>State</th><th>Actions</th><th>At boot</th><th>Watchdog</th><th>Log</th></tr></thead><tbody>';
        $installed = [];
        $missing = [];
        foreach ($states as $s) {
            if ($s['installed']) {
                $installed[] = $s;
            } else {
                $missing[] = $s;
            }
        }
        foreach (array_merge($installed, $missing) as $s) {
            $n = self::h($s['name']);
            if (!$s['installed']) {
                $h .= '<tr class="mute"><td>' . $n . '</td><td colspan="5">not installed</td></tr>';
                continue;
            }
            $cls = $s['active'] === 'active' ? 'ok' : ($s['active'] === 'failed' ? 'bad' : 'mute');
            $pill = $s['active'] === 'active' ? 'pill-ok' : ($s['active'] === 'failed' ? 'pill-bad' : 'pill-mute');
            $bootPill = KloxoRecovery::isEnabledAtBoot($s['enabled']) ? 'pill-ok' : 'pill-mute';
            $state = $s['active'];
            if ($s['sub'] !== '' && !in_array($s['active'], ['inactive', 'unknown'], true)) {
                $state .= ' / ' . $s['sub'];
            }
            $h .= '<tr><td class="name"><strong>' . $n . '</strong>' . ($s['self'] ? ' <span class="tag">(panel)</span>' : '') . '</td>'
                . '<td class="' . $cls . '"><span class="pill ' . $pill . '">' . self::h($state) . '</span></td>'
                . '<td class="actions">';
            $btn = ['start' => 'btn btn-start', 'stop' => 'btn btn-stop', 'restart' => 'btn btn-restart'];
            foreach (['start', 'stop', 'restart'] as $act) {
                if (!KloxoRecovery::mayAct($s['name'], $act)) {
                    continue;
                }
                $h .= self::actButton($csrf, $n, $act, ucfirst($act), $btn[$act]);
            }
            if (KloxoRecovery::mayAct($s['name'], 'enable') && !KloxoRecovery::isEnabledAtBoot($s['enabled'])) {
                $h .= self::actButton($csrf, $n, 'enable', 'Enable at boot', 'btn btn-boot');
            }
            $h .= '</td><td><span class="pill ' . $bootPill . '">' . self::h($s['enabled']) . '</span></td>'
                . '<td>' . self::watchdogCell($s, $wd, $root) . '</td>'
                . '<td><a class="btn-log" href="/recovery/?journal=' . rawurlencode($s['name']) . '">log</a></td></tr>';
        }
        $h .= '</tbody></table></div><h2>System</h2>' . self::system()
            . '<p class="foot">Panel units cannot be stopped or restarted here because that would end this page. '
            . 'Back to the <a href="/">panel</a>.</p>';

        return self::page('Recovery console', $h, ['logout' => self::logoutForm($csrf)]);
    }

    private static function journalPage(string $name, string $csrf): string
    {
        if (!KloxoRecovery::isKnown($name)) {
            return self::page('Recovery console', '<p>Unknown application.</p><p><a class="back" href="/recovery/">Back</a></p>',
                ['logout' => self::logoutForm($csrf)]);
        }
        $r = KloxoRecovery::viaBackend('serverJournal', [$name, 40]);
        $text = is_array($r) && isset($r['text']) ? (string) $r['text'] : 'No answer from kloxo-backend.';

        return self::page('Recovery console', '<p><a class="back" href="/recovery/">&larr; Back</a></p><h2>Log: '
            . self::h($name) . '</h2><div class="card"><pre>' . self::h($text) . '</pre></div>',
            ['logout' => self::logoutForm($csrf)]);
    }
}
