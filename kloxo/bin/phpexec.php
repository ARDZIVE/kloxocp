<?php
/**
 * Drop privileges then run argv as that user (security CC3).
 * Never rebuild a shell string from arguments.
 *
 * Usage: phpexec.php <uid> <gid> <cmd> [args...]
 */

declare(strict_types=1);

if ($argc < 4) {
	fwrite(STDERR, "usage: phpexec.php <uid> <gid> <cmd> [args...]\n");
	exit(127);
}

$uid = filter_var($argv[1], FILTER_VALIDATE_INT);
$gid = filter_var($argv[2], FILTER_VALIDATE_INT);
if ($uid === false || $gid === false || $uid < 0 || $gid < 0) {
	fwrite(STDERR, "invalid uid/gid\n");
	exit(127);
}

$cmdArgv = array_slice($argv, 3);
foreach ($cmdArgv as $a) {
	if (!is_string($a) || str_contains($a, "\0")) {
		fwrite(STDERR, "invalid argument\n");
		exit(127);
	}
}

if (!@posix_setgid((int) $gid) || !@posix_setuid((int) $uid)) {
	fwrite(STDERR, "setuid/setgid failed\n");
	exit(127);
}

$desc = array(
	0 => array('file', '/dev/null', 'r'),
	1 => array('pipe', 'w'),
	2 => array('pipe', 'w'),
);
$p = @proc_open($cmdArgv, $desc, $pipes);
if (!is_resource($p)) {
	fwrite(STDERR, "proc_open failed\n");
	exit(127);
}
$out = stream_get_contents($pipes[1]);
$err = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$ret = proc_close($p);
if (is_string($out) && $out !== '') {
	echo $out;
}
if (is_string($err) && $err !== '') {
	fwrite(STDERR, $err);
}

exit($ret);
