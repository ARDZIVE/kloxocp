<?php
declare(strict_types=1);

/**
 * Service watchdog: restarts crashed or unresponsive server applications.
 *
 * Runs as its own systemd service (bin/kloxo-watchdog), so it keeps working
 * when the panel, its PHP or its database are down. It reads the panel's
 * Watchdog on/off settings from a file (etc/watchdog.conf) and the chosen
 * programs from etc/slavedb/driver, and never touches the database.
 *
 * Rules (the cPanel chkservd model):
 *  - only units that are installed AND enabled are watched;
 *  - a unit stopped on purpose (panel Services page, recovery console) is
 *    "held" and left alone until it is started again;
 *  - unhealthy = not active, or active but its port/socket does not answer;
 *  - at most `max_restarts` restarts per `window`; then it gives up, tells the
 *    admin once and waits for the window to pass or the unit to recover;
 *  - nothing is restarted during the first `grace` seconds after boot.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoWatchdog
{
    public const DEFAULTS = [
        'interval' => 60,
        'max_restarts' => 3,
        'window' => 1800,
        'grace' => 300,
        'notify' => '',
        'disabled' => [],
    ];

    /**
     * service => units (by driver where the program is selectable) and checks.
     * 'driver' names the slavedb key; 'units' maps a driver value to a unit.
     */
    private const SERVICES = [
        'web' => ['driver' => 'web', 'units' => ['nginx' => 'nginx', 'apache' => 'apache2'], 'ports' => [80]],
        'webcache' => ['units' => ['kloxo-webcache'], 'ports' => [8080]],
        'webproxy' => ['units' => ['kloxo-webproxy'], 'ports' => [30080]],
        'dns' => ['driver' => 'dns', 'units' => ['bind' => 'bind9', 'pdns' => 'pdns'], 'ports' => [53]],
        'mail' => ['driver' => 'smtp', 'units' => ['exim' => 'exim4', 'postfix' => 'postfix'], 'ports' => [25]],
        'pop3' => ['driver' => 'pop3', 'units' => ['dovecot' => 'dovecot'], 'ports' => [143]],
        'ftp' => ['units' => ['pure-ftpd'], 'ports' => [21]],
        'mysql' => ['units' => ['mariadb'], 'ports' => [3306], 'sockets' => ['/run/mysqld/mysqld.sock']],
        'php-fpm' => ['units' => [], 'php_fpm' => true],
        'fail2ban' => ['units' => ['fail2ban'], 'sockets' => ['/run/fail2ban/fail2ban.sock']],
        'rspamd' => ['units' => ['rspamd']],
        'redis' => ['units' => ['redis-server']],
        'kloxo-php' => ['units' => ['kloxo-php'], 'ports' => [39000]],
        'kloxo-web' => ['units' => ['kloxo-web'], 'ports' => [7778]],
        'kloxo-backend' => ['units' => ['kloxo-backend'], 'ports' => [7776]],
    ];

    private string $root;
    /** @var callable */
    private $run;
    /** @var callable */
    private $probe;
    /** @var callable */
    private $mail;
    /** @var callable */
    private $uptime;
    /** @var callable */
    private $now;
    /** @var callable */
    private $sleep;
    /** @var callable */
    private $log;

    /**
     * Every collaborator can be replaced so the logic is testable without
     * systemd, sockets, sendmail or a clock.
     */
    public function __construct(
        string $root,
        ?callable $run = null,
        ?callable $probe = null,
        ?callable $mail = null,
        ?callable $uptime = null,
        ?callable $now = null,
        ?callable $sleep = null,
        ?callable $log = null
    ) {
        $this->root = rtrim($root, '/');
        $this->run = $run ?? [self::class, 'defaultRun'];
        $this->probe = $probe ?? [self::class, 'defaultProbe'];
        $this->mail = $mail ?? [self::class, 'defaultMail'];
        $this->uptime = $uptime ?? static function (): float {
            $u = is_readable('/proc/uptime') ? (string) @file_get_contents('/proc/uptime') : '';

            return (float) strtok($u, ' ');
        };
        $this->now = $now ?? static fn(): int => time();
        $this->sleep = $sleep ?? static function (int $s): void {
            sleep($s);
        };
        $this->log = $log ?? function (string $line): void {
            $this->writeLog($line);
        };
    }

    // ------------------------------------------------------------------ paths

    public static function stateDir(string $root): string
    {
        return rtrim($root, '/') . '/var/watchdog';
    }

    public static function validUnit(string $unit): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $unit) === 1;
    }

    // ------------------------------------------------------------------- hold

    /** Mark a unit as stopped on purpose: the watchdog will not restart it. */
    public static function hold(string $unit, string $root): void
    {
        if (!self::validUnit($unit)) {
            return;
        }
        $dir = self::stateDir($root) . '/hold';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        @file_put_contents($dir . '/' . $unit, (string) time() . "\n");
        @chmod($dir . '/' . $unit, 0644);
    }

    public static function release(string $unit, string $root): void
    {
        if (self::validUnit($unit)) {
            @unlink(self::stateDir($root) . '/hold/' . $unit);
        }
    }

    public static function isHeld(string $unit, string $root): bool
    {
        if (!self::validUnit($unit)) {
            return false;
        }
        clearstatcache(true);

        return is_file(self::stateDir($root) . '/hold/' . $unit);
    }

    // ----------------------------------------------------------------- config

    /** @return array{interval:int,max_restarts:int,window:int,grace:int,notify:string,disabled:list<string>} */
    public static function config(string $root): array
    {
        $cfg = self::DEFAULTS;
        $file = rtrim($root, '/') . '/etc/conf/watchdog.json';
        $j = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (is_array($j)) {
            foreach (['interval' => [15, 3600], 'max_restarts' => [1, 10], 'window' => [300, 86400], 'grace' => [0, 1800]] as $k => [$lo, $hi]) {
                if (isset($j[$k]) && is_int($j[$k]) && $j[$k] >= $lo && $j[$k] <= $hi) {
                    $cfg[$k] = $j[$k];
                }
            }
            if (isset($j['notify']) && is_string($j['notify']) && filter_var($j['notify'], FILTER_VALIDATE_EMAIL) !== false
                && !preg_match('/[\r\n,;<>]/', $j['notify'])) {
                $cfg['notify'] = $j['notify'];
            }
            if (isset($j['disabled']) && is_array($j['disabled'])) {
                $cfg['disabled'] = array_values(array_filter($j['disabled'], static fn($v): bool => is_string($v) && isset(self::SERVICES[$v])));
            }
        }

        return $cfg;
    }

    /** Admin contact address kept by the panel as a file (no database needed). */
    public static function contactEmail(string $root): string
    {
        $f = rtrim($root, '/') . '/etc/slavedb/contactemail';
        if (!is_file($f)) {
            return '';
        }
        $o = @unserialize((string) @file_get_contents($f), ['allowed_classes' => false]);
        $a = is_object($o) ? (array) $o : (is_array($o) ? $o : []);
        $mail = $a['data']['admin']['contactemail'] ?? '';

        return (is_string($mail) && filter_var($mail, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\r\n,;<>]/', $mail)) ? $mail : '';
    }

    /** Address that gets the mails: `notify` from watchdog.json, else the admin contact email. */
    public static function recipient(string $root): string
    {
        $n = self::config($root)['notify'];

        return $n !== '' ? $n : self::contactEmail($root);
    }

    /** Send a test message with the same mailer the watchdog uses. Returns a one-line result. */
    public function testMail(): string
    {
        $to = self::recipient($this->root);
        if ($to === '') {
            return 'no recipient: the admin has no contact email (panel profile) and watchdog.json has no notify address';
        }
        $host = (string) (gethostname() ?: 'server');
        $ok = ($this->mail)($to, "[Kloxo CP watchdog] {$host}: test message",
            "This is a test from the Kloxo CP watchdog.\nIf you can read it, restart and give-up notices will reach you.\n\nHost: {$host}\nTime: " . date('c', ($this->now)()) . "\n");

        return $ok ? "test message handed to the mail system for {$to}" : "could not hand the message for {$to} to the mail system (/usr/sbin/sendmail missing or failed)";
    }

    /** @return array<string,string> slavedb driver choices (web, dns, smtp, pop3, ...) */
    public static function drivers(string $root): array
    {
        $f = rtrim($root, '/') . '/etc/slavedb/driver';
        $o = is_file($f) ? @unserialize((string) @file_get_contents($f), ['allowed_classes' => false]) : false;
        $a = is_object($o) ? (array) $o : (is_array($o) ? $o : []);
        $data = $a['data'] ?? [];
        $out = [];
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                if (is_string($k) && is_string($v) && preg_match('/^[a-z0-9-]+$/', $v) === 1) {
                    $out[$k] = $v;
                }
            }
        }

        return $out;
    }

    /** @return array<string,bool> service => on, from the panel's Watchdog screen (etc/watchdog.conf) */
    public static function panelSettings(string $root): array
    {
        $f = rtrim($root, '/') . '/etc/watchdog.conf';
        $rows = is_file($f) ? @unserialize((string) @file_get_contents($f), ['allowed_classes' => false]) : false;
        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $r) {
                if (!is_array($r) || !isset($r['servicename']) || !is_string($r['servicename'])) {
                    continue;
                }
                $out[$r['servicename']] = in_array(strtolower((string) ($r['status'] ?? 'off')), ['on', '1', 'yes', 'true'], true);
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- targets

    /**
     * @return list<array{service:string,unit:string,ports:list<int>,sockets:list<string>}>
     */
    public function targets(): array
    {
        $drivers = self::drivers($this->root);
        $panel = self::panelSettings($this->root);
        $cfg = self::config($this->root);
        $out = [];
        foreach (self::SERVICES as $service => $def) {
            if (in_array($service, $cfg['disabled'], true)) {
                continue;
            }
            // The panel's own screen can switch a service off.
            if (isset($panel[$service]) && $panel[$service] === false) {
                continue;
            }
            $units = [];
            if (!empty($def['php_fpm'])) {
                foreach (['/lib/systemd/system', '/usr/lib/systemd/system', '/etc/systemd/system'] as $dir) {
                    foreach (glob($dir . '/php*-fpm.service') ?: [] as $f) {
                        $n = basename($f, '.service');
                        if (preg_match('/^php[0-9]+\.[0-9]+-fpm$/', $n) === 1) {
                            $units[$n] = true;
                        }
                    }
                }
                $units = array_keys($units);
            } elseif (isset($def['driver'])) {
                $chosen = $drivers[$def['driver']] ?? null;
                if ($chosen === null) {
                    // no slavedb yet: watch whatever is enabled
                    $units = array_values($def['units']);
                } elseif (isset($def['units'][$chosen])) {
                    $units = [$def['units'][$chosen]];
                }
            } else {
                $units = $def['units'];
            }
            foreach ($units as $unit) {
                $out[] = [
                    'service' => $service,
                    'unit' => (string) $unit,
                    'ports' => array_map('intval', $def['ports'] ?? []),
                    'sockets' => array_values($def['sockets'] ?? []),
                ];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ state

    /** @return array{updated:int,units:array<string,array<string,mixed>>} */
    public static function readState(string $root): array
    {
        $f = self::stateDir($root) . '/state.json';
        $j = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;

        return (is_array($j) && isset($j['units']) && is_array($j['units']))
            ? ['updated' => (int) ($j['updated'] ?? 0), 'units' => $j['units']]
            : ['updated' => 0, 'units' => []];
    }

    /** @param array{updated:int,units:array<string,array<string,mixed>>} $state */
    private function writeState(array $state): void
    {
        $dir = self::stateDir($this->root);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }
        $state['updated'] = ($this->now)();
        $tmp = $dir . '/state.json.tmp' . getmypid();
        if (@file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX) !== false) {
            @chmod($tmp, 0644);
            @rename($tmp, $dir . '/state.json');
        }
    }

    // ------------------------------------------------------------------ cycle

    /**
     * One pass over every watched unit.
     *
     * @return list<array{unit:string,event:string,reason:string}>
     */
    public function cycle(): array
    {
        $cfg = self::config($this->root);
        $events = [];
        if (($this->uptime)() < $cfg['grace']) {
            return $events;
        }
        $targets = $this->targets();
        if ($targets === []) {
            return $events;
        }
        $info = $this->show(array_map(static fn(array $t): string => $t['unit'], $targets));
        if ($info === null) {
            ($this->log)('systemctl not available; nothing to watch');

            return $events;
        }
        $state = self::readState($this->root);
        $now = ($this->now)();

        foreach ($targets as $t) {
            $unit = $t['unit'];
            $row = $info[$unit] ?? null;
            if ($row === null || ($row['LoadState'] ?? '') !== 'loaded') {
                continue;
            }
            if (!in_array($row['UnitFileState'] ?? '', ['enabled', 'enabled-runtime'], true)) {
                continue;
            }
            if (self::isHeld($unit, $this->root)) {
                continue;
            }
            $active = (string) ($row['ActiveState'] ?? '');
            if (in_array($active, ['activating', 'deactivating', 'reloading'], true)) {
                continue;
            }

            $reason = '';
            if ($active !== 'active') {
                $reason = 'unit is ' . ($active !== '' ? $active : 'unknown');
            } else {
                $reason = $this->probeFailure($t);
            }

            $u = $state['units'][$unit] ?? ['restarts' => [], 'gave_up' => false];
            $u['restarts'] = array_values(array_filter(
                is_array($u['restarts'] ?? null) ? $u['restarts'] : [],
                static fn($ts): bool => is_int($ts) && $now - $ts < $cfg['window']
            ));

            if ($reason === '') {
                if (!empty($u['gave_up'])) {
                    $u['gave_up'] = false;
                    $u['last'] = 'recovered';
                    $u['last_ts'] = $now;
                    ($this->log)("{$unit} is healthy again");
                    $events[] = ['unit' => $unit, 'event' => 'recovered', 'reason' => ''];
                }
                $state['units'][$unit] = $u;
                continue;
            }

            if (count($u['restarts']) >= $cfg['max_restarts']) {
                if (empty($u['gave_up'])) {
                    $u['gave_up'] = true;
                    $u['last'] = 'gave_up';
                    $u['last_ts'] = $now;
                    $u['last_reason'] = $reason;
                    ($this->log)("{$unit}: giving up after {$cfg['max_restarts']} restarts in " . intdiv($cfg['window'], 60) . " min ({$reason})");
                    $this->notify($cfg, $u, $unit, 'GAVE UP', "{$unit} keeps failing ({$reason}). Restarted {$cfg['max_restarts']} times in "
                        . intdiv($cfg['window'], 60) . " minutes; the watchdog stops trying. Check: journalctl -u {$unit}", $now);
                    $events[] = ['unit' => $unit, 'event' => 'gave_up', 'reason' => $reason];
                }
                $state['units'][$unit] = $u;
                continue;
            }

            // restart
            $u['gave_up'] = false;
            $u['restarts'][] = $now;
            ($this->log)("restarting {$unit} ({$reason})");
            $ok = $this->restart($t);
            $u['last'] = $ok ? 'restarted' : 'restart_failed';
            $u['last_ts'] = $now;
            $u['last_reason'] = $reason;
            $ev = $ok ? 'restarted' : 'restart_failed';
            ($this->log)($ok ? "{$unit} is back" : "{$unit} did not come back");
            $this->notify($cfg, $u, $unit, $ok ? 'restarted' : 'RESTART FAILED',
                "{$unit} was down ({$reason}); the watchdog " . ($ok ? 'restarted it and it is healthy.' : 'tried to restart it but it is still unhealthy.'), $now);
            $events[] = ['unit' => $unit, 'event' => $ev, 'reason' => $reason];
            $state['units'][$unit] = $u;
        }
        $this->writeState($state);

        return $events;
    }

    /** @param array{service:string,unit:string,ports:list<int>,sockets:list<string>} $t */
    private function probeFailure(array $t): string
    {
        if ($t['ports'] === [] && $t['sockets'] === []) {
            return '';
        }
        for ($try = 0; $try < 3; $try++) {
            foreach ($t['sockets'] as $s) {
                if (($this->probe)('unix', $s)) {
                    return '';
                }
            }
            foreach ($t['ports'] as $p) {
                if (($this->probe)('tcp', (string) $p)) {
                    return '';
                }
            }
            if ($try < 2) {
                ($this->sleep)(2);
            }
        }
        $what = $t['ports'] !== [] ? 'port ' . implode('/', $t['ports']) : 'socket ' . implode('/', $t['sockets']);

        return $what . ' does not answer';
    }

    /** @param array{service:string,unit:string,ports:list<int>,sockets:list<string>} $t */
    private function restart(array $t): bool
    {
        $bin = $this->systemctl();
        $unit = $t['unit'] . '.service';
        ($this->run)([$bin, 'reset-failed', $unit], 10);
        $r = ($this->run)([$bin, 'restart', $unit], 60);
        if (($r['rc'] ?? 1) !== 0) {
            return false;
        }
        for ($i = 0; $i < 8; $i++) {
            $info = $this->show([$t['unit']]);
            $active = $info[$t['unit']]['ActiveState'] ?? '';
            if ($active === 'active' && $this->probeFailureQuick($t) === '') {
                return true;
            }
            ($this->sleep)(2);
        }

        return false;
    }

    /** @param array{service:string,unit:string,ports:list<int>,sockets:list<string>} $t */
    private function probeFailureQuick(array $t): string
    {
        if ($t['ports'] === [] && $t['sockets'] === []) {
            return '';
        }
        foreach ($t['sockets'] as $s) {
            if (($this->probe)('unix', $s)) {
                return '';
            }
        }
        foreach ($t['ports'] as $p) {
            if (($this->probe)('tcp', (string) $p)) {
                return '';
            }
        }

        return 'down';
    }

    /**
     * @param array<string,mixed> $cfg
     * @param array<string,mixed> $u
     */
    private function notify(array $cfg, array &$u, string $unit, string $what, string $text, int $now): void
    {
        $to = self::recipient($this->root);
        if ($to === '') {
            ($this->log)("no admin email set: cannot mail about {$unit} {$what} (set it in the panel profile or notify in etc/conf/watchdog.json)");

            return;
        }
        // one mail per unit per window, so a flapping service cannot flood the inbox
        if (isset($u['notified_ts']) && is_int($u['notified_ts']) && $now - $u['notified_ts'] < $cfg['window']
            && $what !== 'GAVE UP') {
            return;
        }
        $host = (string) (gethostname() ?: 'server');
        $subject = "[Kloxo CP watchdog] {$host}: {$unit} {$what}";
        if (($this->mail)($to, $subject, $text . "\n\nHost: {$host}\nTime: " . date('c', $now) . "\n")) {
            $u['notified_ts'] = $now;
        }
    }

    // -------------------------------------------------------------- systemctl

    private function systemctl(): string
    {
        foreach (['/usr/bin/systemctl', '/bin/systemctl'] as $b) {
            if (is_executable($b)) {
                return $b;
            }
        }

        return '/usr/bin/systemctl';
    }

    /**
     * @param list<string> $units
     * @return array<string,array<string,string>>|null  null when systemctl cannot run
     */
    private function show(array $units): ?array
    {
        $argv = array_merge([$this->systemctl(), 'show', '--no-pager', '-p', 'Id', '-p', 'LoadState', '-p', 'ActiveState',
            '-p', 'SubState', '-p', 'UnitFileState'], array_map(static fn(string $u): string => $u . '.service', $units));
        $r = ($this->run)($argv, 15);
        if (($r['rc'] ?? 1) !== 0 && trim((string) ($r['out'] ?? '')) === '') {
            return null;
        }
        $info = [];
        foreach (preg_split('/\n\s*\n/', trim((string) $r['out'])) ?: [] as $block) {
            $row = [];
            foreach (preg_split('/\R/', $block) ?: [] as $line) {
                if (str_contains($line, '=')) {
                    [$k, $v] = explode('=', $line, 2);
                    $row[$k] = $v;
                }
            }
            if (isset($row['Id'])) {
                $info[(string) preg_replace('/\.service$/', '', $row['Id'])] = $row;
            }
        }

        return $info;
    }

    // ---------------------------------------------------------------- default collaborators

    /**
     * @param list<string> $argv
     * @return array{rc:int,out:string,err:string}
     */
    public static function defaultRun(array $argv, int $seconds = 10): array
    {
        $env = ['PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'LC_ALL' => 'C'];
        $p = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if (!is_resource($p)) {
            return ['rc' => 127, 'out' => '', 'err' => 'cannot run ' . $argv[0]];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $deadline = microtime(true) + $seconds;
        $rc = -1;
        while (true) {
            $out .= (string) fread($pipes[1], 8192);
            $err .= (string) fread($pipes[2], 8192);
            $st = proc_get_status($p);
            if (!$st['running']) {
                $rc = (int) $st['exitcode'];
                $out .= (string) stream_get_contents($pipes[1]);
                $err .= (string) stream_get_contents($pipes[2]);
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($p);
                $err .= "\ntimed out";
                break;
            }
            usleep(30000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($p);

        return ['rc' => $rc >= 0 ? $rc : (int) $closed, 'out' => $out, 'err' => trim($err)];
    }

    public static function defaultProbe(string $kind, string $target): bool
    {
        if ($kind === 'unix') {
            if (!preg_match('#^/[A-Za-z0-9._/-]+$#', $target)) {
                return false;
            }
            $s = @stream_socket_client('unix://' . $target, $errno, $errstr, 3);
        } else {
            $port = (int) $target;
            if ($port < 1 || $port > 65535) {
                return false;
            }
            $s = @stream_socket_client('tcp://127.0.0.1:' . $port, $errno, $errstr, 3);
            if (!$s) {
                $s = @stream_socket_client('tcp://[::1]:' . $port, $errno, $errstr, 3);
            }
        }
        if (!$s) {
            return false;
        }
        fclose($s);

        return true;
    }

    public static function defaultMail(string $to, string $subject, string $body): bool
    {
        $bin = '/usr/sbin/sendmail';
        if (!is_executable($bin) || preg_match('/[\r\n]/', $to . $subject) === 1) {
            return false;
        }
        $msg = "To: {$to}\nSubject: {$subject}\nContent-Type: text/plain; charset=utf-8\nAuto-Submitted: auto-generated\n\n{$body}";
        $p = @proc_open([$bin, '-t', '-i'], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!is_resource($p)) {
            return false;
        }
        fwrite($pipes[0], $msg);
        fclose($pipes[0]);

        return proc_close($p) === 0;
    }

    private function writeLog(string $line): void
    {
        $line = date('c') . ' watchdog: ' . preg_replace('/[^\x20-\x7e]/', '?', $line) . "\n";
        fwrite(STDERR, $line);
        $dir = $this->root . '/log';
        if (!is_dir($dir)) {
            return;
        }
        $f = $dir . '/watchdog.log';
        if (is_file($f) && (int) @filesize($f) > 1048576) {
            $tail = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: [], -200);
            @file_put_contents($f, implode("\n", $tail) . "\n");
        }
        @file_put_contents($f, $line, FILE_APPEND | LOCK_EX);
    }
}
