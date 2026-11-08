<?php
declare(strict_types=1);

/**
 * Recovery console support: keeps server applications manageable when the
 * panel database (MariaDB) or the customer web server (Nginx/Apache) is down.
 *
 * Self-contained on purpose: no legacy panel code, no database. The page
 * (httpdocs/recovery/index.php) logs in with a recovery password that lives in
 * a file, lists whitelisted systemd units, and starts/stops/restarts them.
 * Privileged work (systemctl, journalctl) runs as root inside kloxo-backend
 * through rl_exec_get(); everything else runs as the panel user.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

require_once __DIR__ . '/kloxo_watchdog.php';
require_once __DIR__ . '/kloxo_authlog.php';

final class KloxoRecovery
{
    /** Failed logins allowed per client address inside the window. */
    public const MAX_FAILURES = 5;
    public const FAIL_WINDOW = 900;
    public const SESSION_IDLE = 900;

    /** Units that must not be stopped, restarted or disabled from the console. */
    private const SELF_UNITS = ['kloxo-web', 'kloxo-php', 'kloxo-backend'];

    /** Logical name => systemd unit. Only these can ever be acted on. */
    private const CATALOG = [
        'nginx' => 'nginx',
        'apache2' => 'apache2',
        'mariadb' => 'mariadb',
        'bind9' => 'bind9',
        'knot' => 'knot',
        'exim4' => 'exim4',
        'postfix' => 'postfix',
        'dovecot' => 'dovecot',
        'rspamd' => 'rspamd',
        'pure-ftpd' => 'pure-ftpd',
        'redis-server' => 'redis-server',
        'ufw' => 'ufw',
        'fail2ban' => 'fail2ban',
        'kloxo-webcache' => 'kloxo-webcache',
        'kloxo-webproxy' => 'kloxo-webproxy',
        'kloxo-backend' => 'kloxo-backend',
        'kloxo-watchdog' => 'kloxo-watchdog',
        'kloxo-php' => 'kloxo-php',
        'kloxo-web' => 'kloxo-web',
    ];

    public const ACTIONS = ['start', 'stop', 'restart', 'enable', 'disable'];

    /**
     * Switch Program pairs: only one of each pair may be active or enabled at boot.
     * Starting, restarting or enabling one stops and disables the other.
     *
     * @var array<string,string>
     */
    private const EXCLUSIVE = [
        'nginx' => 'apache2',
        'apache2' => 'nginx',
        'bind9' => 'knot',
        'knot' => 'bind9',
        'exim4' => 'postfix',
        'postfix' => 'exim4',
    ];

    /**
     * Extra systemd names for a catalog unit. Debian bind9.service is an alias
     * of named.service; postfix.service is often an alias of postfix@-.service.
     * A multi-unit `systemctl show` can omit the alias, which looked like
     * "not installed".
     *
     * @var array<string,list<string>>
     */
    private const UNIT_ALIASES = [
        'bind9' => ['named'],
        'postfix' => ['postfix@-'],
    ];

    /** Other catalog name that must be inactive when this one is started. Empty if none. */
    public static function rivalOf(string $name): string
    {
        return self::EXCLUSIVE[$name] ?? '';
    }

    public static function rootDir(): string
    {
        $env = getenv('KLOXO_ROOT');
        if (is_string($env) && $env !== '' && $env[0] === '/') {
            return rtrim($env, '/');
        }

        return dirname(__DIR__, 3);
    }

    public static function credentialPath(?string $root = null): string
    {
        return ($root ?? self::rootDir()) . '/etc/conf/recovery.json';
    }

    public static function stateDir(?string $root = null): string
    {
        return ($root ?? self::rootDir()) . '/var/recovery';
    }

    // ---------------------------------------------------------------- credential

    public static function generatePassword(int $length = 20): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $max = strlen($alphabet) - 1;
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    public static function hashPassword(string $plain): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        $h = password_hash($plain, $algo);
        if (!is_string($h) || $h === '') {
            throw new RuntimeException('cannot_hash_password');
        }

        return $h;
    }

    /**
     * Atomically write the credential file (0640, group lxlabs when it exists).
     * Only the hash is stored.
     */
    public static function writeCredential(string $plain, ?string $path = null): void
    {
        if (strlen($plain) < 12) {
            throw new InvalidArgumentException('recovery_password_too_short');
        }
        $path ??= self::credentialPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot_create_credential_dir');
        }
        $json = json_encode(['version' => 1, 'hash' => self::hashPassword($plain), 'updated' => time()],
            JSON_UNESCAPED_SLASHES);
        $tmp = $path . '.tmp' . getmypid();
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('cannot_write_credential');
        }
        @chmod($tmp, 0640);
        if (function_exists('posix_getgrnam') && is_array($g = @posix_getgrnam('lxlabs'))
            && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            @chgrp($tmp, (int) $g['gid']);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('cannot_install_credential');
        }
    }

    public static function hasCredential(?string $path = null): bool
    {
        return self::readHash($path ?? self::credentialPath()) !== '';
    }

    public static function verifyCredential(string $plain, ?string $path = null): bool
    {
        $hash = self::readHash($path ?? self::credentialPath());
        // Burn comparable time when no credential exists.
        if ($hash === '') {
            password_verify($plain, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');

            return false;
        }

        return password_verify($plain, $hash);
    }

    private static function readHash(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            return '';
        }
        $j = json_decode((string) @file_get_contents($path), true);

        return (is_array($j) && isset($j['hash']) && is_string($j['hash'])) ? $j['hash'] : '';
    }

    // ------------------------------------------------------------------ throttle

    private static function throttleFile(string $ip, string $dir): string
    {
        return $dir . '/fail-' . hash('sha256', $ip) . '.json';
    }

    /** Seconds the client must wait, or 0 when a login attempt is allowed. */
    public static function lockedFor(string $ip, ?string $dir = null, ?int $now = null): int
    {
        $dir ??= self::stateDir();
        $now ??= time();
        $f = self::throttleFile($ip, $dir);
        $j = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
        if (!is_array($j) || !isset($j['first'], $j['count'])) {
            return 0;
        }
        if ($now - (int) $j['first'] >= self::FAIL_WINDOW) {
            return 0;
        }
        if ((int) $j['count'] < self::MAX_FAILURES) {
            return 0;
        }

        return max(1, self::FAIL_WINDOW - ($now - (int) $j['first']));
    }

    public static function recordFailure(string $ip, ?string $dir = null, ?int $now = null): void
    {
        $dir ??= self::stateDir();
        $now ??= time();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $f = self::throttleFile($ip, $dir);
        $j = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
        if (!is_array($j) || !isset($j['first'], $j['count']) || $now - (int) $j['first'] >= self::FAIL_WINDOW) {
            $j = ['first' => $now, 'count' => 0];
        }
        $j['count'] = (int) $j['count'] + 1;
        @file_put_contents($f, json_encode($j), LOCK_EX);
        @chmod($f, 0600);
    }

    public static function clearFailures(string $ip, ?string $dir = null): void
    {
        @unlink(self::throttleFile($ip, $dir ?? self::stateDir()));
    }

    // --------------------------------------------------------------------- units

    /** @return array<string,string> logical name => unit, including installed phpX.Y-fpm */
    public static function catalog(): array
    {
        $cat = self::CATALOG;
        foreach (['/lib/systemd/system', '/usr/lib/systemd/system', '/etc/systemd/system'] as $dir) {
            foreach (glob($dir . '/php*-fpm.service') ?: [] as $f) {
                $n = basename($f, '.service');
                if (preg_match('/^php[0-9]+\.[0-9]+-fpm$/', $n) === 1) {
                    $cat[$n] = $n;
                }
            }
        }

        return $cat;
    }

    public static function isKnown(string $name): bool
    {
        return isset(self::catalog()[$name]);
    }

    public static function mayAct(string $name, string $act): bool
    {
        if (!self::isKnown($name) || !in_array($act, self::ACTIONS, true)) {
            return false;
        }
        if (in_array($name, self::SELF_UNITS, true)) {
            // Stopping or restarting these ends the request being served.
            return in_array($act, ['start', 'enable'], true);
        }

        return true;
    }

    /** systemd UnitFileState values that already start the unit at boot. */
    public static function isEnabledAtBoot(string $unitFileState): bool
    {
        return in_array($unitFileState, ['enabled', 'enabled-runtime', 'static', 'indirect'], true);
    }

    public static function systemctlBin(): string
    {
        if (defined('KLOXO_RECOVERY_SYSTEMCTL') && is_string(KLOXO_RECOVERY_SYSTEMCTL)
            && is_executable(KLOXO_RECOVERY_SYSTEMCTL)) {
            return KLOXO_RECOVERY_SYSTEMCTL;
        }
        foreach (['/usr/bin/systemctl', '/bin/systemctl'] as $b) {
            if (is_executable($b)) {
                return $b;
            }
        }

        return '';
    }

    /**
     * @param list<string> $argv
     * @return array{rc:int,out:string,err:string}
     */
    private static function run(array $argv, int $seconds = 8): array
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
                if ($rc < 0 && $st['exitcode'] >= 0) {
                    $rc = (int) $st['exitcode'];
                }
                $out .= (string) stream_get_contents($pipes[1]);
                $err .= (string) stream_get_contents($pipes[2]);
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($p);
                $err .= "\ntimed out";
                break;
            }
            if (strlen($out) > 65536) {
                break;
            }
            usleep(30000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($p);

        return ['rc' => $rc >= 0 ? $rc : (int) $closed, 'out' => $out, 'err' => trim($err)];
    }

    /**
     * systemd Id of a catalog unit. Debian bind9.service is an alias of
     * named.service; enable/disable refuse the alias name.
     */
    public static function canonicalUnit(string $unitBase): string
    {
        $want = $unitBase . '.service';
        $bin = self::systemctlBin();
        if ($bin === '') {
            return $want;
        }
        $r = self::run([$bin, 'show', '--no-pager', '-p', 'Id', '--value', $want], 5);
        $id = trim($r['out']);
        if ($id !== '' && preg_match('/^[A-Za-z0-9_.@-]+\.service$/', $id) === 1) {
            return $id;
        }
        if ($id !== '' && preg_match('/^[A-Za-z0-9_.@-]+$/', $id) === 1) {
            return $id . '.service';
        }

        return $want;
    }

    /**
     * Unit states, read without privileges.
     *
     * @return list<array{name:string,unit:string,installed:bool,active:string,sub:string,enabled:string,self:bool}>
     */
    public static function states(): array
    {
        $cat = self::catalog();
        $bin = self::systemctlBin();
        $info = [];
        if ($bin !== '') {
            $show = array_values($cat);
            foreach (self::UNIT_ALIASES as $alts) {
                foreach ($alts as $a) {
                    $show[] = $a;
                }
            }
            $units = array_map(static fn(string $u): string => $u . '.service', array_values(array_unique($show)));
            // Do not pair blocks by argument index: systemd omits alias names
            // (bind9, postfix) from a multi-unit show, which shifted later rows
            // and left those two as "not installed". Key by Id/Names instead,
            // and ask for the real units (named, postfix@-) as well.
            $r = self::run(array_merge([$bin, 'show', '--no-pager',
                '-p', 'Id', '-p', 'Names', '-p', 'LoadState', '-p', 'ActiveState',
                '-p', 'SubState', '-p', 'UnitFileState'], $units));
            $blocks = preg_split('/\n\s*\n/', trim($r['out'])) ?: [];
            foreach ($blocks as $block) {
                $row = self::parseShowBlock((string) $block);
                if ($row === []) {
                    continue;
                }
                foreach (self::showBlockKeys($row) as $k) {
                    $prev = $info[$k]['LoadState'] ?? '';
                    if (!self::loadStateInstalled($prev)) {
                        $info[$k] = $row;
                    }
                }
            }
        }
        $out = [];
        foreach ($cat as $name => $unit) {
            $row = self::stateRow($info, $name, $unit);
            $installed = self::loadStateInstalled((string) ($row['LoadState'] ?? ''));
            $out[] = [
                'name' => $name,
                'unit' => $unit,
                'installed' => $installed,
                'active' => (string) ($row['ActiveState'] ?? 'unknown'),
                'sub' => (string) ($row['SubState'] ?? ''),
                'enabled' => (string) ($row['UnitFileState'] ?? ''),
                'self' => in_array($name, self::SELF_UNITS, true),
            ];
        }

        return $out;
    }

    /** systemd LoadState values that mean the unit exists on this machine. */
    public static function loadStateInstalled(string $load): bool
    {
        return in_array($load, ['loaded', 'masked', 'alias'], true);
    }

    /**
     * @param array<string,array<string,string>> $info
     * @return array<string,string>
     */
    private static function stateRow(array $info, string $name, string $unit): array
    {
        $cands = array_merge([$unit, $name], self::UNIT_ALIASES[$name] ?? []);
        $best = [];
        foreach (array_unique($cands) as $c) {
            $row = $info[$c] ?? [];
            if (self::loadStateInstalled((string) ($row['LoadState'] ?? ''))) {
                return $row;
            }
            if ($row !== [] && $best === []) {
                $best = $row;
            }
        }

        return $best;
    }

    /** @return array<string,string> */
    private static function parseShowBlock(string $block): array
    {
        $row = [];
        foreach (preg_split('/\R/', $block) ?: [] as $line) {
            if (str_contains($line, '=')) {
                [$k, $v] = explode('=', $line, 2);
                $row[$k] = $v;
            }
        }

        return $row;
    }

    /**
     * @param array<string,string> $row
     * @return list<string>
     */
    private static function showBlockKeys(array $row): array
    {
        $keys = [];
        if (($row['Id'] ?? '') !== '') {
            $keys[] = (string) preg_replace('/\.service$/', '', $row['Id']);
        }
        foreach (preg_split('/\s+/', (string) ($row['Names'] ?? '')) ?: [] as $n) {
            if ($n !== '') {
                $keys[] = (string) preg_replace('/\.service$/', '', $n);
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Stop and disable the Switch Program pair of $name so both cannot run.
     *
     * @return array{err:string,retired:string}
     */
    private static function retireRival(string $bin, string $name): array
    {
        $rival = self::EXCLUSIVE[$name] ?? '';
        if ($rival === '' || !isset(self::catalog()[$rival])) {
            return ['err' => '', 'retired' => ''];
        }
        $unitBase = self::catalog()[$rival];
        $unit = self::canonicalUnit($unitBase);
        $show = self::run([$bin, 'show', '--no-pager', '-p', 'LoadState', '-p', 'ActiveState', '-p', 'UnitFileState', $unit], 5);
        $row = self::parseShowBlock($show['out']);
        if (!self::loadStateInstalled((string) ($row['LoadState'] ?? ''))) {
            return ['err' => '', 'retired' => ''];
        }
        $active = (string) ($row['ActiveState'] ?? '');
        $boot = (string) ($row['UnitFileState'] ?? '');
        $did = [];
        if ($active === 'active') {
            self::run([$bin, '--no-block', 'stop', $unit], 10);
            $deadline = microtime(true) + 12;
            do {
                $active = self::activeState($bin, $unit);
                if ($active !== 'active' && $active !== 'deactivating') {
                    break;
                }
                usleep(250000);
            } while (microtime(true) < $deadline);
            if ($active === 'active') {
                return ['err' => 'could not stop ' . $rival . ' (it must be inactive to start ' . $name . ')', 'retired' => ''];
            }
            KloxoWatchdog::hold($unitBase, self::rootDir());
            $did[] = 'stopped ' . $rival;
            $boot = self::unitFileState($bin, $unit);
        }
        if (self::isEnabledAtBoot($boot)) {
            self::run([$bin, 'disable', $unit], 10);
            $boot = self::unitFileState($bin, $unit);
            if (!self::isEnabledAtBoot($boot)) {
                KloxoWatchdog::hold($unitBase, self::rootDir());
                $did[] = 'disabled ' . $rival . ' at boot';
            }
        }

        return ['err' => '', 'retired' => implode('; ', $did)];
    }

    /**
     * Root side (runs inside kloxo-backend): control one unit and wait for it.
     *
     * @return array{ok:bool,rc:int,euid:int,active:string,err:string,enabled?:string,retired?:string}
     */
    public static function serverControl(string $act, string $name): array
    {
        $euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : -1;
        $res = ['ok' => false, 'rc' => -1, 'euid' => $euid, 'active' => 'unknown', 'err' => ''];
        if (!self::mayAct($name, $act)) {
            $res['err'] = 'refused';

            return $res;
        }
        $bin = self::systemctlBin();
        if ($bin === '') {
            $res['err'] = 'systemctl not found';

            return $res;
        }
        if (in_array($act, ['start', 'restart', 'enable'], true)) {
            $pair = self::retireRival($bin, $name);
            if ($pair['err'] !== '') {
                $res['err'] = $pair['err'];

                return $res;
            }
            if ($pair['retired'] !== '') {
                $res['retired'] = $pair['retired'];
            }
        }
        $unitBase = self::catalog()[$name];
        $unit = self::canonicalUnit($unitBase);
        if (in_array($act, ['start', 'restart'], true)) {
            // A unit left "failed" refuses to start until reset.
            self::run([$bin, 'reset-failed', $unit], 5);
        }
        $args = [$bin];
        if (in_array($act, ['start', 'stop', 'restart'], true)) {
            $args[] = '--no-block';
        }
        $r = self::run(array_merge($args, [$act, $unit]), 10);
        $res['rc'] = $r['rc'];
        $res['err'] = $r['err'];
        if (in_array($act, ['start', 'stop', 'restart'], true)) {
            $deadline = microtime(true) + 12;
            if ($act === 'restart') {
                usleep(300000);
            }
            do {
                $res['active'] = self::activeState($bin, $unit);
                $settled = $act === 'stop'
                    ? !in_array($res['active'], ['active', 'deactivating'], true)
                    : in_array($res['active'], ['active', 'failed'], true);
                if ($settled) {
                    break;
                }
                usleep(250000);
            } while (microtime(true) < $deadline);
        } else {
            $res['active'] = self::activeState($bin, $unit);
        }
        if (in_array($act, ['enable', 'disable'], true)) {
            $res['enabled'] = self::unitFileState($bin, $unit);
        }
        // A unit stopped here on purpose must not be brought back by the watchdog;
        // starting it again hands it back to the watchdog.
        if ($r['rc'] === 0) {
            if (in_array($act, ['stop', 'disable'], true)) {
                KloxoWatchdog::hold($unitBase, self::rootDir());
            } elseif (in_array($act, ['start', 'restart'], true)) {
                KloxoWatchdog::release($unitBase, self::rootDir());
            }
        }
        $bootOk = !isset($res['enabled'])
            || ($act === 'enable' && self::isEnabledAtBoot($res['enabled']))
            || ($act === 'disable' && !self::isEnabledAtBoot($res['enabled']));
        $res['ok'] = $r['rc'] === 0 && $bootOk && ($act === 'stop' ? $res['active'] !== 'active' : (in_array($act, ['enable', 'disable'], true) || $res['active'] === 'active'));
        if (!$res['ok'] && $res['err'] === '') {
            $res['err'] = $euid > 0
                ? 'ran as uid ' . $euid . ', not root: kloxo-backend did not handle the request'
                : 'unit state is ' . $res['active'];
        }

        return $res;
    }

    private static function activeState(string $bin, string $unit): string
    {
        $r = self::run([$bin, 'show', '--no-pager', '-p', 'ActiveState', '--value', $unit], 5);

        return trim($r['out']) !== '' ? trim($r['out']) : 'unknown';
    }

    private static function unitFileState(string $bin, string $unit): string
    {
        $r = self::run([$bin, 'show', '--no-pager', '-p', 'UnitFileState', '--value', $unit], 5);

        return trim($r['out']);
    }

    /**
     * Root side: last journal lines of a unit (journalctl needs root or the
     * systemd-journal group).
     *
     * @return array{ok:bool,text:string,euid:int}
     */
    public static function serverJournal(string $name, int $lines = 40): array
    {
        $euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : -1;
        if (!self::isKnown($name)) {
            return ['ok' => false, 'text' => 'refused', 'euid' => $euid];
        }
        $lines = max(5, min(200, $lines));
        $bin = '/usr/bin/journalctl';
        if (defined('KLOXO_RECOVERY_JOURNALCTL') && is_string(KLOXO_RECOVERY_JOURNALCTL)
            && is_executable(KLOXO_RECOVERY_JOURNALCTL)) {
            $bin = KLOXO_RECOVERY_JOURNALCTL;
        }
        if (!is_executable($bin)) {
            return ['ok' => false, 'text' => 'journalctl not found', 'euid' => $euid];
        }
        $r = self::run([$bin, '-u', self::canonicalUnit(self::catalog()[$name]), '-n', (string) $lines, '--no-pager', '-o', 'short-iso'], 8);
        $text = $r['out'] !== '' ? $r['out'] : $r['err'];
        $text = (string) preg_replace('/[^\x09\x0a\x20-\x7e]/', '?', $text);

        return ['ok' => $r['rc'] === 0, 'text' => substr($text, -12000), 'euid' => $euid];
    }

    // ------------------------------------------------------------------- client

    /**
     * Run a root-side method. Panel PHP-FPM is not root, so it asks
     * kloxo-backend; as root (CLI, tests) the method runs in-process.
     *
     * @param list<mixed> $args
     * @return array<string,mixed>|null
     */
    public static function viaBackend(string $method, array $args): ?array
    {
        if (!in_array($method, ['serverControl', 'serverJournal'], true)) {
            return null;
        }
        $euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : -1;
        if ($euid === 0 || !function_exists('rl_exec_get')) {
            $got = self::$method(...$args);

            return is_array($got) ? $got : null;
        }
        try {
            $got = rl_exec_get(null, 'localhost', ['KloxoRecovery', $method], $args);
        } catch (Throwable $e) {
            return ['ok' => false, 'err' => 'kloxo-backend: ' . $e->getMessage(), 'text' => 'kloxo-backend: ' . $e->getMessage(), 'euid' => $euid];
        }

        return is_array($got) ? $got : null;
    }

    /**
     * Stand-in for the global $login. The legacy RPC helpers only ask a few
     * questions of it, and no logged-in user exists when the database is down.
     * The page includes lib/html/include.php at global scope (the legacy files
     * need that) and then assigns this to $login.
     */
    public static function backendLoginStub(): object
    {
        return new class {
            public function isSuperClient(): bool
            {
                return false;
            }

            public function isAdmin(): bool
            {
                return false;
            }

            public function getThrow(string $k): string
            {
                return $k;
            }
        };
    }

    /** True when the exception means the panel database cannot be reached. */
    public static function isDatabaseDown(Throwable $e): bool
    {
        if ($e instanceof mysqli_sql_exception) {
            return in_array((int) $e->getCode(), [2002, 2003, 2006, 2013], true);
        }

        return false;
    }

    /** Small 503 page that points to the recovery console. */
    public static function databaseDownHtml(): string
    {
        return '<!doctype html><meta charset="utf-8"><title>MariaDB unavailable</title>'
            . '<body style="font:15px sans-serif;max-width:40em;margin:3em auto">'
            . '<h2>A MariaDB connection failed</h2>'
            . '<p>The panel itself uses SQLite and stays up. Customer databases, phpMyAdmin or mail lookups need MariaDB. Start it from the '
            . '<a href="/recovery/">recovery console</a>.</p></body>';
    }
}
