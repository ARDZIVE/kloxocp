<?php
declare(strict_types=1);

/**
 * Domain SSL install and Let's Encrypt / self-signed issue for Ubuntu.
 * Runs as root through kloxo-backend (panel FPM cannot write /home/kloxo/ssl
 * or invoke certbot). Argument-array process execution only.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoSslException extends RuntimeException
{
}

final class KloxoSsl
{
    /** Tests point every absolute path under this directory. Never set from input. */
    public static string $fsPrefix = '';

    /** @var callable|null fn(list<string> $argv): array{code:int,out:string} */
    public static $runCmd = null;

    /**
     * Tests / hooks: install packages (e.g. certbot). Live default uses KloxoApt.
     *
     * @var callable|null fn(list<string> $packages): array{ok:bool,error:string}
     */
    public static $installPackages = null;

    private static function p(string $abs): string
    {
        return self::$fsPrefix . $abs;
    }

    public static function sslDir(): string
    {
        return self::p('/home/kloxo/ssl');
    }

    /**
     * Legacy shared ACME path (kept as last-resort fallback). Prefer the
     * domain document root via resolveWebroot().
     */
    public static function webroot(): string
    {
        return self::p('/var/lib/kloxo/acme');
    }

    /**
     * Resolve the certbot/acme.sh webroot: domain docroot (so Nginx/Apache
     * try_files / DocumentRoot serve the token). Falls back to shared store.
     */
    public static function resolveWebroot(string $domain, string $docroot = ''): string
    {
        $domain = self::validateDomain($domain);
        $candidates = [];
        $docroot = trim($docroot);
        if ($docroot !== ''
            && str_starts_with($docroot, '/')
            && !str_contains($docroot, "\0")
            && !str_contains($docroot, '..')
            && preg_match('#^/(home|var/lib/kloxo)(/|$)#', $docroot) === 1) {
            $candidates[] = self::$fsPrefix !== '' ? self::p($docroot) : $docroot;
        }
        $candidates[] = self::p('/home/httpd/' . $domain . '/httpdocs');
        $candidates[] = self::webroot();
        $seen = [];
        foreach ($candidates as $c) {
            $c = rtrim(str_replace('\\', '/', (string) $c), '/');
            if ($c === '' || isset($seen[$c])) {
                continue;
            }
            $seen[$c] = true;
            if (is_dir($c) || is_link($c)) {
                return $c;
            }
            // Create under an existing parent (httpdocs symlink not yet made).
            if (str_ends_with($c, '/httpdocs') && is_dir(dirname($c))) {
                return $c;
            }
            if ($c === rtrim(self::webroot(), '/')) {
                return $c;
            }
        }

        return self::webroot();
    }

    /** @return non-empty-string */
    public static function validateDomain(string $domain): string
    {
        $d = strtolower(trim($domain));
        if ($d === '' || strlen($d) > 253
            || preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $d) !== 1) {
            throw new KloxoSslException('invalid domain name');
        }

        return $d;
    }

    /**
     * Hostnames allowed in SAN / -d lists (includes bare labels used in tests).
     *
     * @return list<string>
     */
    public static function validateSans(array $sans): array
    {
        $out = [];
        foreach ($sans as $s) {
            if (!is_string($s)) {
                continue;
            }
            $s = strtolower(trim($s));
            if ($s === '') {
                continue;
            }
            if (preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/i', $s) !== 1) {
                throw new KloxoSslException('invalid subject alt name: ' . $s);
            }
            $out[] = $s;
        }

        return array_values(array_unique($out));
    }

    public static function validateEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '' || strtoupper($email) === 'N/A') {
            return '';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new KloxoSslException('invalid email address');
        }

        return $email;
    }

    public static function validateKeyBits(string $bits): string
    {
        $bits = trim($bits);
        $ok = ['2048', '4096', 'ec-256', 'ec-384'];
        if (!in_array($bits, $ok, true)) {
            return '2048';
        }

        return $bits;
    }

    public static function ensureSslDir(): void
    {
        $dir = self::sslDir();
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new KloxoSslException('cannot create ' . $dir);
        }
    }

    public static function ensureWebroot(string $webroot = ''): void
    {
        $w = $webroot !== '' ? rtrim($webroot, '/') : self::webroot();
        $challenge = $w . '/.well-known/acme-challenge';
        if (!is_dir($challenge) && !@mkdir($challenge, 0755, true) && !is_dir($challenge)) {
            throw new KloxoSslException('cannot create ACME webroot ' . $challenge);
        }
        // Nginx/Apache (www-data) must read tokens written by root/certbot.
        @chmod($w, 0755);
        @chmod($w . '/.well-known', 0755);
        @chmod($challenge, 0755);
    }

    /**
     * Write a probe token under $webroot and fetch it via loopback with Host.
     * Skipped under $fsPrefix (unit tests) unless $runCmd is set to handle curl.
     */
    public static function preflightAcmeHttp(string $domain, string $webroot): void
    {
        // Unit tests use $fsPrefix; skip loopback HTTP against a fake tree.
        if (self::$fsPrefix !== '') {
            return;
        }
        $domain = self::validateDomain($domain);
        $webroot = rtrim($webroot, '/');
        self::ensureWebroot($webroot);
        $token = 'kloxo-preflight-' . bin2hex(random_bytes(8));
        $path = $webroot . '/.well-known/acme-challenge/' . $token;
        if (@file_put_contents($path, $token) === false) {
            throw new KloxoSslException('cannot write ACME preflight token under ' . $webroot);
        }
        @chmod($path, 0644);
        $url = 'http://127.0.0.1/.well-known/acme-challenge/' . $token;
        $code = 0;
        $body = '';
        try {
            $curl = self::which('curl');
            if ($curl !== '') {
                $r = self::run([
                    $curl, '-sS', '--max-time', '5',
                    '-H', 'Host: ' . $domain,
                    '-o', $path . '.out',
                    '-w', '%{http_code}',
                    $url,
                ]);
                $code = (int) trim($r['out']);
                if (is_file($path . '.out')) {
                    $body = (string) file_get_contents($path . '.out');
                    @unlink($path . '.out');
                }
            } else {
                $ctx = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'header' => "Host: {$domain}\r\n",
                        'timeout' => 5,
                        'ignore_errors' => true,
                    ],
                ]);
                $body = (string) @file_get_contents($url, false, $ctx);
                if (isset($http_response_header[0])
                    && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $m)) {
                    $code = (int) $m[1];
                }
            }
        } finally {
            @unlink($path);
        }
        if ($code === 200 && trim($body) === $token) {
            return;
        }
        throw new KloxoSslException(
            'ACME HTTP-01 preflight failed for ' . $domain
            . ' (HTTP ' . $code . ' from ' . $url . ' via docroot ' . $webroot . ')'
            . '; Nginx/Apache must serve /.well-known/acme-challenge/ from the site'
            . ' document root — run /script/fixweb --domain=' . $domain
            . ' and confirm port 80 reaches this host'
        );
    }

    /**
     * Install/refresh the Nginx ACME snippet under /opt/configs (and reload
     * is left to fixweb). Safe no-op when the tree is absent.
     */
    public static function ensureAcmeNginxSnippet(): void
    {
        $destDir = self::p('/opt/configs/nginx/conf/globals');
        $dest = $destDir . '/acme-challenge.conf';
        $candidates = [
            self::p('/opt/kloxo/file/nginx/conf/globals/acme-challenge.conf'),
            dirname(__DIR__, 3) . '/file/nginx/conf/globals/acme-challenge.conf',
        ];
        $src = '';
        foreach ($candidates as $c) {
            if (is_file($c) && is_readable($c)) {
                $src = $c;
                break;
            }
        }
        if ($src === '') {
            return;
        }
        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            return;
        }
        $body = (string) file_get_contents($src);
        if ($body === '') {
            return;
        }
        $cur = is_file($dest) ? (string) file_get_contents($dest) : '';
        if ($cur === $body) {
            return;
        }
        @file_put_contents($dest, $body);
    }

    /**
     * @param list<string> $argv
     * @return array{code:int,out:string}
     */
    public static function run(array $argv): array
    {
        if (self::$runCmd !== null) {
            /** @var array{code:int,out:string} $r */
            $r = (self::$runCmd)($argv);

            return $r;
        }
        if ($argv === [] || !is_string($argv[0]) || $argv[0] === '') {
            return ['code' => 127, 'out' => 'empty command'];
        }
        $env = [
            'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'LC_ALL' => 'C',
        ];
        $proc = @proc_open(
            $argv,
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        if (!is_resource($proc)) {
            return ['code' => 127, 'out' => 'could not start ' . $argv[0]];
        }
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['code' => (int) proc_close($proc), 'out' => trim($out . "\n" . $err)];
    }

    public static function which(string $name): string
    {
        foreach ([
            self::p('/usr/bin/' . $name),
            self::p('/usr/sbin/' . $name),
            self::p('/usr/local/bin/' . $name),
            '/usr/bin/' . $name,
            '/usr/sbin/' . $name,
            '/usr/local/bin/' . $name,
        ] as $p) {
            if (is_file($p) && is_executable($p)) {
                return $p;
            }
        }

        return '';
    }

    /**
     * Write domain key/crt/ca/csr/pem under /home/kloxo/ssl and reload site config.
     *
     * @return array{ok:bool, path:string}
     */
    public static function installDomainCert(
        string $domain,
        string $key,
        string $crt,
        string $ca = '',
        string $csr = '',
        bool $fixweb = true
    ): array {
        $domain = self::validateDomain($domain);
        $key = trim($key);
        $crt = trim($crt);
        $ca = trim($ca);
        $csr = trim($csr);
        if ($key === '' || $crt === '') {
            throw new KloxoSslException('certificate and key are required');
        }
        if (!str_contains($key, 'PRIVATE KEY') || !str_contains($crt, 'BEGIN CERTIFICATE')) {
            throw new KloxoSslException('certificate or key content looks invalid');
        }
        self::ensureSslDir();
        $base = self::sslDir() . '/' . $domain;
        $map = [
            'key' => $key,
            'crt' => $crt,
        ];
        if ($ca !== '') {
            $map['ca'] = $ca;
        }
        if ($csr !== '') {
            $map['csr'] = $csr;
        }
        $pem = $key . "\n" . $crt;
        if ($ca !== '') {
            $pem .= "\n" . $ca;
        }
        $map['pem'] = $pem;
        foreach ($map as $ext => $body) {
            $path = $base . '.' . $ext;
            if (@file_put_contents($path, $body) === false) {
                throw new KloxoSslException('could not write ' . $path);
            }
            @chmod($path, $ext === 'key' ? 0600 : 0644);
        }
        if ($fixweb) {
            self::fixweb($domain);
        }

        return ['ok' => true, 'path' => $base];
    }

    public static function fixweb(string $domain): void
    {
        $domain = self::validateDomain($domain);
        $script = self::p('/script/fixweb');
        if (!is_file($script)) {
            $script = '/script/fixweb';
        }
        if (!is_file($script)) {
            // Fallback: flag a web restart (legacy).
            if (function_exists('createRestartFile')) {
                createRestartFile('restart-web');
            }

            return;
        }
        $php = self::which('php') !== '' ? self::which('php') : 'php';
        // fixweb is usually a shell wrapper.
        $r = self::run(['/bin/sh', $script, '--domain=' . $domain]);
        if ($r['code'] !== 0 && function_exists('createRestartFile')) {
            createRestartFile('restart-web');
        }
        unset($php);
    }

    /**
     * Find certbot, or apt-install it (install.sh ships it; older hosts may lack it).
     * Skips apt when $fsPrefix is set (unit tests) unless $installPackages is hooked.
     */
    public static function ensureCertbot(): string
    {
        $certbot = self::which('certbot');
        if ($certbot !== '') {
            return $certbot;
        }
        if (self::$installPackages === null && self::$fsPrefix !== '') {
            return '';
        }
        $r = self::installAptPackages(['certbot']);
        if (!$r['ok']) {
            throw new KloxoSslException(
                'certbot not found and apt install failed'
                . ($r['error'] !== '' ? ': ' . $r['error'] : '')
                . '; run: apt install -y certbot'
            );
        }
        $certbot = self::which('certbot');
        if ($certbot === '') {
            throw new KloxoSslException(
                'certbot still missing after apt install; check PATH (/usr/bin/certbot)'
            );
        }

        return $certbot;
    }

    /**
     * @param list<string> $packages
     * @return array{ok:bool,error:string}
     */
    private static function installAptPackages(array $packages): array
    {
        if (self::$installPackages !== null) {
            /** @var array{ok:bool,error:string} $r */
            $r = (self::$installPackages)($packages);

            return $r;
        }
        $apt = __DIR__ . '/kloxo_apt.php';
        if (!is_file($apt)) {
            return ['ok' => false, 'error' => 'kloxo_apt.php missing'];
        }
        require_once $apt;
        if (!class_exists('KloxoApt', false)) {
            return ['ok' => false, 'error' => 'KloxoApt unavailable'];
        }
        $got = KloxoApt::install($packages);

        return ['ok' => !empty($got['ok']), 'error' => (string) ($got['error'] ?? '')];
    }

    /**
     * Issue (or renew) a Let's Encrypt cert with certbot, else acme.sh.
     *
     * @param list<string> $sans
     * @return array{ok:bool, key:string, crt:string, ca:string, tool:string, out:string, webroot?:string}
     */
    public static function issueLetsEncrypt(
        string $domain,
        string $email,
        array $sans,
        string $keyBits = '2048',
        string $docroot = ''
    ): array {
        $domain = self::validateDomain($domain);
        $email = self::validateEmail($email);
        $keyBits = self::validateKeyBits($keyBits);
        $sans = self::validateSans($sans);
        if ($sans === []) {
            $sans = [$domain, 'www.' . $domain];
        }
        if (!in_array($domain, $sans, true)) {
            array_unshift($sans, $domain);
        }
        self::ensureSslDir();
        $webroot = self::resolveWebroot($domain, $docroot);
        self::ensureWebroot($webroot);
        foreach ($sans as $s) {
            $host = strtolower($s);
            if ($host === 'webmail.' . $domain || $host === 'mail.' . $domain) {
                $wm = self::p('/home/kloxo/httpd/webmail/roundcube');
                if (!is_dir($wm)) {
                    $wm = self::p('/home/kloxo/httpd/webmail');
                }
                if (is_dir($wm) || @mkdir($wm, 0755, true)) {
                    self::ensureWebroot($wm);
                }
            }
        }
        self::ensureAcmeNginxSnippet();
        // Refresh vhost so Force-HTTPS / www redirects skip ACME before validation.
        self::fixweb($domain);
        // Prove the site serves tokens from this docroot before burning LE attempts.
        self::preflightAcmeHttp($domain, $webroot);

        $acme = self::p('/root/.acme.sh/acme.sh');
        if (!is_file($acme)) {
            $acme = '/root/.acme.sh/acme.sh';
        }
        if (is_file($acme) && is_executable($acme)) {
            return self::issueWithAcmeSh($acme, $domain, $email, $sans, $keyBits, $webroot);
        }

        $certbot = self::ensureCertbot();
        if ($certbot === '') {
            throw new KloxoSslException(
                'neither certbot nor /root/.acme.sh/acme.sh found; install certbot (apt install certbot)'
            );
        }

        return self::issueWithCertbot($certbot, $domain, $email, $sans, $keyBits, $webroot);
    }

    /**
     * Build certbot certonly argv. $duplicate adds --duplicate for a new lineage
     * when an overlapping certificate still blocks --expand.
     *
     * @param list<string> $sans
     * @return list<string>
     */
    private static function certbotCertonlyArgv(
        string $certbot,
        string $domain,
        string $email,
        array $sans,
        string $keyBits,
        string $webroot,
        bool $duplicate
    ): array {
        // --force-renewal: Add Let's Encrypt must re-issue when expanding SANs
        // even if the lineage is not near expiry (otherwise certbot 4.x exits 0
        // with "Certificate not yet due for renewal; no action taken").
        $argv = [
            $certbot, 'certonly',
            '--non-interactive',
            '--agree-tos',
            '--cert-name', $domain,
            '--expand',
            '--force-renewal',
            '--webroot',
        ];
        if ($duplicate) {
            $argv[] = '--duplicate';
        }
        if ($email !== '') {
            $argv[] = '--email';
            $argv[] = $email;
        } else {
            $argv[] = '--register-unsafely-without-email';
        }
        if ($keyBits === '4096') {
            $argv[] = '--rsa-key-size';
            $argv[] = '4096';
        } elseif ($keyBits === 'ec-256') {
            $argv[] = '--key-type';
            $argv[] = 'ecdsa';
            $argv[] = '--elliptic-curve';
            $argv[] = 'secp256r1';
        } elseif ($keyBits === 'ec-384') {
            $argv[] = '--key-type';
            $argv[] = 'ecdsa';
            $argv[] = '--elliptic-curve';
            $argv[] = 'secp384r1';
        }

        // Pair each name with its HTTP-01 webroot. webmail/mail use the panel
        // webmail docroot; everything else uses the domain site webroot.
        $webmailRoot = self::p('/home/kloxo/httpd/webmail/roundcube');
        if (!is_dir($webmailRoot)) {
            $webmailRoot = self::p('/home/kloxo/httpd/webmail');
        }
        foreach ($sans as $s) {
            $host = strtolower($s);
            $w = $webroot;
            if ($host === 'webmail.' . $domain || $host === 'mail.' . $domain) {
                $w = $webmailRoot;
            }
            $argv[] = '--webroot-path';
            $argv[] = $w;
            $argv[] = '-d';
            $argv[] = $s;
        }

        return $argv;
    }

    /** Certbot asked for a choice about an overlapping existing certificate. */
    private static function certbotNeedsExpandOrDuplicate(string $out): bool
    {
        return preg_match(
            '/existing certificate that contains a portion|Missing command line flag or config entry/i',
            $out
        ) === 1;
    }

    /**
     * @param list<string> $sans
     * @return array{ok:bool, key:string, crt:string, ca:string, tool:string, out:string, webroot:string}
     */
    private static function issueWithCertbot(
        string $certbot,
        string $domain,
        string $email,
        array $sans,
        string $keyBits,
        string $webroot
    ): array {
        // --cert-name + --expand: non-interactive answer when an existing
        // lineage covers a subset/overlap of the requested names (otherwise
        // certbot asks "Missing command line flag... existing certificate").
        // Do not use --keep-until-expiring here: Add Let's Encrypt always wants
        // a usable cert now, including SAN expands before expiry.
        $argv = self::certbotCertonlyArgv($certbot, $domain, $email, $sans, $keyBits, $webroot, false);
        $r = self::run($argv);
        if ($r['code'] !== 0 && self::certbotNeedsExpandOrDuplicate($r['out'])) {
            // Still interactive-overlap after --expand: try a duplicate lineage.
            $firstOut = $r['out'];
            $retry = self::certbotCertonlyArgv($certbot, $domain, $email, $sans, $keyBits, $webroot, true);
            $r2 = self::run($retry);
            $r = $r2;
            if ($r['code'] !== 0) {
                $r['out'] = trim($firstOut . "\n" . $r2['out']);
            }
        }
        if ($r['code'] !== 0) {
            $tail = self::tailOut($r['out'], 16);
            $hint = '';
            if (preg_match('/unauthorized|404|Invalid response|authenticate/i', $r['out']) === 1) {
                $hint = '; ACME HTTP-01 failed for webroot ' . $webroot
                    . ' — use only names that resolve here (domain + www),'
                    . ' run /script/fixweb --domain=' . $domain
                    . ', and ensure port 80 serves /.well-known/acme-challenge/ from the site docroot';
            } elseif (self::certbotNeedsExpandOrDuplicate($r['out'])) {
                $hint = '; an existing Let\'s Encrypt cert overlaps these names'
                    . ' — retry after removing the old lineage under /etc/letsencrypt/live/'
                    . ' or use only the names already on that certificate';
            }
            throw new KloxoSslException(
                'certbot failed' . ($tail !== '' ? ': ' . $tail : ' (exit ' . $r['code'] . ')') . $hint
            );
        }
        $live = self::p('/etc/letsencrypt/live/' . $domain);
        if (!is_dir($live)) {
            // First SAN may be the live dir name when domain was prepended.
            $live = self::p('/etc/letsencrypt/live/' . $sans[0]);
        }
        $key = self::readFile($live . '/privkey.pem');
        $crt = self::readFile($live . '/cert.pem');
        $ca = self::readFile($live . '/chain.pem');
        self::installDomainCert($domain, $key, $crt, $ca, '', true);

        return [
            'ok' => true,
            'key' => $key,
            'crt' => $crt,
            'ca' => $ca,
            'tool' => 'certbot',
            'out' => self::tailOut($r['out'], 20),
            'webroot' => $webroot,
        ];
    }

    /**
     * @param list<string> $sans
     * @return array{ok:bool, key:string, crt:string, ca:string, tool:string, out:string, webroot:string}
     */
    private static function issueWithAcmeSh(
        string $acme,
        string $domain,
        string $email,
        array $sans,
        string $keyBits,
        string $webroot
    ): array {
        $home = dirname($acme);
        $exist = is_file($home . '/' . $domain . '/ca.cer');
        $argv = [$acme, $exist ? '--renew' : '--issue', '--force', '--webroot', $webroot];
        foreach ($sans as $s) {
            $argv[] = '--domain';
            $argv[] = $s;
        }
        if ($email !== '') {
            $argv[] = '--accountemail';
            $argv[] = $email;
        }
        $argv[] = '--keylength';
        $argv[] = $keyBits;
        $r = self::run($argv);
        if ($r['code'] !== 0) {
            $tail = self::tailOut($r['out'], 12);
            throw new KloxoSslException(
                'acme.sh failed' . ($tail !== '' ? ': ' . $tail : ' (exit ' . $r['code'] . ')')
            );
        }
        $dir = $home . '/' . $domain;
        $key = self::readFile($dir . '/' . $domain . '.key');
        $crt = self::readFile($dir . '/' . $domain . '.cer');
        $ca = self::readFile($dir . '/ca.cer');
        self::installDomainCert($domain, $key, $crt, $ca, '', true);

        return [
            'ok' => true,
            'key' => $key,
            'crt' => $crt,
            'ca' => $ca,
            'tool' => 'acme.sh',
            'out' => self::tailOut($r['out'], 20),
            'webroot' => $webroot,
        ];
    }

    /**
     * Self-signed cert via openssl (argument array). Writes under /home/kloxo/ssl
     * when $install is true.
     *
     * @param array<string,string> $subj keys: commonName, emailAddress, countryName, ...
     * @return array{ok:bool, key:string, crt:string, csr:string}
     */
    public static function createSelfSigned(
        string $name,
        array $subj,
        int $bits = 2048,
        bool $install = true
    ): array {
        $name = preg_replace('/[^a-zA-Z0-9._-]/', '', $name) ?? '';
        if ($name === '') {
            throw new KloxoSslException('invalid certificate name');
        }
        $bits = ($bits === 4096) ? 4096 : 2048;
        $cn = isset($subj['commonName']) ? trim((string) $subj['commonName']) : $name;
        if ($cn === '') {
            $cn = $name;
        }
        $openssl = self::which('openssl');
        if ($openssl === '') {
            throw new KloxoSslException('openssl not found');
        }
        self::ensureSslDir();
        $dir = self::sslDir();
        $keyFile = $dir . '/' . $name . '.key';
        $crtFile = $dir . '/' . $name . '.crt';
        $csrFile = $dir . '/' . $name . '.csr';
        $subjStr = self::opensslSubj($subj, $cn);
        $r1 = self::run([
            $openssl, 'req', '-new', '-newkey', 'rsa:' . $bits,
            '-days', '365', '-sha256', '-nodes', '-x509',
            '-subj', $subjStr,
            '-keyout', $keyFile,
            '-out', $crtFile,
        ]);
        if ($r1['code'] !== 0 || !is_file($keyFile) || !is_file($crtFile)) {
            throw new KloxoSslException(
                'openssl self-signed failed: ' . self::tailOut($r1['out'], 8)
            );
        }
        $r2 = self::run([
            $openssl, 'req', '-new', '-sha256',
            '-subj', $subjStr,
            '-key', $keyFile,
            '-out', $csrFile,
        ]);
        if ($r2['code'] !== 0) {
            // CSR is optional for self-signed use.
            $csrFile = '';
        }
        $key = self::readFile($keyFile);
        $crt = self::readFile($crtFile);
        $csr = ($csrFile !== '' && is_file($csrFile)) ? self::readFile($csrFile) : '';
        if ($install) {
            $pem = $key . "\n" . $crt;
            @file_put_contents($dir . '/' . $name . '.pem', $pem);
        }

        return ['ok' => true, 'key' => $key, 'crt' => $crt, 'csr' => $csr];
    }

    /**
     * @param array<string,string> $subj
     */
    private static function opensslSubj(array $subj, string $cn): string
    {
        $parts = [];
        $email = isset($subj['emailAddress']) ? trim((string) $subj['emailAddress']) : '';
        if ($email !== '' && strtoupper($email) !== 'N/A') {
            $parts[] = 'emailAddress=' . self::opensslEscape($email);
        }
        $parts[] = 'CN=' . self::opensslEscape($cn);
        foreach ([
            'countryName' => 'C',
            'stateOrProvinceName' => 'ST',
            'localityName' => 'L',
            'organizationName' => 'O',
            'organizationalUnitName' => 'OU',
        ] as $key => $short) {
            $v = isset($subj[$key]) ? trim((string) $subj[$key]) : '';
            if ($v === '' || strtoupper($v) === 'N/A') {
                continue;
            }
            if ($key === 'countryName' && str_contains($v, ':')) {
                $v = explode(':', $v, 2)[0];
            }
            $parts[] = $short . '=' . self::opensslEscape($v);
        }

        return '/' . implode('/', $parts);
    }

    private static function opensslEscape(string $v): string
    {
        return str_replace(['\\', '/'], ['\\\\', '\\/'], $v);
    }

    private static function readFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new KloxoSslException('missing certificate file: ' . $path);
        }
        $body = (string) file_get_contents($path);
        if (trim($body) === '') {
            throw new KloxoSslException('empty certificate file: ' . $path);
        }

        return $body;
    }

    private static function tailOut(string $out, int $lines): string
    {
        $parts = preg_split('/\R/', trim($out)) ?: [];
        $parts = array_values(array_filter($parts, static fn ($l) => trim((string) $l) !== ''));
        $slice = array_slice($parts, -$lines);
        $s = trim(implode(' | ', $slice));
        $s = preg_replace('/[^\x20-\x7e]/', '', $s) ?? '';

        return substr($s, 0, 400);
    }
}
