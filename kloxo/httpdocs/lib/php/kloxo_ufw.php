<?php
declare(strict_types=1);

/**
 * Ubuntu UFW frontend. Argument-array process execution only.
 *
 * Enable always allows panel ports 7777/7778 (and SSH/web/mail) first so
 * the host does not lock out the panel. Never builds a shell string.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoUfw
{
    /** @return list<int> */
    public static function protectedTcpPorts(): array
    {
        return [22, 25, 80, 443, 465, 587, 993, 995, 7777, 7778];
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
        if ($a !== 'allow' && $a !== 'deny') {
            throw new InvalidArgumentException('invalid_ufw_action');
        }

        return $a;
    }

    public static function validateProto(string $proto): string
    {
        $p = strtolower(trim($proto));
        if ($p !== 'tcp' && $p !== 'udp') {
            throw new InvalidArgumentException('invalid_ufw_proto');
        }

        return $p;
    }

    public static function validatePort(string $port): string
    {
        $port = trim(str_replace(' ', '', $port));
        // Hestia-style range 80-90 → UFW 80:90
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
     * Split a Hestia-style port field (80,443,8080 or 80-82) into UFW ports.
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
        $to = trim($to);
        if (preg_match('/^(\d+(?::\d+)?)\/(tcp|udp)$/i', $to, $m) === 1) {
            return ['port' => $m[1], 'proto' => strtolower($m[2])];
        }
        if (preg_match('/^(tcp|udp)\/(\d+(?::\d+)?)$/i', $to, $m) === 1) {
            return ['port' => $m[2], 'proto' => strtolower($m[1])];
        }

        return ['port' => $to, 'proto' => ''];
    }

    public static function validateFrom(string $from): string
    {
        $from = trim($from);
        if ($from === '' || strtolower($from) === 'any' || $from === 'Anywhere') {
            return '';
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
    public static function addRuleArgv(string $action, string $proto, string $port, string $from = ''): array
    {
        $action = self::validateAction($action);
        $proto = self::validateProto($proto);
        $port = self::validatePort($port);
        $from = self::validateFrom($from);
        if ($action === 'deny' && self::portTouchesPanel($port)) {
            throw new InvalidArgumentException('cannot_deny_panel_port');
        }
        if ($from === '') {
            return [$action, $port . '/' . $proto];
        }

        return [$action, 'from', $from, 'to', 'any', 'port', $port, 'proto', $proto];
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
     * @return array{active:bool, installed:bool, default_in:string, default_out:string, raw:string, rules:list<array{number:int,to:string,action:string,from:string}>}
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
     * @return list<array{number:int,to:string,action:string,from:string}>
     */
    public static function parseNumbered(string $text): array
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $line) {
            $line = trim((string) $line);
            if ($line === '' || !str_starts_with($line, '[')) {
                continue;
            }
            if (preg_match('/^\[\s*(\d+)\]\s+(\S+)\s+(\S+(?:\s+\S+)?)\s+(.+)$/', $line, $m) !== 1) {
                continue;
            }
            $out[] = [
                'number' => (int) $m[1],
                'to' => $m[2],
                'action' => trim($m[3]),
                'from' => trim($m[4]),
            ];
        }

        return $out;
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
        $target = null;
        foreach ($rules as $r) {
            if ((int) $r['number'] === $number) {
                $target = $r;
                break;
            }
        }
        if ($target === null) {
            return false;
        }
        if (!self::ruleTouchesPanel($target) || !self::ruleIsAllow($target)) {
            return true;
        }
        $port = '';
        if (preg_match('/\b(7777|7778)/', (string) $target['to'], $m) === 1) {
            $port = $m[1];
        }
        $others = 0;
        foreach ($rules as $r) {
            if ((int) $r['number'] === $number) {
                continue;
            }
            if (!self::ruleIsAllow($r)) {
                continue;
            }
            if ($port !== '' && preg_match('/\b' . $port . '\b/', (string) $r['to']) === 1) {
                $others++;
            }
        }

        return $others > 0;
    }

    /**
     * @return array{active:bool, installed:bool, default_in:string, default_out:string, raw:string, rules:list<array{number:int,to:string,action:string,from:string}>, error?:string}
     */
    public static function status(): array
    {
        if (!self::isInstalled()) {
            return [
                'active' => false,
                'installed' => false,
                'default_in' => '',
                'default_out' => '',
                'raw' => '',
                'rules' => [],
                'error' => 'ufw_not_installed',
            ];
        }
        $verbose = self::run([self::ufwBinary(), 'status', 'verbose']);
        $numbered = self::run([self::ufwBinary(), 'status', 'numbered']);
        $parsed = self::parseStatus($verbose['out'] . "\n" . $verbose['err'], $numbered['out']);
        $parsed['installed'] = true;

        return $parsed;
    }

    /**
     * @return array{ok:bool, out:string, error?:string}
     */
    public static function enable(): array
    {
        if (!self::isInstalled()) {
            return ['ok' => false, 'out' => '', 'error' => 'ufw_not_installed'];
        }
        $bin = self::ufwBinary();
        $parts = [];
        foreach (self::protectedTcpPorts() as $port) {
            $parts[] = self::run([$bin, 'allow', $port . '/tcp']);
        }
        $parts[] = self::run([$bin, 'allow', '53/tcp']);
        $parts[] = self::run([$bin, 'allow', '53/udp']);
        $parts[] = self::run([$bin, 'allow', '21/tcp']);
        $done = self::run([$bin, '--force', 'enable']);
        $out = '';
        foreach ($parts as $p) {
            $out .= $p['out'] . $p['err'];
        }
        $out .= $done['out'] . $done['err'];
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
    public static function addRule(string $action, string $proto, string $port, string $from = ''): array
    {
        if (!self::isInstalled()) {
            return ['ok' => false, 'out' => '', 'error' => 'ufw_not_installed'];
        }
        $argv = self::addRuleArgv($action, $proto, $port, $from);
        $done = self::run(array_merge([self::ufwBinary()], $argv));
        $out = $done['out'] . $done['err'];
        if ($done['code'] !== 0) {
            return ['ok' => false, 'out' => $out, 'error' => 'ufw_addrule_failed'];
        }

        return ['ok' => true, 'out' => $out];
    }

    /**
     * @return array{ok:bool, out:string, error?:string}
     */
    public static function deleteRule(string $number): array
    {
        if (!self::isInstalled()) {
            return ['ok' => false, 'out' => '', 'error' => 'ufw_not_installed'];
        }
        $n = self::validateRuleNumber($number);
        $st = self::status();
        if (!self::canDeleteRule($st['rules'], $n)) {
            throw new InvalidArgumentException('cannot_delete_panel_port');
        }
        $done = self::run([self::ufwBinary(), '--force', 'delete', (string) $n]);
        $out = $done['out'] . $done['err'];
        if ($done['code'] !== 0) {
            return ['ok' => false, 'out' => $out, 'error' => 'ufw_deleterule_failed'];
        }

        return ['ok' => true, 'out' => $out];
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
