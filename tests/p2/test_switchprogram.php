<?php
/**
 * Switch Program page: form lists and updateSwitchProgram flow.
 * Runs the real code in a throw-away COPY of the kloxo tree with fake apt-get
 * and systemctl first in PATH. Does not touch the system. php-cli only.
 * NOT covered: real apt installs, real systemd, web driver switch, panel DB.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function ok(string $d): void { global $pass; $pass++; printf("  ok   %s\n", $d); }
function bad(string $d, string $x = ''): void
{
	global $fail;
	$fail++;
	printf("  FAIL %s\n", $d);
	if ($x !== '') {
		printf("       %s\n", $x);
	}
}

$repo = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/kloxo-switchprogram-' . getmypid();
@mkdir($tmp . '/bin', 0700, true);
exec('cp -R ' . escapeshellarg($repo . '/kloxo') . ' ' . escapeshellarg($tmp . '/kloxo'));
@mkdir($tmp . '/kloxo/etc/conf', 0755, true);
file_put_contents($tmp . '/kloxo/etc/conf/kloxo.pass', "testpass\n");
@mkdir($tmp . '/kloxo/etc/flag', 0755, true);
$log = $tmp . '/calls.log';
$fail_flag = $tmp . '/aptfail';
foreach (array('apt-get', 'systemctl') as $b) {
	file_put_contents($tmp . '/bin/' . $b,
		"#!/bin/sh\necho \"$b \$DEBIAN_FRONTEND \$*\" >> \"$log\"\n"
		. ($b === 'apt-get'
			? "if [ -f \"$fail_flag\" ]; then echo 'E: Unable to locate package zzz' >&2; exit 100; fi\n"
			: '')
		. "exit 0\n");
	chmod($tmp . '/bin/' . $b, 0755);
}

function child(string $tmp, string $scenario): array
{
	@unlink($tmp . '/calls.log');
	$cmd = array(PHP_BINARY, __DIR__ . '/switchprogram_child.php', $scenario);
	$env = array(
		'KLOXO_ROOT' => $tmp . '/kloxo',
		'KLOXO_APT_TEST_BIN' => $tmp . '/bin/apt-get',
		'PATH' => $tmp . '/bin:/usr/bin:/bin',
		'HOME' => $tmp,
	);
	$proc = proc_open($cmd, array(0 => array('file', '/dev/null', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes, null, $env);
	$out = (string) stream_get_contents($pipes[1]);
	$err = (string) stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($proc);
	$res = array('raw' => $out . $err);
	foreach (preg_split('/\R/', $out) ?: array() as $line) {
		$j = json_decode($line, true);
		if (is_array($j) && isset($j['scenario'])) {
			$res = $j + $res;
		}
	}
	$res['calls'] = is_file($tmp . '/calls.log') ? (string) file_get_contents($tmp . '/calls.log') : '';

	return $res;
}

// 1. form
$r = child($tmp, 'form');
$v = $r['vlist'] ?? array();
$ok = isset($v['web_driver'][1], $v['dns_driver'][1], $v['spam_driver'][1], $v['pop3_driver'][1], $v['smtp_driver'][1])
	&& $v['web_driver'][1] === array('none', 'nginx', 'apache')
	&& $v['dns_driver'][1] === array('none', 'bind', 'knot')
	&& $v['spam_driver'][1] === array('none', 'spamassassin')
	&& $v['pop3_driver'][1] === array('none', 'dovecot')
	&& $v['smtp_driver'][1] === array('none', 'exim', 'postfix')
	&& !isset($v['webcache_driver']) && !isset($v['use_pagespeed']) && isset($v['__v_updateall_button']);
$ok ? ok('form lists every program (no Cache list, no Use Pagespeed) and renders without an exception') : bad('form lists every program (no Cache list, no Use Pagespeed) and renders without an exception', $r['raw']);

// 2. every offered program has a driver class
$r = child($tmp, 'classes');
(isset($r['missing']) && $r['missing'] === array()) ? ok('every offered program has a driver class with installMe/uninstallMe')
	: bad('every offered program has a driver class with installMe/uninstallMe', json_encode($r['missing'] ?? $r['raw']));

// 3. unchanged submit: nothing installed or restarted
$r = child($tmp, 'same');
($r['error'] === '' && $r['calls'] === '') ? ok('unchanged Update installs and restarts nothing')
	: bad('unchanged Update installs and restarts nothing', ($r['error'] ?? '') . ' calls=' . $r['calls']);

// 4. smtp exim -> postfix
$r = child($tmp, 'smtp');
($r['error'] === '' && ($r['slave']['smtp'] ?? '') === 'postfix'
	&& str_contains($r['calls'], 'noninteractive -y') && str_contains($r['calls'], 'remove exim4-daemon-heavy')
	&& str_contains($r['calls'], 'install postfix')
	&& strpos($r['calls'], 'remove exim4') < strpos($r['calls'], 'install postfix'))
	? ok('smtp exim -> postfix: non-interactive remove then install, choice stored')
	: bad('smtp exim -> postfix: non-interactive remove then install, choice stored', json_encode($r));

// 5. pop3 none -> dovecot (installs packages; unchanged keys untouched)
$r = child($tmp, 'pop3');
($r['error'] === '' && ($r['slave']['pop3'] ?? '') === 'dovecot' && str_contains($r['calls'], 'install dovecot-core'))
	? ok('pop3 none -> dovecot installs the packages and stores the choice')
	: bad('pop3 none -> dovecot installs the packages and stores the choice', json_encode($r));

// 6. spam
$r = child($tmp, 'spam_spamassassin');
($r['error'] === '' && ($r['slave']['spam'] ?? '') === 'spamassassin' && str_contains($r['calls'], 'install spamassassin spamc ripmime'))
	? ok('spam none -> spamassassin') : bad('spam none -> spamassassin', json_encode($r));

// 7. dns bind -> knot
$r = child($tmp, 'dns');
($r['error'] === '' && ($r['slave']['dns'] ?? '') === 'knot' && str_contains($r['calls'], 'enable --now knot'))
	? ok('dns bind -> knot enables knot and stores the choice') : bad('dns bind -> knot enables knot and stores the choice', json_encode($r));

// 8. a failing install is reported and the old choice is kept
touch($fail_flag);
foreach (array('smtp' => 'postfix', 'spam_spamassassin' => 'spamassassin', 'pop3' => 'dovecot') as $sc => $want) {
	$cls = $sc === 'spam_spamassassin' ? 'spam' : $sc;
	$r = child($tmp, $sc);
	$old = $sc === 'pop3' ? 'none' : ($sc === 'smtp' ? 'exim' : 'none');
	(str_contains($r['error'] ?? '', 'Install failed') && ($r['slave'][$cls] ?? '') === $old)
		? ok("failed apt install for {$cls} is reported and the old choice is kept")
		: bad("failed apt install for {$cls} is reported and the old choice is kept", json_encode($r));
}
unlink($fail_flag);

// 9. web cache nginx without nginx installed: clear error, choice kept (no nginx binary on a dev host)
if (!is_executable('/usr/sbin/nginx') && !is_executable('/usr/bin/nginx') && !is_executable('/usr/local/bin/nginx')) {
	$r = child($tmp, 'cache_nginx');
	(str_contains($r['error'] ?? '', 'nginx is not installed') && ($r['slave']['webcache'] ?? '') === 'none')
		? ok('web cache nginx without nginx: clear error, choice kept')
		: bad('web cache nginx without nginx: clear error, choice kept', json_encode($r));
} else {
	ok('web cache nginx without nginx: skipped (nginx present on this host)');
}

// 10. a hostile driver name never becomes a class name
$r = child($tmp, 'bad');
(str_contains($r['error'] ?? '', 'Unknown smtp program') && ($r['slave']['smtp'] ?? '') === 'exim' && $r['calls'] === '')
	? ok('unknown program name is rejected before anything runs') : bad('unknown program name is rejected before anything runs', json_encode($r));

// 11. source invariants
$src = (string) file_get_contents($repo . '/kloxo/httpdocs/driver/pserver/pserverlib.php');
$i = strpos($src, 'function updateSwitchProgram');
$body = substr($src, (int) $i, 6000);
(str_contains($body, 'foreach ($driverClasses as $class)') && !str_contains($body, 'foreach($param as $k => $v)')
	&& !str_contains($body, 'chkconfig') && str_contains($body, '$fixc = "mail";') && !str_contains($body, '$fixc = "mmail";'))
	? ok('only driver keys reach switchDriver; no chkconfig; mail fix script name is fixmail')
	: bad('only driver keys reach switchDriver; no chkconfig; mail fix script name is fixmail');
is_file($repo . '/kloxo/pscript/fixmail') && !is_file($repo . '/kloxo/pscript/fixmmail')
	? ok('/script/fixmail exists (the script called after spam/pop3/smtp switches)')
	: bad('/script/fixmail exists (the script called after spam/pop3/smtp switches)');
$drv = '';
foreach (array('smtp__eximlib', 'smtp__postfixlib', 'pop3__dovecotlib', 'spam__spamassassinlib') as $f) {
	$drv .= (string) file_get_contents($repo . '/kloxo/httpdocs/driver/mmail/' . $f . '.php');
}
!str_contains($drv, "lxshell_return('apt-get'") ? ok('mail program drivers install only through KloxoApt (non-interactive, errors reported)')
	: bad('mail program drivers install only through KloxoApt (non-interactive, errors reported)');

exec('rm -rf ' . escapeshellarg($tmp));
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
