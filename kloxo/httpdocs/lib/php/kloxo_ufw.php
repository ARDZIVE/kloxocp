<?php
declare(strict_types=1);

/**
 * Ubuntu UFW frontend. Argument-array process execution only.
 *
 * Enable always allows panel ports 7777/7778 (and SSH/web/mail/FTP/DNS)
 * first so the host does not lock out the panel. Never builds a shell string.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoUfw
{
    /** @return list<int> */
    public static function protectedTcpPorts(): array
    {
        // Panel, SSH, web, FTP, mail (SMTP/submission/IMAP/POP), Watchdog frontends.
        return [
            21, 22, 25, 80, 110, 143, 443, 465, 587, 993, 995,
            7777, 7778, 8080, 30080,
        ];
    }

    /**
     * Purpose of each default (install-time) open port, used as the rule
     * comment. Characters are limited to what validateComment() accepts.
     *
     * @return array<string,string> key "port/proto"
     */
    public static function defaultComments(): array
    {
        return [
            '21/tcp' => 'FTP file transfer, Pure-FTPd control channel',
            '22/tcp' => 'SSH remote administration',
            '25/tcp' => 'SMTP, mail delivery between servers',
            '53/tcp' => 'DNS over TCP, zone transfers and large answers',
            '53/udp' => 'DNS queries, BIND or Knot DNS',
            '80/tcp' => 'HTTP, websites',
            '110/tcp' => 'POP3, mail download, STARTTLS required',
            '143/tcp' => 'IMAP, mail access, STARTTLS required',
            '443/tcp' => 'HTTPS, websites over TLS',
            '465/tcp' => 'SMTPS, mail submission over implicit TLS',
            '587/tcp' => 'Mail submission with STARTTLS',
            '993/tcp' => 'IMAPS, mail access over TLS',
            '995/tcp' => 'POP3S, mail download over TLS',
            '7777/tcp' => 'Kloxo CP panel HTTPS',
            '7778/tcp' => 'Kloxo CP panel HTTP',
            '8080/tcp' => 'Webcache frontend, Nginx',
            '30080/tcp' => 'Webproxy frontend, Nginx',
        ];
    }

    /** Default purpose text for a plain single-port rule, '' when unknown. */
    public static function defaultComment(string $port, string $proto): string
    {
        $map = self::defaultComments();
        $key = trim($port) . '/' . strtolower(trim($proto));

        return $map[$key] ?? '';
    }

    /** @return list<int> */
    public static function panelPorts(): array
    {
        return [7777, 7778];
    }

    public static function isPanelPort(int $port): bool
    {
        return in_array($port, self::panelPorts(), true);
    }

    public static function ufwBinary(): string
    {
        // Tests define this constant to point at a fake ufw; never set from input.
        if (defined('KLOXO_UFW_TEST_BIN') && is_string(KLOXO_UFW_TEST_BIN)
            && is_executable(KLOXO_UFW_TEST_BIN)) {
            return KLOXO_UFW_TEST_BIN;
        }
        foreach (['/usr/sbin/ufw', '/sbin/ufw', '/usr/bin/ufw'] as $p) {
            if (is_executable($p)) {
                return $p;
            }
        }

        return '';
    }

    public static function isInstalled(): bool
    {
        return self::ufwBinary() !== '';
    }

    public static function validateAction(string $action): string
    {
        $a = strtolower(trim($action));
        if ($a !== 'allow' && $a !== 'deny' && $a !== 'reject') {
            throw new InvalidArgumentException('invalid_ufw_action');
        }

        return $a;
    }

    public static function validateProto(string $proto): string
    {
        $p = strtolower(trim($proto));
        if ($p !== 'tcp' && $p !== 'udp' && $p !== 'icmp') {
            throw new InvalidArgumentException('invalid_ufw_proto');
        }

        return $p;
    }

    public static function validateComment(string $comment): string
    {
        $comment = trim($comment);
        if ($comment === '') {
            return '';
        }
        if (strlen($comment) > 80) {
            throw new InvalidArgumentException('invalid_ufw_comment');
        }
        // UFW comments are single-quoted on the CLI; keep printable safe chars only.
        if (preg_match("/^[A-Za-z0-9 .,_:@+\\/\\-]+$/", $comment) !== 1) {
            throw new InvalidArgumentException('invalid_ufw_comment');
        }

        return $comment;
    }

    public static function validatePort(string $port): string
    {
        $port = trim(str_replace(' ', '', $port));
        if ($port === '' || $port === '-' || strcasecmp($port, 'any') === 0) {
            throw new InvalidArgumentException('invalid_ufw_port');
        }
        // Range 80-90 → UFW 80:90
        if (preg_match('/^(\d+)-(\d+)$/', $port, $m) === 1) {
            $port = $m[1] . ':' . $m[2];
        }
        if (preg_match('/^\d+$/', $port) === 1) {
            $n = (int) $port;
            if ($n < 1 || $n > 65535) {
                throw new InvalidArgumentException('invalid_ufw_port');
            }

            return (string) $n;
        }
        if (preg_match('/^(\d+):(\d+)$/', $port, $m) === 1) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            if ($a < 1 || $b > 65535 || $a > $b) {
                throw new InvalidArgumentException('invalid_ufw_port');
            }

            return $m[1] . ':' . $m[2];
        }

        throw new InvalidArgumentException('invalid_ufw_port');
    }

    /**
     * Split a port field (80,443,8080 or 80-82) into UFW ports.
     *
     * @return list<string>
     */
    public static function splitPorts(string $ports): array
    {
        $ports = trim($ports);
        if ($ports === '' || $ports === '0') {
            throw new InvalidArgumentException('invalid_ufw_port');
        }
        $parts = preg_split('/\s*,\s*/', $ports) ?: [];
        $out = [];
        foreach ($parts as $p) {
            if (!is_string($p) || trim($p) === '') {
                continue;
            }
            $out[] = self::validatePort($p);
        }
        if ($out === []) {
            throw new InvalidArgumentException('invalid_ufw_port');
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array{port:string, proto:string}
     */
    public static function parseRuleTo(string $to): array
    {
        $to = trim(preg_replace('/\s*\(v6\)\s*$/i', '', $to) ?? $to);
        if (preg_match('/^icmp$/i', $to) === 1) {
            return ['port' => '', 'proto' => 'icmp'];
        }
        $pl = '\d+(?::\d+)?(?:,\d+(?::\d+)?)*';
        if (preg_match('/^(' . $pl . ')\/(tcp|udp|icmp)$/i', $to, $m) === 1) {
            return ['port' => $m[1], 'proto' => strtolower($m[2])];
        }
        if (preg_match('/^(tcp|udp|icmp)\/(' . $pl . ')$/i', $to, $m) === 1) {
            return ['port' => $m[2], 'proto' => strtolower($m[1])];
        }
        if (preg_match('/^(' . $pl . ')$/', $to, $m) === 1) {
            return ['port' => $m[1], 'proto' => ''];
        }

        return ['port' => $to, 'proto' => ''];
    }

    public static function validateFrom(string $from): string
    {
        $from = trim($from);
        if ($from === '' || strtolower($from) === 'any' || strcasecmp($from, 'Anywhere') === 0) {
            return '';
        }
        if (str_contains($from, ':')) {
            // IPv6 address or CIDR.
            $addr = $from;
            $pfx = null;
            if (str_contains($from, '/')) {
                [$addr, $pfxRaw] = explode('/', $from, 2);
                if (preg_match('/^\d{1,3}$/', $pfxRaw) !== 1 || (int) $pfxRaw > 128) {
                    throw new InvalidArgumentException('invalid_ufw_from');
                }
                $pfx = (int) $pfxRaw;
            }
            if (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new InvalidArgumentException('invalid_ufw_from');
            }

            return $pfx === null ? strtolower($addr) : strtolower($addr) . '/' . $pfx;
        }
        if (preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})(?:\/(\d{1,2}))?$/', $from, $m) !== 1) {
            throw new InvalidArgumentException('invalid_ufw_from');
        }
        for ($i = 1; $i <= 4; $i++) {
            $o = (int) $m[$i];
            if ($o < 0 || $o > 255) {
                throw new InvalidArgumentException('invalid_ufw_from');
            }
        }
        if (isset($m[5]) && $m[5] !== '') {
            $pfx = (int) $m[5];
            if ($pfx < 0 || $pfx > 32) {
                throw new InvalidArgumentException('invalid_ufw_from');
            }

            return $m[1] . '.' . $m[2] . '.' . $m[3] . '.' . $m[4] . '/' . $m[5];
        }

        return $m[1] . '.' . $m[2] . '.' . $m[3] . '.' . $m[4];
    }

    public static function validateRuleNumber(string $number): int
    {
        $number = trim($number);
        if (preg_match('/^\d+$/', $number) !== 1) {
            throw new InvalidArgumentException('invalid_ufw_rule');
        }
        $n = (int) $number;
        if ($n < 1 || $n > 9999) {
            throw new InvalidArgumentException('invalid_ufw_rule');
        }

        return $n;
    }

    /**
     * @return list<string>
     */
    public static function addRuleArgv(
        string $action,
        string $proto,
        string $port = '',
        string $from = '',
        string $comment = ''
    ): array {
        $action = self::validateAction($action);
        $proto = self::validateProto($proto);
        $from = self::validateFrom($from);
        $comment = self::validateComment($comment);
        if ($proto === 'icmp') {
            $argv = [$action, 'proto', 'icmp'];
            if ($from !== '') {
                $argv = [$action, 'proto', 'icmp', 'from', $from];
            }
        } else {
            $port = self::validatePort($port);
            if (($action === 'deny' || $action === 'reject') && self::portTouchesPanel($port)) {
                throw new InvalidArgumentException('cannot_deny_panel_port');
            }
            if ($from === '') {
                $argv = [$action, $port . '/' . $proto];
            } else {
                $argv = [$action, 'from', $from, 'to', 'any', 'port', $port, 'proto', $proto];
            }
        }
        if ($comment !== '') {
            $argv[] = 'comment';
            $argv[] = $comment;
        }

        return $argv;
    }

    public static function portTouchesPanel(string $port): bool
    {
        if (preg_match('/^(\d+)$/', $port, $m) === 1) {
            return self::isPanelPort((int) $m[1]);
        }
        if (preg_match('/^(\d+):(\d+)$/', $port, $m) === 1) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            foreach (self::panelPorts() as $p) {
                if ($p >= $a && $p <= $b) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{active:bool, installed:bool, default_in:string, default_out:string, raw:string, rules:list<array{number:int,to:string,action:string,from:string,ipv6:bool}>}
     */
    public static function parseStatus(string $verbose, string $numbered = ''): array
    {
        $active = (bool) preg_match('/^Status:\s+active\b/mi', $verbose);
        $defaultIn = '';
        $defaultOut = '';
        if (preg_match('/^Default:\s+(.+)$/mi', $verbose, $m) === 1) {
            $line = trim($m[1]);
            if (preg_match('/\b(deny|allow|reject)\s+\(incoming\)/i', $line, $im) === 1) {
                $defaultIn = strtolower($im[1]);
            }
            if (preg_match('/\b(deny|allow|reject)\s+\(outgoing\)/i', $line, $om) === 1) {
                $defaultOut = strtolower($om[1]);
            }
        }

        return [
            'active' => $active,
            'installed' => true,
            'default_in' => $defaultIn,
            'default_out' => $defaultOut,
            'raw' => trim($verbose),
            'rules' => self::parseNumbered($numbered !== '' ? $numbered : $verbose),
        ];
    }

    /**
     * UFW with IPv6 enabled lists each allow twice (IPv4 then IPv6) with
     * distinct rule numbers. Keep ipv6 so collapseDualStack can pair them.
     *
     * @return list<array{number:int,to:string,action:string,from:string,ipv6:bool,comment:string}>
     */
    public static function parseNumbered(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim((string) $line);
            if ($line === '' || !str_starts_with($line, '[')) {
                continue;
            }
            // [ 1] 22/tcp                     ALLOW IN    Anywhere
            // [ 2] 80/tcp (v6)                 DENY IN     1.2.3.4
            // [ 3] 443                        ALLOW       Anywhere (v6)
            // [ 4] 443/tcp                     ALLOW IN    Anywhere                   # https
            $comment = '';
            if (preg_match('/^(.*?)(?:\s+#\s*(.*))$/', $line, $cm) === 1) {
                $line = rtrim((string) $cm[1]);
                $comment = trim((string) $cm[2]);
            }
            if (preg_match(
                '/^\[\s*(\d+)\]\s+(.+?)\s+(ALLOW|DENY|REJECT|LIMIT)(?:\s+(IN|OUT))?\s+(.+)$/i',
                $line,
                $m
            ) !== 1) {
                continue;
            }
            $toRaw = trim($m[2]);
            $fromRaw = trim($m[5]);
            $ipv6 = (bool) preg_match('/\(v6\)/i', $toRaw . ' ' . $fromRaw);
            $to = trim(preg_replace('/\s*\(v6\)\s*$/i', '', $toRaw) ?? $toRaw);
            $action = strtoupper(trim($m[3]));
            if (isset($m[4]) && is_string($m[4]) && $m[4] !== '') {
                $action .= ' ' . strtoupper($m[4]);
            }
            $from = trim(preg_replace('/\s*\(v6\)\s*$/i', '', $fromRaw) ?? $fromRaw);
            $out[] = [
                'number' => (int) $m[1],
                'to' => $to,
                'action' => $action,
                'from' => $from,
                'ipv6' => $ipv6,
                'comment' => $comment,
            ];
        }

        return $out;
    }

    /**
     * True when two numbered rows are the IPv4/IPv6 halves of one UFW rule.
     *
     * @param array{number:int,to:string,action:string,from:string,ipv6?:bool,comment?:string} $a
     * @param array{number:int,to:string,action:string,from:string,ipv6?:bool,comment?:string} $b
     */
    public static function rulesAreDualPair(array $a, array $b): bool
    {
        $a6 = !empty($a['ipv6']);
        $b6 = !empty($b['ipv6']);
        if ($a6 === $b6) {
            return false;
        }
        $toA = strtolower(trim((string) ($a['to'] ?? '')));
        $toB = strtolower(trim((string) ($b['to'] ?? '')));
        $actA = strtoupper(trim((string) ($a['action'] ?? '')));
        $actB = strtoupper(trim((string) ($b['action'] ?? '')));
        $fromA = strtolower(trim((string) ($a['from'] ?? '')));
        $fromB = strtolower(trim((string) ($b['from'] ?? '')));
        $cA = trim((string) ($a['comment'] ?? ''));
        $cB = trim((string) ($b['comment'] ?? ''));

        return $toA === $toB && $actA === $actB && $fromA === $fromB && $cA === $cB;
    }

    /**
     * Collapse IPv4+IPv6 UFW pairs into one logical row (applies to both).
     *
     * @param list<array{number:int,to:string,action:string,from:string,ipv6?:bool,comment?:string}> $rules
     * @return list<array{number:int,numbers:list<int>,to:string,action:string,from:string,ipv6:bool,comment:string,suspended:bool,id:string}>
     */
    public static function collapseDualStack(array $rules): array
    {
        $used = [];
        $out = [];
        $n = count($rules);
        for ($i = 0; $i < $n; $i++) {
            if (isset($used[$i])) {
                continue;
            }
            $a = $rules[$i];
            if (!is_array($a) || !isset($a['number'])) {
                continue;
            }
            $pairIdx = null;
            for ($j = $i + 1; $j < $n; $j++) {
                if (isset($used[$j])) {
                    continue;
                }
                $b = $rules[$j];
                if (!is_array($b) || !isset($b['number'])) {
                    continue;
                }
                if (self::rulesAreDualPair($a, $b)) {
                    $pairIdx = $j;
                    break;
                }
            }
            $numbers = [(int) $a['number']];
            $row = $a;
            if ($pairIdx !== null) {
                $used[$pairIdx] = true;
                $numbers[] = (int) $rules[$pairIdx]['number'];
                // Prefer the IPv4 half for display fields.
                if (!empty($a['ipv6']) && empty($rules[$pairIdx]['ipv6'])) {
                    $row = $rules[$pairIdx];
                }
            }
            sort($numbers, SORT_NUMERIC);
            $out[] = [
                'number' => $numbers[0],
                'numbers' => array_values($numbers),
                'to' => (string) ($row['to'] ?? ''),
                'action' => (string) ($row['action'] ?? ''),
                'from' => (string) ($row['from'] ?? ''),
                'ipv6' => false,
                'comment' => (string) ($row['comment'] ?? ''),
                'suspended' => false,
                'id' => 'n:' . implode(',', $numbers),
            ];
            $used[$i] = true;
        }

        return $out;
    }

    /**
     * Path for paused rules (JSON). Root owns the file via kloxo-backend.
     */
    public static function suspendedStorePath(): string
    {
        global $sgbl;

        if (isset($sgbl) && is_object($sgbl) && isset($sgbl->__path_program_etc)
            && is_string($sgbl->__path_program_etc) && $sgbl->__path_program_etc !== '') {
            return rtrim($sgbl->__path_program_etc, '/') . '/ufw_suspended.json';
        }
        foreach (['/opt/kloxo/etc/ufw_suspended.json', '/usr/local/lxlabs/kloxo/etc/ufw_suspended.json'] as $p) {
            $dir = dirname($p);
            if (is_dir($dir)) {
                return $p;
            }
        }

        return '/opt/kloxo/etc/ufw_suspended.json';
    }

    /**
     * @return list<array{id:string,action:string,proto:string,port:string,from:string,comment:string,suspended:bool,number:int,numbers:list<int>,to:string,from_label?:string}>
     */
    public static function listSuspended(): array
    {
        $path = self::suspendedStorePath();
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || !isset($row['id']) || !is_string($row['id']) || $row['id'] === '') {
                continue;
            }
            $action = strtolower((string) ($row['action'] ?? 'allow'));
            $proto = strtolower((string) ($row['proto'] ?? 'tcp'));
            $port = (string) ($row['port'] ?? '');
            $from = (string) ($row['from'] ?? '');
            $comment = (string) ($row['comment'] ?? '');
            $to = ($proto === 'icmp') ? 'icmp' : (($port !== '' && $proto !== '') ? ($port . '/' . $proto) : $port);
            $out[] = [
                'id' => $row['id'],
                'action' => strtoupper($action),
                'proto' => $proto,
                'port' => $port,
                'from' => $from !== '' ? $from : 'Anywhere',
                'comment' => $comment,
                'suspended' => true,
                'number' => 0,
                'numbers' => [],
                'to' => $to,
            ];
        }

        return $out;
    }

    /**
     * @param list<array{id:string,action:string,proto:string,port:string,from:string,comment:string}> $rows
     */
    public static function writeSuspended(array $rows): bool
    {
        $path = self::suspendedStorePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $clean = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id']) || !is_string($row['id']) || $row['id'] === '') {
                continue;
            }
            $clean[] = [
                'id' => $row['id'],
                'action' => strtolower((string) ($row['action'] ?? 'allow')),
                'proto' => strtolower((string) ($row['proto'] ?? 'tcp')),
                'port' => (string) ($row['port'] ?? ''),
                'from' => (string) ($row['from'] ?? ''),
                'comment' => (string) ($row['comment'] ?? ''),
            ];
        }
        $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            return false;
        }
        @chmod($tmp, 0640);

        return @rename($tmp, $path);
    }

    public static function newSuspendedId(): string
    {
        try {
            return 's:' . bin2hex(random_bytes(8));
        } catch (Throwable $e) {
            return 's:' . str_replace('.', '', uniqid('', true));
        }
    }

    public static function ruleTouchesPanel(array $rule): bool
    {
        $to = isset($rule['to']) ? (string) $rule['to'] : '';
        if (preg_match('/\b(7777|7778)(?:\/|$)/', $to) === 1) {
            return true;
        }

        return false;
    }

    public static function ruleIsAllow(array $rule): bool
    {
        $a = isset($rule['action']) ? strtoupper((string) $rule['action']) : '';

        return str_contains($a, 'ALLOW');
    }

    /**
     * @param list<array{number:int,to:string,action:string,from:string}> $rules
     */
    public static function canDeleteRule(array $rules, int $number): bool
    {
        return self::canDeleteNumbers($rules, [$number]);
    }

    /**
     * True when deleting these UFW numbers would leave at least one allow
     * for each panel port (7777/7778) that the removed set currently covers.
     *
     * @param list<array{number:int,to:string,action:string,from:string}> $rules
     * @param list<int> $numbers
     */
    public static function canDeleteNumbers(array $rules, array $numbers): bool
    {
        $remove = [];
        foreach ($numbers as $n) {
            $remove[(int) $n] = true;
        }
        if ($remove === []) {
            return false;
        }
        $targets = [];
        foreach ($rules as $r) {
            $num = (int) ($r['number'] ?? 0);
            if (!isset($remove[$num])) {
                continue;
            }
            $targets[] = $r;
        }
        if (count($targets) !== count($remove)) {
            // One of the numbers is missing from the live list.
            return false;
        }
        $portsNeeded = [];
        foreach ($targets as $t) {
            if (!self::ruleTouchesPanel($t) || !self::ruleIsAllow($t)) {
                continue;
            }
            if (preg_match('/\b(7777|7778)/', (string) $t['to'], $m) === 1) {
                $portsNeeded[$m[1]] = true;
            }
        }
        if ($portsNeeded === []) {
            return true;
        }
        foreach (array_keys($portsNeeded) as $port) {
            $others = 0;
            foreach ($rules as $r) {
                $num = (int) ($r['number'] ?? 0);
                if (isset($remove[$num])) {
                    continue;
                }
                if (!self::ruleIsAllow($r)) {
                    continue;
                }
                if (preg_match('/\b' . preg_quote((string) $port, '/') . '\b/', (string) $r['to']) === 1) {
                    $others++;
                }
            }
            if ($others < 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Which firewall this page manages, plus kernel backend and other
     * managers that could conflict. Safe to call before Enable.
     *
     * @return array{
     *   managed:string,
     *   managed_label:string,
     *   backend:string,
     *   backend_label:string,
     *   firewalld_active:bool,
     *   nftables_service_active:bool,
     *   conflict:bool,
     *   conflict_note:string
     * }
     */
    public static function environment(): array
    {
        $backend = self::detectPacketBackend();
        $firewalld = self::unitIsActive('firewalld');
        $nftSvc = self::unitIsActive('nftables');
        $conflict = $firewalld;
        $note = '';
        if ($firewalld) {
            $note = 'firewalld is active. Disable firewalld before enabling UFW '
                . '(both manage the same packet filter and will conflict).';
        } elseif ($nftSvc) {
            $note = 'nftables.service is active. UFW still manages rules via its '
                . 'own backend; avoid mixing hand-written nftables.service rules with UFW.';
        }

        return [
            'managed' => 'ufw',
            'managed_label' => 'UFW (Uncomplicated Firewall)',
            'backend' => $backend['id'],
            'backend_label' => $backend['label'],
            'firewalld_active' => $firewalld,
            'nftables_service_active' => $nftSvc,
            'conflict' => $conflict,
            'conflict_note' => $note,
        ];
    }

    /**
     * @return array{id:string, label:string}
     */
    public static function detectPacketBackend(): array
    {
        foreach (['/usr/sbin/iptables', '/sbin/iptables', '/usr/bin/iptables'] as $bin) {
            if (!is_executable($bin)) {
                continue;
            }
            $done = self::run([$bin, '-V']);
            $text = trim($done['out'] . ' ' . $done['err']);

            return self::parseIptablesVersion($text);
        }

        return [
            'id' => 'unknown',
            'label' => 'unknown (iptables not found)',
        ];
    }

    /**
     * @return array{id:string, label:string}
     */
    public static function parseIptablesVersion(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['id' => 'unknown', 'label' => 'unknown'];
        }
        if (preg_match('/nf_tables/i', $text) === 1) {
            return [
                'id' => 'nftables',
                'label' => 'nftables (iptables-nft API)',
            ];
        }
        if (preg_match('/legacy/i', $text) === 1) {
            return [
                'id' => 'iptables-legacy',
                'label' => 'iptables-legacy',
            ];
        }

        return [
            'id' => 'iptables',
            'label' => 'iptables (' . $text . ')',
        ];
    }

    public static function unitIsActive(string $unit): bool
    {
        $unit = trim($unit);
        if ($unit === '' || preg_match('/^[a-zA-Z0-9@._:-]+$/', $unit) !== 1) {
            return false;
        }
        foreach (['/bin/systemctl', '/usr/bin/systemctl'] as $bin) {
            if (!is_executable($bin)) {
                continue;
            }
            $done = self::run([$bin, 'is-active', $unit]);
            $out = strtolower(trim($done['out'] . $done['err']));

            return $done['code'] === 0 || $out === 'active';
        }

        return false;
    }

    /**
     * @return array{
     *   active:bool,
     *   installed:bool,
     *   default_in:string,
     *   default_out:string,
     *   raw:string,
     *   rules:list<array{number:int,to:string,action:string,from:string,ipv6?:bool}>,
     *   managed?:string,
     *   managed_label?:string,
     *   backend?:string,
     *   backend_label?:string,
     *   firewalld_active?:bool,
     *   nftables_service_active?:bool,
     *   conflict?:bool,
     *   conflict_note?:string,
     *   error?:string
     * }
     */
    public static function status(): array
    {
        $env = self::environment();
        if (!self::isInstalled()) {
            return array_merge([
                'active' => false,
                'installed' => false,
                'default_in' => '',
                'default_out' => '',
                'raw' => '',
                'rules' => [],
                'error' => 'ufw_not_installed',
            ], $env);
        }
        $verbose = self::run([self::ufwBinary(), 'status', 'verbose']);
        $numbered = self::run([self::ufwBinary(), 'status', 'numbered']);
        $parsed = self::parseStatus($verbose['out'] . "\n" . $verbose['err'], $numbered['out']);
        $parsed['installed'] = true;

        return array_merge($parsed, $env);
    }

    /**
     * Allow protected TCP ports + DNS/FTP before enable (idempotent).
     *
     * @return array{ok:bool, out:string, error?:string}
     */
    public static function ensureProtectedPorts(): array
    {
        if (!self::isInstalled()) {
            return ['ok' => false, 'out' => '', 'error' => 'ufw_not_installed'];
        }
        $bin = self::ufwBinary();
        $out = '';
        $pairs = [];
        foreach (self::protectedTcpPorts() as $port) {
            $pairs[] = [(string) $port, 'tcp'];
        }
        // DNS only here; TCP 21 is already in protectedTcpPorts().
        $pairs[] = ['53', 'tcp'];
        $pairs[] = ['53', 'udp'];
        foreach ($pairs as $pair) {
            // Each default rule carries its purpose as the UFW comment.
            $rule = self::addRuleArgv('allow', $pair[1], $pair[0], '', self::defaultComment($pair[0], $pair[1]));
            $p = self::run(array_merge([$bin], $rule));
            $out .= $p['out'] . $p['err'];
        }

        return ['ok' => true, 'out' => $out];
    }

    /**
     * @return array{ok:bool, out:string, error?:string}
     */
    public static function enable(): array
    {
        if (!self::isInstalled()) {
            return ['ok' => false, 'out' => '', 'error' => 'ufw_not_installed'];
        }
        $prep = self::ensureProtectedPorts();
        $done = self::run([self::ufwBinary(), '--force', 'enable']);
        $out = (string) ($prep['out'] ?? '') . $done['out'] . $done['err'];
        if ($done['code'] !== 0) {
            return ['ok' => false, 'out' => $out, 'error' => 'ufw_enable_failed'];
        }

        return ['ok' => true, 'out' => $out];
    }

    /**
     * @return array{ok:bool, out:string, error?:string}
     */
    public static function disable(): array
    {
        if (!self::isInstalled()) {
            return ['ok' => false, 'out' => '', 'error' => 'ufw_not_installed'];
        }
        $done = self::run([self::ufwBinary(), 'disable']);
        $out = $done['out'] . $done['err'];
        if ($done['code'] !== 0) {
            return ['ok' => false, 'out' => $out, 'error' => 'ufw_disable_failed'];
        }

        return ['ok' => true, 'out' => $out];
    }

    /**
     * @return array{ok:bool, out:string, error?:string}
     */
    public static function addRule(
        string $action,
        string $proto,
        string $port = '',
        string $from = '',
        string $comment = ''
    ): array {
        if (!self::isInstalled()) {
            return ['ok' => false, 'out' => '', 'error' => 'ufw_not_installed'];
        }
        $argv = self::addRuleArgv($action, $proto, $port, $from, $comment);
        $done = self::run(array_merge([self::ufwBinary()], $argv));
        $out = $done['out'] . $done['err'];
        if ($done['code'] !== 0) {
            return ['ok' => false, 'out' => $out, 'error' => 'ufw_addrule_failed'];
        }

        return ['ok' => true, 'out' => $out];
    }

    /**
     * Find the collapsed logical rule that owns this UFW number.
     *
     * @param list<array{number:int,to:string,action:string,from:string,ipv6?:bool,comment?:string}> $rawRules
     * @return array{number:int,numbers:list<int>,to:string,action:string,from:string,comment:string}|null
     */
    public static function findLogicalRule(array $rawRules, int $number): ?array
    {
        foreach (self::collapseDualStack($rawRules) as $row) {
            $nums = isset($row['numbers']) && is_array($row['numbers']) ? $row['numbers'] : [(int) $row['number']];
            foreach ($nums as $n) {
                if ((int) $n === $number) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * Delete one or more UFW numbers highest-first (renumber-safe). Also
     * deletes the IPv6 twin when the selected number is part of a pair.
     *
     * @param list<int|string> $numbers
     * @return array{ok:bool, out:string, error?:string}
     */
    public static function deleteRules(array $numbers, bool $allowLastPanel = false): array
    {
        if (!self::isInstalled()) {
            return ['ok' => false, 'out' => '', 'error' => 'ufw_not_installed'];
        }
        $want = [];
        foreach ($numbers as $n) {
            if (is_int($n) || (is_string($n) && preg_match('/^\d+$/', $n) === 1)) {
                $want[] = (int) $n;
            }
        }
        $want = array_values(array_unique($want));
        if ($want === []) {
            throw new InvalidArgumentException('invalid_ufw_rule');
        }
        $st = self::status();
        $raw = $st['rules'];
        $toDelete = [];
        foreach ($want as $n) {
            $logical = self::findLogicalRule($raw, $n);
            if ($logical === null) {
                throw new InvalidArgumentException('invalid_ufw_rule');
            }
            foreach ($logical['numbers'] as $ln) {
                $toDelete[] = (int) $ln;
            }
        }
        $toDelete = array_values(array_unique($toDelete));
        if (!$allowLastPanel && !self::canDeleteNumbers($raw, $toDelete)) {
            throw new InvalidArgumentException('cannot_delete_panel_port');
        }
        rsort($toDelete, SORT_NUMERIC);
        $out = '';
        foreach ($toDelete as $n) {
            // Re-read after each delete (UFW renumbers). Skip already-gone twins.
            $live = self::status();
            $stillThere = false;
            foreach ($live['rules'] as $r) {
                if ((int) $r['number'] === $n) {
                    $stillThere = true;
                    break;
                }
            }
            if (!$stillThere) {
                continue;
            }
            $done = self::run([self::ufwBinary(), '--force', 'delete', (string) $n]);
            $out .= $done['out'] . $done['err'];
            if ($done['code'] !== 0) {
                return ['ok' => false, 'out' => $out, 'error' => 'ufw_deleterule_failed'];
            }
        }

        return ['ok' => true, 'out' => $out];
    }

    /**
     * @return array{ok:bool, out:string, error?:string}
     */
    public static function deleteRule(string $number): array
    {
        return self::deleteRules([$number]);
    }

    /**
     * Replace a logical rule (IPv4+IPv6 pair): delete both then add once.
     *
     * @return array{ok:bool, out:string, error?:string}
     */
    public static function editRule(
        string $number,
        string $action,
        string $proto,
        string $port = '',
        string $from = '',
        string $comment = ''
    ): array {
        if (!self::isInstalled()) {
            return ['ok' => false, 'out' => '', 'error' => 'ufw_not_installed'];
        }
        $n = self::validateRuleNumber($number);
        $action = self::validateAction($action);
        $proto = self::validateProto($proto);
        $fromNorm = self::validateFrom($from);
        $comment = self::validateComment($comment);
        // The port field may hold a list (80,443) or range (8000-8010): one rule each.
        $ports = ($proto === 'icmp') ? [''] : self::splitPorts($port);
        // Validate every new rule before touching the firewall.
        foreach ($ports as $p) {
            self::addRuleArgv($action, $proto, $p, $fromNorm, $comment);
        }
        $st = self::status();
        $logical = self::findLogicalRule($st['rules'], $n);
        if ($logical === null) {
            throw new InvalidArgumentException('invalid_ufw_rule');
        }
        $keepsPanel = false;
        if ($proto !== 'icmp' && $action === 'allow' && $fromNorm === '') {
            foreach ($ports as $p) {
                if (self::portTouchesPanel($p)) {
                    $keepsPanel = true;
                }
            }
        }
        if (!self::canDeleteNumbers($st['rules'], $logical['numbers']) && !$keepsPanel) {
            throw new InvalidArgumentException('cannot_delete_panel_port');
        }
        $original = self::logicalRuleSpec($logical);
        $del = self::deleteRules($logical['numbers'], $keepsPanel);
        $out = (string) ($del['out'] ?? '');
        if (empty($del['ok'])) {
            return [
                'ok' => false,
                'out' => $out,
                'error' => isset($del['error']) ? (string) $del['error'] : 'ufw_editrule_failed',
            ];
        }
        foreach ($ports as $p) {
            $add = self::addRule($action, $proto, $p, $fromNorm, $comment);
            $out .= (string) ($add['out'] ?? '');
            if (empty($add['ok'])) {
                // Put the original rule back so a failed edit never loses it.
                $restored = false;
                if ($original !== null) {
                    try {
                        $restored = true;
                        $backPorts = $original['proto'] === 'icmp' ? [''] : self::splitPorts($original['port']);
                        foreach ($backPorts as $bp) {
                            $back = self::addRule(
                                $original['action'],
                                $original['proto'],
                                $bp,
                                $original['from'],
                                $original['comment']
                            );
                            $out .= (string) ($back['out'] ?? '');
                            if (empty($back['ok'])) {
                                $restored = false;
                            }
                        }
                    } catch (Throwable $e) {
                        $restored = false;
                    }
                }

                return [
                    'ok' => false,
                    'out' => $out,
                    'error' => $restored
                        ? (isset($add['error']) ? (string) $add['error'] : 'ufw_editrule_failed')
                        : 'ufw_editrule_failed',
                ];
            }
        }

        return ['ok' => true, 'out' => $out];
    }

    /**
     * Rebuild add-rule arguments from a logical (collapsed) rule so it can be
     * re-created. Returns null when the rule cannot be represented.
     *
     * @param array<string,mixed> $logical
     * @return array{action:string,proto:string,port:string,from:string,comment:string}|null
     */
    public static function logicalRuleSpec(array $logical): ?array
    {
        $parsed = self::parseRuleTo((string) ($logical['to'] ?? ''));
        $actUp = strtoupper((string) ($logical['action'] ?? ''));
        if (str_contains($actUp, 'REJECT')) {
            $action = 'reject';
        } elseif (str_contains($actUp, 'DENY')) {
            $action = 'deny';
        } elseif (str_contains($actUp, 'ALLOW')) {
            $action = 'allow';
        } else {
            return null;
        }
        $proto = $parsed['proto'] !== '' ? $parsed['proto'] : 'tcp';
        $port = $proto === 'icmp' ? '' : $parsed['port'];
        $from = (string) ($logical['from'] ?? '');
        if ($from === '' || strcasecmp($from, 'Anywhere') === 0 || strcasecmp($from, 'any') === 0) {
            $from = '';
        }
        if ($proto !== 'icmp') {
            try {
                self::splitPorts($port);
            } catch (InvalidArgumentException $e) {
                return null;
            }
        }

        return [
            'action' => $action,
            'proto' => $proto,
            'port' => $port,
            'from' => $from,
            'comment' => (string) ($logical['comment'] ?? ''),
        ];
    }

    /**
     * Pause a live rule: remove from UFW and store for later unsuspend.
     *
     * @return array{ok:bool, out:string, error?:string}
     */
    public static function suspendRule(string $number): array
    {
        if (!self::isInstalled()) {
            return ['ok' => false, 'out' => '', 'error' => 'ufw_not_installed'];
        }
        $n = self::validateRuleNumber($number);
        $st = self::status();
        $logical = self::findLogicalRule($st['rules'], $n);
        if ($logical === null) {
            throw new InvalidArgumentException('invalid_ufw_rule');
        }
        if (!self::canDeleteNumbers($st['rules'], $logical['numbers'])) {
            throw new InvalidArgumentException('cannot_delete_panel_port');
        }
        $parsed = self::parseRuleTo((string) $logical['to']);
        $act = strtolower((string) $logical['action']);
        if (str_contains(strtoupper($act), 'REJECT')) {
            $action = 'reject';
        } elseif (str_contains(strtoupper($act), 'DENY')) {
            $action = 'deny';
        } else {
            $action = 'allow';
        }
        $proto = $parsed['proto'] !== '' ? $parsed['proto'] : 'tcp';
        $port = $parsed['port'];
        $from = (string) $logical['from'];
        if ($from === '' || strcasecmp($from, 'Anywhere') === 0 || strcasecmp($from, 'any') === 0) {
            $from = '';
        }
        $comment = (string) ($logical['comment'] ?? '');
        $id = self::newSuspendedId();
        $rows = [];
        foreach (self::listSuspended() as $s) {
            $rows[] = [
                'id' => $s['id'],
                'action' => (string) ($s['action'] ?? 'allow'),
                'proto' => (string) ($s['proto'] ?? 'tcp'),
                'port' => (string) ($s['port'] ?? ''),
                'from' => (isset($s['from']) && strcasecmp((string) $s['from'], 'Anywhere') !== 0)
                    ? (string) $s['from'] : '',
                'comment' => (string) ($s['comment'] ?? ''),
            ];
        }
        $rows[] = [
            'id' => $id,
            'action' => $action,
            'proto' => $proto,
            'port' => $port,
            'from' => $from,
            'comment' => $comment,
        ];
        if (!self::writeSuspended($rows)) {
            return ['ok' => false, 'out' => '', 'error' => 'ufw_suspend_failed'];
        }
        $del = self::deleteRules($logical['numbers']);
        if (empty($del['ok'])) {
            // Roll back store entry.
            array_pop($rows);
            self::writeSuspended($rows);

            return [
                'ok' => false,
                'out' => (string) ($del['out'] ?? ''),
                'error' => isset($del['error']) ? (string) $del['error'] : 'ufw_suspend_failed',
            ];
        }

        return ['ok' => true, 'out' => (string) ($del['out'] ?? '')];
    }

    /**
     * Restore a paused rule into UFW.
     *
     * @return array{ok:bool, out:string, error?:string}
     */
    public static function unsuspendRule(string $id): array
    {
        if (!self::isInstalled()) {
            return ['ok' => false, 'out' => '', 'error' => 'ufw_not_installed'];
        }
        $id = trim($id);
        if ($id === '' || !str_starts_with($id, 's:')) {
            throw new InvalidArgumentException('invalid_ufw_rule');
        }
        $rows = [];
        $target = null;
        foreach (self::listSuspended() as $s) {
            if ($s['id'] === $id) {
                $target = $s;
                continue;
            }
            $rows[] = [
                'id' => $s['id'],
                'action' => (string) ($s['action'] ?? 'allow'),
                'proto' => (string) ($s['proto'] ?? 'tcp'),
                'port' => (string) ($s['port'] ?? ''),
                'from' => (isset($s['from']) && strcasecmp((string) $s['from'], 'Anywhere') !== 0)
                    ? (string) $s['from'] : '',
                'comment' => (string) ($s['comment'] ?? ''),
            ];
        }
        if ($target === null) {
            throw new InvalidArgumentException('invalid_ufw_rule');
        }
        $action = strtolower((string) ($target['action'] ?? 'allow'));
        $proto = strtolower((string) ($target['proto'] ?? 'tcp'));
        $port = (string) ($target['port'] ?? '');
        $from = (isset($target['from']) && strcasecmp((string) $target['from'], 'Anywhere') !== 0)
            ? (string) $target['from'] : '';
        $comment = (string) ($target['comment'] ?? '');
        $add = self::addRule($action, $proto, $port, $from, $comment);
        if (empty($add['ok'])) {
            return [
                'ok' => false,
                'out' => (string) ($add['out'] ?? ''),
                'error' => isset($add['error']) ? (string) $add['error'] : 'ufw_unsuspend_failed',
            ];
        }
        self::writeSuspended($rows);

        return ['ok' => true, 'out' => (string) ($add['out'] ?? '')];
    }

    /**
     * Delete a suspended rule from the store (does not touch live UFW).
     *
     * @return array{ok:bool, out:string, error?:string}
     */
    public static function deleteSuspended(string $id): array
    {
        $id = trim($id);
        if ($id === '' || !str_starts_with($id, 's:')) {
            throw new InvalidArgumentException('invalid_ufw_rule');
        }
        $rows = [];
        $found = false;
        foreach (self::listSuspended() as $s) {
            if ($s['id'] === $id) {
                $found = true;
                continue;
            }
            $rows[] = [
                'id' => $s['id'],
                'action' => (string) ($s['action'] ?? 'allow'),
                'proto' => (string) ($s['proto'] ?? 'tcp'),
                'port' => (string) ($s['port'] ?? ''),
                'from' => (isset($s['from']) && strcasecmp((string) $s['from'], 'Anywhere') !== 0)
                    ? (string) $s['from'] : '',
                'comment' => (string) ($s['comment'] ?? ''),
            ];
        }
        if (!$found) {
            throw new InvalidArgumentException('invalid_ufw_rule');
        }
        if (!self::writeSuspended($rows)) {
            return ['ok' => false, 'out' => '', 'error' => 'ufw_deleterule_failed'];
        }

        return ['ok' => true, 'out' => ''];
    }

    /**
     * Edit a suspended (paused) rule in the store. It stays suspended; the
     * live firewall is not touched until it is unsuspended.
     *
     * @return array{ok:bool, out:string, error?:string}
     */
    public static function editSuspended(
        string $id,
        string $action,
        string $proto,
        string $port = '',
        string $from = '',
        string $comment = ''
    ): array {
        $id = trim($id);
        if ($id === '' || !str_starts_with($id, 's:')) {
            throw new InvalidArgumentException('invalid_ufw_rule');
        }
        $action = self::validateAction($action);
        $proto = self::validateProto($proto);
        $fromNorm = self::validateFrom($from);
        $comment = self::validateComment($comment);
        $ports = ($proto === 'icmp') ? [''] : self::splitPorts($port);
        foreach ($ports as $p) {
            self::addRuleArgv($action, $proto, $p, $fromNorm, $comment);
        }
        $rows = [];
        $found = false;
        foreach (self::listSuspended() as $s) {
            if ($s['id'] !== $id) {
                $rows[] = [
                    'id' => $s['id'],
                    'action' => (string) ($s['action'] ?? 'allow'),
                    'proto' => (string) ($s['proto'] ?? 'tcp'),
                    'port' => (string) ($s['port'] ?? ''),
                    'from' => (isset($s['from']) && strcasecmp((string) $s['from'], 'Anywhere') !== 0)
                        ? (string) $s['from'] : '',
                    'comment' => (string) ($s['comment'] ?? ''),
                ];
                continue;
            }
            $found = true;
            foreach ($ports as $i => $p) {
                $rows[] = [
                    'id' => $i === 0 ? $id : self::newSuspendedId(),
                    'action' => $action,
                    'proto' => $proto,
                    'port' => $p,
                    'from' => $fromNorm,
                    'comment' => $comment,
                ];
            }
        }
        if (!$found) {
            throw new InvalidArgumentException('invalid_ufw_rule');
        }
        if (!self::writeSuspended($rows)) {
            return ['ok' => false, 'out' => '', 'error' => 'ufw_suspend_failed'];
        }

        return ['ok' => true, 'out' => ''];
    }

    public static function validateDefaultPolicy(string $policy): string
    {
        $p = strtolower(trim($policy));
        if ($p !== 'allow' && $p !== 'deny' && $p !== 'reject') {
            throw new InvalidArgumentException('invalid_ufw_default');
        }

        return $p;
    }

    /**
     * @return array{ok:bool, out:string, error?:string}
     */
    public static function setDefaults(string $incoming, string $outgoing): array
    {
        if (!self::isInstalled()) {
            return ['ok' => false, 'out' => '', 'error' => 'ufw_not_installed'];
        }
        $incoming = self::validateDefaultPolicy($incoming);
        $outgoing = self::validateDefaultPolicy($outgoing);
        $bin = self::ufwBinary();
        $in = self::run([$bin, 'default', $incoming, 'incoming']);
        $outDone = self::run([$bin, 'default', $outgoing, 'outgoing']);
        $out = $in['out'] . $in['err'] . $outDone['out'] . $outDone['err'];
        if ($in['code'] !== 0 || $outDone['code'] !== 0) {
            return ['ok' => false, 'out' => $out, 'error' => 'ufw_default_failed'];
        }

        return ['ok' => true, 'out' => $out];
    }

    /**
     * @param list<string> $cmd
     * @return array{code:int, out:string, err:string}
     */
    public static function run(array $cmd): array
    {
        if ($cmd === [] || !isset($cmd[0]) || !is_string($cmd[0]) || !is_executable($cmd[0])) {
            return ['code' => 127, 'out' => '', 'err' => 'ufw_not_installed'];
        }
        foreach ($cmd as $arg) {
            if (!is_string($arg)) {
                return ['code' => 2, 'out' => '', 'err' => 'invalid_ufw_arg'];
            }
        }
        $p = @proc_open($cmd, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($p)) {
            return ['code' => 127, 'out' => '', 'err' => 'ufw_exec_failed'];
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);

        return [
            'code' => is_int($code) ? $code : 1,
            'out' => is_string($out) ? $out : '',
            'err' => is_string($err) ? $err : '',
        ];
    }
}
