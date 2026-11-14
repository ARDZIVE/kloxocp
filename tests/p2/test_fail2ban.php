<?php
/**
 * KloxoFail2ban + KloxoAuthLog against a fake machine: a temp tree stands in for
 * /, with a fake fail2ban-client and systemctl. Nothing outside the temp dir is
 * touched. php-cli only.
 *
 * NOT covered: a real Fail2Ban (the filters are only checked by translating
 * <HOST> into a regex), real nftables, real journald, real systemd.
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
function check(bool $c, string $d, string $x = ''): void { $c ? ok($d) : bad($d, $x); }

$repo = dirname(__DIR__, 2);
require $repo . '/kloxo/httpdocs/lib/php/kloxo_fail2ban.php';
require $repo . '/kloxo/httpdocs/lib/php/kloxo_authlog.php';

$tmp = sys_get_temp_dir() . '/kloxo-f2b-test-' . getmypid();
$fs = $tmp . '/fs';
$root = $tmp . '/root';
foreach ([
	"$fs/usr/bin", "$fs/etc/fail2ban/jail.d", "$fs/etc/fail2ban/filter.d", "$fs/lib/systemd/system",
	"$fs/var/log/exim4", "$fs/var/log/nginx", "$fs/usr/lib/python3/dist-packages/systemd", "$fs/etc/ssh",
	"$fs/state", "$root/etc/conf", "$root/log", "$root/file/fail2ban/filter.d",
] as $d) {
	mkdir($d, 0755, true);
}
foreach (glob($repo . '/kloxo/file/fail2ban/filter.d/*.conf') ?: [] as $f) {
	copy($f, "$root/file/fail2ban/filter.d/" . basename($f));
}
foreach (['sshd', 'recidive', 'exim', 'dovecot', 'nginx-http-auth', 'nginx-botsearch', 'pure-ftpd', 'mysqld-auth', 'postfix'] as $f) {
	file_put_contents("$fs/etc/fail2ban/filter.d/$f.conf", "[Definition]\n");
}
foreach (['ssh', 'exim4', 'dovecot', 'nginx', 'pure-ftpd', 'kloxo-web', 'fail2ban', 'mariadb'] as $u) {
	file_put_contents("$fs/lib/systemd/system/$u.service", "[Unit]\n");
}
file_put_contents("$fs/usr/lib/python3/dist-packages/systemd/journal.py", "");
file_put_contents("$fs/var/log/exim4/mainlog", "");
file_put_contents("$fs/var/log/nginx/error.log", "");
file_put_contents("$fs/var/log/fail2ban.log", implode("\n", [
	'2026-11-07 05:00:01,100 fail2ban.server         [812]: INFO    Starting Fail2ban v1.0.2',
	'2026-11-07 05:10:00,101 fail2ban.filter         [812]: INFO    [sshd] Found 203.0.113.9 - 2026-11-07 05:10:00',
	'2026-11-07 05:10:05,200 fail2ban.actions        [812]: NOTICE  [sshd] Ban 203.0.113.9',
	'2026-11-07 05:20:05,300 fail2ban.actions        [812]: NOTICE  [sshd] Unban 203.0.113.9',
	'2026-11-07 05:30:00,400 fail2ban.jail           [812]: ERROR   Failed to start <script>alert(1)</script> jail "x"',
	'2026-11-07 05:31:00,400 fail2ban.actions        [812]: NOTICE  [recidive] Ban 2001:db8::5',
	'garbage line',
]) . "\n");

// fake fail2ban-client (state in $fs/state)
file_put_contents("$fs/usr/bin/fail2ban-client", <<<'PHP'
#!/usr/bin/env php
<?php
$fs = dirname(__DIR__, 2);
$sf = $fs . '/state/state.json';
$st = is_file($sf) ? json_decode((string) file_get_contents($sf), true) : ['jails' => [], 'banned' => []];
function save(string $sf, array $st): void { file_put_contents($sf, json_encode($st)); }
function jailsFromFile(string $fs): array
{
	$f = $fs . '/etc/fail2ban/jail.d/kloxo-cp.local';
	$txt = is_file($f) ? (string) file_get_contents($f) : '';
	$out = [];
	foreach (preg_split('/\n(?=\[)/', $txt) as $blk) {
		if (preg_match('/^\[([A-Za-z0-9_.-]+)\]/', $blk, $m) === 1 && $m[1] !== 'DEFAULT'
			&& preg_match('/^enabled\s*=\s*true/m', $blk) === 1) {
			$out[] = $m[1];
		}
	}
	return $out;
}
$a = array_slice($argv, 1);
$cfg = $fs . '/etc/fail2ban/jail.d/kloxo-cp.local';
$txt = is_file($cfg) ? (string) file_get_contents($cfg) : '';
if ($a === ['--version']) { echo "Fail2Ban v1.0.2\n"; exit(0); }
if ($a === ['-t']) {
	if (is_file($fs . '/state/fail-t')) { fwrite(STDERR, "ERROR  Errors in jail 'x'. Skipping..."); exit(255); }
	echo "OK: configuration test is successful\n"; exit(0);
}
if ($a === ['reload']) {
	if (is_file($fs . '/state/fail-reload') && !is_file($fs . '/state/reload-failed-once')) {
		touch($fs . '/state/reload-failed-once'); fwrite(STDERR, "reload failed"); exit(1);
	}
	$st['jails'] = jailsFromFile($fs); save($sf, $st); echo "OK\n"; exit(0);
}
if ($a === ['status']) {
	echo "Status\n|- Number of jail:\t" . count($st['jails']) . "\n`- Jail list:\t" . implode(', ', $st['jails']) . "\n"; exit(0);
}
if (count($a) === 2 && $a[0] === 'status') {
	$j = $a[1];
	if (!in_array($j, $st['jails'], true)) { fwrite(STDERR, "Sorry but the jail '$j' does not exist"); exit(255); }
	$b = $st['banned'][$j] ?? [];
	echo "Status for the jail: $j\n|- Filter\n|  |- Currently failed:\t2\n|  |- Total failed:\t11\n|  `- Journal matches:\t_COMM=sshd _COMM=sshd-session\n"
		. "`- Actions\n   |- Currently banned:\t" . count($b) . "\n   |- Total banned:\t" . (count($b) + 3) . "\n   `- Banned IP list:\t" . implode(' ', $b) . "\n";
	exit(0);
}
if (count($a) === 4 && $a[0] === 'set' && in_array($a[2], ['banip', 'unbanip'], true)) {
	$j = $a[1];
	if (!in_array($j, $st['jails'], true)) { fwrite(STDERR, "no jail"); exit(255); }
	$b = $st['banned'][$j] ?? [];
	if ($a[2] === 'banip') { $b[] = $a[3]; } else { $b = array_values(array_diff($b, [$a[3]])); }
	$st['banned'][$j] = array_values(array_unique($b)); save($sf, $st); echo "1\n"; exit(0);
}
if (count($a) === 2 && $a[0] === 'unban' && $a[1] === '--all') { $st['banned'] = []; save($sf, $st); echo "0\n"; exit(0); }
if (count($a) === 2 && $a[0] === 'unban') {
	foreach ($st['banned'] as $j => $b) { $st['banned'][$j] = array_values(array_diff($b, [$a[1]])); }
	save($sf, $st); echo "1\n"; exit(0);
}
fwrite(STDERR, "unknown command: " . implode(' ', $a)); exit(2);
PHP
);
file_put_contents("$fs/usr/bin/systemctl", <<<'PHP'
#!/usr/bin/env php
<?php
$fs = dirname(__DIR__, 2);
$a = array_slice($argv, 1);
$cfg = $fs . '/etc/fail2ban/jail.d/kloxo-cp.local';
if ($a === ['is-active', 'fail2ban']) { echo is_file($fs . '/state/active') ? "active\n" : "inactive\n"; exit(is_file($fs . '/state/active') ? 0 : 3); }
if ($a === ['enable', 'fail2ban']) { exit(0); }
if ($a === ['restart', 'fail2ban']) {
	if (is_file($fs . '/state/fail-restart')) { fwrite(STDERR, "Job failed"); exit(1); }
	touch($fs . '/state/active'); exit(0);
}
exit(1);
PHP
);
chmod("$fs/usr/bin/fail2ban-client", 0755);
chmod("$fs/usr/bin/systemctl", 0755);

putenv('KLOXO_ROOT=' . $root);
KloxoFail2ban::$fsPrefix = $fs;
$jailFile = "$fs/etc/fail2ban/jail.d/kloxo-cp.local";

function setActive(string $fs, bool $on): void
{
	$f = "$fs/state/active";
	if ($on) { touch($f); } else { @unlink($f); }
	clearstatcache(true);
}
function clearState(string $fs): void
{
	foreach (glob("$fs/state/*") ?: [] as $f) { @unlink($f); }
	clearstatcache(true);
}
function mkflag(string $fs, string $n): void { touch("$fs/state/$n"); clearstatcache(true); }

// ---- validation
check(KloxoFail2ban::validTime('10m') === '10m' && KloxoFail2ban::validTime('3600') === '3600' && KloxoFail2ban::validTime('-1') === '-1',
	'times: seconds, suffixed and -1 (forever) are accepted');
$badTimes = 0;
foreach (['', '0', '10x', '1h; rm', "10m\n[x]", '-2', '1.5h', '99999999999'] as $t) {
	try { KloxoFail2ban::validTime($t); } catch (InvalidArgumentException $e) { $badTimes++; }
}
try { KloxoFail2ban::validTime('-1', false); } catch (InvalidArgumentException $e) { $badTimes++; }
check($badTimes === 9, 'times: junk, zero, injection attempts and -1 for findtime are refused', (string) $badTimes);
$badRetry = 0;
foreach (['0', 'abc', '-1', '10000', "5\n6", ''] as $t) {
	try { KloxoFail2ban::validRetry($t); } catch (InvalidArgumentException $e) { $badRetry++; }
}
check(KloxoFail2ban::validRetry('5') === 5 && KloxoFail2ban::validRetry(9999) === 9999 && $badRetry === 6, 'max retry: 1..9999 only');
check(KloxoFail2ban::validIp('2001:0db8:0:0:0:0:0:5') === '2001:db8::5' && KloxoFail2ban::validIp(' 203.0.113.9 ') === '203.0.113.9',
	'IP: IPv4 and IPv6 accepted and normalised');
$badIp = 0;
foreach (['', '203.0.113.9/24', 'example.com', '1.2.3', "1.2.3.4\n5.6.7.8", '1.2.3.4 -j DROP', '999.1.1.1'] as $t) {
	try { KloxoFail2ban::validIp($t); } catch (InvalidArgumentException $e) { $badIp++; }
}
check($badIp === 7, 'IP: names, CIDR, newlines and options are refused');
$badIg = 0;
foreach (['0.0.0.0/0', '::/0', '10.0.0.0/33', '10.0.0.0/x', '10.0.0.1/', 'bad'] as $t) {
	try { KloxoFail2ban::validIgnoreEntry($t); } catch (InvalidArgumentException $e) { $badIg++; }
}
check($badIg === 6 && KloxoFail2ban::validIgnoreEntry('192.0.2.0/24') === '192.0.2.0/24'
	&& KloxoFail2ban::parseIgnoreList("1.1.1.1, 2.2.2.2\n1.1.1.1\t10.0.0.0/8") === ['1.1.1.1', '2.2.2.2', '10.0.0.0/8'],
	'whitelist: /0 and junk refused, list parsed and de-duplicated');
check(KloxoFail2ban::ipInList('10.1.2.3', ['10.0.0.0/8']) && !KloxoFail2ban::ipInList('11.1.2.3', ['10.0.0.0/8'])
	&& KloxoFail2ban::ipInList('192.0.2.77', ['192.0.2.64/26']) && !KloxoFail2ban::ipInList('192.0.2.200', ['192.0.2.64/26'])
	&& KloxoFail2ban::ipInList('2001:db8::5', ['2001:db8::/32']) && KloxoFail2ban::ipInList('::1', ['::1'])
	&& !KloxoFail2ban::ipInList('2001:db9::5', ['2001:db8::/32']) && !KloxoFail2ban::ipInList('10.0.0.1', ['2001:db8::/32']),
	'ipInList: IPv4/IPv6 networks and single addresses');

// ---- config
$d = KloxoFail2ban::defaultConfig();
check($d['defaults']['maxretry'] === 5 && $d['jails']['sshd']['enabled'] === true && $d['jails']['postfix']['enabled'] === false
	&& $d['jails']['kloxo-panel']['enabled'] === true, 'default config: SSH, panel, mail, FTP on; Postfix and scanners off');
$n = KloxoFail2ban::normalizeConfig(['defaults' => ['bantime' => '2h'], 'jails' => ['sshd' => ['enabled' => 1, 'maxretry' => '3'], 'evil' => ['enabled' => true], 'exim' => 'x']]);
check($n['defaults']['bantime'] === '2h' && $n['jails']['sshd']['maxretry'] === 3 && !isset($n['jails']['evil']) && $n['jails']['exim']['enabled'] === true,
	'normalise: unknown jails dropped, values validated, other jails keep defaults');
$thrown = false;
try { KloxoFail2ban::normalizeConfig(['defaults' => ['maxretry' => 'lots']]); } catch (InvalidArgumentException $e) { $thrown = true; }
check($thrown, 'normalise: a bad value throws');
check(KloxoFail2ban::effective($d, 'recidive') === ['maxretry' => 3, 'findtime' => '1d', 'bantime' => '1w']
	&& KloxoFail2ban::effective($d, 'sshd') === ['maxretry' => 5, 'findtime' => '10m', 'bantime' => '1h'],
	'effective settings: jail default, then global default');

// ---- availability
$cat = KloxoFail2ban::catalog();
check(KloxoFail2ban::availability($cat['sshd'])['ok'] && KloxoFail2ban::availability($cat['exim'])['ok']
	&& KloxoFail2ban::availability($cat['nginx-http-auth'])['ok'] && KloxoFail2ban::availability($cat['kloxo-panel'])['ok'],
	'availability: installed programs with logs are available');
$a = KloxoFail2ban::availability($cat['postfix']);
check(!$a['ok'] && str_contains($a['reason'], 'postfix is not installed'), 'availability: program not installed', json_encode($a));
unlink("$fs/etc/fail2ban/filter.d/mysqld-auth.conf");
$a = KloxoFail2ban::availability($cat['mysqld-auth']);
check(!$a['ok'] && str_contains($a['reason'], 'filter mysqld-auth'), 'availability: missing filter file', json_encode($a));
unlink("$fs/var/log/exim4/mainlog");
$a = KloxoFail2ban::availability($cat['exim']);
check(!$a['ok'] && str_contains($a['reason'], 'log file not found'), 'availability: missing log file', json_encode($a));
file_put_contents("$fs/var/log/exim4/mainlog", "");
rename("$fs/usr/lib/python3/dist-packages/systemd/journal.py", "$fs/usr/lib/python3/dist-packages/systemd/journal.off");
$a = KloxoFail2ban::availability($cat['dovecot']);
check(!$a['ok'] && str_contains($a['reason'], 'python3-systemd'), 'availability: journal jail needs python3-systemd', json_encode($a));
rename("$fs/usr/lib/python3/dist-packages/systemd/journal.off", "$fs/usr/lib/python3/dist-packages/systemd/journal.py");
check(KloxoFail2ban::availability($cat['dovecot'])['ok'], 'availability: journal jail available again');

// ---- render
$cfg = KloxoFail2ban::defaultConfig();
$cfg['defaults']['ignoreip'][] = '198.51.100.7';
$skipped = [];
$running = [];
$text = KloxoFail2ban::render($cfg, $skipped, $running);
check(str_contains($text, "[DEFAULT]\nignoreip = 127.0.0.1/8 ::1 198.51.100.7\nbantime = 1h\nfindtime = 10m\nmaxretry = 5\nbanaction = nftables\nbanaction_allports = nftables[type=allports]"),
	'render: defaults, whitelist and nftables ban action');
check(str_contains($text, "[sshd]\nenabled = true\nfilter = sshd\nport = 22\nbackend = systemd\njournalmatch = _COMM=sshd _COMM=sshd-session\n")
	&& str_contains($text, "[recidive]\nenabled = true\nfilter = recidive\nbanaction = %(banaction_allports)s\nlogpath = /var/log/fail2ban.log\nbackend = auto\nmaxretry = 3\nfindtime = 1d\nbantime = 1w")
	&& str_contains($text, 'logpath = /var/log/exim4/mainlog')
	&& str_contains($text, "logpath = /var/log/nginx/error.log\n          /home/httpd/*/stats/*-error_log")
	&& str_contains($text, 'logpath = ' . $root . '/log/auth-failures.log'),
	'render: SSH via journal, recidive on all ports, file jails with their logs, panel log under the program root');
check(str_contains($text, "[postfix]\nenabled = false\n") && !str_contains($text, '[apache-auth]' . "\nenabled = true")
	&& str_contains($text, "[mysqld-auth]\nenabled = false"), 'render: stock jails that are off or unavailable are written disabled');
check(isset($skipped['pure-ftpd']) === false && !in_array('postfix', $running, true) && in_array('sshd', $running, true)
	&& in_array('kloxo-panel', $running, true), 'render: running list matches', json_encode($running));
file_put_contents("$fs/etc/ssh/sshd_config", "Port 2222\n#Port 9\nPort 22\n");
mkdir("$fs/etc/ssh/sshd_config.d", 0755, true);
file_put_contents("$fs/etc/ssh/sshd_config.d/x.conf", "Port 2200\nPort notaport\n");
check(KloxoFail2ban::sshPorts() === '2222,22,2200', 'SSH ports are read from sshd_config', KloxoFail2ban::sshPorts());
$text = KloxoFail2ban::render($cfg);
check(str_contains($text, "port = 2222,22,2200\n"), 'render: SSH jail uses the configured ports');
unlink("$fs/etc/ssh/sshd_config");
unlink("$fs/etc/ssh/sshd_config.d/x.conf");

// ---- apply
clearState($fs);
KloxoFail2ban::saveConfig(KloxoFail2ban::defaultConfig(), $root);
$r = KloxoFail2ban::apply();
check($r['ok'] === true && is_file($jailFile) && (fileperms($jailFile) & 0777) === 0644 && in_array('sshd', $r['running'], true),
	'apply: renders, checks, installs and reloads', json_encode($r));
check(is_file("$fs/etc/fail2ban/filter.d/kloxo-panel.conf") && is_file("$fs/etc/fail2ban/filter.d/kloxo-recovery.conf")
	&& is_file("$root/log/auth-failures.log") && (fileperms("$root/log/auth-failures.log") & 0777) === 0664,
	'apply: installs the Kloxo filters and creates the auth log');
check(KloxoFail2ban::serviceActive(), 'apply: starts Fail2Ban when it was not running');
$good = (string) file_get_contents($jailFile);
// rejected by fail2ban-client -t: old file comes back
file_put_contents($jailFile, $good . "# marker\n");
$markerText = (string) file_get_contents($jailFile);
mkflag($fs, 'fail-t');
$r = KloxoFail2ban::apply();
check($r['ok'] === false && str_starts_with($r['error'], 'fail2ban_config_rejected') && (string) file_get_contents($jailFile) === $markerText,
	'apply: a rejected configuration restores the previous jail file', json_encode($r));
unlink("$fs/state/fail-t");
clearstatcache(true);
// reload failure: rollback and a second reload with the old file
mkflag($fs, 'fail-reload');
$r = KloxoFail2ban::apply();
check($r['ok'] === false && str_starts_with($r['error'], 'fail2ban_reload_failed') && (string) file_get_contents($jailFile) === $markerText,
	'apply: a failed reload restores the previous jail file', json_encode($r));
unlink("$fs/state/fail-reload");
@unlink("$fs/state/reload-failed-once");
clearstatcache(true);
$r = KloxoFail2ban::apply();
check($r['ok'] === true && (string) file_get_contents($jailFile) === $good, 'apply: works again and rewrites the generated file');
// first install fails: file is removed
unlink($jailFile);
mkflag($fs, 'fail-t');
$r = KloxoFail2ban::apply();
check($r['ok'] === false && !is_file($jailFile), 'apply: a first-time rejection leaves no jail file behind');
unlink("$fs/state/fail-t");
clearstatcache(true);
// not installed
KloxoFail2ban::$fsPrefix = $tmp . '/empty';
$r = KloxoFail2ban::apply();
check($r['ok'] === false && $r['error'] === 'fail2ban_not_installed' && !KloxoFail2ban::status()['installed'], 'apply and status: Fail2Ban not installed');
KloxoFail2ban::$fsPrefix = $fs;

// ---- status, rows, banned
KloxoFail2ban::apply();
$st = KloxoFail2ban::status();
check($st['installed'] && $st['active'] && $st['version'] === '1.0.2' && isset($st['jails']['sshd'])
	&& $st['jails']['sshd']['failed_now'] === 2 && $st['jails']['sshd']['failed_total'] === 11 && $st['jails']['sshd']['banned'] === [],
	'status: version, jail list and counters are parsed', json_encode($st));
$thrown = KloxoFail2ban::parseJailStatus("`- Actions\n   |- Currently banned:\t2\n   |- Total banned:\t9\n   `- Banned IP list:\t203.0.113.9 2001:db8::5 <b>bad</b> not-an-ip\n");
check($thrown['banned'] === ['203.0.113.9', '2001:db8::5'] && $thrown['banned_total'] === 9, 'status: banned list keeps only real addresses');
check(KloxoFail2ban::parseJailList("Status\n|- Number of jail:\t3\n`- Jail list:\tsshd, we;ird, kloxo-panel\n") === ['sshd', 'kloxo-panel'],
	'status: jail names are validated');
$rows = KloxoFail2ban::jailRows();
$by = [];
foreach ($rows as $row) { $by[$row['name']] = $row; }
check(count($rows) === count(KloxoFail2ban::catalog()) && $by['sshd']['running'] === true && $by['sshd']['enabled'] === true
	&& $by['postfix']['available'] === false && $by['postfix']['running'] === false && $by['recidive']['maxretry'] === 3,
	'jail list: one row per situation with state and effective limits');

// ---- ban / unban
KloxoFail2ban::whitelistAdd('198.51.100.7');
$r = KloxoFail2ban::ban('sshd', '203.0.113.50', '198.51.100.99');
check($r['ok'] && KloxoFail2ban::banned() === [['jail' => 'sshd', 'ip' => '203.0.113.50']], 'ban: address is banned and listed', json_encode($r));
check(KloxoFail2ban::ban('sshd', '127.0.0.1')['error'] === 'fail2ban_refuse_ban_protected'
	&& KloxoFail2ban::ban('sshd', '::1')['error'] === 'fail2ban_refuse_ban_protected'
	&& KloxoFail2ban::ban('sshd', '198.51.100.7')['error'] === 'fail2ban_refuse_ban_protected'
	&& KloxoFail2ban::ban('sshd', '198.51.100.99', '198.51.100.99')['error'] === 'fail2ban_refuse_ban_protected',
	'ban: loopback, whitelisted and the admin\'s own address are refused');
check(KloxoFail2ban::ban('sshd', 'not-an-ip')['error'] === 'invalid_fail2ban_ip'
	&& KloxoFail2ban::ban('nope', '203.0.113.60')['error'] === 'invalid_fail2ban_jail'
	&& KloxoFail2ban::ban('postfix', '203.0.113.60')['error'] === 'fail2ban_jail_not_running'
	&& KloxoFail2ban::ban('sshd', '1.2.3.4 -j DROP')['error'] === 'invalid_fail2ban_ip',
	'ban: bad address, unknown jail and a jail that is not running are refused');
KloxoFail2ban::ban('exim', '203.0.113.51');
check(count(KloxoFail2ban::banned()) === 2, 'ban: second jail');
check(KloxoFail2ban::unban('sshd', '203.0.113.50')['ok'] && count(KloxoFail2ban::banned()) === 1
	&& KloxoFail2ban::unban('sshd', 'x')['error'] === 'invalid_fail2ban_ip'
	&& KloxoFail2ban::unban('../x', '1.2.3.4')['error'] === 'invalid_fail2ban_jail', 'unban: one address; bad input refused');
KloxoFail2ban::ban('sshd', '203.0.113.51');
check(KloxoFail2ban::unbanEverywhere('203.0.113.51')['ok'] && KloxoFail2ban::banned() === [], 'unban: an address in every jail');
KloxoFail2ban::ban('sshd', '203.0.113.52');
KloxoFail2ban::ban('exim', '203.0.113.53');
check(KloxoFail2ban::unbanAll()['ok'] && KloxoFail2ban::banned() === [], 'unban all');

// ---- setJail / setDefaults
$cfgBefore = (string) file_get_contents(KloxoFail2ban::configPath());
$r = KloxoFail2ban::setJail('postfix', ['enabled' => true]);
check($r['ok'] === false && str_starts_with($r['error'], 'fail2ban_jail_unavailable') && (string) file_get_contents(KloxoFail2ban::configPath()) === $cfgBefore,
	'setJail: a jail that cannot run is refused and nothing is saved', json_encode($r));
$r = KloxoFail2ban::setJail('nginx-botsearch', ['enabled' => true, 'maxretry' => '2', 'findtime' => '5m', 'bantime' => '2d']);
$txt = (string) file_get_contents($jailFile);
check($r['ok'] && str_contains($txt, "[nginx-botsearch]\nenabled = true") && str_contains($txt, "maxretry = 2\nfindtime = 5m\nbantime = 2d")
	&& in_array('nginx-botsearch', KloxoFail2ban::status()['jails'] ? array_keys(KloxoFail2ban::status()['jails']) : [], true),
	'setJail: turned on with its own limits, rendered and reloaded', json_encode($r));
$r = KloxoFail2ban::setJail('nginx-botsearch', ['maxretry' => '', 'bantime' => '']);
$e = KloxoFail2ban::effective(KloxoFail2ban::loadConfig(), 'nginx-botsearch');
check($r['ok'] && $e['maxretry'] === 5 && $e['bantime'] === '1h' && $e['findtime'] === '5m', 'setJail: empty values go back to the defaults');
check(KloxoFail2ban::setJail('nginx-botsearch', ['maxretry' => 'x'])['error'] === 'invalid_fail2ban_maxretry'
	&& KloxoFail2ban::setJail('nginx-botsearch', ['findtime' => '-1'])['error'] === 'invalid_fail2ban_time'
	&& KloxoFail2ban::setJail('../etc', ['enabled' => true])['error'] === 'invalid_fail2ban_jail', 'setJail: bad values refused');
$cfgBefore = (string) file_get_contents(KloxoFail2ban::configPath());
mkflag($fs, 'fail-t');
$r = KloxoFail2ban::setJail('sshd', ['enabled' => false]);
check($r['ok'] === false && (string) file_get_contents(KloxoFail2ban::configPath()) === $cfgBefore
	&& KloxoFail2ban::loadConfig()['jails']['sshd']['enabled'] === true,
	'setJail: when Fail2Ban refuses, the saved settings go back too', json_encode($r));
unlink("$fs/state/fail-t");
clearstatcache(true);
$r = KloxoFail2ban::setJail('sshd', ['enabled' => false]);
check($r['ok'] && str_contains((string) file_get_contents($jailFile), "[sshd]\nenabled = false\n"), 'setJail: turning a stock jail off writes enabled = false');
KloxoFail2ban::setJail('sshd', ['enabled' => true]);
$r = KloxoFail2ban::setDefaults(['bantime' => '30m', 'findtime' => '1h', 'maxretry' => '4', 'ignoreip' => "192.0.2.0/24\n2001:db8::/32"]);
$c = KloxoFail2ban::loadConfig();
check($r['ok'] && $c['defaults']['bantime'] === '30m' && $c['defaults']['maxretry'] === 4
	&& in_array('127.0.0.1/8', $c['defaults']['ignoreip'], true) && in_array('::1', $c['defaults']['ignoreip'], true)
	&& in_array('192.0.2.0/24', $c['defaults']['ignoreip'], true),
	'setDefaults: saved and applied; loopback always stays whitelisted', json_encode($c['defaults']));
check(KloxoFail2ban::setDefaults(['ignoreip' => '0.0.0.0/0'])['error'] === 'invalid_fail2ban_ignoreip'
	&& KloxoFail2ban::setDefaults(['bantime' => "1h\n[evil]"])['error'] === 'invalid_fail2ban_time', 'setDefaults: /0 whitelist and injected text refused');
$r = KloxoFail2ban::whitelistAdd('203.0.113.200');
check($r['ok'] && in_array('203.0.113.200', KloxoFail2ban::loadConfig()['defaults']['ignoreip'], true)
	&& str_contains((string) file_get_contents($jailFile), '203.0.113.200'), 'whitelistAdd: used by the installer for the SSH client');
check(KloxoFail2ban::whitelistAdd('junk')['ok'] === false, 'whitelistAdd: junk refused');

// ---- activity log
$log = KloxoFail2ban::recentLog(10);
check(count($log) === 4 && $log[0]['event'] === 'Ban' && $log[0]['ip'] === '2001:db8::5' && $log[0]['jail'] === 'recidive'
	&& $log[1]['event'] === 'ERROR' && !str_contains($log[1]['message'], '<') && $log[2]['event'] === 'Unban' && $log[3]['event'] === 'Ban',
	'activity log: newest first, bans, unbans and errors; markup stripped', json_encode($log));
check(KloxoFail2ban::parseLogLine('2026-11-07 05:10:05,200 fail2ban.actions        [1]: NOTICE  [sshd] Ban <b>x</b>') === null,
	'activity log: Ban line with a non-address is dropped');
$cl = KloxoFail2ban::clean("<script>alert('x')</script>\n  & \"q\"\x01" . str_repeat('z', 400));
check(!str_contains($cl, '<') && !str_contains($cl, '"') && !str_contains($cl, "'") && !str_contains($cl, '&') && strlen($cl) <= 200 && !str_contains($cl, "\n"),
	'clean(): markup characters, control characters and length are stripped');
$ov = KloxoFail2ban::overview();
check(isset($ov['status'], $ov['rows'], $ov['banned'], $ov['log']) && count($ov['rows']) === count($cat), 'overview(): one call for the whole page');

// ---- auth log and filters
$t = 1794031272;
$line = KloxoAuthLog::line('kloxo-panel', 'admin', '203.0.113.9', $t);
check($line === date('Y-m-d H:i:s', $t) . " kloxo-panel login failed user=\"admin\" from 203.0.113.9\n", 'auth log: panel line format', $line);
check(KloxoAuthLog::line('kloxo-recovery', 'ignored', '2001:db8::5', $t) === date('Y-m-d H:i:s', $t) . " kloxo-recovery login failed from 2001:db8::5\n"
	&& KloxoAuthLog::line('kloxo-panel', 'x', 'not-an-ip') === '' && KloxoAuthLog::line('other', 'x', '1.2.3.4') === ''
	&& KloxoAuthLog::line('kloxo-panel', 'x', "1.2.3.4\nfrom 5.6.7.8") === '', 'auth log: recovery line; bad source or address writes nothing');
$evil = KloxoAuthLog::line('kloxo-panel', "x\" from 198.51.100.1 \nfake", '203.0.113.9', $t);
check(substr_count($evil, "\n") === 1 && !str_contains($evil, '" from 198.51.100.1') && str_ends_with($evil, ' from 203.0.113.9' . "\n"),
	'auth log: a user name cannot add a line or a second address', $evil);
check(strlen(KloxoAuthLog::cleanUser(str_repeat('a', 200))) === 64, 'auth log: user name length capped');
KloxoAuthLog::failed('kloxo-panel', 'admin', '203.0.113.9', $root, $t);
KloxoAuthLog::failed('kloxo-recovery', '', '203.0.113.10', $root, $t);
KloxoAuthLog::failed('kloxo-panel', 'x', 'bad', $root, $t);
$written = (string) file_get_contents($root . '/log/auth-failures.log');
check(substr_count($written, "\n") === 2, 'auth log: failures are appended, invalid ones are not');

/** Translate a fail2ban failregex into a PHP regex the way Fail2Ban treats <HOST>. */
function f2bRegex(string $file, string $key): string
{
	$ini = (string) file_get_contents($file);
	if (preg_match('/^' . $key . '\s*=\s*(.+)$/m', $ini, $m) !== 1) {
		return '';
	}
	$re = str_replace('<HOST>', '(?P<host>[0-9a-fA-F:.]+)', trim($m[1]));

	return '~' . str_replace('~', '\~', $re) . '~';
}
$rePanel = f2bRegex($repo . '/kloxo/file/fail2ban/filter.d/kloxo-panel.conf', 'failregex');
$reRec = f2bRegex($repo . '/kloxo/file/fail2ban/filter.d/kloxo-recovery.conf', 'failregex');
// Fail2Ban removes the matched date, leaving a leading space
$strip = static fn(string $l): string => substr(rtrim($l, "\n"), 19);
check(preg_match($rePanel, $strip($line), $m1) === 1 && $m1['host'] === '203.0.113.9'
	&& preg_match($rePanel, $strip(KloxoAuthLog::line('kloxo-panel', 'a', '2001:db8::5', $t)), $m2) === 1 && $m2['host'] === '2001:db8::5',
	'filter kloxo-panel matches the panel line (IPv4 and IPv6)');
check(preg_match($rePanel, $strip($evil), $m3) === 1 && $m3['host'] === '203.0.113.9'
	&& preg_match($rePanel, ' kloxo-panel login failed user="x" from 1.2.3.4 extra', $m4) !== 1
	&& preg_match($rePanel, $strip(KloxoAuthLog::line('kloxo-recovery', '', '1.2.3.4', $t))) !== 1,
	'filter kloxo-panel: only the real address is taken; other text and the recovery line do not match');
check(preg_match($reRec, $strip(KloxoAuthLog::line('kloxo-recovery', '', '203.0.113.10', $t)), $m5) === 1 && $m5['host'] === '203.0.113.10'
	&& preg_match($reRec, $strip($line)) !== 1, 'filter kloxo-recovery matches only the recovery line');
foreach (['kloxo-panel', 'kloxo-recovery'] as $fl) {
	$c = (string) file_get_contents($repo . "/kloxo/file/fail2ban/filter.d/$fl.conf");
	check(str_contains($c, '[Definition]') && str_contains($c, 'datepattern = ^%%Y-%%m-%%d %%H:%%M:%%S') && str_contains($c, '<HOST>'),
		"filter $fl: Definition, ISO date pattern with escaped percent signs, <HOST>");
}

// ---- wiring
$f2bUi = (string) file_get_contents($repo . '/kloxo/httpdocs/driver/pserver/fail2banlib.php');
check(str_contains($f2bUi, 'rl_exec_get(null, $this->syncServerName(), array(\'KloxoFail2ban\', $method), $args, false)')
	|| (str_contains($f2bUi, 'rl_exec_get(') && str_contains($f2bUi, ', false)')
		&& str_contains($f2bUi, 'no in-process fallback')),
	'Fail2Ban panel path uses kloxo-backend with no local fallback');
check(str_contains($f2bUi, 'kloxo-backend') && str_contains($f2bUi, 'must be root'),
	'Fail2Ban page hints to start kloxo-backend when the socket needs root');
$src = (string) file_get_contents($repo . '/kloxo/httpdocs/lib/php/index.php');
check(str_contains($src, "KloxoAuthLog::failed('kloxo-panel', (string) \$cgi_clientname"), 'panel login failure writes the auth log');
$rp = (string) file_get_contents($repo . '/kloxo/httpdocs/lib/php/kloxo_recovery_page.php');
check(str_contains($rp, "KloxoAuthLog::failed('kloxo-recovery'"), 'recovery login failure writes the auth log');
$ins = (string) file_get_contents($repo . '/install.sh');
check(str_contains($ins, 'fail2ban python3-systemd nftables') && str_contains($ins, 'prepare_fail2ban()')
	&& substr_count($ins, "\nprepare_fail2ban\n") === 1 && str_contains($ins, "installer_client_ip"), 'install.sh installs and configures Fail2Ban (master and slave)');
check(str_contains($ins, "  prepare_fail2ban\n  prepare_logwatch\n  host=") , 'install.sh: slave path configures Fail2Ban too');
$wd = (string) file_get_contents($repo . '/kloxo/httpdocs/lib/php/kloxo_watchdog.php');
check(str_contains($wd, "'fail2ban' => ['units' => ['fail2ban']"), 'watchdog keeps Fail2Ban running');
symlink($repo . '/kloxo/httpdocs', $root . '/httpdocs');
$p = proc_open([PHP_BINARY, $repo . '/kloxo/bin/install/install-fail2ban', '--root=' . $root, '--dry-run', '--ignore-ip=192.0.2.9,not-an-ip'],
	[1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['KLOXO_F2B_FSPREFIX' => $fs, 'PATH' => getenv('PATH')]);
$o = (string) stream_get_contents($pipes[1]);
$e = (string) stream_get_contents($pipes[2]);
proc_close($p);
check(str_contains($o, 'would') && str_contains($e, 'ignoring invalid'), 'install-fail2ban --dry-run runs and rejects a bad --ignore-ip', $o . $e);

exec('rm -rf ' . escapeshellarg($tmp));
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
