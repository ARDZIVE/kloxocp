<?php
include_once "lib/html/include.php";

$list = parse_opt($argv);

$nologtext = isset($list['nolog']) ? '--nolog' : '';
$typetext = lxfile_exists($sgbl->__path_slave_db) ? '--type=slave' : '--type=master';
$withoutservices = isset($list['without-services']) ? '--without-services' : '';

// argv only — never shell-string spawn with interpolated flags (security DD6).
$php = is_executable('/usr/bin/lxphp.exe') ? '/usr/bin/lxphp.exe'
	: (isset($sgbl->__path_php_path) ? (string) $sgbl->__path_php_path : 'php');
$script = '../bin/common/tmpupdatecleanup.php';
$cmd = array($php, $script, $typetext);
if ($withoutservices !== '') {
	$cmd[] = $withoutservices;
}
if ($nologtext !== '') {
	$cmd[] = $nologtext;
}
$desc = array(
	0 => array('file', '/dev/null', 'r'),
	1 => STDOUT,
	2 => STDERR,
);
$p = @proc_open($cmd, $desc, $pipes);
if (is_resource($p)) {
	exit(proc_close($p));
}
exit(127);
