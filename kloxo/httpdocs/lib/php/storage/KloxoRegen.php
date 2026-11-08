<?php

declare(strict_types=1);

/**
 * Render Hiawatha config from SQLite, validate, then rename into place.
 * Exclusive flock on regen.lock; changes inside 2 seconds are coalesced.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoRegen
{
    public const DEBOUNCE_US = 2000000;

    public function __construct(
        private readonly string $liveDir,
        private readonly string $templateFile,
        private readonly string $hiawathaBin,
        private readonly string $lockPath,
        private readonly ?KloxoPanelDb $db = null,
        private readonly string $reloadUnit = 'kloxo-web',
    ) {
    }

    public static function fromEnv(): self
    {
        $root = getenv('KLOXO_ROOT');
        $root = is_string($root) && $root !== '' ? rtrim($root, '/') : '/opt/kloxo';
        $live = getenv('KLOXO_HIAWATHA_CONFDIR');
        $live = is_string($live) && $live !== '' ? rtrim($live, '/') : $root . '/init';
        $bin = $live . '/kloxo-hiawatha';
        if (!is_executable($bin)) {
            $bin = $root . '/init/kloxo-hiawatha';
        }
        $lockDir = KloxoStore::runDir();
        KloxoStore::prepareDir($lockDir);

        return new self(
            $live,
            $live . '/hiawatha.conf.base',
            $bin,
            $lockDir . '/regen.lock',
            class_exists(KloxoPanelDb::class, false) ? KloxoPanelDb::open() : null,
        );
    }

    /**
     * @return array{ok:bool, error:string, written:bool}
     */
    public function run(): array
    {
        KloxoStore::prepareDir(dirname($this->lockPath));
        $fh = fopen($this->lockPath, 'c+');
        if ($fh === false) {
            return ['ok' => false, 'error' => 'cannot open regen.lock', 'written' => false];
        }
        // Exclusive lock: only one regen at a time (panel PHP and CLI).
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);

            return ['ok' => false, 'error' => 'cannot flock regen.lock', 'written' => false];
        }
        try {
            $this->debounce($fh);
            return $this->regenLocked();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * @return array{ok:bool, error:string, written:bool}
     */
    private function regenLocked(): array
    {
        $tmp = $this->liveDir . '/.regen.' . getmypid();
        $this->rmTree($tmp);
        if (!mkdir($tmp, 0700) && !is_dir($tmp)) {
            return ['ok' => false, 'error' => "cannot create {$tmp}", 'written' => false];
        }
        try {
            $this->renderInto($tmp);
            $check = $this->validate($tmp);
            if ($check !== '') {
                $this->log('hiawatha rejected generated config: ' . $check);

                return ['ok' => false, 'error' => $check, 'written' => false];
            }
            $target = $this->liveDir . '/hiawatha.conf';
            $from = $tmp . '/hiawatha.conf';
            // rename() is atomic on the same filesystem.
            if (!rename($from, $target)) {
                return ['ok' => false, 'error' => 'rename of hiawatha.conf failed', 'written' => false];
            }
            $this->reload();

            return ['ok' => true, 'error' => '', 'written' => true];
        } finally {
            $this->rmTree($tmp);
        }
    }

    public function renderText(): string
    {
        $base = is_file($this->templateFile)
            ? (string) file_get_contents($this->templateFile)
            : $this->minimalTemplate();
        $base = str_replace(
            ['__nonssl_port__', '__ssl_port__', '__fpmdisabled__', '__php__'],
            ['7778', '7777', '', 'php'],
            $base
        );
        $vhosts = $this->db !== null ? $this->db->vhosts() : [];
        $extra = '';
        foreach ($vhosts as $v) {
            $extra .= $this->virtualHostBlock($v);
        }

        return rtrim($base) . "\n" . $extra;
    }

    /** @param array<string,mixed> $v */
    private function virtualHostBlock(array $v): string
    {
        $domain = $this->hostname((string) ($v['domain'] ?? ''));
        $root = $this->fsPath((string) ($v['website_root'] ?? ''));
        $start = $this->startFile((string) ($v['start_file'] ?? 'index.php'));
        if ($domain === '' || $root === '') {
            return '';
        }
        $log = KloxoStore::domainLogDir($domain);
        $lines = [
            '',
            'VirtualHost {',
            '    Hostname = ' . $domain,
            '    WebsiteRoot = ' . $root,
            '    StartFile = ' . $start,
            '    AccessLogfile = ' . $log . '/access.log',
            '    ErrorLogfile = ' . $log . '/error.log',
            '}',
            '',
        ];

        return implode("\n", $lines);
    }

    private function renderInto(string $dir): void
    {
        $conf = $this->renderText();
        if (file_put_contents($dir . '/hiawatha.conf', $conf) === false) {
            throw new RuntimeException('cannot write temp hiawatha.conf');
        }
        foreach (['hiawatha-mimetype.conf'] as $side) {
            $src = $this->liveDir . '/' . $side;
            if (is_file($src)) {
                copy($src, $dir . '/' . $side);
            }
        }
        if (!is_file($dir . '/hiawatha-mimetype.conf')) {
            file_put_contents($dir . '/hiawatha-mimetype.conf', "text/plain txt\n");
        }
    }

    private function validate(string $dir): string
    {
        $bin = $this->hiawathaBin;
        if (!is_executable($bin)) {
            return 'hiawatha binary not executable: ' . $bin;
        }
        // Hiawatha 12: -k checks the config and exits; -c is the config directory.
        $cmd = [$bin, '-k', '-c', $dir];
        $proc = @proc_open(
            $cmd,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($proc)) {
            return 'could not start hiawatha -k';
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);
        if ($rc === 0) {
            return '';
        }

        return trim($err . "\n" . $out);
    }

    private function reload(): void
    {
        $unit = $this->reloadUnit;
        if (preg_match('/^[A-Za-z0-9_.@-]+$/', $unit) !== 1) {
            return;
        }
        $ctl = is_executable('/usr/bin/systemctl') ? '/usr/bin/systemctl' : '';
        if ($ctl === '') {
            return;
        }
        $proc = @proc_open(
            [$ctl, 'reload', $unit],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (is_resource($proc)) {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        }
    }

    private function debounce($fh): void
    {
        $env = getenv('KLOXO_REGEN_DEBOUNCE');
        if ($env === '0') {
            return;
        }
        $meta = stream_get_meta_data($fh);
        $uri = $meta['uri'] ?? $this->lockPath;
        $stamp = $uri . '.last';
        $last = is_file($stamp) ? (float) file_get_contents($stamp) : 0.0;
        $now = microtime(true);
        $wait = self::DEBOUNCE_US / 1e6 - ($now - $last);
        if ($wait > 0 && $last > 0) {
            usleep((int) round($wait * 1e6));
        }
        file_put_contents($stamp, (string) microtime(true));
    }

    private function hostname(string $v): string
    {
        $v = strtolower(trim($v));
        if (preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $v) !== 1) {
            return '';
        }

        return $v;
    }

    private function fsPath(string $v): string
    {
        $v = trim($v);
        if ($v === '' || $v[0] !== '/' || str_contains($v, "\0") || str_contains($v, '..')) {
            return '';
        }
        if (preg_match('#^[A-Za-z0-9._/-]+$#', $v) !== 1) {
            return '';
        }

        return $v;
    }

    private function startFile(string $v): string
    {
        $v = trim($v);
        if ($v === '' || preg_match('/^[A-Za-z0-9._-]+$/', $v) !== 1) {
            return 'index.php';
        }

        return $v;
    }

    private function log(string $msg): void
    {
        $file = (getenv('KLOXO_ROOT') ?: '/opt/kloxo') . '/log/hiawatha-regen.log';
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents($file, date('c') . ' ' . $msg . "\n", FILE_APPEND);
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            $p = $dir . '/' . $n;
            is_dir($p) ? $this->rmTree($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    private function minimalTemplate(): string
    {
        return "ServerId = lxlabs\n"
            . "PIDfile = /tmp/kloxo-hiawatha.pid\n"
            . "SystemLogfile = /dev/null\n"
            . "GarbageLogfile = /dev/null\n"
            . "AccessLogfile = /dev/null\n"
            . "MimetypeConfig = hiawatha-mimetype.conf\n"
            . "Binding { BindingId = http; Port = 7778 }\n"
            . "Hostname = 127.0.0.1\n"
            . "WebsiteRoot = /tmp\n"
            . "StartFile = index.html\n";
    }
}
