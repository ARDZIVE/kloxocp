<?php
declare(strict_types=1);

/**
 * LogWatch configuration for Ubuntu. Runs as root through kloxo-backend.
 *
 * Settings live in etc/conf/logwatch.json. The system file
 * /etc/logwatch/conf/logwatch.conf and /etc/cron.d/kloxo-logwatch are rendered
 * from them (never edit those by hand): write temp, install atomically, prune
 * old saved reports under /var/log/logwatch/. Process execution uses argument
 * arrays only; every value that reaches a command or conf file is validated.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoLogwatch
{
    /** Tests point every absolute path under this directory. Never set from input. */
    public static string $fsPrefix = '';

    public const REPORT_DIR = '/var/log/logwatch';
    public const CONF_FILE = '/etc/logwatch/conf/logwatch.conf';
    public const CRON_FILE = '/etc/cron.d/kloxo-logwatch';
    public const STOCK_DAILY = '/etc/cron.daily/00logwatch';

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
        return ($root ?? self::rootDir()) . '/etc/conf/logwatch.json';
    }

    public static function reportDir(): string
    {
        return self::p(self::REPORT_DIR);
    }

    public static function logwatchBin(): string
    {
        foreach (['/usr/sbin/logwatch', '/usr/bin/logwatch'] as $b) {
            if (is_executable(self::p($b))) {
                return self::p($b);
            }
        }

        return '';
    }

    public static function isInstalled(): bool
    {
        return self::logwatchBin() !== '';
    }

    // ---------------------------------------------------------------- config

    /**
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        // Package is installed by default; schedule stays off until the admin enables it.
        return [
            'enabled' => false,
            'mailto' => 'root',
            'mailfrom' => 'logwatch',
            'detail' => 'Low',
            'range' => 'Yesterday',
            'format' => 'text',
            'archives' => true,
            'frequency' => 'daily',
            'hour' => 6,
            'minute' => 0,
            'keep_days' => 14,
            'save_reports' => true,
            'mail_reports' => true,
        ];
    }

    /**
     * @param array<string,mixed> $cfg
     * @return array<string,mixed>
     */
    public static function normalizeConfig(array $cfg): array
    {
        $d = self::defaults();
        $out = $d;
        $out['enabled'] = !empty($cfg['enabled']);
        $out['mailto'] = self::validEmailOrLocal((string) ($cfg['mailto'] ?? $d['mailto']), (string) $d['mailto']);
        $out['mailfrom'] = self::validEmailOrLocal((string) ($cfg['mailfrom'] ?? $d['mailfrom']), (string) $d['mailfrom']);
        $detail = (string) ($cfg['detail'] ?? $d['detail']);
        $out['detail'] = in_array($detail, ['Low', 'Med', 'High', '0', '5', '10'], true) ? $detail : 'Low';
        if ($out['detail'] === '0') {
            $out['detail'] = 'Low';
        } elseif ($out['detail'] === '5') {
            $out['detail'] = 'Med';
        } elseif ($out['detail'] === '10') {
            $out['detail'] = 'High';
        }
        $range = (string) ($cfg['range'] ?? $d['range']);
        $allowedRange = ['Yesterday', 'Today', 'All', 'between -7 days and -1 days'];
        $out['range'] = in_array($range, $allowedRange, true) ? $range : 'Yesterday';
        $format = strtolower((string) ($cfg['format'] ?? $d['format']));
        $out['format'] = in_array($format, ['text', 'html'], true) ? $format : 'text';
        $out['archives'] = array_key_exists('archives', $cfg) ? !empty($cfg['archives']) : true;
        $freq = strtolower((string) ($cfg['frequency'] ?? $d['frequency']));
        $out['frequency'] = in_array($freq, ['daily', 'weekly', 'monthly', 'none'], true) ? $freq : 'daily';
        $hour = (int) ($cfg['hour'] ?? $d['hour']);
        $out['hour'] = max(0, min(23, $hour));
        $minute = (int) ($cfg['minute'] ?? $d['minute']);
        $out['minute'] = max(0, min(59, $minute));
        $keep = (int) ($cfg['keep_days'] ?? $d['keep_days']);
        $out['keep_days'] = max(1, min(3650, $keep));
        $out['save_reports'] = array_key_exists('save_reports', $cfg) ? !empty($cfg['save_reports']) : true;
        $out['mail_reports'] = array_key_exists('mail_reports', $cfg) ? !empty($cfg['mail_reports']) : true;

        return $out;
    }

    public static function validEmailOrLocal(string $raw, string $fallback): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return $fallback;
        }
        // Local mailbox or simple addr; spaces for multiple recipients.
        if (preg_match('/^[A-Za-z0-9._%+@\-]+(?:\s+[A-Za-z0-9._%+@\-]+)*$/', $raw) !== 1) {
            return $fallback;
        }
        if (strlen($raw) > 200) {
            return $fallback;
        }

        return $raw;
    }

    /**
     * @return array<string,mixed>
     */
    public static function loadConfig(?string $root = null): array
    {
        $path = self::configPath($root);
        if (!is_file($path)) {
            return self::defaults();
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return self::defaults();
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            return self::defaults();
        }

        return self::normalizeConfig($j);
    }

    /**
     * @param array<string,mixed> $cfg
     */
    public static function saveConfig(array $cfg, ?string $root = null): void
    {
        $root = $root ?? self::rootDir();
        $cfg = self::normalizeConfig($cfg);
        $dir = $root . '/etc/conf';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot_create_logwatch_config_dir');
        }
        $path = self::configPath($root);
        $tmp = $path . '.tmp.' . getmypid();
        $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || @file_put_contents($tmp, $json . "\n") === false) {
            throw new RuntimeException('logwatch_config_not_writable');
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('logwatch_config_not_writable');
        }
    }

    // --------------------------------------------------------------- render

    /**
     * @param array<string,mixed> $cfg
     */
    public static function renderConf(array $cfg): string
    {
        $cfg = self::normalizeConfig($cfg);
        $archives = !empty($cfg['archives']) ? 'Yes' : 'No';
        $lines = [
            '# Generated by Kloxo CP — do not edit by hand. Change Security > LogWatch.',
            'MailTo = ' . $cfg['mailto'],
            'MailFrom = ' . $cfg['mailfrom'],
            'Detail = ' . $cfg['detail'],
            'Range = ' . $cfg['range'],
            'Format = ' . $cfg['format'],
            'Archives = ' . $archives,
            'Service = All',
            // Mail is handled by kloxo-logwatch run; conf still names recipients.
            'Output = mail',
        ];

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string,mixed> $cfg
     */
    public static function renderCron(array $cfg, ?string $root = null): string
    {
        $cfg = self::normalizeConfig($cfg);
        $root = $root ?? self::rootDir();
        $bin = $root . '/bin/logwatch/kloxo-logwatch';
        if (empty($cfg['enabled']) || ($cfg['frequency'] ?? '') === 'none') {
            return "# Kloxo CP LogWatch disabled\n";
        }
        $m = (int) $cfg['minute'];
        $h = (int) $cfg['hour'];
        $freq = (string) $cfg['frequency'];
        if ($freq === 'weekly') {
            $sched = sprintf('%d %d * * 0', $m, $h);
        } elseif ($freq === 'monthly') {
            $sched = sprintf('%d %d 1 * *', $m, $h);
        } else {
            $sched = sprintf('%d %d * * *', $m, $h);
        }

        return "# Generated by Kloxo CP — do not edit by hand.\n"
            . "SHELL=/bin/sh\n"
            . "PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin\n"
            . $sched . ' root ' . $bin . " run --root=" . $root . " >/dev/null 2>&1\n";
    }

    /**
     * Apply config: write conf + cron, mute stock daily cron, ensure report dir.
     *
     * @param array<string,mixed>|null $cfg
     * @return array{ok:bool,error?:string,installed:bool,enabled:bool,report_dir:string}
     */
    public static function apply(?array $cfg = null, ?string $root = null): array
    {
        $root = $root ?? self::rootDir();
        $cfg = self::normalizeConfig($cfg ?? self::loadConfig($root));
        self::saveConfig($cfg, $root);

        $installed = self::isInstalled();
        $reportDir = self::reportDir();
        if (!empty($cfg['save_reports'])) {
            if (!is_dir($reportDir) && !@mkdir($reportDir, 0750, true) && !is_dir($reportDir)) {
                return ['ok' => false, 'error' => 'cannot_create_report_dir', 'installed' => $installed,
                    'enabled' => !empty($cfg['enabled']), 'report_dir' => self::REPORT_DIR];
            }
        }

        if ($installed) {
            $confText = self::renderConf($cfg);
            $confPath = self::p(self::CONF_FILE);
            $confDir = dirname($confPath);
            if (!is_dir($confDir) && !@mkdir($confDir, 0755, true) && !is_dir($confDir)) {
                return ['ok' => false, 'error' => 'cannot_write_logwatch_conf', 'installed' => true,
                    'enabled' => !empty($cfg['enabled']), 'report_dir' => self::REPORT_DIR];
            }
            if (!self::atomicWrite($confPath, $confText, 0644)) {
                return ['ok' => false, 'error' => 'cannot_write_logwatch_conf', 'installed' => true,
                    'enabled' => !empty($cfg['enabled']), 'report_dir' => self::REPORT_DIR];
            }
            // Always mute Ubuntu's cron.daily LogWatch; only our cron.d runs when enabled.
            self::muteStockDaily(true);
        }

        $cronText = self::renderCron($cfg, $root);
        $cronPath = self::p(self::CRON_FILE);
        if (!self::atomicWrite($cronPath, $cronText, 0644)) {
            return ['ok' => false, 'error' => 'cannot_write_logwatch_cron', 'installed' => $installed,
                'enabled' => !empty($cfg['enabled']), 'report_dir' => self::REPORT_DIR];
        }

        self::pruneReports((int) $cfg['keep_days']);

        return [
            'ok' => true,
            'installed' => $installed,
            'enabled' => !empty($cfg['enabled']) && ($cfg['frequency'] ?? '') !== 'none',
            'report_dir' => self::REPORT_DIR,
        ];
    }

    /**
     * Stop Ubuntu's /etc/cron.daily/00logwatch so only Security > LogWatch
     * (our cron.d entry) can schedule runs when the admin enables it.
     */
    private static function muteStockDaily(bool $mute): void
    {
        $daily = self::p(self::STOCK_DAILY);
        $disabled = $daily . '.kloxo-disabled';
        if ($mute) {
            if (is_file($daily) && !is_file($disabled)) {
                @rename($daily, $disabled);
            }
        } else {
            if (is_file($disabled) && !is_file($daily)) {
                @rename($disabled, $daily);
            }
        }
    }

    private static function atomicWrite(string $path, string $text, int $mode): bool
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $path . '.tmp.' . getmypid();
        if (@file_put_contents($tmp, $text) === false) {
            return false;
        }
        @chmod($tmp, $mode);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    // ----------------------------------------------------------------- run

    /**
     * Run LogWatch once: save a dated report and optionally mail.
     *
     * @return array{ok:bool,error?:string,report?:string,mailed?:bool}
     */
    public static function run(?string $root = null): array
    {
        $root = $root ?? self::rootDir();
        $cfg = self::loadConfig($root);
        if (!self::isInstalled()) {
            return ['ok' => false, 'error' => 'logwatch_not_installed'];
        }
        $bin = self::logwatchBin();
        $report = '';
        if (!empty($cfg['save_reports'])) {
            $dir = self::reportDir();
            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                return ['ok' => false, 'error' => 'cannot_create_report_dir'];
            }
            $ext = (($cfg['format'] ?? 'text') === 'html') ? 'html' : 'txt';
            $report = $dir . '/logwatch-' . gmdate('Y-m-d') . '.' . $ext;
            $args = [
                $bin,
                '--detail', (string) $cfg['detail'],
                '--range', (string) $cfg['range'],
                '--format', (string) $cfg['format'],
                '--output', 'file',
                '--filename', $report,
            ];
            if (!empty($cfg['archives'])) {
                $args[] = '--archives';
            }
            $r = self::runCmd($args);
            if (($r['code'] ?? 1) !== 0) {
                return ['ok' => false, 'error' => 'logwatch_run_failed', 'report' => $report];
            }
        }

        $mailed = false;
        if (!empty($cfg['mail_reports']) && trim((string) $cfg['mailto']) !== '') {
            $args = [
                $bin,
                '--detail', (string) $cfg['detail'],
                '--range', (string) $cfg['range'],
                '--format', (string) $cfg['format'],
                '--output', 'mail',
                '--mailto', (string) $cfg['mailto'],
            ];
            if (!empty($cfg['archives'])) {
                $args[] = '--archives';
            }
            $r = self::runCmd($args);
            $mailed = (($r['code'] ?? 1) === 0);
        }

        self::pruneReports((int) $cfg['keep_days']);

        return ['ok' => true, 'report' => $report, 'mailed' => $mailed];
    }

    /**
     * Email the newest saved report under /var/log/logwatch/, ignoring the
     * mail_reports checkbox (force send). Uses sendmail when available.
     *
     * @return array{ok:bool,error?:string,report?:string,mailto?:string}
     */
    public static function mailLastReport(?string $root = null): array
    {
        $root = $root ?? self::rootDir();
        $cfg = self::loadConfig($root);
        $mailto = trim((string) ($cfg['mailto'] ?? ''));
        $mailfrom = trim((string) ($cfg['mailfrom'] ?? 'logwatch'));
        if ($mailto === '') {
            return ['ok' => false, 'error' => 'logwatch_no_mailto'];
        }
        $mailto = self::validEmailOrLocal($mailto, '');
        $mailfrom = self::validEmailOrLocal($mailfrom, 'logwatch');
        if ($mailto === '') {
            return ['ok' => false, 'error' => 'logwatch_no_mailto'];
        }

        $reports = self::listReports();
        if ($reports === []) {
            return ['ok' => false, 'error' => 'logwatch_no_report'];
        }
        $path = (string) ($reports[0]['path'] ?? '');
        if ($path === '' || !is_file($path) || !self::isReportPath($path)) {
            return ['ok' => false, 'error' => 'logwatch_no_report'];
        }
        $body = @file_get_contents($path);
        if (!is_string($body)) {
            return ['ok' => false, 'error' => 'logwatch_no_report', 'report' => $path];
        }

        $name = basename($path);
        $isHtml = str_ends_with(strtolower($name), '.html');
        $ctype = $isHtml ? 'text/html; charset=UTF-8' : 'text/plain; charset=UTF-8';
        $subject = 'LogWatch report ' . $name;
        $headers = 'To: ' . $mailto . "\n"
            . 'From: ' . $mailfrom . "\n"
            . 'Subject: ' . self::headerSafe($subject) . "\n"
            . 'MIME-Version: 1.0' . "\n"
            . 'Content-Type: ' . $ctype . "\n"
            . "\n";
        $message = $headers . $body;

        $sendmail = self::sendmailBin();
        if ($sendmail === '') {
            return ['ok' => false, 'error' => 'logwatch_no_sendmail', 'report' => $path];
        }
        // Envelope sender is a single address (first token of MailFrom).
        $envelope = preg_split('/\s+/', $mailfrom, 2)[0] ?? 'logwatch';
        $r = self::runCmdWithStdin([$sendmail, '-t', '-oi', '-f', $envelope], $message);
        if (($r['code'] ?? 1) !== 0) {
            return ['ok' => false, 'error' => 'logwatch_mail_failed', 'report' => $path];
        }

        return ['ok' => true, 'report' => $path, 'mailto' => $mailto];
    }

    public static function isReportPath(string $path): bool
    {
        $dir = rtrim(self::reportDir(), '/');
        if ($path === '' || !str_starts_with($path, $dir . '/')) {
            return false;
        }
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            return false;
        }

        return (bool) preg_match('#/logwatch-[A-Za-z0-9._-]+$#', $path);
    }

    private static function headerSafe(string $s): string
    {
        return str_replace(["\r", "\n"], '', $s);
    }

    private static function sendmailBin(): string
    {
        foreach (['/usr/sbin/sendmail', '/usr/lib/sendmail'] as $b) {
            if (is_executable(self::p($b))) {
                return self::p($b);
            }
        }

        return '';
    }

    /**
     * @param list<string> $argv
     * @return array{code:int,out:string,err:string}
     */
    private static function runCmdWithStdin(array $argv, string $stdin): array
    {
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($argv, $desc, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return ['code' => 127, 'out' => '', 'err' => 'proc_open failed'];
        }
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) ?: '';
        $err = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return ['code' => (int) $code, 'out' => $out, 'err' => $err];
    }

    /**
     * @param list<string> $argv
     * @return array{code:int,out:string,err:string}
     */
    private static function runCmd(array $argv): array
    {
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($argv, $desc, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            return ['code' => 127, 'out' => '', 'err' => 'proc_open failed'];
        }
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) ?: '';
        $err = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        return ['code' => (int) $code, 'out' => $out, 'err' => $err];
    }

    public static function pruneReports(int $keepDays): int
    {
        $keepDays = max(1, min(3650, $keepDays));
        $dir = self::reportDir();
        if (!is_dir($dir)) {
            return 0;
        }
        $cut = time() - ($keepDays * 86400);
        $n = 0;
        foreach (glob($dir . '/logwatch-*') ?: [] as $f) {
            if (!is_file($f)) {
                continue;
            }
            $mt = @filemtime($f);
            if ($mt !== false && $mt < $cut) {
                if (@unlink($f)) {
                    $n++;
                }
            }
        }

        return $n;
    }

    /**
     * @return list<array{name:string,path:string,mtime:int,size:int}>
     */
    public static function listReports(): array
    {
        $dir = self::reportDir();
        $out = [];
        if (!is_dir($dir)) {
            return $out;
        }
        foreach (glob($dir . '/logwatch-*') ?: [] as $f) {
            if (!is_file($f)) {
                continue;
            }
            $out[] = [
                'name' => basename($f),
                'path' => $f,
                'mtime' => (int) (@filemtime($f) ?: 0),
                'size' => (int) (@filesize($f) ?: 0),
            ];
        }
        usort($out, static function ($a, $b) {
            return ($b['mtime'] <=> $a['mtime']);
        });

        return $out;
    }

    /**
     * @return array{ok:bool,installed:bool,enabled:bool,config:array<string,mixed>,reports:list<array<string,mixed>>,report_dir:string,error?:string}
     */
    public static function status(?string $root = null): array
    {
        $root = $root ?? self::rootDir();
        $cfg = self::loadConfig($root);

        return [
            'ok' => true,
            'installed' => self::isInstalled(),
            'enabled' => !empty($cfg['enabled']) && ($cfg['frequency'] ?? '') !== 'none',
            'config' => $cfg,
            'reports' => self::listReports(),
            'report_dir' => self::REPORT_DIR,
        ];
    }

    /**
     * Save settings from the panel form and apply.
     *
     * @param array<string,mixed> $fields
     * @return array{ok:bool,error?:string,installed?:bool,enabled?:bool,report_dir?:string}
     */
    public static function setSettings(array $fields, ?string $root = null): array
    {
        $root = $root ?? self::rootDir();
        $cur = self::loadConfig($root);
        foreach ([
            'enabled', 'mailto', 'mailfrom', 'detail', 'range', 'format',
            'archives', 'frequency', 'hour', 'minute', 'keep_days',
            'save_reports', 'mail_reports',
        ] as $k) {
            if (array_key_exists($k, $fields)) {
                $cur[$k] = $fields[$k];
            }
        }
        // Checkboxes arrive as on/off strings.
        foreach (['enabled', 'archives', 'save_reports', 'mail_reports'] as $flag) {
            if (isset($fields[$flag])) {
                $v = $fields[$flag];
                $cur[$flag] = ($v === true || $v === 1 || $v === '1' || $v === 'on' || $v === 'yes');
            }
        }

        try {
            return self::apply($cur, $root);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
