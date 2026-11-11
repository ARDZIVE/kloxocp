<?php
declare(strict_types=1);

/**
 * Fail2Ban frontend for Ubuntu. Runs as root through kloxo-backend.
 *
 * Settings live in etc/conf/fail2ban.json. The jail file
 * /etc/fail2ban/jail.d/kloxo-cp.local is rendered from them and is never edited
 * by hand: render, check with `fail2ban-client -t`, install atomically, reload,
 * and put the previous file back if the check or the reload fails.
 * Process execution uses argument arrays only; every value that reaches a
 * command line or the jail file is validated first.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoFail2ban
{
    /** Tests point every absolute path under this directory. Never set from input. */
    public static string $fsPrefix = '';

    public const JAIL_FILE = 'kloxo-cp.local';

    /** Filters shipped in file/fail2ban/filter.d and installed with the jail file. */
    public const CUSTOM_FILTERS = ['kloxo-panel', 'kloxo-recovery'];

    // ------------------------------------------------------------------ paths

    public static function rootDir(): string
    {
        $env = getenv('KLOXO_ROOT');
        if (is_string($env) && $env !== '' && $env[0] === '/') {
            return rtrim($env, '/');
        }

        return '/opt/kloxo';
    }

    private static function p(string $abs): string
    {
        return self::$fsPrefix . $abs;
    }

    public static function configPath(?string $root = null): string
    {
        return ($root ?? self::rootDir()) . '/etc/conf/fail2ban.json';
    }

    public static function jailFilePath(): string
    {
        return self::p('/etc/fail2ban/jail.d/' . self::JAIL_FILE);
    }

    public static function clientBin(): string
    {
        foreach (['/usr/bin/fail2ban-client', '/usr/local/bin/fail2ban-client'] as $b) {
            if (is_executable(self::p($b))) {
                return self::p($b);
            }
        }

        return '';
    }

    private static function systemctlBin(): string
    {
        foreach (['/usr/bin/systemctl', '/bin/systemctl'] as $b) {
            if (is_executable(self::p($b))) {
                return self::p($b);
            }
        }

        return '';
    }

    public static function isInstalled(): bool
    {
        return self::clientBin() !== '';
    }

    // ---------------------------------------------------------------- catalog

    /**
     * Every situation the panel can watch. `stock` jails exist in Ubuntu's
     * jail.conf (they are switched off explicitly when disabled, because other
     * files such as the mail templates may enable them); the others are defined
     * here. `ports` is a fail2ban port list, 'ssh' (resolved from sshd_config)
     * or 'all' (every port).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function catalog(): array
    {
        $errWeb = ['/var/log/nginx/error.log', '/home/httpd/*/stats/*-error_log'];
        $errApache = ['/var/log/apache2/error.log', '/home/httpd/*/stats/*-error_log'];
        $mailPorts = 'smtp,465,submission';
        $mboxPorts = 'pop3,pop3s,imap,imaps,submission,465,sieve';

        return [
            'sshd' => [
                'group' => 'Server access', 'title' => 'SSH logins',
                'desc' => 'Wrong passwords and unknown users on SSH (brute force).',
                'stock' => true, 'filter' => 'sshd', 'ports' => 'ssh',
                'journal' => '_COMM=sshd', 'needs' => ['ssh', 'sshd'], 'default' => true,
            ],
            'recidive' => [
                'group' => 'Server access', 'title' => 'Repeat offenders',
                'desc' => 'Addresses that get banned again and again are banned for a week on every port.',
                'stock' => true, 'filter' => 'recidive', 'ports' => 'all',
                'files' => ['/var/log/fail2ban.log'], 'needs' => ['fail2ban'], 'default' => true,
                'maxretry' => 3, 'findtime' => '1d', 'bantime' => '1w',
            ],
            'kloxo-panel' => [
                'group' => 'Panel', 'title' => 'Panel login failures',
                'desc' => 'Wrong username or password on the panel login page (ports 7777/7778).',
                'stock' => false, 'filter' => 'kloxo-panel', 'ports' => '7777,7778',
                'files' => ['{ROOT}/log/auth-failures.log'], 'needs' => ['kloxo-web'], 'default' => true,
            ],
            'kloxo-recovery' => [
                'group' => 'Panel', 'title' => 'Recovery console failures',
                'desc' => 'Wrong password on the recovery console (/recovery/).',
                'stock' => false, 'filter' => 'kloxo-recovery', 'ports' => '7777,7778',
                'files' => ['{ROOT}/log/auth-failures.log'], 'needs' => ['kloxo-web'], 'default' => true,
            ],
            'exim' => [
                'group' => 'Mail', 'title' => 'SMTP authentication and abuse',
                'desc' => 'Failed SMTP logins and clients rejected by Exim.',
                'stock' => true, 'filter' => 'exim', 'ports' => $mailPorts,
                'files' => ['/var/log/exim4/mainlog'], 'needs' => ['exim4'], 'default' => true,
            ],
            'dovecot' => [
                'group' => 'Mail', 'title' => 'IMAP / POP3 logins',
                'desc' => 'Failed mailbox logins on Dovecot (IMAP, POP3, Sieve).',
                'stock' => true, 'filter' => 'dovecot', 'ports' => $mboxPorts,
                'journal' => '_SYSTEMD_UNIT=dovecot.service', 'needs' => ['dovecot'], 'default' => true,
            ],
            'postfix' => [
                'group' => 'Mail', 'title' => 'Postfix rejections',
                'desc' => 'Clients rejected by Postfix (only when Postfix is the selected SMTP program).',
                'stock' => true, 'filter' => 'postfix', 'ports' => $mailPorts,
                'journal' => '_SYSTEMD_UNIT=postfix@-.service', 'needs' => ['postfix'], 'default' => false,
            ],
            'nginx-http-auth' => [
                'group' => 'Web', 'title' => 'Nginx password-protected areas',
                'desc' => 'Failed HTTP basic authentication on sites served by Nginx.',
                'stock' => true, 'filter' => 'nginx-http-auth', 'ports' => 'http,https',
                'files' => $errWeb, 'needs' => ['nginx'], 'default' => true,
            ],
            'nginx-botsearch' => [
                'group' => 'Web', 'title' => 'Nginx script scanners',
                'desc' => 'Bots probing for scripts that do not exist (wp-login.php, phpmyadmin, ...).',
                'stock' => true, 'filter' => 'nginx-botsearch', 'ports' => 'http,https',
                'files' => $errWeb, 'needs' => ['nginx'], 'default' => false,
            ],
            'apache-auth' => [
                'group' => 'Web', 'title' => 'Apache password-protected areas',
                'desc' => 'Failed HTTP basic authentication on sites served by Apache.',
                'stock' => true, 'filter' => 'apache-auth', 'ports' => 'http,https',
                'files' => $errApache, 'needs' => ['apache2'], 'default' => false,
            ],
            'apache-noscript' => [
                'group' => 'Web', 'title' => 'Apache script probes',
                'desc' => 'Requests for scripts that do not exist, seen in the Apache error log.',
                'stock' => true, 'filter' => 'apache-noscript', 'ports' => 'http,https',
                'files' => $errApache, 'needs' => ['apache2'], 'default' => false,
            ],
            'apache-botsearch' => [
                'group' => 'Web', 'title' => 'Apache bot searches',
                'desc' => 'Bots scanning for well-known vulnerable files on Apache.',
                'stock' => true, 'filter' => 'apache-botsearch', 'ports' => 'http,https',
                'files' => $errApache, 'needs' => ['apache2'], 'default' => false,
            ],
            'apache-overflows' => [
                'group' => 'Web', 'title' => 'Apache overflow attempts',
                'desc' => 'Over-long requests and buffer overflow attempts against Apache.',
                'stock' => true, 'filter' => 'apache-overflows', 'ports' => 'http,https',
                'files' => $errApache, 'needs' => ['apache2'], 'default' => false,
            ],
            'pure-ftpd' => [
                'group' => 'FTP', 'title' => 'FTP logins',
                'desc' => 'Failed logins on Pure-FTPd.',
                'stock' => true, 'filter' => 'pure-ftpd', 'ports' => 'ftp,ftp-data,ftps,ftps-data',
                'journal' => '_SYSTEMD_UNIT=pure-ftpd.service', 'needs' => ['pure-ftpd'], 'default' => true,
            ],
            'mysqld-auth' => [
                'group' => 'Database', 'title' => 'MariaDB logins',
                'desc' => 'Failed logins on MariaDB (only matters when it listens on a network address).',
                'stock' => true, 'filter' => 'mysqld-auth', 'ports' => '3306',
                'journal' => '_SYSTEMD_UNIT=mariadb.service', 'needs' => ['mariadb'], 'default' => false,
            ],
        ];
    }

    public static function isJail(string $name): bool
    {
        return isset(self::catalog()[$name]);
    }

    // ------------------------------------------------------------ validation

    public static function validTime(string $v, bool $allowForever = true): string
    {
        $v = trim($v);
        if ($v === '-1' && $allowForever) {
            return $v;
        }
        if (preg_match('/^[1-9][0-9]{0,8}[smhdw]?$/', $v) !== 1) {
            throw new InvalidArgumentException('invalid_fail2ban_time');
        }

        return $v;
    }

    /** @param int|string $v */
    public static function validRetry($v): int
    {
        $s = trim((string) $v);
        if (preg_match('/^[1-9][0-9]{0,3}$/', $s) !== 1) {
            throw new InvalidArgumentException('invalid_fail2ban_maxretry');
        }

        return (int) $s;
    }

    /** One IPv4 or IPv6 address, no CIDR. */
    public static function validIp(string $ip): string
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('invalid_fail2ban_ip');
        }
        $bin = @inet_pton($ip);
        if ($bin === false) {
            throw new InvalidArgumentException('invalid_fail2ban_ip');
        }

        return (string) inet_ntop($bin);
    }

    /** IPv4/IPv6 address or CIDR for the whitelist. A /0 would switch banning off. */
    public static function validIgnoreEntry(string $e): string
    {
        $e = trim($e);
        if (str_contains($e, '/')) {
            [$addr, $len] = explode('/', $e, 2);
            $addr = self::validIp($addr);
            $max = str_contains($addr, ':') ? 128 : 32;
            if (preg_match('/^[0-9]{1,3}$/', $len) !== 1 || (int) $len < 1 || (int) $len > $max) {
                throw new InvalidArgumentException('invalid_fail2ban_ignoreip');
            }

            return $addr . '/' . (int) $len;
        }
        try {
            return self::validIp($e);
        } catch (InvalidArgumentException $x) {
            throw new InvalidArgumentException('invalid_fail2ban_ignoreip');
        }
    }

    /**
     * @param string|array<int,mixed> $in text (whitespace/comma separated) or list
     * @return list<string>
     */
    public static function parseIgnoreList($in): array
    {
        $parts = is_array($in) ? array_map('strval', $in) : (preg_split('/[\s,;]+/', (string) $in) ?: []);
        $out = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            $out[self::validIgnoreEntry($p)] = true;
        }
        if (count($out) > 100) {
            throw new InvalidArgumentException('invalid_fail2ban_ignoreip');
        }

        return array_keys($out);
    }

    /** True when $ip is inside one of the addresses or networks. */
    public static function ipInList(string $ip, array $entries): bool
    {
        $b = @inet_pton($ip);
        if ($b === false) {
            return false;
        }
        foreach ($entries as $e) {
            $e = (string) $e;
            $len = null;
            if (str_contains($e, '/')) {
                [$e, $l] = explode('/', $e, 2);
                $len = (int) $l;
            }
            $eb = @inet_pton($e);
            if ($eb === false || strlen($eb) !== strlen($b)) {
                continue;
            }
            $bits = $len ?? strlen($b) * 8;
            $full = intdiv($bits, 8);
            if ($full > 0 && substr($b, 0, $full) !== substr($eb, 0, $full)) {
                continue;
            }
            $rem = $bits % 8;
            if ($rem > 0) {
                $mask = (0xFF << (8 - $rem)) & 0xFF;
                if ((ord($b[$full]) & $mask) !== (ord($eb[$full]) & $mask)) {
                    continue;
                }
            }

            return true;
        }

        return false;
    }

    // ---------------------------------------------------------------- config

    /** @return array{defaults:array{bantime:string,findtime:string,maxretry:int,ignoreip:list<string>},jails:array<string,array<string,mixed>>} */
    public static function defaultConfig(): array
    {
        $jails = [];
        foreach (self::catalog() as $name => $def) {
            $jails[$name] = ['enabled' => !empty($def['default'])];
        }

        return [
            'defaults' => ['bantime' => '1h', 'findtime' => '10m', 'maxretry' => 5, 'ignoreip' => ['127.0.0.1/8', '::1']],
            'jails' => $jails,
        ];
    }

    /**
     * Validate a configuration; anything unknown is dropped. Throws
     * InvalidArgumentException on a bad value.
     *
     * @param array<string,mixed> $in
     * @return array{defaults:array{bantime:string,findtime:string,maxretry:int,ignoreip:list<string>},jails:array<string,array<string,mixed>>}
     */
    public static function normalizeConfig(array $in): array
    {
        $cfg = self::defaultConfig();
        $d = isset($in['defaults']) && is_array($in['defaults']) ? $in['defaults'] : [];
        if (isset($d['bantime'])) {
            $cfg['defaults']['bantime'] = self::validTime((string) $d['bantime']);
        }
        if (isset($d['findtime'])) {
            $cfg['defaults']['findtime'] = self::validTime((string) $d['findtime'], false);
        }
        if (isset($d['maxretry'])) {
            $cfg['defaults']['maxretry'] = self::validRetry($d['maxretry']);
        }
        if (isset($d['ignoreip'])) {
            $cfg['defaults']['ignoreip'] = self::parseIgnoreList($d['ignoreip']);
        }
        $j = isset($in['jails']) && is_array($in['jails']) ? $in['jails'] : [];
        foreach ($j as $name => $row) {
            if (!is_string($name) || !self::isJail($name) || !is_array($row)) {
                continue;
            }
            $o = ['enabled' => !empty($row['enabled'])];
            if (isset($row['maxretry']) && $row['maxretry'] !== '') {
                $o['maxretry'] = self::validRetry($row['maxretry']);
            }
            if (isset($row['findtime']) && $row['findtime'] !== '') {
                $o['findtime'] = self::validTime((string) $row['findtime'], false);
            }
            if (isset($row['bantime']) && $row['bantime'] !== '') {
                $o['bantime'] = self::validTime((string) $row['bantime']);
            }
            $cfg['jails'][$name] = $o;
        }

        return $cfg;
    }

    /** @return array<string,mixed> */
    public static function loadConfig(?string $root = null): array
    {
        $f = self::configPath($root);
        $raw = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null;
        if (!is_array($raw)) {
            return self::defaultConfig();
        }
        try {
            return self::normalizeConfig($raw);
        } catch (InvalidArgumentException $e) {
            return self::defaultConfig();
        }
    }

    /** @param array<string,mixed> $cfg */
    public static function saveConfig(array $cfg, ?string $root = null): void
    {
        $f = self::configPath($root);
        $dir = dirname($f);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('fail2ban_config_not_writable');
        }
        $tmp = $f . '.tmp.' . getmypid();
        $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($tmp, $json . "\n") === false) {
            throw new RuntimeException('fail2ban_config_not_writable');
        }
        @chmod($tmp, 0644);
        if (!@rename($tmp, $f)) {
            @unlink($tmp);
            throw new RuntimeException('fail2ban_config_not_writable');
        }
    }

    /**
     * Setting that applies to one jail: its own value, else the jail's built-in
     * default, else the global default.
     *
     * @param array<string,mixed> $cfg
     * @return array{maxretry:int,findtime:string,bantime:string}
     */
    public static function effective(array $cfg, string $name): array
    {
        $def = self::catalog()[$name] ?? [];
        $o = $cfg['jails'][$name] ?? [];
        $g = $cfg['defaults'];

        return [
            'maxretry' => (int) ($o['maxretry'] ?? $def['maxretry'] ?? $g['maxretry']),
            'findtime' => (string) ($o['findtime'] ?? $def['findtime'] ?? $g['findtime']),
            'bantime' => (string) ($o['bantime'] ?? $def['bantime'] ?? $g['bantime']),
        ];
    }

    // ----------------------------------------------------------- availability

    private static function unitInstalled(string $unit): bool
    {
        foreach (['/lib/systemd/system', '/usr/lib/systemd/system', '/etc/systemd/system'] as $d) {
            if (is_file(self::p($d . '/' . $unit . '.service'))) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $globs */
    private static function firstExisting(array $globs): string
    {
        foreach ($globs as $g) {
            $g = str_replace('{ROOT}', self::rootDir(), $g);
            $hit = glob(self::p($g)) ?: [];
            if ($hit !== []) {
                return $g;
            }
        }

        return '';
    }

    private static function journalSupport(): bool
    {
        return (glob(self::p('/usr/lib/python3*/dist-packages/systemd/journal.py')) ?: []) !== []
            || (glob(self::p('/usr/lib/python3/dist-packages/systemd/_journal*')) ?: []) !== [];
    }

    /**
     * Can the jail run on this host?
     *
     * @param array<string,mixed> $def
     * @return array{ok:bool,reason:string}
     */
    public static function availability(array $def): array
    {
        $needs = isset($def['needs']) && is_array($def['needs']) ? $def['needs'] : [];
        if ($needs !== []) {
            $found = false;
            foreach ($needs as $u) {
                if (self::unitInstalled((string) $u)) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return ['ok' => false, 'reason' => $needs[0] . ' is not installed'];
            }
        }
        $filter = (string) ($def['filter'] ?? '');
        if ($filter !== '' && !in_array($filter, self::CUSTOM_FILTERS, true)
            && !is_file(self::p('/etc/fail2ban/filter.d/' . $filter . '.conf'))) {
            return ['ok' => false, 'reason' => 'filter ' . $filter . ' is missing from this Fail2Ban version'];
        }
        if (isset($def['journal']) && !self::journalSupport()) {
            return ['ok' => false, 'reason' => 'python3-systemd is not installed (needed to read the journal)'];
        }
        if (isset($def['files']) && is_array($def['files'])
            && !in_array((string) ($def['filter'] ?? ''), self::CUSTOM_FILTERS, true)
            && self::firstExisting($def['files']) === '') {
            return ['ok' => false, 'reason' => 'log file not found: ' . str_replace('{ROOT}', self::rootDir(), (string) $def['files'][0])];
        }

        return ['ok' => true, 'reason' => ''];
    }

    /** SSH ports from sshd_config (default 22). */
    public static function sshPorts(): string
    {
        $files = [self::p('/etc/ssh/sshd_config')];
        foreach (glob(self::p('/etc/ssh/sshd_config.d/*.conf')) ?: [] as $f) {
            $files[] = $f;
        }
        $ports = [];
        foreach ($files as $f) {
            $txt = is_file($f) ? (string) @file_get_contents($f) : '';
            foreach (preg_split('/\R/', $txt) ?: [] as $line) {
                if (preg_match('/^\s*Port\s+([0-9]{1,5})\s*$/i', $line, $m) === 1 && (int) $m[1] >= 1 && (int) $m[1] <= 65535) {
                    $ports[(int) $m[1]] = true;
                }
            }
        }

        return $ports === [] ? '22' : implode(',', array_keys($ports));
    }

    // ---------------------------------------------------------------- render

    /**
     * @param array<string,mixed> $cfg
     * @param array<string,string> $skipped out: jail => reason it is not running
     * @param list<string> $running out: jails written as enabled
     */
    public static function render(array $cfg, array &$skipped = [], array &$running = []): string
    {
        $skipped = [];
        $running = [];
        $d = $cfg['defaults'];
        $out = "# Kloxo CP: generated from etc/conf/fail2ban.json by KloxoFail2ban. Do not edit by hand.\n"
            . "# Use Security > Fail2Ban in the panel; changes made here are overwritten.\n\n"
            . "[DEFAULT]\n"
            . 'ignoreip = ' . implode(' ', $d['ignoreip']) . "\n"
            . 'bantime = ' . $d['bantime'] . "\n"
            . 'findtime = ' . $d['findtime'] . "\n"
            . 'maxretry = ' . $d['maxretry'] . "\n"
            . "banaction = nftables\n"
            . "banaction_allports = nftables[type=allports]\n\n";

        foreach (self::catalog() as $name => $def) {
            $want = !empty($cfg['jails'][$name]['enabled']);
            $av = self::availability($def);
            if ($want && !$av['ok']) {
                $skipped[$name] = $av['reason'];
            }
            if (!$want || !$av['ok']) {
                if (!empty($def['stock'])) {
                    $out .= '[' . $name . "]\nenabled = false\n\n";
                }
                continue;
            }
            $e = self::effective($cfg, $name);
            $out .= '[' . $name . "]\nenabled = true\n"
                . 'filter = ' . $def['filter'] . "\n";
            $ports = (string) ($def['ports'] ?? '');
            if ($ports === 'all') {
                $out .= "banaction = %(banaction_allports)s\n";
            } else {
                $out .= 'port = ' . ($ports === 'ssh' ? self::sshPorts() : $ports) . "\n";
            }
            if (isset($def['journal'])) {
                $out .= "backend = systemd\njournalmatch = " . $def['journal'] . "\n";
            } else {
                $paths = array_map(static fn(string $g): string => str_replace('{ROOT}', self::rootDir(), $g), $def['files']);
                $out .= 'logpath = ' . implode("\n          ", $paths) . "\nbackend = auto\n";
            }
            $out .= 'maxretry = ' . $e['maxretry'] . "\n"
                . 'findtime = ' . $e['findtime'] . "\n"
                . 'bantime = ' . $e['bantime'] . "\n\n";
            $running[] = $name;
        }

        return $out;
    }

    // ----------------------------------------------------------------- apply

    /** Create the auth log with the right owner so the panel can append and fail2ban can read. */
    public static function ensureAuthLog(?string $root = null): void
    {
        $root ??= self::rootDir();
        $dir = $root . '/log';
        if (!is_dir($dir)) {
            return;
        }
        $f = $dir . '/auth-failures.log';
        if (!is_file($f)) {
            @touch($f);
        }
        @chmod($f, 0664);
        if (function_exists('posix_geteuid') && posix_geteuid() === 0
            && function_exists('posix_getgrnam') && posix_getgrnam('lxlabs') !== false) {
            @chown($f, 'lxlabs');
            @chgrp($f, 'lxlabs');
        }
    }

    private static function writeAtomic(string $path, string $content, int $mode = 0644): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $content) === false) {
            return false;
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /**
     * Render the jail file from the saved configuration, check it, install it
     * and reload Fail2Ban. The previous files are restored on any failure.
     *
     * @return array{ok:bool,error:string,running:list<string>,skipped:array<string,string>}
     */
    public static function apply(): array
    {
        $res = ['ok' => false, 'error' => '', 'running' => [], 'skipped' => []];
        if (!self::isInstalled()) {
            $res['error'] = 'fail2ban_not_installed';

            return $res;
        }
        self::ensureAuthLog();
        $cfg = self::loadConfig();
        $skipped = [];
        $running = [];
        $text = self::render($cfg, $skipped, $running);

        // Filters first (the jail refers to them); remember the old content.
        $backups = [];
        $srcDir = self::rootDir() . '/file/fail2ban/filter.d';
        foreach (self::CUSTOM_FILTERS as $f) {
            $src = $srcDir . '/' . $f . '.conf';
            $dst = self::p('/etc/fail2ban/filter.d/' . $f . '.conf');
            $new = is_file($src) ? (string) file_get_contents($src) : '';
            if ($new === '') {
                continue;
            }
            $old = is_file($dst) ? (string) file_get_contents($dst) : null;
            if ($old !== $new) {
                $backups[$dst] = $old;
                if (!self::writeAtomic($dst, $new)) {
                    self::restore($backups);
                    $res['error'] = 'fail2ban_cannot_write_filter';

                    return $res;
                }
            }
        }
        $jailFile = self::jailFilePath();
        $oldJail = is_file($jailFile) ? (string) file_get_contents($jailFile) : null;
        $backups[$jailFile] = $oldJail;
        if (!self::writeAtomic($jailFile, $text)) {
            self::restore($backups);
            $res['error'] = 'fail2ban_cannot_write_jail_file';

            return $res;
        }

        $t = self::run([self::clientBin(), '-t']);
        if ($t['code'] !== 0) {
            self::restore($backups);
            $res['error'] = 'fail2ban_config_rejected: ' . self::clean($t['out'] . ' ' . $t['err']);

            return $res;
        }

        if (self::serviceActive()) {
            $r = self::run([self::clientBin(), 'reload']);
        } else {
            $r = self::startService();
        }
        if ($r['code'] !== 0) {
            self::restore($backups);
            if (self::serviceActive()) {
                self::run([self::clientBin(), 'reload']);
            }
            $res['error'] = 'fail2ban_reload_failed: ' . self::clean($r['out'] . ' ' . $r['err']);

            return $res;
        }

        $res['ok'] = true;
        $res['running'] = $running;
        $res['skipped'] = $skipped;

        return $res;
    }

    /** @param array<string,?string> $backups path => previous content (null = file did not exist) */
    private static function restore(array $backups): void
    {
        foreach ($backups as $path => $old) {
            if ($old === null) {
                @unlink($path);
            } else {
                self::writeAtomic($path, $old);
            }
        }
    }

    private static function startService(): array
    {
        $sc = self::systemctlBin();
        if ($sc === '') {
            return ['code' => 127, 'out' => '', 'err' => 'systemctl not found'];
        }
        self::run([$sc, 'enable', 'fail2ban']);

        return self::run([$sc, 'restart', 'fail2ban'], 40);
    }

    public static function serviceActive(): bool
    {
        $sc = self::systemctlBin();
        if ($sc === '') {
            return false;
        }
        $r = self::run([$sc, 'is-active', 'fail2ban'], 10);

        return trim($r['out']) === 'active';
    }

    // ------------------------------------------------------------ live status

    /** @return list<string> */
    public static function parseJailList(string $out): array
    {
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            if (preg_match('/Jail list:\s*(.*)$/', $line, $m) === 1) {
                $names = [];
                foreach (preg_split('/[\s,]+/', trim($m[1])) ?: [] as $n) {
                    if (preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $n) === 1) {
                        $names[] = $n;
                    }
                }

                return $names;
            }
        }

        return [];
    }

    /** @return array{failed_now:int,failed_total:int,banned_now:int,banned_total:int,banned:list<string>} */
    public static function parseJailStatus(string $out): array
    {
        $g = static function (string $re) use ($out): int {
            return preg_match($re, $out, $m) === 1 ? (int) $m[1] : 0;
        };
        $ips = [];
        if (preg_match('/Banned IP list:[ \t]*(.*)$/m', $out, $m) === 1) {
            foreach (preg_split('/\s+/', trim($m[1])) ?: [] as $ip) {
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    $ips[] = $ip;
                }
            }
        }

        return [
            'failed_now' => $g('/Currently failed:\s*([0-9]+)/'),
            'failed_total' => $g('/Total failed:\s*([0-9]+)/'),
            'banned_now' => $g('/Currently banned:\s*([0-9]+)/'),
            'banned_total' => $g('/Total banned:\s*([0-9]+)/'),
            'banned' => $ips,
        ];
    }

    /**
     * @return array{installed:bool,active:bool,version:string,error:string,jails:array<string,array<string,mixed>>}
     */
    public static function status(): array
    {
        $st = ['installed' => self::isInstalled(), 'active' => false, 'version' => '', 'error' => '', 'jails' => []];
        if (!$st['installed']) {
            $st['error'] = 'fail2ban_not_installed';

            return $st;
        }
        $v = self::run([self::clientBin(), '--version'], 10);
        if ($v['code'] === 0 && preg_match('/([0-9]+\.[0-9]+(?:\.[0-9]+)?)/', $v['out'], $m) === 1) {
            $st['version'] = $m[1];
        }
        $st['active'] = self::serviceActive();
        if (!$st['active']) {
            return $st;
        }
        // The control socket is root-only. Callers that are not root must go
        // through kloxo-backend (execF2b with no local fallback). Skip when
        // $fsPrefix is set (unit tests use a fake client).
        $euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : 0;
        if ($euid !== 0 && self::$fsPrefix === '') {
            $st['error'] = 'fail2ban_backend_failed: fail2ban-client needs root (start kloxo-backend)';

            return $st;
        }
        $r = self::run([self::clientBin(), 'status'], 20);
        if ($r['code'] !== 0) {
            $st['error'] = 'fail2ban_status_failed: ' . self::clean($r['err'] . ' ' . $r['out']);

            return $st;
        }
        foreach (self::parseJailList($r['out']) as $jail) {
            $j = self::run([self::clientBin(), 'status', $jail], 20);
            if ($j['code'] === 0) {
                $st['jails'][$jail] = self::parseJailStatus($j['out']);
            }
        }

        return $st;
    }

    /**
     * Everything the Fail2Ban page shows, from a single read of Fail2Ban.
     *
     * @return array{status:array<string,mixed>,rows:list<array<string,mixed>>,banned:list<array{jail:string,ip:string}>,log:list<array<string,string>>}
     */
    public static function overview(): array
    {
        $st = self::status();

        return [
            'status' => $st,
            'rows' => self::jailRows($st),
            'banned' => self::banned($st),
            'log' => self::recentLog(60),
        ];
    }

    /**
     * One row per situation for the jail list: catalog + saved settings + live counters.
     *
     * @param array<string,mixed>|null $st result of status() when the caller already has it
     * @return list<array<string,mixed>>
     */
    public static function jailRows(?array $st = null): array
    {
        $cfg = self::loadConfig();
        $st ??= self::status();
        $rows = [];
        foreach (self::catalog() as $name => $def) {
            $av = self::availability($def);
            $e = self::effective($cfg, $name);
            $live = $st['jails'][$name] ?? null;
            $rows[] = [
                'name' => $name,
                'group' => (string) $def['group'],
                'title' => (string) $def['title'],
                'desc' => (string) $def['desc'],
                'available' => $av['ok'],
                'reason' => $av['reason'],
                'enabled' => !empty($cfg['jails'][$name]['enabled']),
                'running' => is_array($live),
                'maxretry' => $e['maxretry'],
                'findtime' => $e['findtime'],
                'bantime' => $e['bantime'],
                'failed_now' => is_array($live) ? $live['failed_now'] : 0,
                'failed_total' => is_array($live) ? $live['failed_total'] : 0,
                'banned_now' => is_array($live) ? $live['banned_now'] : 0,
                'banned_total' => is_array($live) ? $live['banned_total'] : 0,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed>|null $st result of status() when the caller already has it
     * @return list<array{jail:string,ip:string}>
     */
    public static function banned(?array $st = null): array
    {
        $out = [];
        $st ??= self::status();
        foreach ($st['jails'] as $jail => $j) {
            foreach ($j['banned'] as $ip) {
                $out[] = ['jail' => (string) $jail, 'ip' => (string) $ip];
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- actions

    /**
     * Ban one address in a running jail. Refuses loopback, a whitelisted
     * address and the address the admin is browsing from ($protect).
     *
     * @return array{ok:bool,error:string}
     */
    public static function ban(string $jail, string $ip, string $protect = ''): array
    {
        try {
            $ip = self::validIp($ip);
            if (!self::isJail($jail)) {
                throw new InvalidArgumentException('invalid_fail2ban_jail');
            }
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $cfg = self::loadConfig();
        $p = trim($protect) !== '' ? @inet_pton(trim($protect)) : false;
        if (self::ipInList($ip, ['127.0.0.0/8', '::1']) || self::ipInList($ip, $cfg['defaults']['ignoreip'])
            || ($p !== false && $p === inet_pton($ip))) {
            return ['ok' => false, 'error' => 'fail2ban_refuse_ban_protected'];
        }
        if (!isset(self::status()['jails'][$jail])) {
            return ['ok' => false, 'error' => 'fail2ban_jail_not_running'];
        }
        $r = self::run([self::clientBin(), 'set', $jail, 'banip', $ip]);

        return $r['code'] === 0 ? ['ok' => true, 'error' => '']
            : ['ok' => false, 'error' => 'fail2ban_ban_failed: ' . self::clean($r['err'] . ' ' . $r['out'])];
    }

    /** @return array{ok:bool,error:string} */
    public static function unban(string $jail, string $ip): array
    {
        try {
            $ip = self::validIp($ip);
            if (!self::isJail($jail)) {
                throw new InvalidArgumentException('invalid_fail2ban_jail');
            }
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $r = self::run([self::clientBin(), 'set', $jail, 'unbanip', $ip]);

        return $r['code'] === 0 ? ['ok' => true, 'error' => '']
            : ['ok' => false, 'error' => 'fail2ban_unban_failed: ' . self::clean($r['err'] . ' ' . $r['out'])];
    }

    /** Remove the address from every jail. @return array{ok:bool,error:string} */
    public static function unbanEverywhere(string $ip): array
    {
        try {
            $ip = self::validIp($ip);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $r = self::run([self::clientBin(), 'unban', $ip]);

        return $r['code'] === 0 ? ['ok' => true, 'error' => '']
            : ['ok' => false, 'error' => 'fail2ban_unban_failed: ' . self::clean($r['err'] . ' ' . $r['out'])];
    }

    /** @return array{ok:bool,error:string} */
    public static function unbanAll(): array
    {
        $r = self::run([self::clientBin(), 'unban', '--all']);

        return $r['code'] === 0 ? ['ok' => true, 'error' => '']
            : ['ok' => false, 'error' => 'fail2ban_unban_failed: ' . self::clean($r['err'] . ' ' . $r['out'])];
    }

    /**
     * Save a configuration and apply it; the saved file goes back to the old
     * content when Fail2Ban refuses the result.
     *
     * @param array<string,mixed> $new
     * @return array{ok:bool,error:string,running:list<string>,skipped:array<string,string>}
     */
    private static function saveAndApply(array $new): array
    {
        $root = self::rootDir();
        $f = self::configPath($root);
        $old = is_file($f) ? (string) file_get_contents($f) : null;
        try {
            self::saveConfig($new, $root);
        } catch (RuntimeException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'running' => [], 'skipped' => []];
        }
        $r = self::apply();
        if (!$r['ok']) {
            if ($old === null) {
                @unlink($f);
            } else {
                self::writeAtomic($f, $old);
            }
        }

        return $r;
    }

    /**
     * Change one jail. Keys: enabled (bool), maxretry, findtime, bantime
     * (blank = use the default).
     *
     * @param array<string,mixed> $fields
     * @return array{ok:bool,error:string,running:list<string>,skipped:array<string,string>}
     */
    public static function setJail(string $name, array $fields): array
    {
        $fail = static fn(string $e): array => ['ok' => false, 'error' => $e, 'running' => [], 'skipped' => []];
        if (!self::isJail($name)) {
            return $fail('invalid_fail2ban_jail');
        }
        $cfg = self::loadConfig();
        $row = $cfg['jails'][$name] ?? ['enabled' => false];
        try {
            if (array_key_exists('enabled', $fields)) {
                $row['enabled'] = (bool) $fields['enabled'];
            }
            foreach (['maxretry', 'findtime', 'bantime'] as $k) {
                if (!array_key_exists($k, $fields)) {
                    continue;
                }
                if (trim((string) $fields[$k]) === '') {
                    unset($row[$k]);
                } else {
                    $row[$k] = $k === 'maxretry' ? self::validRetry($fields[$k])
                        : self::validTime((string) $fields[$k], $k !== 'findtime');
                }
            }
            if (!empty($row['enabled'])) {
                $av = self::availability(self::catalog()[$name]);
                if (!$av['ok']) {
                    return $fail('fail2ban_jail_unavailable: ' . $av['reason']);
                }
            }
            $cfg['jails'][$name] = $row;
            $cfg = self::normalizeConfig($cfg);
        } catch (InvalidArgumentException $e) {
            return $fail($e->getMessage());
        }

        return self::saveAndApply($cfg);
    }

    /**
     * Change the global defaults: bantime, findtime, maxretry, ignoreip (text or list).
     *
     * @param array<string,mixed> $fields
     * @return array{ok:bool,error:string,running:list<string>,skipped:array<string,string>}
     */
    public static function setDefaults(array $fields): array
    {
        $cfg = self::loadConfig();
        try {
            foreach (['bantime', 'findtime', 'maxretry', 'ignoreip'] as $k) {
                if (array_key_exists($k, $fields)) {
                    $cfg['defaults'][$k] = $fields[$k];
                }
            }
            $cfg = self::normalizeConfig($cfg);
            // Never let the whitelist end up without loopback.
            if (!self::ipInList('127.0.0.1', $cfg['defaults']['ignoreip']) || !self::ipInList('::1', $cfg['defaults']['ignoreip'])) {
                $cfg['defaults']['ignoreip'] = array_values(array_unique(array_merge(['127.0.0.1/8', '::1'], $cfg['defaults']['ignoreip'])));
            }
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'running' => [], 'skipped' => []];
        }

        return self::saveAndApply($cfg);
    }

    /** Add an address to the whitelist (used by the installer for the SSH client). */
    public static function whitelistAdd(string $entry): array
    {
        try {
            $entry = self::validIgnoreEntry($entry);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'running' => [], 'skipped' => []];
        }
        $cfg = self::loadConfig();
        $list = $cfg['defaults']['ignoreip'];
        if (!in_array($entry, $list, true)) {
            $list[] = $entry;
        }

        return self::setDefaults(['ignoreip' => $list]);
    }

    // -------------------------------------------------------------------- log

    /**
     * Newest first: bans, unbans and problems from /var/log/fail2ban.log.
     *
     * @return list<array{time:string,event:string,jail:string,ip:string,message:string}>
     */
    public static function recentLog(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $f = self::p('/var/log/fail2ban.log');
        if (!is_file($f) || !is_readable($f)) {
            return [];
        }
        $size = (int) filesize($f);
        $h = @fopen($f, 'rb');
        if ($h === false) {
            return [];
        }
        $take = min($size, 524288);
        fseek($h, $size - $take);
        $data = (string) fread($h, $take);
        fclose($h);
        $lines = preg_split('/\R/', $data) ?: [];
        $out = [];
        for ($i = count($lines) - 1; $i >= 0 && count($out) < $limit; $i--) {
            $ev = self::parseLogLine($lines[$i]);
            if ($ev !== null) {
                $out[] = $ev;
            }
        }

        return $out;
    }

    /** @return array{time:string,event:string,jail:string,ip:string,message:string}|null */
    public static function parseLogLine(string $line): ?array
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}),\d+\s+\S+\s+\[\d+\]:\s+(\w+)\s+(.*)$/', $line, $m) !== 1) {
            return null;
        }
        $msg = $m[3];
        if (preg_match('/^\[([A-Za-z0-9_.-]{1,64})\]\s+(Restore Ban|Ban|Unban)\s+(\S+)\s*$/', $msg, $b) === 1
            && filter_var($b[3], FILTER_VALIDATE_IP) !== false) {
            return ['time' => $m[1], 'event' => $b[2], 'jail' => $b[1], 'ip' => $b[3], 'message' => ''];
        }
        if (in_array($m[2], ['ERROR', 'CRITICAL', 'WARNING'], true)) {
            return ['time' => $m[1], 'event' => $m[2], 'jail' => '', 'ip' => '', 'message' => self::clean($msg)];
        }

        return null;
    }

    // ---------------------------------------------------------------- process

    /** Printable text without markup characters, for messages shown in the panel. */
    public static function clean(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', $s));
        $s = (string) preg_replace('/[^\x20-\x7e]/', '?', $s);
        $s = str_replace(['<', '>', '&', '"', "'"], '', $s);

        return substr($s, 0, 200);
    }

    /**
     * @param list<string> $cmd
     * @return array{code:int,out:string,err:string}
     */
    public static function run(array $cmd, int $timeout = 25): array
    {
        if ($cmd === [] || !isset($cmd[0]) || !is_string($cmd[0]) || $cmd[0] === '' || !is_executable($cmd[0])) {
            return ['code' => 127, 'out' => '', 'err' => 'fail2ban_not_installed'];
        }
        foreach ($cmd as $arg) {
            if (!is_string($arg) || str_contains($arg, "\0")) {
                return ['code' => 2, 'out' => '', 'err' => 'invalid_argument'];
            }
        }
        $p = @proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            return ['code' => 127, 'out' => '', 'err' => 'exec_failed'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $err = '';
        $deadline = microtime(true) + $timeout;
        $code = -1;
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);
            $s = proc_get_status($p);
            if (!$s['running']) {
                $code = (int) $s['exitcode'];
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($p, 9);
                $err .= ' timed out';
                $code = 124;
                break;
            }
            usleep(20000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($p);
        if ($code === -1 || ($code === 0 && $closed > 0)) {
            $code = $closed;
        }

        return ['code' => $code, 'out' => $out, 'err' => $err];
    }
}
