<?php
/**
 * kloxo-watchdog: restart rules, hold/release, give-up, drivers, notification.
 * Runs the real KloxoWatchdog class against a fake systemd, fake sockets, a
 * fake clock and a fake mailer; never touches the system. php-cli only.
 * NOT covered: real systemd as root, real sockets, real sendmail/Exim.
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
function check(bool $cond, string $d, string $x = ''): void { $cond ? ok($d) : bad($d, $x); }

$repo = dirname(__DIR__, 2);
require $repo . '/kloxo/httpdocs/lib/php/kloxo_watchdog.php';

$tmp = sys_get_temp_dir() . '/kloxo-watchdog-test-' . getmypid();
@mkdir($tmp . '/etc/conf', 0755, true);
@mkdir($tmp . '/etc/slavedb', 0755, true);
@mkdir($tmp . '/log', 0755, true);
putenv('KLOXO_VAR_LIB=' . $tmp);

/** Fake machine: units, open ports, a clock and recorded calls. */
final class Fake
{
	/** @var array<string,array{LoadState:string,ActiveState:string,UnitFileState:string}> */
	public array $units = [];
	/** @var array<int,bool> */
	public array $ports = [];
	/** @var list<string> */
	public array $calls = [];
	/** @var list<array{string,string,string}> */
	public array $mails = [];
	/** @var list<string> */
	public array $log = [];
	public int $now = 1800000000;
	public float $uptime = 100000.0;
	public bool $restartWorks = true;
	/** @var array<string,list<int>> units whose restart opens these ports again */
	public array $opens = [];

	public function unit(string $n, string $active = 'active', string $file = 'enabled', string $load = 'loaded'): void
	{
		$this->units[$n] = ['LoadState' => $load, 'ActiveState' => $active, 'UnitFileState' => $file];
	}

	public function run(array $argv, int $s = 10): array
	{
		$this->calls[] = implode(' ', array_slice($argv, 1));
		$cmd = $argv[1] ?? '';
		if ($cmd === 'show') {
			$out = [];
			foreach ($argv as $a) {
				if (str_ends_with((string) $a, '.service')) {
					$n = substr((string) $a, 0, -8);
					$u = $this->units[$n] ?? ['LoadState' => 'not-found', 'ActiveState' => 'inactive', 'UnitFileState' => ''];
					$out[] = "Id={$n}.service\nLoadState={$u['LoadState']}\nActiveState={$u['ActiveState']}\nSubState=x\nUnitFileState={$u['UnitFileState']}";
				}
			}

			return ['rc' => 0, 'out' => implode("\n\n", $out) . "\n", 'err' => ''];
		}
		if ($cmd === 'restart') {
			$n = substr((string) end($argv), 0, -8);
			if ($this->restartWorks) {
				$this->units[$n]['ActiveState'] = 'active';
				foreach ($this->opens[$n] ?? [] as $p) {
					$this->ports[$p] = true;
				}

				return ['rc' => 0, 'out' => '', 'err' => ''];
			}

			return ['rc' => 1, 'out' => '', 'err' => 'Job failed'];
		}

		return ['rc' => 0, 'out' => '', 'err' => ''];
	}

	public function wd(string $root): KloxoWatchdog
	{
		return new KloxoWatchdog(
			$root,
			fn(array $a, int $s = 10): array => $this->run($a, $s),
			fn(string $k, string $t): bool => $k === 'tcp' ? !empty($this->ports[(int) $t]) : false,
			function (string $to, string $subj, string $body): bool {
				$this->mails[] = [$to, $subj, $body];

				return true;
			},
			fn(): float => $this->uptime,
			fn(): int => $this->now,
			function (int $s): void {
				$this->now += $s;
			},
			function (string $line): void {
				$this->log[] = $line;
			}
		);
	}

	public function restarts(): int
	{
		return count(array_filter($this->calls, static fn(string $c): bool => str_starts_with($c, 'restart ')));
	}
}

function ev(array $events, string $unit): string
{
	foreach ($events as $e) {
		if ($e['unit'] === $unit) {
			return $e['event'];
		}
	}

	return '';
}

// ---- a healthy machine: nothing happens
$f = new Fake();
foreach (['nginx', 'mariadb', 'bind9', 'exim4', 'dovecot', 'pure-ftpd', 'kloxo-webcache', 'kloxo-webproxy', 'rspamd', 'redis-server'] as $u) {
	$f->unit($u);
}
foreach ([80, 8080, 30080, 53, 25, 143, 21, 3306] as $p) {
	$f->ports[$p] = true;
}
$wd = $f->wd($tmp);
$e = $wd->cycle();
check($e === [] && $f->restarts() === 0 && !is_dir($tmp . '/var/watchdog/hold'), 'healthy machine: no restarts');

// ---- failed unit
$f->unit('mariadb', 'failed');
$e = $wd->cycle();
check(ev($e, 'mariadb') === 'restarted' && $f->restarts() === 1
	&& in_array('reset-failed mariadb.service', $f->calls, true) && in_array('restart mariadb.service', $f->calls, true),
	'failed unit: reset-failed then restart', json_encode($e));
$st = KloxoWatchdog::readState($tmp);
check(($st['units']['mariadb']['last'] ?? '') === 'restarted' && count($st['units']['mariadb']['restarts'] ?? []) === 1
	&& (fileperms($tmp . '/var/watchdog/state.json') & 0777) === 0644, 'state file records the restart (0644)');

// ---- inactive but enabled and not held: restart (crash that left it inactive)
$f->unit('exim4', 'inactive');
$e = $wd->cycle();
check(ev($e, 'exim4') === 'restarted', 'enabled unit that is inactive is restarted');

// ---- hold: stopped on purpose
$f->unit('rspamd', 'inactive');
KloxoWatchdog::hold('rspamd', $tmp);
$before = $f->restarts();
$e = $wd->cycle();
check(ev($e, 'rspamd') === '' && $f->restarts() === $before && KloxoWatchdog::isHeld('rspamd', $tmp), 'held unit is left alone');
KloxoWatchdog::release('rspamd', $tmp);
$e = $wd->cycle();
check(ev($e, 'rspamd') === 'restarted' && !KloxoWatchdog::isHeld('rspamd', $tmp), 'released unit is watched again');
KloxoWatchdog::hold('../etc', $tmp);
KloxoWatchdog::hold('a/b', $tmp);
check(!KloxoWatchdog::isHeld('../etc', $tmp) && glob($tmp . '/var/watchdog/hold/*') !== false
	&& !is_file($tmp . '/var/watchdog/etc') && !KloxoWatchdog::validUnit('..') && !KloxoWatchdog::validUnit('a b'),
	'hold refuses unit names that are paths');

// ---- disabled / not installed units are not watched
$f->unit('pure-ftpd', 'inactive', 'disabled');
$f->unit('redis-server', 'inactive', 'masked');
unset($f->units['dovecot']);
$before = $f->restarts();
$e = $wd->cycle();
check($f->restarts() === $before, 'disabled, masked and not installed units are never restarted');
$f->unit('pure-ftpd');
$f->unit('redis-server');
$f->unit('dovecot');

// ---- active but not answering
$f->ports[80] = false;
$f->opens['nginx'] = [80];
$e = $wd->cycle();
check(ev($e, 'nginx') === 'restarted' && str_contains(json_encode($e), 'port 80 does not answer'), 'active unit whose port is closed is restarted');
check(count(array_filter($f->calls, static fn(string $c): bool => $c === 'restart nginx.service')) === 1, 'restart happened once');

// ---- the port check retries (a busy moment is not a failure)
$f2 = new Fake();
$f2->unit('bind9');
$flap = 0;
$wdf = new KloxoWatchdog($tmp . '-flap',
	fn(array $a, int $s = 10): array => $f2->run($a, $s),
	function (string $k, string $t) use (&$flap): bool {
		$flap++;

		return $flap >= 3;
	},
	fn(): bool => true, fn(): float => 99999.0, fn(): int => $f2->now, fn(int $s) => null, fn(string $l) => null);
@mkdir($tmp . '-flap/etc', 0755, true);
$e = $wdf->cycle();
check($e === [] && $f2->restarts() === 0, 'a port that answers on the third try is healthy');
exec('rm -rf ' . escapeshellarg($tmp . '-flap'));

// ---- boot grace
$f3 = new Fake();
$f3->unit('nginx', 'failed');
$f3->uptime = 60.0;
$e = $f3->wd($tmp . '/grace')->cycle();
check($e === [] && $f3->calls === [], 'nothing is touched during the boot grace period');

// ---- restart that fails
$f4 = new Fake();
$f4->unit('mariadb', 'failed');
$f4->restartWorks = false;
@mkdir($tmp . '/r4/etc', 0755, true);
$e = $f4->wd($tmp . '/r4')->cycle();
check(ev($e, 'mariadb') === 'restart_failed', 'restart that fails is reported as restart_failed');

// ---- give up after max restarts, then recover
$f5 = new Fake();
$f5->unit('mariadb', 'failed');
$f5->restartWorks = false;
@mkdir($tmp . '/r5/etc/conf', 0755, true);
file_put_contents($tmp . '/r5/etc/conf/watchdog.json', json_encode(['max_restarts' => 3, 'window' => 600, 'notify' => 'ops@example.com']));
$w5 = $f5->wd($tmp . '/r5');
$seen = [];
for ($i = 0; $i < 5; $i++) {
	$f5->now += 61;
	$seen[] = ev($w5->cycle(), 'mariadb');
}
check($seen === ['restart_failed', 'restart_failed', 'restart_failed', 'gave_up', ''] && $f5->restarts() === 3,
	'after max_restarts it gives up once and stops restarting', json_encode($seen) . ' restarts=' . $f5->restarts());
$gave = array_values(array_filter($f5->mails, static fn(array $m): bool => str_contains($m[1], 'GAVE UP')));
check(count($gave) === 1 && $gave[0][0] === 'ops@example.com' && str_contains($gave[0][1], 'mariadb'), 'the admin is mailed once when it gives up');
check(count($f5->mails) <= 2, 'mail is throttled (no flood)', (string) count($f5->mails));
$f5->now += 700; // window passed
$f5->restartWorks = true;
$f5->opens['mariadb'] = [3306];
$e = $w5->cycle();
check(ev($e, 'mariadb') === 'restarted', 'after the window it tries again');
$f5->units['mariadb']['ActiveState'] = 'active';
$st = KloxoWatchdog::readState($tmp . '/r5');
$f5->now += 5;
$w5->cycle();
check(empty(KloxoWatchdog::readState($tmp . '/r5')['units']['mariadb']['gave_up']), 'a healthy unit clears the gave-up flag');

// ---- drivers choose the unit
@mkdir($tmp . '/r6/etc/slavedb', 0755, true);
$o = new stdClass();
$o->data = ['web' => 'apache', 'dns' => 'knot', 'smtp' => 'postfix', 'pop3' => 'none'];
file_put_contents($tmp . '/r6/etc/slavedb/driver', str_replace('O:8:"stdClass"', 'O:6:"Remote"', serialize($o)));
$f6 = new Fake();
$units = array_map(static fn(array $t): string => $t['unit'], $f6->wd($tmp . '/r6')->targets());
check(in_array('apache2', $units, true) && !in_array('nginx', $units, true)
	&& in_array('knot', $units, true) && !in_array('bind9', $units, true)
	&& in_array('postfix', $units, true) && !in_array('exim4', $units, true) && !in_array('dovecot', $units, true),
	'slavedb drivers pick apache2/knot/postfix and drop nginx/bind9/exim4/dovecot', implode(',', $units));
$units = array_map(static fn(array $t): string => $t['unit'], $f6->wd($tmp . '/nowhere')->targets());
check(in_array('nginx', $units, true) && in_array('apache2', $units, true) && in_array('mariadb', $units, true),
	'without slavedb every candidate is considered (only enabled ones are acted on)');

// ---- panel Watchdog screen and watchdog.json switch services off
@mkdir($tmp . '/r7/etc/conf', 0755, true);
file_put_contents($tmp . '/r7/etc/watchdog.conf', serialize([
	['servicename' => 'ftp', 'status' => 'off', 'port' => '21', 'action' => '__driver_ftp'],
	['servicename' => 'dns', 'status' => 'on', 'port' => '53', 'action' => '__driver_dns'],
	'junk',
]));
file_put_contents($tmp . '/r7/etc/conf/watchdog.json', json_encode(['disabled' => ['redis', 'nonsense']]));
$units = array_map(static fn(array $t): string => $t['unit'], $f6->wd($tmp . '/r7')->targets());
check(!in_array('pure-ftpd', $units, true) && in_array('bind9', $units, true) && !in_array('redis-server', $units, true),
	'panel Watchdog "off" and watchdog.json "disabled" both stop watching a service');

// ---- config bounds and notify validation
file_put_contents($tmp . '/r7/etc/conf/watchdog.json', json_encode(['interval' => 1, 'max_restarts' => 99, 'window' => 5, 'grace' => 99999, 'notify' => "a@b.c\nBcc: x@y.z"]));
$c = KloxoWatchdog::config($tmp . '/r7');
check($c['interval'] === 60 && $c['max_restarts'] === 3 && $c['window'] === 1800 && $c['grace'] === 300 && $c['notify'] === '',
	'out-of-range settings and a header-injection address fall back to the defaults');

// ---- contact email file (no database)
$o = new stdClass();
$o->data = ['admin' => ['contactemail' => 'admin@example.org']];
file_put_contents($tmp . '/etc/slavedb/contactemail', str_replace('O:8:"stdClass"', 'O:6:"Remote"', serialize($o)));
check(KloxoWatchdog::contactEmail($tmp) === 'admin@example.org' && KloxoWatchdog::contactEmail($tmp . '/none') === '',
	'admin address is read from the panel file, not the database');
check(KloxoWatchdog::validAddress('ops@example.net') === 'ops@example.net'
	&& KloxoWatchdog::validAddress('ops@example.net, backup@example.net') === 'ops@example.net'
	&& KloxoWatchdog::validAddress('Admin <ops@example.net>') === 'ops@example.net'
	&& KloxoWatchdog::validAddress("ops@example.net\nBcc: x@y.z") === '',
	'profile field may be one address, a list, or Name <addr>; header injection is refused');
$vlib = $tmp . '/varlib';
@mkdir($vlib, 0755, true);
$pdo = new PDO('sqlite:' . $vlib . '/panel.sqlite');
$pdo->exec('CREATE TABLE client (nname TEXT, contactemail TEXT)');
$pdo->exec("INSERT INTO client (nname, contactemail) VALUES ('admin', 'ops@example.net')");
putenv('KLOXO_VAR_LIB=' . $vlib);
$emptyRoot = $tmp . '/r-sqlite';
@mkdir($emptyRoot, 0755, true);
check(KloxoWatchdog::contactEmail($emptyRoot) === 'ops@example.net',
	'admin address in panel.sqlite is used when the slavedb file is missing');
check(KloxoWatchdog::contactEmailFromFile($emptyRoot) === 'ops@example.net',
	'reading sqlite copies the address into the slavedb file for the watchdog');
putenv('KLOXO_VAR_LIB=' . $tmp);

// ---- notification on a restart uses the contact address
$f8 = new Fake();
$f8->unit('nginx', 'failed');
$f8->opens['nginx'] = [80];
@mkdir($tmp . '/r8/etc/slavedb', 0755, true);
copy($tmp . '/etc/slavedb/contactemail', $tmp . '/r8/etc/slavedb/contactemail');
$f8->wd($tmp . '/r8')->cycle();
check(count($f8->mails) === 1 && $f8->mails[0][0] === 'admin@example.org' && str_contains($f8->mails[0][1], 'nginx restarted')
	&& !str_contains($f8->mails[0][1], "\n"), 'a restart mails the admin address from the panel file');

// ---- no admin address: logged, nothing sent; notify overrides the admin address
$f9 = new Fake();
$f9->unit('nginx', 'failed');
$f9->opens['nginx'] = [80];
@mkdir($tmp . '/r9/etc', 0755, true);
$w9 = $f9->wd($tmp . '/r9');
$w9->cycle();
check($f9->mails === [] && str_contains(implode("\n", $f9->log), 'no admin email set')
	&& str_contains($w9->testMail(), 'no recipient'), 'without an admin address nothing is mailed and the log says why');
$f9->now += 4000;
$f9->units['nginx']['ActiveState'] = 'failed';
@mkdir($tmp . '/r9/etc/conf', 0755, true);
file_put_contents($tmp . '/r9/etc/conf/watchdog.json', json_encode(['notify' => 'ops@example.net']));
check(KloxoWatchdog::recipient($tmp . '/r9') === 'ops@example.net' && KloxoWatchdog::recipient($tmp . '/r8') === 'admin@example.org',
	'notify wins over the admin address; otherwise the admin address is used');
$w9->cycle();
check(count($f9->mails) === 1 && $f9->mails[0][0] === 'ops@example.net', 'mail goes to the notify address when set');
$f9->mails = [];
check(str_contains($w9->testMail(), 'ops@example.net') && count($f9->mails) === 1 && str_contains($f9->mails[0][1], 'test message'),
	'--test-mail sends a test message through the same mailer');

// ---- the admin address reaches the watchdog without the database
$lib = (string) file_get_contents($repo . '/kloxo/httpdocs/lib/html/lib.php');
$cl = (string) file_get_contents($repo . '/kloxo/httpdocs/lib/client/lxclient.php');
$ipd = (string) file_get_contents($repo . '/kloxo/bin/install/init-panel-db');
check(str_contains($lib, 'function save_admin_contactemail') && str_contains($lib, 'slave_save_db("contactemail"')
	&& str_contains($lib, "__path_program_root") && str_contains($lib, "KloxoWatchdog::validAddress")
	&& preg_match("/nname === 'admin'\\) \\{\\s*\\/\\/[^\\n]*\\n\\s*save_admin_contactemail\\(\\\$this->contactemail\\)/", $cl) === 1,
	'saving the admin profile writes etc/slavedb/contactemail for the watchdog');
check(str_contains($ipd, 'syncAdminContactEmail($pdo)') && str_contains($ipd, "'O:6:\"Remote\"'"),
	'init-panel-db copies an existing admin address to etc/slavedb/contactemail');
$rp = (string) file_get_contents($repo . '/kloxo/httpdocs/lib/php/kloxo_recovery_page.php');
check(str_contains($rp, 'KloxoWatchdog::recipient($root)'), 'recovery console warns when the watchdog has no address');

// ---- the panel and recovery hooks
$wdSrc = (string) file_get_contents($repo . '/kloxo/httpdocs/driver/pserver/watchdog__synclib.php');
$i = strpos($wdSrc, 'static function watchRun');
$body = substr($wdSrc, (int) $i, (int) strpos($wdSrc, 'function dbactionUpdate') - (int) $i);
check(str_contains($body, 'return;') && !str_contains($body, 'restart') && !str_contains($body, 'lxshell'),
	'legacy watchRun no longer runs the old restart scripts');
$svc = (string) file_get_contents($repo . '/kloxo/httpdocs/driver/pserver/service__linuxlib.php');
check(str_contains($svc, 'KloxoWatchdog::hold($n, $root)') && str_contains($svc, 'KloxoWatchdog::release($n, $root)'),
	'Services page stop/start tells the watchdog (hold/release)');
$ins = (string) file_get_contents($repo . '/install.sh');
$unit = (string) file_get_contents($repo . '/kloxo/init/kloxo-watchdog.service');
check(str_contains($ins, 'install_watchdog()') && str_contains($ins, 'systemctl enable kloxo-watchdog')
	&& str_contains($unit, 'Restart=always') && str_contains($unit, 'User=root') && !str_contains($unit, 'mariadb'),
	'install.sh installs a standalone kloxo-watchdog service that does not depend on MariaDB');
symlink($repo . '/kloxo/httpdocs', $tmp . '/r5/httpdocs');
$p = proc_open([PHP_BINARY, $repo . '/kloxo/bin/kloxo-watchdog', '--status', '--root=' . $tmp . '/r5'],
	[1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$out = (string) stream_get_contents($pipes[1]);
$rc = proc_close($p);
check($rc === 0 && str_contains($out, 'mariadb') && str_contains($out, 'last='), 'kloxo-watchdog --status prints the state', $out);

$jobRoot = $tmp . '/sitephp';
@mkdir($jobRoot . '/log', 0755, true);
@mkdir($jobRoot . '/bin/install', 0755, true);
file_put_contents($jobRoot . '/log/sitephp.request', "{\"op\":\"install\",\"versions\":[\"8.1\"]}\n");
file_put_contents($jobRoot . '/bin/install/run-site-php-job', "#!/bin/sh\nexit 0\n");
$fj = new Fake();
$fj->wd($jobRoot)->cycle();
$phpJob = array_values(array_filter($fj->calls, static fn(string $c): bool => str_contains($c, 'run-site-php-job')));
check($phpJob !== [], 'watchdog runs a dropped site-PHP job as root', implode(' | ', $fj->calls));

exec('rm -rf ' . escapeshellarg($tmp));
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
