<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/kloxo/httpdocs/lib/php/kloxo_ipaddr.php';

$fail = 0;
$ok = static function (string $d) { printf("  ok   %s\n", $d); };
$bad = static function (string $d, string $why = '') use (&$fail) {
    $fail++;
    printf("  FAIL %s%s\n", $d, $why !== '' ? " — $why" : '');
};

if (KloxoIpaddr::prefixToMask(24) === '255.255.255.0') {
    $ok('prefix /24');
} else {
    $bad('prefix /24', KloxoIpaddr::prefixToMask(24));
}
if (KloxoIpaddr::prefixToMask(32) === '255.255.255.255') {
    $ok('prefix /32');
} else {
    $bad('prefix /32');
}
if (KloxoIpaddr::prefixToMask(8) === '255.0.0.0') {
    $ok('prefix /8');
} else {
    $bad('prefix /8');
}

$addr = "2: ens160    inet 37.59.173.71/24 brd 37.59.173.255 scope global ens160\n"
    . "1: lo    inet 127.0.0.1/8 scope host lo\n";
$route = "default via 37.59.173.254 dev ens160\n";
$rows = KloxoIpaddr::parseAddrShow($addr, $route);
if (count($rows) === 1 && $rows[0]['ipaddr'] === '37.59.173.71'
    && $rows[0]['devname'] === 'ens160'
    && $rows[0]['netmask'] === '255.255.255.0'
    && $rows[0]['gateway'] === '37.59.173.254') {
    $ok('parse one Ubuntu iface, skip lo');
} else {
    $bad('parse one Ubuntu iface, skip lo', var_export($rows, true));
}

if (KloxoIpaddr::parseAddrShow('') === []) {
    $ok('empty ip output');
} else {
    $bad('empty ip output');
}

$os = KloxoIpaddr::parseOsRelease("ID=ubuntu\nPRETTY_NAME=\"Ubuntu 26.04 LTS\"\n");
if ($os['ostype'] === 'debian' && $os['osversion'] === 'Ubuntu 26.04 LTS') {
    $ok('os-release ubuntu');
} else {
    $bad('os-release ubuntu', var_export($os, true));
}
$el = KloxoIpaddr::parseOsRelease("ID=almalinux\nPRETTY_NAME=\"AlmaLinux 9.5\"\n");
if ($el['ostype'] === 'rhel' && $el['osversion'] === 'AlmaLinux 9.5') {
    $ok('os-release rhel family');
} else {
    $bad('os-release rhel family', var_export($el, true));
}

echo $fail === 0 ? "ipaddr tests ok\n" : "ipaddr tests failed\n";
exit($fail === 0 ? 0 : 1);
