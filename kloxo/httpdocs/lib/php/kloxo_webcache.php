<?php
declare(strict_types=1);

/**
 * Web cache (Switch Program > Web Cache): Nginx proxy cache in front of the
 * Nginx or Apache site server. Per-domain TLS records feed install-webcache;
 * apply() runs it (argument-array process, root via kloxo-backend).
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoWebcache
{
    public const SSL_DIR = '/home/kloxo/ssl';

    public static function rootDir(): string
    {
        global $sgbl;

        if (isset($sgbl) && is_object($sgbl) && isset($sgbl->__path_program_root)
            && is_string($sgbl->__path_program_root) && $sgbl->__path_program_root !== '') {
            return rtrim($sgbl->__path_program_root, '/');
        }
        $env = getenv('KLOXO_ROOT');

        return rtrim(is_string($env) && $env !== '' ? $env : '/opt/kloxo', '/');
    }

    public static function recordsDir(): string
    {
        return self::rootDir() . '/etc/webfront/domains';
    }

    public static function validName(string $n): bool
    {
        return strlen($n) <= 253 && preg_match('/^(\*\.)?[a-z0-9]([a-z0-9._-]*[a-z0-9])?$/', $n) === 1
            && !str_contains($n, '..');
    }

    /** One extra host name out of a string / array / object entry. */
    private static function entryName($e, array $keys): string
    {
        if (is_string($e)) {
            return strtolower(trim($e));
        }
        foreach ($keys as $k) {
            if (is_array($e) && isset($e[$k]) && is_string($e[$k])) {
                return strtolower(trim($e[$k]));
            }
            if (is_object($e) && isset($e->$k) && is_string($e->$k)) {
                return strtolower(trim($e->$k));
            }
        }

        return '';
    }

    /**
     * Names and certificate a domain is served with, from the same input the
     * site templates get. Returns null when the domain has no certificate.
     *
     * @return array{domain:string,names:list<string>,cert:string}|null
     */
    public static function buildRecord(array $input): ?array
    {
        $domain = strtolower(trim((string) ($input['domainname'] ?? '')));
        if (!self::validName($domain) || str_starts_with($domain, '*')) {
            return null;
        }
        $names = array('www.' . $domain);
        if (!empty($input['wildcards'])) {
            $names[] = '*.' . $domain;
        }
        foreach ((array) ($input['serveraliases'] ?? array()) as $e) {
            $names[] = self::entryName($e, array('alias', 'nname'));
        }
        foreach ((array) ($input['parkdomains'] ?? array()) as $e) {
            $p = self::entryName($e, array('parkdomain', 'nname'));
            if ($p !== '') {
                $names[] = $p;
                $names[] = 'www.' . $p;
            }
        }
        $clean = array();
        foreach ($names as $n) {
            if ($n !== '' && $n !== $domain && self::validName($n) && !in_array($n, $clean, true)) {
                $clean[] = $n;
            }
        }
        $certname = '';
        foreach ((array) ($input['certnamelist'] ?? array()) as $c) {
            if (is_string($c) && $c !== '') {
                $certname = $c;
                break;
            }
        }
        if ($certname === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $certname) !== 1 || str_contains($certname, '..')) {
            return null;
        }

        return array('domain' => $domain, 'names' => $clean, 'cert' => self::SSL_DIR . '/' . $certname);
    }

    public static function writeRecord(array $input): bool
    {
        $rec = self::buildRecord($input);
        $domain = strtolower(trim((string) ($input['domainname'] ?? '')));
        if (!self::validName($domain)) {
            return false;
        }
        if ($rec === null) {
            self::removeRecord($domain);

            return false;
        }
        $dir = self::recordsDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $file = $dir . '/' . $domain . '.json';
        $tmp = $file . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, json_encode($rec, JSON_UNESCAPED_SLASHES)) === false) {
            return false;
        }
        @chmod($tmp, 0644);

        return @rename($tmp, $file);
    }

    public static function removeRecord(string $domain): void
    {
        $domain = strtolower(trim($domain));
        if (!self::validName($domain)) {
            return;
        }
        $file = self::recordsDir() . '/' . $domain . '.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }

    public static function removeAllRecords(): void
    {
        foreach (glob(self::recordsDir() . '/*.json') ?: array() as $f) {
            @unlink($f);
        }
    }

    /** Switch Program driver name -> install-webcache mode. */
    public static function modeFor(string $driver): string
    {
        return $driver === 'nginx' ? 'front' : 'none';
    }

    /**
     * Run install-webcache for a web cache driver ('nginx' or 'none').
     *
     * @return array{ok:bool,out:string,error:string}
     */
    public static function apply(string $driver): array
    {
        if ($driver !== 'nginx' && $driver !== 'none') {
            return array('ok' => false, 'out' => '', 'error' => 'invalid_webcache_driver');
        }
        $root = self::rootDir();
        $tool = $root . '/bin/install/install-webcache';
        if (!is_file($tool)) {
            return array('ok' => false, 'out' => '', 'error' => 'install-webcache is missing');
        }
        $php = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
        $p = @proc_open(
            array($php, $tool, '--mode=' . self::modeFor($driver), '--root=' . $root),
            array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes
        );
        if (!is_resource($p)) {
            return array('ok' => false, 'out' => '', 'error' => 'could not start install-webcache');
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($p);
        $err = trim(preg_replace('/^error:\s*/', '', trim($err)) ?? '');

        return array('ok' => $rc === 0, 'out' => trim($out), 'error' => $rc === 0 ? '' : ($err !== '' ? substr($err, 0, 400) : 'install-webcache failed'));
    }
}
