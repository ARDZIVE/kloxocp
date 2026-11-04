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

if (!class_exists('lxclass', false)) {
    class lxclass
    {
        public function __construct($a = null, $b = null, $c = null) {}
        public function write() {}
        public function get() {}
        public function display($var)
        {
            return isset($this->$var) ? $this->$var : null;
        }
    }
}
if (!function_exists('kcount')) {
    function kcount($a)
    {
        return is_array($a) ? count($a) : 0;
    }
}
require $root . '/kloxo/httpdocs/lib/html/traceroute.php';

if (traceroute::formatHopTime(0.456) === '0.456'
    && traceroute::formatHopTime(12.3) === '12.300'
    && traceroute::formatHopTime(-1) === '*'
    && traceroute::formatHopTime(null) === '*') {
    $ok('formatHopTime uses 3 decimals or *');
} else {
    $bad('formatHopTime uses 3 decimals or *');
}

if (traceroute::formatHopIp('*') === '*'
    && traceroute::formatHopIp('no') === '*'
    && traceroute::formatHopIp('no reply') === '*'
    && traceroute::formatHopIp('1.2.3.4') === '1.2.3.4') {
    $ok('formatHopIp maps unreachable to *');
} else {
    $bad('formatHopIp maps unreachable to *');
}

$o = new traceroute(null, null, '__name__');
$tracepathHops = $o->Net_Traceroute_Result([
    ' 1?: [LOCALHOST]                      pmtu 1500',
    ' 1:  51.91.73.252                      0.456ms',
    ' 2:  no reply',
    ' 3:  1.1.1.1                           12.345ms reached',
], 'linux');
$tpOk = is_array($tracepathHops)
    && isset($tracepathHops[1]['ip'], $tracepathHops[1]['responsetimes'])
    && $tracepathHops[1]['ip'] === '51.91.73.252'
    && abs((float) $tracepathHops[1]['responsetimes'] - 0.456) < 0.0001
    && $tracepathHops[2]['ip'] === '*'
    && (int) $tracepathHops[2]['responsetimes'] === -1
    && $tracepathHops[3]['ip'] === '1.1.1.1';
if ($tpOk) {
    $ok('parses tracepath no-reply and ms times');
} else {
    $bad('parses tracepath no-reply and ms times');
}

$o2 = new traceroute(null, null, '__name__');
$trHops = $o2->Net_Traceroute_Result([
    'traceroute to 1.1.1.1 (1.1.1.1), 15 hops max, 60 byte packets',
    ' 1  51.91.73.252  0.456 ms',
    ' 2  *',
    ' 3  10.0.0.1  12.345 ms',
], 'linux');
$trOk = is_array($trHops)
    && $trHops[1]['ip'] === '51.91.73.252'
    && abs((float) $trHops[1]['responsetimes'] - 0.456) < 0.0001
    && $trHops[2]['ip'] === '*'
    && (int) $trHops[2]['responsetimes'] === -1;
if ($trOk) {
    $ok('parses classic traceroute hops');
} else {
    $bad('parses classic traceroute hops');
}

$disp = new traceroute(null, null, '1');
$disp->ip = '51.91.73.252';
$disp->responsetimes = 0.456;
$disp2 = new traceroute(null, null, '2');
$disp2->ip = 'no';
$disp2->responsetimes = null;
if ($disp->display('responsetimes') === '0.456'
    && $disp2->display('ip') === '*'
    && $disp2->display('responsetimes') === '*') {
    $ok('display shows 3-decimal time and * for no');
} else {
    $bad('display shows 3-decimal time and * for no');
}

if (strpos($src, 'formatHopTime') !== false && strpos($src, 'number_format') !== false) {
    $ok('traceroute display formats response times');
} else {
    $bad('traceroute display formats response times');
}

echo $fail === 0 ? "traceroute tests ok\n" : "traceroute tests failed\n";
exit($fail === 0 ? 0 : 1);
