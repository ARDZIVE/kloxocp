<?php
/**
 * Knot DNS: zone renderer, knot.conf renderer, installZone/applyConf (validate,
 * atomic install, reload, rollback) and install-knot, against FAKE kzonecheck,
 * knotc and systemctl scripts in a temp directory.
 * NOT covered: the real kzonecheck / knotc / knotd (fakes only reject text that
 * contains BADZONE / BADCONF), a real reload, a real DNS query, real systemd.
 * php-cli only.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function check(bool $cond, string $d, string $x = ''): void
{
	global $pass, $fail;
	if ($cond) {
		$pass++;
		printf("  ok   %s\n", $d);
		return;
	}
	$fail++;
	printf("  FAIL %s\n", $d);
	if ($x !== '') {
		printf("       %s\n", $x);
	}
}

$repo = dirname(__DIR__, 2);
require $repo . '/kloxo/httpdocs/lib/php/kloxo_knot.php';

$tmp = sys_get_temp_dir() . '/kloxo-knot-test-' . getmypid();
$bin = $tmp . '/bin';
@mkdir($bin, 0755, true);
@mkdir($tmp . '/etc/knot', 0755, true);
@mkdir($tmp . '/var/lib/knot/zones', 0755, true);
$log = $tmp . '/calls.log';

file_put_contents($bin . '/kzonecheck', "#!/bin/sh\necho \"kzonecheck \$*\" >> '$log'\n"
	. "for last; do :; done\ngrep -q BADZONE \"\$last\" && { echo 'bad zone' >&2; exit 1; }\nexit 0\n");
file_put_contents($bin . '/knotc', "#!/bin/sh\necho \"knotc \$*\" >> '$log'\n"
	. "if [ \"\$1\" = -c ]; then grep -q BADCONF \"\$2\" && { echo 'bad conf' >&2; exit 1; }; exit 0; fi\n"
	. "if [ \"\$1\" = reload ] && [ -e '$tmp/reload-fails' ]; then echo 'reload error' >&2; exit 1; fi\nexit 0\n");
file_put_contents($bin . '/systemctl', "#!/bin/sh\necho \"systemctl \$*\" >> '$log'\n"
	. "[ -e '$tmp/knot-active' ] && exit 0\nexit 3\n");
chmod($bin . '/kzonecheck', 0755);
chmod($bin . '/knotc', 0755);
chmod($bin . '/systemctl', 0755);
$opt = ['base' => $tmp, 'bindirs' => [$bin]];
$calls = static fn (): string => is_file($GLOBALS['log']) ? (string) file_get_contents($GLOBALS['log']) : '';

echo "== names\n";
check(KloxoKnot::owner('www', 'example.com') === 'www.example.com.', 'relative owner');
check(KloxoKnot::owner('__base__', 'example.com') === 'example.com.', '__base__ is the apex');
check(KloxoKnot::owner('', 'example.com') === 'example.com.', 'empty owner is the apex');
check(KloxoKnot::owner('example.com', 'example.com') === 'example.com.', 'domain name is the apex');
check(KloxoKnot::owner('mail.example.com', 'example.com') === 'mail.example.com.', 'owner already inside the zone');
check(KloxoKnot::owner('a b', 'example.com') === null, 'owner with a space rejected');
check(KloxoKnot::owner("x\ny", 'example.com') === null, 'owner with a newline rejected');
check(KloxoKnot::target('mail', 'example.com') === 'mail.example.com.', 'single label target is inside the zone');
check(KloxoKnot::target('mx.other.net', 'example.com') === 'mx.other.net.', 'dotted target is absolute');
check(KloxoKnot::target('mx.other.net.', 'example.com') === 'mx.other.net.', 'trailing dot kept');
check(KloxoKnot::target('bad host', 'example.com') === null, 'target with a space rejected');
check(KloxoKnot::isDomain('example.com') && !KloxoKnot::isDomain('Example.com') && !KloxoKnot::isDomain('a..b') && !KloxoKnot::isDomain('../x'), 'isDomain');
check(KloxoKnot::rname('john.doe@mail.example.com', 'example.com') === 'john\.doe.mail.example.com.', 'SOA rname escapes the local-part dot');
check(KloxoKnot::rname('', 'example.com') === 'admin.example.com.', 'SOA rname default');
check(KloxoKnot::rname('x@y"; evil', 'example.com') === 'admin.example.com.', 'SOA rname rejects junk');
check(strlen(KloxoKnot::quote(str_repeat('a', 300))) === 300 + 4 + 1, 'TXT longer than 255 bytes is split in two strings');
check(KloxoKnot::quote('a"b\\c') === '"a\\"b\\\\c"', 'TXT quotes and backslashes are escaped');

echo "== zone renderer\n";
$zone = [
	'domain' => 'example.com',
	'ttl' => 3600,
	'soa_ns' => 'ns1.example.com',
	'hostmaster' => 'admin@example.com',
	'serial' => 2026110701,
	'records' => [
		['ttype' => 'ns', 'hostname' => '__base__', 'param' => 'ns1.example.com'],
		['ttype' => 'ns', 'hostname' => '__base__', 'param' => 'ns2.example.com'],
		['ttype' => 'a', 'hostname' => 'ns1', 'param' => '192.0.2.1'],
		['ttype' => 'a', 'hostname' => '__base__', 'param' => '192.0.2.10'],
		['ttype' => 'aaaa', 'hostname' => '__base__', 'param' => '2001:DB8::10'],
		['ttype' => 'mx', 'hostname' => '__base__', 'param' => 'mail', 'priority' => '10'],
		['ttype' => 'a', 'hostname' => 'mail', 'param' => '192.0.2.11'],
		['ttype' => 'cn', 'hostname' => 'www', 'param' => '__base__'],
		['ttype' => 'cname', 'hostname' => 'ftp', 'param' => 'www'],
		['ttype' => 'txt', 'hostname' => '__base__', 'param' => 'v=spf1 a mx -all'],
		['ttype' => 'caa', 'hostname' => '__base__', 'param' => 'letsencrypt.org', 'tag' => 'issue', 'flag' => '0'],
		['ttype' => 'srv', 'hostname' => '_sip._tcp', 'param' => 'sip', 'priority' => '1', 'weight' => '5', 'port' => '5060'],
	],
];
$r = KloxoKnot::renderZone($zone);
$t = $r['text'];
check($r['warnings'] === [], 'clean zone has no warnings', implode('; ', $r['warnings']));
check(str_contains($t, "\$ORIGIN example.com.\n\$TTL 3600\n"), 'ORIGIN and TTL');
check(str_contains($t, 'example.com. IN SOA ns1.example.com. admin.example.com. ( 2026110701 3600 1800 604800 3600 )'), 'SOA', $t);
check(str_contains($t, 'example.com. IN NS ns1.example.com.') && str_contains($t, 'example.com. IN NS ns2.example.com.'), 'apex NS records');
check(str_contains($t, 'example.com. IN A 192.0.2.10'), 'apex A');
check(str_contains($t, 'example.com. IN AAAA 2001:db8::10'), 'AAAA lower-cased');
check(str_contains($t, 'example.com. IN MX 10 mail.example.com.'), 'MX with a relative target');
check(str_contains($t, 'www.example.com. IN CNAME example.com.'), 'CNAME to the apex');
check(str_contains($t, 'ftp.example.com. IN CNAME www.example.com.'), 'CNAME to a label in the zone');
check(str_contains($t, 'example.com. IN TXT "v=spf1 a mx -all"'), 'TXT');
check(str_contains($t, 'example.com. IN CAA 0 issue "letsencrypt.org"'), 'CAA');
check(str_contains($t, '_sip._tcp.example.com. IN SRV 1 5 5060 sip.example.com.'), 'SRV');
check($r['serial'] === 2026110701, 'serial as given');
check(KloxoKnot::renderZone($zone + ['min_serial' => 2026110799])['serial'] === 2026110799, 'serial raised to min_serial');

$bad = $zone;
$bad['records'] = [
	['ttype' => 'a', 'hostname' => 'www', 'param' => '999.1.1.1'],
	['ttype' => 'a', 'hostname' => 'www', 'param' => '192.0.2.1'],
	['ttype' => 'cname', 'hostname' => 'www', 'param' => 'other'],
	['ttype' => 'cname', 'hostname' => '__base__', 'param' => 'other'],
	['ttype' => 'mx', 'hostname' => '__base__', 'param' => 'a b', 'priority' => '10'],
	['ttype' => 'wks', 'hostname' => 'x', 'param' => 'y'],
	['ttype' => 'txt', 'hostname' => 'a b', 'param' => 'x'],
];
$rb = KloxoKnot::renderZone($bad);
check(!str_contains($rb['text'], '999.1.1.1'), 'invalid IPv4 skipped');
check(!str_contains($rb['text'], 'CNAME'), 'CNAME next to an A and an apex CNAME are both dropped');
check(!str_contains($rb['text'], ' MX '), 'MX with an invalid target skipped');
check(count($rb['warnings']) >= 6, 'each skipped record is reported (' . count($rb['warnings']) . ')');
check(str_contains($rb['text'], 'example.com. IN NS ns1.example.com.'), 'apex NS added when the zone has none');

$inj = KloxoKnot::renderZone(['domain' => 'example.com', 'records' => [
	['ttype' => 'txt', 'hostname' => '__base__', 'param' => "x\" IN A 6.6.6.6\n evil.example.com. IN A 6.6.6.6"],
]]);
check(!preg_match('/^\s*evil\.example\.com/m', $inj['text']), 'a TXT value cannot start a new record line');
check(!str_contains($inj['text'], "\" IN A 6.6.6.6\n"), 'TXT quote cannot be closed from the value');
try {
	KloxoKnot::renderZone(['domain' => "bad\nzone", 'records' => []]);
	check(false, 'invalid zone name throws');
} catch (KloxoKnotException) {
	check(true, 'invalid zone name throws');
}
$withObj = new stdClass();
$withObj->ttype = 'a';
$withObj->hostname = 'o';
$withObj->param = '192.0.2.77';
check(str_contains(KloxoKnot::renderZone(['domain' => 'example.com', 'records' => [$withObj]])['text'], 'o.example.com. IN A 192.0.2.77'), 'record objects are accepted');

echo "== knot.conf renderer\n";
$c = KloxoKnot::renderConf(['listen' => ['192.0.2.1', '2001:db8::1', 'not-an-ip'], 'masters' => ['b.com', 'a.com', 'Bad..'],
	'slaves' => ['s.org:198.51.100.5'], 'transfer' => ['198.51.100.7', '192.0.2.1', '127.0.0.1']]);
check(str_contains($c, 'listen: [ 192.0.2.1@53, 2001:db8::1@53 ]'), 'explicit listen addresses, junk dropped');
check(!str_contains($c, '0.0.0.0') && !str_contains($c, '::@53'), 'never the wildcard');
check(strpos($c, 'domain: a.com') < strpos($c, 'domain: b.com'), 'zones sorted');
check(!str_contains($c, 'Bad..'), 'invalid zone name dropped');
check(str_contains($c, 'id: kloxo_transfer') && str_contains($c, '198.51.100.7') && !preg_match('/kloxo_transfer\n\s+address: \[[^\]]*192\.0\.2\.1/', $c), 'transfer ACL lists peers, not ourselves');
check(str_contains($c, 'domain: s.org') && str_contains($c, 'master: master_1'), 'slave zone with its master');
check(str_contains($c, 'file: "%s.zone"') && str_contains($c, 'semantic-checks: on'), 'template');
check(str_contains(KloxoKnot::renderConf([]), 'listen: [ 127.0.0.1@53 ]'), 'default listen is loopback only');
check(!str_contains(KloxoKnot::renderConf([]), 'zone:'), 'no zones, no zone section');
try {
	KloxoKnot::renderConf(['zone_dir' => "/x\"\ny"]);
	check(false, 'bad zone dir throws');
} catch (KloxoKnotException) {
	check(true, 'bad zone dir throws');
}

echo "== installZone\n";
$zdir = $tmp . '/var/lib/knot/zones';
$res = KloxoKnot::installZone($zone, $opt);
check($res['changed'] === true && is_file($zdir . '/example.com.zone'), 'zone file installed');
check(str_contains($calls(), 'kzonecheck -o example.com '), 'kzonecheck ran with the origin');
check((fileperms($zdir . '/example.com.zone') & 0777) === 0640, 'zone file mode 0640');
check(glob($zdir . '/.*check*') === [] && glob($zdir . '/*.new.*') === [], 'no temp files left');
check(KloxoKnot::$pendingReload === true, 'reload marked pending');
file_put_contents($log, '');
$res = KloxoKnot::installZone($zone, $opt);
check($res['changed'] === false && !str_contains($calls(), 'kzonecheck'), 'unchanged zone: not rewritten, not re-checked');
$z2 = $zone;
$z2['records'][] = ['ttype' => 'a', 'hostname' => 'new', 'param' => '192.0.2.99'];
$res = KloxoKnot::installZone($z2, $opt);
$live = (string) file_get_contents($zdir . '/example.com.zone');
check($res['changed'] === true && str_contains($live, 'new.example.com. IN A 192.0.2.99'), 'changed zone is rewritten');
check(KloxoKnot::serialOf($live) > 2026110701, 'serial raised above the live one', (string) KloxoKnot::serialOf($live));
$z3 = $z2;
$z3['records'][] = ['ttype' => 'txt', 'hostname' => '__base__', 'param' => 'BADZONE'];
$before = (string) file_get_contents($zdir . '/example.com.zone');
try {
	KloxoKnot::installZone($z3, $opt);
	check(false, 'a zone that fails kzonecheck throws');
} catch (KloxoKnotException $e) {
	check(str_contains($e->getMessage(), 'failed kzonecheck'), 'a zone that fails kzonecheck throws', $e->getMessage());
}
check((string) file_get_contents($zdir . '/example.com.zone') === $before, 'failed check leaves the live zone untouched');
check(glob($zdir . '/.*check*') === [], 'failed check leaves no temp file');
try {
	KloxoKnot::installZone($zone, ['base' => $tmp, 'bindirs' => [$tmp . '/nobin']]);
	check(false, 'missing kzonecheck throws');
} catch (KloxoKnotException $e) {
	check(true, 'missing kzonecheck throws (' . $e->getMessage() . ')');
}
try {
	KloxoKnot::installZone(['domain' => '../etc/passwd'], $opt);
	check(false, 'path-like zone name rejected');
} catch (KloxoKnotException) {
	check(true, 'path-like zone name rejected');
}

echo "== applyConf\n";
$conf = $tmp . '/etc/knot/knot.conf';
file_put_contents($zdir . '/gone.org.zone', "; stale\n");
@touch($tmp . '/knot-active');
file_put_contents($log, '');
$o = ['listen' => ['192.0.2.1'], 'masters' => ['example.com', 'nofile.net'], 'slaves' => ['s.org:198.51.100.5'], 'transfer' => ['198.51.100.7']];
$r = KloxoKnot::applyConf($o, $opt);
$cf = (string) file_get_contents($conf);
check($r['changed'] && $r['reloaded'], 'conf installed and reloaded while knot runs');
check($r['masters'] === ['example.com'], 'only zones with a file are served');
check(str_contains($cf, 'domain: example.com') && !str_contains($cf, 'nofile.net'), 'zone without a file is not in knot.conf');
check(str_contains($calls(), 'conf-check') && str_contains($calls(), 'knotc reload'), 'conf-check then reload');
check(!is_file($zdir . '/gone.org.zone') && is_file($zdir . '/example.com.zone'), 'zone file of a deleted domain removed');
check(glob($tmp . '/etc/knot/*.check.*') === [], 'no conf temp file left');
check(KloxoKnot::$pendingReload === false, 'pending reload cleared');
file_put_contents($log, '');
$r = KloxoKnot::applyConf($o, $opt);
check(!$r['changed'] && !$r['reloaded'] && !str_contains($calls(), 'reload'), 'same input: no rewrite, no reload');

$o2 = $o;
$o2['transfer'] = ['198.51.100.8'];
file_put_contents($tmp . '/reload-fails', '');
$goodConf = (string) file_get_contents($conf);
try {
	KloxoKnot::applyConf($o2, $opt);
	check(false, 'failed reload throws');
} catch (KloxoKnotException $e) {
	check(str_contains($e->getMessage(), 'previous knot.conf restored'), 'failed reload throws', $e->getMessage());
}
check((string) file_get_contents($conf) === $goodConf, 'failed reload puts the previous knot.conf back');
unlink($tmp . '/reload-fails');

$o3 = $o;
$o3['masters'] = ['example.com'];
$o3['listen'] = ['BADCONF'];
KloxoKnot::applyConf($o3, $opt);
check(!str_contains((string) file_get_contents($conf), 'BADCONF'), 'an invalid listen address is dropped by the renderer');
file_put_contents($bin . '/knotc', "#!/bin/sh\necho \"knotc \$*\" >> '$log'\n"
	. "if [ \"\$1\" = -c ]; then echo 'bad conf' >&2; exit 1; fi\nexit 0\n");
$before = (string) file_get_contents($conf);
try {
	KloxoKnot::applyConf(['listen' => ['192.0.2.2'], 'masters' => ['example.com']], $opt);
	check(false, 'conf that fails conf-check throws');
} catch (KloxoKnotException $e) {
	check(str_contains($e->getMessage(), 'failed conf-check'), 'conf that fails conf-check throws', $e->getMessage());
}
check((string) file_get_contents($conf) === $before, 'failed conf-check leaves the live knot.conf untouched');
check(glob($tmp . '/etc/knot/*.check.*') === [], 'failed conf-check leaves no temp file');

unlink($tmp . '/knot-active');
file_put_contents($bin . '/knotc', "#!/bin/sh\necho \"knotc \$*\" >> '$log'\nexit 0\n");
file_put_contents($log, '');
$r = KloxoKnot::applyConf(['listen' => ['192.0.2.3'], 'masters' => ['example.com']], $opt);
check($r['changed'] && !$r['reloaded'] && !str_contains($calls(), 'reload'), 'knot stopped: conf written, no reload attempted');
check(!str_contains($calls(), 'start') && !str_contains($calls(), 'enable'), 'applyConf never starts or enables the unit');

echo "== install-knot\n";
$inst = $repo . '/kloxo/bin/install/install-knot';
$run = static function (array $args) use ($inst): array {
	$p = proc_open(array_merge([PHP_BINARY, $inst], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	$out = stream_get_contents($pipes[1]);
	$err = stream_get_contents($pipes[2]);

	return [proc_close($p), (string) $out, (string) $err];
};
[$rc, $out] = $run(['--help']);
check($rc === 0 && str_contains($out, 'install-knot'), '--help');
[$rc, $out] = $run(['--dry-run']);
check($rc === 0 && str_contains($out, 'knot stays disabled'), '--dry-run');
[$rc, , $err] = $run(['--root=' . $repo . '/kloxo/../kloxo']);
check($rc === 1 && str_contains($err, 'without ..'), '--root with .. rejected');
[$rc, , $err] = $run(['--base=relative']);
check($rc === 1, '--base must be absolute');
[$rc, , $err] = $run(['--bogus']);
check($rc === 1, 'unknown argument rejected');
$tmp2 = $tmp . '/inst';
@mkdir($tmp2 . '/var/lib/knot/zones', 0755, true);
file_put_contents($tmp2 . '/var/lib/knot/zones/keep.net.zone', "; keep\n");
@mkdir($tmp2 . '/etc/knot', 0755, true);
[$rc, $out, $err] = $run(['--base=' . $tmp2, '--bindirs=' . $bin, '--listen=192.0.2.9,127.0.0.1']);
$cf2 = is_file($tmp2 . '/etc/knot/knot.conf') ? (string) file_get_contents($tmp2 . '/etc/knot/knot.conf') : '';
check($rc === 0 && str_contains($cf2, 'listen: [ 192.0.2.9@53, 127.0.0.1@53 ]'), 'install-knot writes knot.conf with explicit listen', $err . $out);
check(str_contains($cf2, 'domain: keep.net') && is_file($tmp2 . '/var/lib/knot/zones/keep.net.zone'), 'install-knot keeps and lists existing zone files');
[$rc, , $err] = $run(['--base=' . $tmp2, '--bindirs=' . $tmp . '/nobin']);
check($rc === 1 && str_contains($err, 'apt-get install knot') && str_contains($err, 'knot-dnssecutils'), 'install-knot says what to install when knot is missing', $err);

echo "== source invariants\n";
$src = (string) file_get_contents($repo . '/kloxo/httpdocs/lib/php/kloxo_knot.php');
check(str_contains($src, 'declare(strict_types=1)'), 'strict_types');
check(!preg_match('/\b(shell_exec|exec|system|passthru|popen|eval)\s*\(/', $src), 'no shell string execution in kloxo_knot.php');
$drv = (string) file_get_contents($repo . '/kloxo/httpdocs/driver/dns/dns__knotlib.php');
check(KloxoKnot::PACKAGES === ['knot', 'knot-dnsutils', 'knot-dnssecutils'], 'Knot packages include knot-dnssecutils (kzonecheck)');
check(str_contains($drv, 'class dns__knot extends dns__') && str_contains($drv, 'KloxoKnot::installZone') && str_contains($drv, 'KloxoKnot::applyConf'), 'dns__knot driver uses KloxoKnot');
check(str_contains($drv, 'KloxoApt::install') && str_contains($drv, 'KloxoKnot::PACKAGES'), 'dns__knot installMe apt-installs Knot packages when binaries are missing');
check(str_contains((string) file_get_contents($repo . '/kloxo/httpdocs/lib/html/driver_definecore.php'), "dns__knot'] =  \"driver/dns/dns__knotlib.php\""), 'dns__knot registered');
check(trim((string) file_get_contents($repo . '/kloxo/etc/list/set.dns.lst')) === 'bind,knot', 'Switch Program DNS list is bind,knot');
check(!is_file($repo . '/kloxo/httpdocs/driver/dns/dns__pdnslib.php') && !is_dir($repo . '/kloxo/file/pdns') && !is_file($repo . '/kloxo/bin/install/install-pdns'), 'PowerDNS driver, templates and installer are gone');

// cleanup
$rm = static function (string $d) use (&$rm): void {
	foreach (glob($d . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
		if (in_array(basename($f), ['.', '..'], true)) {
			continue;
		}
		is_dir($f) && !is_link($f) ? $rm($f) : @unlink($f);
	}
	@rmdir($d);
};
$rm($tmp);

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
