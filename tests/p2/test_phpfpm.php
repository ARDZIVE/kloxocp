<?php
declare(strict_types=1);

/**
 * Ubuntu per-domain PHP-FPM pools and versioned sockets. php-cli only; fake
 * php-fpm/systemctl/nginx. Does not touch a live host.
 */

$root = dirname(__DIR__, 2);
require $root . '/kloxo/httpdocs/lib/php/kloxo_phpver.php';
require $root . '/kloxo/httpdocs/lib/php/kloxo_phpfpm.php';

$fail = 0;
$ok = static function (string $d): void { printf("  ok   %s\n", $d); };
$bad = static function (string $d) use (&$fail): void { $fail++; printf("  FAIL %s\n", $d); };

$base = sys_get_temp_dir() . '/kloxo-phpfpm-' . getmypid();
@mkdir($base, 0755, true);
KloxoPhpfpm::$fsPrefix = $base;
$calls = [];
KloxoPhpfpm::$runCmd = static function (array $argv) use (&$calls): array {
    $calls[] = $argv;
    $bin = (string) ($argv[0] ?? '');
    if (str_contains($bin, 'php-fpm') && in_array('-t', $argv, true)) {
        return ['code' => 0, 'out' => 'test is successful'];
    }
    if (str_contains($bin, 'nginx') || str_contains($bin, 'apache')) {
        return ['code' => 0, 'out' => 'syntax ok'];
    }
    if (str_contains($bin, 'systemctl') || $argv[0] === 'systemctl') {
        return ['code' => 0, 'out' => ''];
    }

    return ['code' => 0, 'out' => ''];
};

$sock = KloxoPhpfpm::socketPath('php8.3', 'alice', 'example.com');
if ($sock === $base . '/run/php/example.com-php8.3.sock' && !str_contains($sock, 'php-fpm.sock')) {
    $ok('per-domain socket is /run/php/<domain>-phpX.Y.sock');
} else {
    $bad('per-domain socket is /run/php/<domain>-phpX.Y.sock');
}
$distro = KloxoPhpfpm::distroSocket('8.5');
if ($distro === $base . '/run/php/php8.5-fpm.sock' && !str_ends_with($distro, '/php-fpm.sock')) {
    $ok('distro socket is versioned phpX.Y-fpm.sock, not php-fpm.sock');
} else {
    $bad('distro socket is versioned phpX.Y-fpm.sock, not php-fpm.sock');
}

$text = KloxoPhpfpm::renderPool('example.com', 'alice', '8.3', [
    'phpfpm_type_flag' => 'ondemand',
    'maxchildren' => 6,
    'display_error_flag' => 'off',
]);
$poolFile = KloxoPhpfpm::poolFile('8.3', 'example.com');
if (str_contains($text, '[kloxo-example_com]')
    && str_contains($text, 'listen = ' . $sock)
    && str_contains($text, 'user = alice')
    && str_contains($text, 'listen.group = www-data')
    && str_contains($text, 'listen.mode = 0660')
    && str_contains($text, 'php_admin_value[open_basedir]')
    && str_contains($poolFile, '/etc/php/8.3/fpm/pool.d/kloxo-example.com.conf')
) {
    $ok('pool render: native pool.d, www-data socket group, open_basedir');
} else {
    $bad('pool render: native pool.d, www-data socket group, open_basedir');
}

foreach (['8.3', '8.5'] as $s) {
    $d = $base . '/etc/php/' . $s . '/fpm';
    @mkdir($d . '/pool.d', 0755, true);
    file_put_contents($d . '/php-fpm.conf', "include=" . $d . "/pool.d/*.conf\n");
}

KloxoPhpfpm::installPool('example.com', 'alice', '8.3');
KloxoPhpfpm::installPool('other.net', 'bob', '8.5');
if (is_file($base . '/etc/php/8.3/fpm/pool.d/kloxo-example.com.conf')
    && is_file($base . '/etc/php/8.5/fpm/pool.d/kloxo-other.net.conf')
) {
    $ok('installPool writes one file per domain under the version pool.d');
} else {
    $bad('installPool writes one file per domain under the version pool.d');
}

$calls = [];
$touched = KloxoPhpfpm::switchDomain('example.com', 'alice', '8.5');
$oldGone = !is_file($base . '/etc/php/8.3/fpm/pool.d/kloxo-example.com.conf');
$newHere = is_file($base . '/etc/php/8.5/fpm/pool.d/kloxo-example.com.conf');
$otherKept = is_file($base . '/etc/php/8.5/fpm/pool.d/kloxo-other.net.conf');
$reloaded = false;
foreach ($calls as $a) {
    $line = implode(' ', $a);
    if (str_contains($line, 'reload') && (str_contains($line, 'php8.3-fpm') || str_contains($line, 'php8.5-fpm'))) {
        $reloaded = true;
    }
}
if ($oldGone && $newHere && $otherKept && in_array('8.3', $touched, true) && in_array('8.5', $touched, true) && $reloaded) {
    $ok('switchDomain moves the pool, keeps the other domain, reloads both FPM');
} else {
    $bad('switchDomain moves the pool, keeps the other domain, reloads both FPM');
}

$prev = (string) file_get_contents($base . '/etc/php/8.5/fpm/pool.d/kloxo-example.com.conf');
KloxoPhpfpm::installPool('example.com', 'alice', '8.5', ['maxchildren' => 8]);
$baks = glob($base . '/etc/php/8.5/fpm/pool.d/kloxo-example.com.conf.bak.*') ?: [];
if ($baks !== [] && is_file($baks[0]) && (string) file_get_contents($baks[0]) === $prev) {
    $ok('existing pool is backed up before overwrite');
} else {
    $bad('existing pool is backed up before overwrite');
}

KloxoPhpfpm::$runCmd = static function (array $argv): array {
    if (in_array('-t', $argv, true)) {
        return ['code' => 1, 'out' => 'ERROR: syntax'];
    }

    return ['code' => 0, 'out' => ''];
};
$beforeFail = (string) file_get_contents($base . '/etc/php/8.5/fpm/pool.d/kloxo-example.com.conf');
$threw = false;
try {
    KloxoPhpfpm::installPool('example.com', 'alice', '8.5', ['maxchildren' => 99]);
} catch (KloxoPhpfpmException $e) {
    $threw = str_contains($e->getMessage(), 'php-fpm8.5 -t failed');
}
$afterFail = (string) file_get_contents($base . '/etc/php/8.5/fpm/pool.d/kloxo-example.com.conf');
if ($threw && $afterFail === $beforeFail && !str_contains($afterFail, 'pm.max_children = 99')) {
    $ok('failed php-fpm -t restores the previous pool');
} else {
    $bad('failed php-fpm -t restores the previous pool');
}

if (KloxoPhpfpm::rewriteCronPhp('php /home/alice/job.php', '/usr/bin/php8.3') === '/usr/bin/php8.3 /home/alice/job.php'
    && KloxoPhpfpm::rewriteCronPhp('/usr/bin/php /home/alice/job.php', '/usr/bin/php8.5') === '/usr/bin/php8.5 /home/alice/job.php'
    && KloxoPhpfpm::rewriteCronPhp('/usr/bin/php8.4 /home/alice/job.php', '/usr/bin/php8.3') === '/usr/bin/php8.4 /home/alice/job.php'
) {
    $ok('cron php is pinned to /usr/bin/phpX.Y and already-versioned binaries stay');
} else {
    $bad('cron php is pinned to /usr/bin/phpX.Y and already-versioned binaries stay');
}

$wrap = KloxoPhpfpm::cronWrapper('example.com', '/usr/bin/php8.5');
$wrapBody = is_file($wrap) ? (string) file_get_contents($wrap) : '';
if ($wrap === $base . '/usr/local/bin/php-example.com' && str_contains($wrapBody, '/usr/bin/php8.5')) {
    $ok('per-domain CLI wrapper is /usr/local/bin/php-<domain>');
} else {
    $bad('per-domain CLI wrapper is /usr/local/bin/php-<domain>');
}

$alts = [];
KloxoPhpver::$alternativesRun = static function (array $args) use (&$alts): int {
    $alts[] = $args;

    return 0;
};
if (KloxoPhpver::setDefaultCli('php8.3') && ($alts[0] ?? []) === ['--set', 'php', '/usr/bin/php8.3']) {
    $ok('setDefaultCli uses update-alternatives --set php /usr/bin/phpX.Y');
} else {
    $bad('setDefaultCli uses update-alternatives --set php /usr/bin/phpX.Y');
}
KloxoPhpver::$alternativesRun = null;

$ng = file_get_contents($root . '/kloxo/file/nginx/conf/globals/php-fpm_standard.conf')
    . file_get_contents($root . '/kloxo/file/nginx/conf/globals/php-fpm_standard_ssl.conf')
    . file_get_contents($root . '/kloxo/file/nginx/conf/globals/php-fpm_wildcards.conf')
    . file_get_contents($root . '/kloxo/file/nginx/conf/globals/php-fpm_wildcards_ssl.conf')
    . file_get_contents($root . '/kloxo/file/nginx/conf/globals/generic.conf');
$ap = (string) file_get_contents($root . '/kloxo/file/apache/tpl/domains.conf.tpl');
if (str_contains($ng, 'unix:$var_phpsock') && !str_contains($ng, '/opt/configs/php-fpm/sock/')
    && !str_contains($ng, '/run/php/php-fpm.sock')
    && str_contains($ap, 'kloxo_apache_sock') && str_contains($ap, '$domain')
) {
    $ok('nginx and apache templates use the versioned per-domain socket');
} else {
    $bad('nginx and apache templates use the versioned per-domain socket');
}

$web = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/web__lib.php');
if (str_contains($web, 'case "webfeatures":') && str_contains($web, 'syncUbuntuPhpPool')
    && str_contains($web, "array('KloxoPhpfpm', 'applyDomain')") && str_contains($web, 'rl_exec_get')
    && str_contains($web, 'KloxoPhpfpm::removeDomain')
    && str_contains($web, 'posix_geteuid') && str_contains($web, 'new lxException')
    && str_contains($web, 'validateUbuntuWebConfig')
) {
    $ok('domain PHP change regenerates the pool; delete removes it');
} else {
    $bad('domain PHP change regenerates the pool; delete removes it');
}

$got = KloxoPhpfpm::applyDomain('noroot.example', 'alice', '8.3', 'php8.3');
$euid = function_exists('posix_geteuid') ? (int) posix_geteuid() : 1;
$wrote = is_file($base . '/etc/php/8.3/fpm/pool.d/kloxo-noroot.example.conf');
if ($euid !== 0 && is_array($got) && empty($got['ok']) && ($got['error'] ?? '') === 'not root' && !$wrote) {
    $ok('applyDomain refuses to write pools when not root');
} elseif ($euid === 0 && is_array($got) && !empty($got['ok'])) {
    $ok('applyDomain refuses to write pools when not root');
} else {
    $bad('applyDomain refuses to write pools when not root');
}

$inc = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/include.php');
$def = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/definecore.php');
if (str_contains($inc, 'kloxo_phpfpm.php') && str_contains($inc, 'kloxo_phpver.php')
    && str_contains($def, "gl_class_array['kloxophpfpm']")
) {
    $ok('backend autoloads KloxoPhpfpm for applyDomain');
} else {
    $bad('backend autoloads KloxoPhpfpm for applyDomain');
}

@mkdir($base . '/usr/sbin', 0755, true);
foreach (['nginx', 'apache2ctl'] as $b) {
    $p = $base . '/usr/sbin/' . $b;
    file_put_contents($p, "#!/bin/sh\n");
    @chmod($p, 0755);
}
$calls = [];
KloxoPhpfpm::$runCmd = static function (array $argv) use (&$calls): array {
    $calls[] = $argv;

    return ['code' => 0, 'out' => 'syntax ok'];
};
KloxoPhpfpm::validateWeb('nginx');
$ng = [];
$ap = [];
foreach ($calls as $a) {
    $bin = (string) ($a[0] ?? '');
    if (str_contains($bin, 'nginx')) {
        $ng[] = $bin;
    }
    if (str_contains($bin, 'apache')) {
        $ap[] = $bin;
    }
}
$calls = [];
KloxoPhpfpm::validateWeb('apache');
foreach ($calls as $a) {
    $bin = (string) ($a[0] ?? '');
    if (str_contains($bin, 'nginx')) {
        $ng[] = 'also-nginx';
    }
    if (str_contains($bin, 'apache')) {
        $ap[] = $bin;
    }
}
if ($ng === [$base . '/usr/sbin/nginx'] && $ap !== [] && !in_array('also-nginx', $ng, true)) {
    $ok('validateWeb tests only the selected web server');
} else {
    $bad('validateWeb tests only the selected web server');
}

$remote = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/remotelib.php');
if (str_contains($remote, 'function kloxo_rethrow_local_action')
    && str_contains($remote, 'kloxo_rethrow_local_action($e, $machine)')
    && str_contains($remote, 'kloxo_rethrow_local_action($le, $machine)')
    && str_contains($remote, 'catch (Throwable $e)')
) {
    $ok('local-action failures keep the real error instead of could_not_connect');
} else {
    $bad('local-action failures keep the real error instead of could_not_connect');
}

$sel = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/weblib.php');
if (str_contains($sel, 'KloxoPhpver::fpmIds()')) {
    $ok('PHP Selected lists only versions with php-fpm');
} else {
    $bad('PHP Selected lists only versions with php-fpm');
}
if (preg_match('/function postUpdate\s*\(\s*\)\s*\{(.*?)function /s', $sel, $pm) === 1
    && !preg_match('/\bexec\s*\([^)]*fixweb/', $pm[1])
    && str_contains($pm[1], 'createRestartFile')
) {
    $ok('Web Features Update does not block the page on /script/fixweb');
} else {
    $bad('Web Features Update does not block the page on /script/fixweb');
}

$fpmSrc = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/kloxo_phpfpm.php');
if (str_contains($fpmSrc, "'--no-block'")) {
    $ok('FPM systemctl uses --no-block so a stuck unit cannot blank the panel');
} else {
    $bad('FPM systemctl uses --no-block so a stuck unit cannot blank the panel');
}

KloxoPhpfpm::$fsPrefix = '';
KloxoPhpfpm::$runCmd = null;
foreach (glob($base . '/{etc,run,usr,var}/*', GLOB_BRACE) ?: [] as $_) {
}
$it = new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS);
$ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
foreach ($ri as $f) {
    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
}
@rmdir($base);

echo $fail === 0 ? "phpfpm tests ok\n" : "phpfpm tests failed\n";
exit($fail === 0 ? 0 : 1);
