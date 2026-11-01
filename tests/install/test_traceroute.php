<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/kloxo/httpdocs/lib/php/kloxo_traceroute.php';

$fail = 0;
$ok = static function (string $d) { printf("  ok   %s\n", $d); };
$bad = static function (string $d) use (&$fail) { $fail++; printf("  FAIL %s\n", $d); };

if (KloxoTraceroute::safeHost('93.26.66.26') === '93.26.66.26'
    && KloxoTraceroute::safeHost('2001:db8::1') === '2001:db8::1'
    && KloxoTraceroute::safeHost('google.com') === 'google.com') {
    $ok('safeHost accepts IP and hostname');
} else {
    $bad('safeHost accepts IP and hostname');
}

if (KloxoTraceroute::safeHost('1.2.3.4; id') === ''
    && KloxoTraceroute::safeHost('8.8.8.8 | wget') === ''
    && KloxoTraceroute::safeHost('host$(id).com') === ''
    && KloxoTraceroute::safeHost('') === '') {
    $ok('safeHost rejects shell metacharacters');
} else {
    $bad('safeHost rejects shell metacharacters');
}

$lines = KloxoTraceroute::run('not a host; rm -rf /');
if ($lines === []) {
    $ok('run rejects unsafe host');
} else {
    $bad('run rejects unsafe host');
}

$src = (string)file_get_contents($root . '/kloxo/httpdocs/lib/html/traceroute.php');
if (strpos($src, 'throw new lxException') === false
    && !preg_match('/\bexec\s*\(/', $src)
    && strpos($src, 'KloxoTraceroute::run') !== false) {
    $ok('traceroute list does not throw or exec a shell string');
} else {
    $bad('traceroute list does not throw or exec a shell string');
}

echo $fail === 0 ? "traceroute tests ok\n" : "traceroute tests failed\n";
exit($fail === 0 ? 0 : 1);
