<?php
/**
 * Recovery console: credential file, login throttle, unit policy, systemctl
 * handling and the request flow. php-cli only; fake systemctl/journalctl and a
 * temp KLOXO_ROOT, never touches the system.
 * NOT covered: real systemd, kloxo-backend as root, Hiawatha/FPM serving the page.
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
function active(string $state, string $n): bool { clearstatcache(true); return is_file($state . '/active-' . $n); }
function check(bool $cond, string $d, string $x = ''): void { $cond ? ok($d) : bad($d, $x); }

$repo = dirname(__DIR__, 2);
$tmp = sys_get_temp_dir() . '/kloxo-recovery-test-' . getmypid();
@mkdir($tmp . '/etc/conf', 0755, true);
putenv('KLOXO_ROOT=' . $tmp);

// Fake systemctl: state kept in files; `show` prints blocks like the real one.
$state = $tmp . '/state';
@mkdir($state);
$fakeCtl = $tmp . '/systemctl';
file_put_contents($fakeCtl, <<<'SH'
#!/bin/sh
S="@STATE@"
echo "$*" >> "$S/calls.log"
case "$1" in
  show)
    shift
    prop=""
    value=0
    units=""
    while [ $# -gt 0 ]; do
      case "$1" in
        -p) shift; prop="$prop $1" ;;
        --value) value=1 ;;
        --no-pager) ;;
        *) units="$units $1" ;;
      esac
      shift
    done
    first=1
    for u in $units; do
      n=${u%.service}
      canon=$n
      case "$n" in
        bind9) canon=named; loaded=loaded ;;
        nginx|mariadb|kloxo-web|kloxo-backend|apache2|php8.3-fpm) loaded=loaded ;;
        *) loaded=not-found ;;
      esac
      act=inactive; [ -f "$S/active-$canon" ] && act=active
      if [ $value -eq 1 ]; then
        case "$prop" in
          *Id*) echo "${canon}.service" ;;
          *) echo "$act" ;;
        esac
        continue
      fi
      [ $first -eq 0 ] && echo
      first=0
      names="${canon}.service"
      [ "$canon" != "$n" ] && names="$names $u"
      echo "Id=${canon}.service"; echo "Names=$names"; echo "LoadState=$loaded"; echo "ActiveState=$act"; echo "SubState=x"; echo "UnitFileState=enabled"
    done
    ;;
  --no-block) shift; act=$1; n=${2%.service}
    case "$act" in start|restart) touch "$S/active-$n" ;; stop) rm -f "$S/active-$n" ;; esac ;;
  reset-failed|enable|disable) : ;;
esac
exit 0
SH
);
file_put_contents($fakeCtl, str_replace('@STATE@', $state, (string) file_get_contents($fakeCtl)));
chmod($fakeCtl, 0755);
touch($state . '/active-nginx');
define('KLOXO_RECOVERY_SYSTEMCTL', $fakeCtl);
$fakeJ = $tmp . '/journalctl';
file_put_contents($fakeJ, "#!/bin/sh\necho \"2026-11-07T05:00:00+0100 host mariadbd[1]: <script>alert(1)</script> start failed\"\nexit 0\n");
chmod($fakeJ, 0755);
define('KLOXO_RECOVERY_JOURNALCTL', $fakeJ);

require $repo . '/kloxo/httpdocs/lib/php/kloxo_recovery_page.php';

// ---- credential
$cred = KloxoRecovery::credentialPath();
check(!KloxoRecovery::hasCredential() && !KloxoRecovery::verifyCredential('anything-at-all'), 'no credential file: nothing verifies');
$pw = KloxoRecovery::generatePassword();
check(strlen($pw) === 20 && preg_match('/^[A-Za-z0-9]+$/', $pw) === 1, 'generated password: 20 alphanumeric characters');
KloxoRecovery::writeCredential($pw);
$raw = (string) file_get_contents($cred);
check(KloxoRecovery::hasCredential() && !str_contains($raw, $pw) && str_contains($raw, '$argon2id$') || str_contains($raw, '$2y$'),
	'credential file stores only a hash');
check(KloxoRecovery::verifyCredential($pw) && !KloxoRecovery::verifyCredential($pw . 'x') && !KloxoRecovery::verifyCredential(''),
	'right password verifies, wrong or empty does not');
check((fileperms($cred) & 0777) === 0640, 'credential file mode is 0640', decoct(fileperms($cred) & 0777));
$threw = false;
try { KloxoRecovery::writeCredential('short'); } catch (InvalidArgumentException $e) { $threw = true; }
check($threw, 'a short recovery password is refused');
check(glob($tmp . '/etc/conf/recovery.json.tmp*') === array(), 'atomic write leaves no temp file');

// ---- throttle
$td = $tmp . '/var/throttle';
$t0 = 1000000;
for ($i = 0; $i < KloxoRecovery::MAX_FAILURES - 1; $i++) {
	KloxoRecovery::recordFailure('203.0.113.9', $td, $t0 + $i);
}
check(KloxoRecovery::lockedFor('203.0.113.9', $td, $t0 + 10) === 0, 'below the limit: not locked');
KloxoRecovery::recordFailure('203.0.113.9', $td, $t0 + 5);
check(KloxoRecovery::lockedFor('203.0.113.9', $td, $t0 + 10) > 0, 'at the limit: locked');
check(KloxoRecovery::lockedFor('198.51.100.1', $td, $t0 + 10) === 0, 'lock is per client address');
check(KloxoRecovery::lockedFor('203.0.113.9', $td, $t0 + KloxoRecovery::FAIL_WINDOW + 1) === 0, 'lock expires after the window');
KloxoRecovery::clearFailures('203.0.113.9', $td);
check(KloxoRecovery::lockedFor('203.0.113.9', $td, $t0 + 10) === 0, 'successful login clears the failures');

// ---- unit policy
check(KloxoRecovery::mayAct('mariadb', 'restart') && KloxoRecovery::mayAct('mariadb', 'start') && KloxoRecovery::mayAct('nginx', 'stop'),
	'MariaDB and Nginx can be started, stopped and restarted');
check(!KloxoRecovery::mayAct('kloxo-web', 'stop') && !KloxoRecovery::mayAct('kloxo-web', 'restart')
	&& !KloxoRecovery::mayAct('kloxo-php', 'disable') && !KloxoRecovery::mayAct('kloxo-backend', 'restart')
	&& KloxoRecovery::mayAct('kloxo-web', 'start'),
	'panel units: start only (stop/restart would end the page)');
check(!KloxoRecovery::mayAct('sshd', 'stop') && !KloxoRecovery::mayAct('nginx; rm -rf /', 'start') && !KloxoRecovery::mayAct('nginx', 'mask'),
	'unknown units and actions are refused');

// ---- states / control through the fake systemctl
$st = [];
foreach (KloxoRecovery::states() as $s) {
	$st[$s['name']] = $s;
}
check(($st['nginx']['active'] ?? '') === 'active' && ($st['mariadb']['active'] ?? '') === 'inactive'
	&& ($st['mariadb']['installed'] ?? false) === true && ($st['knot']['installed'] ?? true) === false,
	'states: parses systemctl show blocks (active, inactive, not installed)');
check(($st['bind9']['installed'] ?? false) === true && ($st['bind9']['active'] ?? '') === 'inactive',
	'states: bind9 is installed even when systemd Id is named.service (Debian alias)');
check(KloxoRecovery::canonicalUnit('bind9') === 'named.service'
	&& KloxoRecovery::canonicalUnit('nginx') === 'nginx.service',
	'canonicalUnit: bind9 resolves to named.service, nginx stays nginx.service');
$r = KloxoRecovery::serverControl('start', 'mariadb');
check($r['ok'] === true && $r['active'] === 'active' && active($state, 'mariadb'), 'start mariadb: reaches active', json_encode($r));
$r = KloxoRecovery::serverControl('stop', 'mariadb');
check($r['ok'] === true && !active($state, 'mariadb'), 'stop mariadb: reaches inactive',
	json_encode($r) . ' file=' . (active($state, 'mariadb') ? 'yes' : 'no') . ' calls=' . str_replace("\n", ' | ', (string) @file_get_contents($state . '/calls.log')));
check(KloxoWatchdog::isHeld('mariadb', KloxoRecovery::rootDir()), 'stop from the console holds the unit so the watchdog leaves it stopped');
$r = KloxoRecovery::serverControl('restart', 'nginx');
check($r['ok'] === true, 'restart nginx', json_encode($r));
$calls = (string) file_get_contents($state . '/calls.log');
check(str_contains($calls, '--no-block start mariadb.service') && str_contains($calls, 'reset-failed mariadb.service'),
	'start uses --no-block and clears a failed state first');
@unlink($state . '/calls.log');
$r = KloxoRecovery::serverControl('start', 'bind9');
check($r['ok'] === true && active($state, 'named'), 'start bind9: reaches named.service (Debian alias)', json_encode($r));
$calls = (string) file_get_contents($state . '/calls.log');
check(str_contains($calls, 'start named.service') && str_contains($calls, 'reset-failed named.service'),
	'start bind9 acts on the canonical named unit');
$r = KloxoRecovery::serverControl('stop', 'bind9');
check($r['ok'] === true && !active($state, 'named') && KloxoWatchdog::isHeld('bind9', KloxoRecovery::rootDir()),
	'stop bind9: named inactive, hold is still the catalog name bind9');
@unlink($state . '/calls.log');
$r = KloxoRecovery::serverControl('restart', 'kloxo-web');
check($r['ok'] === false && $r['err'] === 'refused' && (clearstatcache(true) || !is_file($state . '/calls.log')), 'restart kloxo-web refused before systemctl runs');
$r = KloxoRecovery::serverControl('start', 'sshd');
check($r['ok'] === false && $r['err'] === 'refused', 'unknown unit refused');
$j = KloxoRecovery::serverJournal('mariadb');
check($j['ok'] && str_contains($j['text'], 'start failed'), 'journal: returns the unit log');
$j = KloxoRecovery::serverJournal('../../etc/passwd');
check(!$j['ok'] && $j['text'] === 'refused', 'journal: unknown unit refused');
check(KloxoRecovery::viaBackend('serverControl', ['start', 'mariadb']) !== null
	&& KloxoRecovery::viaBackend('doEvil', ['x']) === null, 'viaBackend only dispatches the two root-side methods');

// ---- request flow
@unlink($state . '/active-mariadb');
$ip = '192.0.2.10';
$https = ['HTTPS' => 'on', 'REQUEST_METHOD' => 'GET'];
$sess = [];
$now = 2000000;
$res = KloxoRecoveryPage::handle(['REQUEST_METHOD' => 'GET'], [], [], $sess, $ip, $now);
check($res['status'] === 403 && str_contains($res['body'], 'https://'), 'plain HTTP is refused');
$res = KloxoRecoveryPage::handle($https, [], [], $sess, $ip, $now);
check($res['status'] === 200 && str_contains($res['body'], 'Recovery login') && !str_contains($res['body'], 'Server applications'),
	'not logged in: login form only');
check(in_array('Cache-Control: no-store', $res['headers'], true)
	&& (bool) array_filter($res['headers'], static fn(string $h): bool => str_starts_with($h, 'Content-Security-Policy: default-src \'none\''))
	&& in_array('X-Frame-Options: DENY', $res['headers'], true), 'security headers are sent');
$sess = [];
$res = KloxoRecoveryPage::handle(['HTTPS' => 'on', 'REQUEST_METHOD' => 'POST'], [], ['do' => 'control', 'unit' => 'mariadb', 'act' => 'start', 'csrf' => 'x'], $sess, $ip, $now);
check(str_contains($res['body'], 'Recovery login') && !active($state, 'mariadb'), 'control without a session does nothing');
$res = KloxoRecoveryPage::handle(['HTTPS' => 'on', 'REQUEST_METHOD' => 'POST'], [], ['do' => 'login', 'password' => 'wrong'], $sess, $ip, $now);
check($res['status'] === 401 && empty($sess['auth']), 'wrong password: 401, no session');
for ($i = 0; $i < KloxoRecovery::MAX_FAILURES; $i++) {
	KloxoRecoveryPage::handle(['HTTPS' => 'on', 'REQUEST_METHOD' => 'POST'], [], ['do' => 'login', 'password' => 'wrong' . $i], $sess, $ip, $now);
}
$res = KloxoRecoveryPage::handle(['HTTPS' => 'on', 'REQUEST_METHOD' => 'POST'], [], ['do' => 'login', 'password' => $pw], $sess, $ip, $now);
check($res['status'] === 429 && empty($sess['auth']), 'too many failures: 429 even with the right password');
KloxoRecovery::clearFailures($ip);
$res = KloxoRecoveryPage::handle(['HTTPS' => 'on', 'REQUEST_METHOD' => 'POST'], [], ['do' => 'login', 'password' => $pw], $sess, $ip, $now);
check($res['status'] === 303 && !empty($sess['auth']) && $res['regenerate'] === true && strlen((string) $sess['csrf']) === 48,
	'right password: session opened, id regenerated');
$csrf = (string) $sess['csrf'];
$res = KloxoRecoveryPage::handle($https, [], [], $sess, $ip, $now + 5);
check($res['status'] === 200 && str_contains($res['body'], 'mariadb') && str_contains($res['body'], 'not installed')
	&& str_contains($res['body'], 'name="csrf" value="' . $csrf . '"'), 'logged in: unit list with CSRF-protected buttons');
check(str_contains($res['body'], 'name="unit" value="bind9"')
	&& !str_contains($res['body'], '<td>bind9</td><td colspan="5">not installed</td>'),
	'logged in: bind9 has Start, not "not installed"');
check(!str_contains($res['body'], 'value="kloxo-web"><input type="hidden" name="act" value="stop"'), 'no Stop button for the panel web server');
$post = ['HTTPS' => 'on', 'REQUEST_METHOD' => 'POST'];
$res = KloxoRecoveryPage::handle($post, [], ['do' => 'control', 'unit' => 'mariadb', 'act' => 'start', 'csrf' => 'forged'], $sess, $ip, $now + 6);
check($res['status'] === 403 && !active($state, 'mariadb'), 'bad CSRF token: refused, nothing started');
$res = KloxoRecoveryPage::handle($post, [], ['do' => 'control', 'unit' => 'mariadb', 'act' => 'start', 'csrf' => $csrf], $sess, $ip, $now + 7);
check($res['status'] === 303 && active($state, 'mariadb') && str_contains((string) $sess['flash'], 'Start mariadb: OK'),
	'Start mariadb from the console works and flashes the result');
check(!KloxoWatchdog::isHeld('mariadb', KloxoRecovery::rootDir()), 'start from the console hands the unit back to the watchdog');
$res = KloxoRecoveryPage::handle($https, [], [], $sess, $ip, $now + 8);
check(str_contains($res['body'], 'Start mariadb: OK') && !isset($sess['flash']), 'flash is shown once');
$res = KloxoRecoveryPage::handle($post, [], ['do' => 'control', 'unit' => 'kloxo-web', 'act' => 'restart', 'csrf' => $csrf], $sess, $ip, $now + 9);
check(str_contains((string) $sess['flash'], 'Refused'), 'restart of the panel web server is refused with a message');
$res = KloxoRecoveryPage::handle($https, ['journal' => 'mariadb'], [], $sess, $ip, $now + 10);
check(str_contains($res['body'], 'start failed') && !str_contains($res['body'], '<script>') && str_contains($res['body'], '&lt;script&gt;'),
	'log page escapes the journal text');
$res = KloxoRecoveryPage::handle($https, ['journal' => '"><script>x</script>'], [], $sess, $ip, $now + 11);
check(str_contains($res['body'], 'Unknown application') && !str_contains($res['body'], '<script>x'), 'unknown log name is rejected and not echoed');
$res = KloxoRecoveryPage::handle($https, [], [], $sess, $ip, $now + 11 + KloxoRecovery::SESSION_IDLE + 1);
check(str_contains($res['body'], 'Recovery login') && $res['destroy'] === true, 'idle session expires');
$sess = ['auth' => true, 'last' => $now, 'csrf' => $csrf];
$res = KloxoRecoveryPage::handle($post, [], ['do' => 'logout', 'csrf' => $csrf], $sess, $ip, $now + 1);
check($res['destroy'] === true && $sess === [] && $res['status'] === 303, 'logout destroys the session');
@unlink($cred);
$sess = [];
$res = KloxoRecoveryPage::handle($https, [], [], $sess, $ip, $now);
check(str_contains($res['body'], 'recovery-password --generate'), 'no credential: tells the operator how to create one');

// ---- the real page script, one request, with HTTPS faked by a prepend file
KloxoRecovery::writeCredential($pw);
file_put_contents($tmp . '/prepend.php', "<?php\n\$_SERVER['HTTPS']='on';\n\$_SERVER['REMOTE_ADDR']='192.0.2.50';\n\$_SERVER['REQUEST_METHOD']='GET';\n");
$cmd = [PHP_BINARY, '-d', 'auto_prepend_file=' . $tmp . '/prepend.php', $repo . '/kloxo/httpdocs/recovery/index.php'];
$p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
	['KLOXO_ROOT' => $tmp, 'PATH' => '/usr/bin:/bin']);
$page = (string) stream_get_contents($pipes[1]);
$perr = (string) stream_get_contents($pipes[2]);
proc_close($p);
check(str_contains($page, 'Recovery login') && $perr === '', 'recovery/index.php renders the login form', $perr . substr($page, 0, 200));

// ---- database-down detection and wiring
if (class_exists('mysqli_sql_exception')) {
	$e1 = new mysqli_sql_exception('No such file or directory', 2002);
	$e2 = new mysqli_sql_exception('Access denied', 1045);
	check(KloxoRecovery::isDatabaseDown($e1) && !KloxoRecovery::isDatabaseDown($e2) && !KloxoRecovery::isDatabaseDown(new Exception('x')),
		'database-down detection: socket/connect errors only');
} else {
	ok('database-down detection: skipped (no mysqli)');
}
$html = KloxoRecovery::databaseDownHtml();
check(str_contains($html, '/recovery/') && str_contains($html, 'MariaDB')
	&& str_contains($html, 'SQLite') && !str_contains($html, 'panel cannot load'),
	'database-down page links to the recovery console; panel stays on SQLite');
$lx = (string) file_get_contents($repo . '/kloxo/httpdocs/lib/php/lxlib.php');
$inc = (string) file_get_contents($repo . '/kloxo/httpdocs/lib/html/include.php');
check(str_contains($lx, 'KloxoRecovery::isDatabaseDown($e)') && str_contains($lx, 'http_response_code(503)')
	&& str_contains($inc, 'kloxo_recovery.php'), 'exception handler shows the recovery link; backend loads the class');
$ins = (string) file_get_contents($repo . '/install.sh');
$unit = (string) file_get_contents($repo . '/kloxo/init/kloxo-web.service');
check(str_contains($ins, 'recovery-password --generate --raw') && str_contains($ins, '/root/kloxo-recovery-password')
	&& str_contains($ins, 'mariadb.service.d/kloxo-restart.conf') && str_contains($ins, 'Restart=on-failure')
	&& str_contains($unit, 'Restart=on-failure'),
	'install.sh creates the recovery password and MariaDB restart drop-in; kloxo-web restarts on failure');
$srv = (string) file_get_contents($repo . '/kloxo/httpdocs/lib/html/lxserverlib.php');
check(str_contains($srv, 'db_degraded') && str_contains($srv, 'backend runs without it until it is back'),
	'kloxo-backend starts without the database and loads it when it returns');
$idx = (string) file_get_contents($repo . '/kloxo/httpdocs/recovery/index.php');
check(str_contains($idx, "'secure' => true") && str_contains($idx, "'httponly' => true") && str_contains($idx, "'samesite' => 'Strict'")
	&& str_contains($idx, '!empty($_SESSION[\'auth\'])'), 'page: secure/HttpOnly/SameSite cookie; legacy client only loaded for a logged-in request');

exec('rm -rf ' . escapeshellarg($tmp));
printf("%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
