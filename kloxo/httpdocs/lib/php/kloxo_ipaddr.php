<?php
declare(strict_types=1);

/**
 * Read IPv4 addresses from `ip` without writing network config.
 *
 * Ubuntu uses netplan, not /etc/sysconfig/network-scripts/ifcfg-* and not
 * /etc/network/interfaces. The panel only needs the list for sites and DNS.
 * Writing those files can take the host off the network.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoIpaddr
{
    public static function isUbuntu(): bool
    {
        $f = '/etc/os-release';
        if (!is_file($f)) {
            return false;
        }
        $t = (string)@file_get_contents($f);

        return (bool)preg_match('/^ID=ubuntu/m', $t);
    }

    /** True when the panel must not write ifcfg or /etc/network/interfaces. */
    public static function refuseNetworkWrite(): bool
    {
        if (self::isUbuntu()) {
            return true;
        }

        return !is_dir('/etc/sysconfig/network-scripts');
    }

    /**
     * @return list<array{devname:string,ipaddr:string,netmask:string,status:string,gateway:string,bproto:string,itype:string}>
     */
    public static function currentIpv4(): array
    {
        return self::parseAddrShow(
            self::run(['ip', '-4', '-o', 'addr', 'show']),
            self::run(['ip', '-4', 'route', 'show', 'default'])
        );
    }

    /**
     * @return list<array{devname:string,ipaddr:string,netmask:string,status:string,gateway:string,bproto:string,itype:string}>
     */
    public static function parseAddrShow(string $addrOut, string $routeOut = ''): array
    {
        $gw = self::parseDefaultGateway($routeOut);
        $rows = [];
        foreach (preg_split('/\R/', $addrOut) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\d+:\s+(\S+)\s+inet\s+(\d+\.\d+\.\d+\.\d+)\/(\d+)/', $line, $m) !== 1) {
                continue;
            }
            $dev = $m[1];
            $ip = $m[2];
            $pfx = (int)$m[3];
            if ($dev === 'lo' || str_starts_with($ip, '127.')) {
                continue;
            }
            $via = '';
            if ($gw['via'] !== '' && ($gw['dev'] === '' || $gw['dev'] === $dev)) {
                $via = $gw['via'];
            }
            $rows[] = [
                'devname' => $dev,
                'ipaddr' => $ip,
                'netmask' => self::prefixToMask($pfx),
                'status' => 'yes',
                'gateway' => $via,
                'bproto' => 'static',
                'itype' => 'Ethernet',
            ];
        }

        return $rows;
    }

    /** @return array{via:string,dev:string} */
    public static function parseDefaultGateway(string $routeOut): array
    {
        if (preg_match('/default via (\d+\.\d+\.\d+\.\d+)(?:\s+dev\s+(\S+))?/', $routeOut, $m) === 1) {
            return ['via' => $m[1], 'dev' => $m[2] ?? ''];
        }

        return ['via' => '', 'dev' => ''];
    }

    public static function prefixToMask(int $prefix): string
    {
        if ($prefix < 0 || $prefix > 32) {
            return '255.255.255.255';
        }
        $bits = $prefix === 0 ? 0 : ((-1 << (32 - $prefix)) & 0xFFFFFFFF);

        return long2ip($bits) ?: '255.255.255.255';
    }

    /**
     * Values for the mandatory localhost pserver row (Servers list).
     *
     * @return array{ostype:string,osversion:string}
     */
    public static function osSeed(): array
    {
        return self::parseOsRelease((string)@file_get_contents('/etc/os-release'));
    }

    /**
     * @return array{ostype:string,osversion:string}
     */
    public static function parseOsRelease(string $text): array
    {
        $id = '';
        $pretty = '';
        if (preg_match('/^ID=(.+)$/m', $text, $m) === 1) {
            $id = strtolower(trim($m[1], " \t\"'"));
        }
        if (preg_match('/^PRETTY_NAME=(.+)$/m', $text, $m) === 1) {
            $pretty = trim($m[1], " \t\"'");
        }
        $debian = ['ubuntu', 'debian', 'linuxmint', 'pop', 'elementary'];
        $ostype = ($id === '' || in_array($id, $debian, true)) ? 'debian' : 'rhel';
        $pretty = preg_replace('/[^A-Za-z0-9 ._()+\/-]/', '', $pretty) ?? '';
        if ($pretty === '') {
            $pretty = $id !== '' ? $id : 'linux';
        }
        if (strlen($pretty) > 250) {
            $pretty = substr($pretty, 0, 250);
        }

        return ['ostype' => $ostype, 'osversion' => $pretty];
    }

    /** @param list<string> $cmd */
    private static function run(array $cmd): string
    {
        $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($p)) {
            return '';
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);

        return is_string($out) ? $out : '';
    }
}
