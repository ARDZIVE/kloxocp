<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!class_exists('lxDriverClass', false)) {
    class lxDriverClass
    {
    }
}
require $root . '/kloxo/httpdocs/driver/pserver/phpmodule__linuxlib.php';

$fail = 0;
$ok = static function (string $d) { printf("  ok   %s\n", $d); };
$bad = static function (string $d) use (&$fail) { $fail++; printf("  FAIL %s\n", $d); };

$list = PhpModule__linux::getListDetail('localhost');
if (is_array($list)) {
    $ok('getListDetail returns a list');
} else {
    $bad('getListDetail returns a list');
}

$on = PhpModule__linux::legacyIniRow('/opt/php53m/etc/php.d/imap.ini', 'localhost', 'multiple-php');
$off = PhpModule__linux::legacyIniRow('/opt/php53m/etc/php.d/imagick.nonini', 'localhost', 'multiple-php');
$branch = PhpModule__linux::legacyIniRow('/etc/php.d/simplexml.ini', 'localhost', 'php-branch', 'php');
if (
    is_array($on) && $on['status'] === 'on' && $on['modulename'] === 'imap' && $on['target'] === 'php53m'
    && is_array($off) && $off['status'] === 'off' && $off['modulename'] === 'imagick'
    && is_array($branch) && $branch['status'] === 'on' && $branch['target'] === 'php'
    && $branch['nname'] === 'php-simplexml___localhost'
) {
    $ok('legacyIniRow status and names');
} else {
    $bad('legacyIniRow status and names');
}

echo $fail === 0 ? "phpmodule tests ok\n" : "phpmodule tests failed\n";
exit($fail === 0 ? 0 : 1);
