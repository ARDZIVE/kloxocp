<?php
/**
 * SMTP protection (the Spamdyke replacement): policy validation, Exim rendering,
 * Postfix application, panel hand-off. php-cli only; never touches the system.
 *
 * Exim is NOT run here (no exim binary on the development host): the rendered
 * ACLs are checked structurally only. Postfix is exercised with the real
 * `postconf` when it is installed (a throw-away config directory), else skipped.
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
function throws(callable $f): string
{
	try {
		$f();
	} catch (InvalidArgumentException $e) {
		return $e->getMessage();
	}

	return '';
}

$root = dirname(__DIR__, 2);
require $root . '/kloxo/httpdocs/lib/php/kloxo_mailpolicy.php';
ob_start();
require $root . '/kloxo/bin/mail/kloxo-mail'; // defines the tool classes; does not run it
ob_end_clean();

$tmp = sys_get_temp_dir() . '/kloxo-mailpolicy-test-' . getmypid();
@mkdir($tmp, 0700, true);
register_shutdown_function(static function () use ($tmp): void {
	if (is_dir($tmp)) {
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $f) {
			$f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
		}
		@rmdir($tmp);
	}
});

echo "== validation\n";
$d = KloxoMailPolicy::normalize([]);
check($d['enabled'] === false && $d['greet_delay'] === 3 && $d['max_rcpnts'] === 150 && $d['graylist'] === true
	&& $d['graylist_min_secs'] === 300 && $d['graylist_max_secs'] === 1814400
	&& $d['reject_missing_sender_mx'] === true && $d['reject_empty_rdns'] === false
	&& $d['dns_blacklists'] === [] && $d['whitelist_ips'] === [], 'defaults: greet 3, graylist on, missing MX on, policy off');
$f = KloxoMailPolicy::normalize([
	'enabled' => 'on', 'greet_delay' => '5', 'max_rcpnts' => '20', 'graylist' => 'off',
	'graylist_min_secs' => '60', 'graylist_max_secs' => '86400',
	'reject_empty_rdns' => 'on', 'reject_ip_in_cc_rdns' => 'on', 'reject_unresolvable_rdns' => 'on', 'reject_missing_sender_mx' => 'off',
	'dns_blacklists' => "ZEN.spamhaus.org, bl.spamcop.net\nzen.spamhaus.org",
	'blacklist_headers' => "# c\nX-Mailer: *Mass Mailer*\n\nSubject: viagra",
	'whitelist_ips' => ['192.0.2.1', '198.51.100.0/24', '2001:DB8::/32'],
]);
check($f['enabled'] && $f['greet_delay'] === 5 && $f['graylist'] === false && $f['reject_missing_sender_mx'] === false
	&& $f['dns_blacklists'] === ['zen.spamhaus.org', 'bl.spamcop.net']
	&& count($f['blacklist_headers']) === 2 && $f['whitelist_ips'] === ['192.0.2.1', '198.51.100.0/24', '2001:db8::/32'],
	'panel form values are normalised (flags, lists, duplicates, case)');
foreach ([
	['greet_delay', 'abc'], ['greet_delay', '31'], ['greet_delay', '-1'], ['max_rcpnts', '10001'], ['max_rcpnts', '1e3'],
	['graylist_min_secs', '0'], ['graylist_max_secs', '99999999999'],
	['dns_blacklists', 'bad zone.example.org'], ['dns_blacklists', 'x.org;rm'], ['dns_blacklists', "a.example.org\" : evil.org"],
	['dns_blacklists', 'localhost'],
	['blacklist_headers', 'no colon here'], ['blacklist_headers', "X-Mailer: back\\slash"], ['blacklist_headers', 'X Mailer: a'],
	['blacklist_headers', 'X-Mailer: '], ['blacklist_headers', "X-Mailer: caf\xc3\xa9"],
	['whitelist_ips', '1.2.3.4; rm -rf /'], ['whitelist_ips', '1.2.3.4/33'], ['whitelist_ips', '::1/129'], ['whitelist_ips', 'example.com'],
] as [$k, $v]) {
	check(throws(static fn () => KloxoMailPolicy::normalize([$k => $v])) !== '', "rejects $k = " . json_encode($v));
}
check(str_contains(throws(static fn () => KloxoMailPolicy::normalize(['graylist_min_secs' => '600', 'graylist_max_secs' => '300'])), 'maximum'),
	'graylist maximum must not be below the minimum');
check(count(KloxoMailPolicy::parseRbl(str_repeat('a.example.org ', 1))) === 1
	&& throws(static function (): void {
		KloxoMailPolicy::parseRbl(implode(' ', array_map(static fn ($i) => "z$i.example.org", range(1, 21))));
	}) !== '', 'at most 20 RBL zones');

echo "== Exim rendering\n";
$full = $f;
$full['graylist'] = true;
$full['blacklist_headers'] = KloxoMailPolicy::parseHeaders("X-Mailer: *Mass Mailer*\nSubject: \$(x){y}");
$off = KloxoMailPolicy::normalize([]);
$sOff = KloxoMailPolicy::eximSnippets($off);
check($sOff['POLICY_CONNECT'] === '' && $sOff['POLICY_MAIL'] === '' && $sOff['POLICY_RCPT'] === '' && $sOff['POLICY_DATA'] === ''
	&& str_contains($sOff['POLICY_SENDER_VERIFY'], '!verify = sender')
	&& str_contains($sOff['POLICY_MAIN'], 'kloxo_policy_whitelist = <; 127.0.0.1'),
	'policy off: only the original sender verification remains');
$s = KloxoMailPolicy::eximSnippets($full);
check(str_contains($s['POLICY_CONNECT'], 'warn    delay = 5s')
	&& str_contains($s['POLICY_CONNECT'], '+kloxo_policy_whitelist')
	&& str_contains($s['POLICY_CONNECT'], '587') && str_contains($s['POLICY_CONNECT'], '465'),
	'greet delay: connect ACL delays unauthenticated port-25 clients, not submission or whitelisted hosts');
check($s['POLICY_SENDER_VERIFY'] === '', 'reject_missing_sender_mx off removes the sender verification');
check(str_contains($s['POLICY_MAIN'], '<; 192.0.2.1 ; 198.51.100.0/24 ; 2001:db8::/32'), 'whitelist becomes a hostlist');
check(str_contains($s['POLICY_MAIL'], 'dnsdb{ptr=$sender_host_address}')
	&& str_contains($s['POLICY_MAIL'], '$host_lookup_deferred')
	&& str_contains($s['POLICY_MAIL'], 'dnslists = zen.spamhaus.org : bl.spamcop.net')
	&& str_contains($s['POLICY_MAIL'], '127\.255\.255\.')
	&& substr_count($s['POLICY_MAIL'], '!authenticated = *') === 4
	&& substr_count($s['POLICY_MAIL'], '+kloxo_policy_whitelist') === 4,
	'rDNS and RBL rules skip authenticated, local and whitelisted clients and ignore Spamhaus error answers');
check(str_contains($s['POLICY_RCPT'], '>={$recipients_count}{20}')
	&& str_contains($s['POLICY_RCPT'], 'readsocket{inet:127.0.0.1:10023}')
	&& str_contains($s['POLICY_RCPT'], 'TEMPFAIL')
	&& strpos($s['POLICY_RCPT'], 'Unknown user') < strpos($s['POLICY_RCPT'], 'readsocket'),
	'recipient limit and Postgrey graylist (unknown users are refused first, a dead Postgrey defers)');
$warn = strpos($s['POLICY_RCPT'], '  warn    domains');
$setp = strpos($s['POLICY_RCPT'], 'set acl_m_grey');
check($warn !== false && $setp !== false && $setp > strpos($s['POLICY_RCPT'], '!hosts = +kloxo_policy_whitelist', $warn),
	'graylist: conditions come before the set modifier');
check(str_contains($s['POLICY_RCPT'], '${sg{$sender_address}{\N[\r\n]\N}{_}}'),
	'values sent to the policy socket cannot carry a newline');
check(substr_count($s['POLICY_DATA'], 'deny    message = Message rejected by header policy') === 2
	&& str_contains($s['POLICY_DATA'], '\N(?i).*Mass Mailer.*\N') && str_contains($s['POLICY_DATA'], '\$\(x\)\{y\}'),
	'header rules: case-insensitive, * is a wildcard, every other character is escaped');
$g = KloxoMailPolicy::normalize(['enabled' => 'on', 'graylist' => 'off', 'greet_delay' => '0', 'max_rcpnts' => '0']);
$sg = KloxoMailPolicy::eximSnippets($g);
check($sg['POLICY_CONNECT'] === '' && $sg['POLICY_RCPT'] === '' && str_contains($sg['POLICY_SENDER_VERIFY'], '+kloxo_policy_whitelist'),
	'delay 0, no limit, no graylist: nothing is added');

// the whole file
$tpl = $root . '/kloxo/file/mail';
$c = new Conf();
$c->v['hostname'] = 'mail.example.com';
$c->v['rspamd_ctrl_hash'] = 'x';
$c->v['policy_file'] = $tmp . '/policy.json';
$out1 = $tmp . '/out-off';
(new Renderer($c, $tpl))->render($out1, []);
$conf1 = (string) file_get_contents($out1 . '/etc/exim4/exim4.conf');
check(!str_contains($conf1, '@@') && str_contains($conf1, 'acl_smtp_connect = acl_check_connect')
	&& str_contains($conf1, "acl_check_connect:\n\n  accept\n")
	&& preg_match('/  deny    message = invalid sender\n          !hosts = \+kloxo_local_hosts\n          !authenticated = \*\n          !verify = sender\n\n  accept\n/', $conf1) === 1,
	'no policy file: the Exim config keeps the original sender check and an empty connect ACL');
file_put_contents($tmp . '/policy.json', json_encode($full));
$out2 = $tmp . '/out-on';
$files = (new Renderer($c, $tpl))->render($out2, (new Renderer($c, $tpl))->policyFiles());
$conf2 = (string) file_get_contents($out2 . '/etc/exim4/exim4.conf');
check(!str_contains($conf2, '@@') && str_contains($conf2, 'hostlist kloxo_policy_whitelist = <; 192.0.2.1')
	&& str_contains($conf2, 'delay = 5s') && str_contains($conf2, 'dnslists = zen.spamhaus.org')
	&& in_array('etc/default/postgrey', $files, true),
	'full policy renders into exim4.conf and a Postgrey defaults file');
$pg = (string) @file_get_contents($out2 . '/etc/default/postgrey');
check(str_contains($pg, '--inet=127.0.0.1:10023') && str_contains($pg, '--delay=60') && str_contains($pg, '--max-age=1"'),
	'Postgrey options come from the graylist lifetimes (seconds -> --delay, days rounded up -> --max-age)');
check(KloxoMailPolicy::postgreyMaxAgeDays(['graylist_max_secs' => 1814400]) === 21
	&& KloxoMailPolicy::postgreyMaxAgeDays(['graylist_max_secs' => 86401]) === 2, 'max age rounds up to whole days');

// structure: braces balance outside \N...\N, referenced ACLs exist, named lists are defined before use
$balanced = true;
$badLine = '';
foreach (explode("\n", (string) preg_replace('/\\\\\n\s*/', ' ', $conf2)) as $line) {
	if (!str_contains($line, '${') && !str_contains($line, '{')) {
		continue;
	}
	$x = preg_replace('/\\\\N.*?\\\\N/', '', $line);
	if (substr_count((string) $x, '{') !== substr_count((string) $x, '}')) {
		$balanced = false;
		$badLine = $line;
	}
}
check($balanced, 'every expansion in the rendered config has balanced braces', $badLine);
preg_match_all('/^acl_smtp_\w+ = (\w+)/m', $conf2, $m);
$missing = array_filter($m[1], static fn ($a) => !preg_match('/^' . preg_quote($a, '/') . ':/m', $conf2));
check($missing === [], 'every acl_smtp_* option names an ACL that exists', implode(',', $missing));
check(strpos($conf2, 'hostlist kloxo_policy_whitelist') < strpos($conf2, 'begin acl')
	&& strpos($conf2, 'hostlist kloxo_local_hosts') < strpos($conf2, 'hostlist kloxo_policy_whitelist'),
	'the whitelist is defined in the main section before the ACLs use it');
// the invariants of docs/MAIL_DESIGN.md still hold in the rendered file
check(str_contains($conf2, 'deny    message = AUTH requires TLS') && str_contains($conf2, 'sender does not match authenticated user')
	&& str_contains($conf2, '/run/dovecot/quota-status') && str_contains($conf2, 'message = Unknown user')
	&& !str_contains((string) preg_replace('/^\s*#.*$/m', '', $conf2), 'defer_ok')
	&& strpos($conf2, 'quota-status', (int) strpos($conf2, 'acl_check_rcpt:')) < strpos($conf2, 'accept  hosts = +kloxo_local_hosts', (int) strpos($conf2, 'acl_check_rcpt:')),
	'mail design invariants still present (TLS for AUTH, sender match, quota first, unknown users, no defer_ok)');
$eximBin = trim((string) @shell_exec('command -v exim4 || command -v exim 2>/dev/null'));
if ($eximBin !== '') {
	[$rc, $o, $e] = Proc::run([$eximBin, '-C', $out2 . '/etc/exim4/exim4.conf', '-bV']);
	check($rc === 0, 'exim -bV accepts the rendered config', $e . $o);
} else {
	echo "  skip exim -bV (no exim binary on this host: the Exim ACLs are NOT verified by a real Exim)\n";
}

echo "== Postfix plan\n";
$pp = KloxoMailPolicy::postfixPlan($full, '/etc/postfix/kloxo');
check($pp['params']['smtpd_recipient_limit'] === '20' && $pp['params']['postscreen_greet_wait'] === '5s'
	&& $pp['postscreen'] === true && $pp['postgrey'] === true
	&& str_contains((string) $pp['params']['smtpd_client_restrictions'], 'reject_unknown_reverse_client_hostname')
	&& str_contains((string) $pp['params']['smtpd_client_restrictions'], 'reject_unknown_client_hostname')
	&& str_contains((string) $pp['params']['smtpd_client_restrictions'], 'reject_rbl_client zen.spamhaus.org=127.0.0.[2..255]')
	&& str_starts_with((string) $pp['params']['smtpd_client_restrictions'], 'permit_mynetworks, permit_sasl_authenticated, check_client_access cidr:/etc/postfix/kloxo/whitelist.cidr')
	&& $pp['params']['smtpd_sender_restrictions'] === null
	&& str_contains((string) $pp['params']['smtpd_recipient_restrictions'], 'check_policy_service inet:127.0.0.1:10023')
	&& str_starts_with((string) $pp['params']['smtpd_recipient_restrictions'], 'permit_mynetworks, permit_sasl_authenticated'),
	'enabled policy: restrictions, limit, postscreen and Postgrey');
check(str_contains($pp['files']['whitelist.cidr'], "198.51.100.0/24 OK\n") && str_contains($pp['files']['postscreen_access.cidr'], "198.51.100.0/24 permit\n")
	&& str_contains($pp['files']['header_checks.regexp'], '/^X-Mailer:.*.*Mass Mailer.*/i REJECT')
	&& str_contains($pp['files']['header_checks.regexp'], '\$\(x\)\{y\}'), 'map files: whitelist (smtpd and postscreen), header regexps');
$po = KloxoMailPolicy::postfixPlan($off);
check(array_filter($po['params'], static fn ($v) => $v !== null) === [] && $po['postscreen'] === false && $po['postgrey'] === false,
	'policy off: Postfix plan changes nothing');
$ps = KloxoMailPolicy::postfixPlan(KloxoMailPolicy::normalize(['enabled' => 'on', 'graylist' => 'off', 'greet_delay' => '0', 'max_rcpnts' => '0', 'reject_missing_sender_mx' => 'on']));
check($ps['params']['smtpd_sender_restrictions'] === 'permit_mynetworks, permit_sasl_authenticated, reject_unknown_sender_domain'
	&& $ps['params']['smtpd_recipient_restrictions'] === null && $ps['params']['postscreen_greet_wait'] === null
	&& $ps['params']['smtpd_client_restrictions'] === null, 'only the enabled rules appear');

echo "== Postfix apply (real postconf, throw-away config directory)\n";
$postconf = trim((string) @shell_exec('command -v postconf 2>/dev/null'));
if ($postconf === '') {
	echo "  skip (postconf not installed on this host)\n";
} else {
	$tool = $root . '/kloxo/bin/mail/kloxo-mail';
	$php = PHP_BINARY;
	$rootDir = $tmp . '/pf';
	$cfg = $rootDir . '/etc/postfix';
	@mkdir($cfg, 0755, true);
	$mainCf = "myhostname = mail.example.com\nmydomain = example.com\ninet_interfaces = 127.0.0.1\ninet_protocols = ipv4\nmydestination =\nmynetworks = 127.0.0.0/8\nsmtpd_client_restrictions = permit_mynetworks, reject_unknown_client_hostname\n";
	$masterCf = "smtp      inet  n       -       y       -       -       smtpd\npickup    unix  n       -       y       60      1       pickup\ncleanup   unix  n       -       y       -       0       cleanup\nqmgr      unix  n       -       n       300     1       qmgr\nsmtp      unix  -       -       y       -       -       smtp\n";
	file_put_contents($cfg . '/main.cf', $mainCf);
	file_put_contents($cfg . '/master.cf', $masterCf);
	$pol = $tmp . '/pf-policy.json';
	$run = static function (array $policy, string $mta = 'postfix', ?string $path = null) use ($php, $tool, $rootDir, $cfg, $pol): array {
		file_put_contents($pol, json_encode($policy));
		$env = $path !== null ? ['PATH' => $path] : null;
		$p = proc_open([$php, $tool, 'policy:apply', "--mta=$mta", "--policy=$pol", "--root=$rootDir", "--postfix-dir=$cfg", '--no-reload'],
			[0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
		$o = (string) stream_get_contents($pipes[1]);
		$e = (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		return [proc_close($p), $o, $e];
	};
	$pc = static function (array $args) use ($cfg): string {
		[, $o] = Proc::run(array_merge(['postconf', '-c', $cfg], $args));

		return trim($o);
	};

	[$rc, $o, $e] = $run($full);
	check($rc === 0, 'policy:apply --mta=postfix succeeds', $e . $o);
	$crs = $pc(['-n', 'smtpd_client_restrictions']);
	check(str_contains($crs, 'check_client_access cidr:' . $cfg . '/kloxo/whitelist.cidr')
		&& str_contains($crs, 'reject_rbl_client bl.spamcop.net=127.0.0.[2..255]'), 'client restrictions installed', $crs);
	check($pc(['-n', 'smtpd_recipient_limit']) === 'smtpd_recipient_limit = 20'
		&& $pc(['-n', 'postscreen_greet_wait']) === 'postscreen_greet_wait = 5s'
		&& str_contains($pc(['-n', 'smtpd_recipient_restrictions']), 'check_policy_service inet:127.0.0.1:10023')
		&& str_contains($pc(['-n', 'header_checks']), 'regexp:' . $cfg . '/kloxo/header_checks.regexp'), 'limit, greet wait, graylist and header checks installed',
		$pc(['-n', 'smtpd_recipient_limit']) . '|' . $pc(['-n', 'postscreen_greet_wait']) . '|' . $pc(['-n', 'smtpd_recipient_restrictions']) . '|' . $pc(['-n', 'header_checks']));
	check(str_contains((string) preg_replace('/\s+/', ' ', $pc(['-M', 'smtp/inet'])), 'postscreen')
		&& $pc(['-M', 'smtpd/pass']) !== '' && $pc(['-M', 'dnsblog/unix']) !== '' && $pc(['-M', 'tlsproxy/unix']) !== '',
		'master.cf: smtp listener is postscreen, smtpd/dnsblog/tlsproxy services added');
	check(is_file($cfg . '/kloxo/whitelist.cidr') && str_contains((string) file_get_contents($cfg . '/kloxo/whitelist.cidr'), '192.0.2.1 OK')
		&& str_contains((string) file_get_contents($rootDir . '/etc/default/postgrey'), '--delay=60'), 'map files and the Postgrey defaults written');
	$state = json_decode((string) @file_get_contents($rootDir . '/etc/kloxo/mail-policy-postfix.state.json'), true);
	check(is_array($state) && ($state['original']['smtpd_client_restrictions'] ?? '') === 'permit_mynetworks, reject_unknown_client_hostname'
		&& array_key_exists('smtpd_recipient_limit', $state['original']) && $state['original']['smtpd_recipient_limit'] === null, 'the original values are remembered', json_encode($state));

	// idempotent
	$before = file_get_contents($cfg . '/main.cf');
	[$rc] = $run($full);
	check($rc === 0 && file_get_contents($cfg . '/main.cf') === $before, 'applying the same policy again changes nothing');

	// failing step: rolled back
	$snapMain = file_get_contents($cfg . '/main.cf');
	$snapMaster = file_get_contents($cfg . '/master.cf');
	$wrapDir = $tmp . '/wrap';
	@mkdir($wrapDir);
	file_put_contents($wrapDir . '/postconf', "#!/bin/sh\ncase \"\$*\" in *smtpd_recipient_limit=7*) echo 'forced failure' >&2; exit 1;; esac\nexec " . $postconf . " \"\$@\"\n");
	chmod($wrapDir . '/postconf', 0755);
	$changed = $full;
	$changed['max_rcpnts'] = '7';
	[$rc, $o, $e] = $run($changed, 'postfix', $wrapDir . ':' . getenv('PATH'));
	check($rc !== 0 && str_contains($e, 'rolled back') && file_get_contents($cfg . '/main.cf') === $snapMain
		&& file_get_contents($cfg . '/master.cf') === $snapMaster, 'a failing postconf step restores main.cf and master.cf', $e);

	// open-relay guard
	$relayMain = file_get_contents($cfg . '/main.cf');
	$run(KloxoMailPolicy::defaults()); // back to the original first
	file_put_contents($cfg . '/main.cf', $mainCf . "smtpd_relay_restrictions = permit\n");
	$guardMain = file_get_contents($cfg . '/main.cf');
	[$rc, $o, $e] = $run($full);
	check($rc !== 0 && str_contains($e, 'open relay') && file_get_contents($cfg . '/main.cf') === $guardMain,
		'refuses to install when smtpd_relay_restrictions would leave an open relay');
	file_put_contents($cfg . '/main.cf', $mainCf);

	// disable restores everything
	[$rc] = $run($full);
	[$rc2, $o2, $e2] = $run(KloxoMailPolicy::defaults());
	check($rc === 0 && $rc2 === 0
		&& $pc(['-n', 'smtpd_client_restrictions']) === 'smtpd_client_restrictions = permit_mynetworks, reject_unknown_client_hostname'
		&& $pc(['-n', 'smtpd_recipient_limit']) === '' && $pc(['-n', 'postscreen_greet_wait']) === ''
		&& $pc(['-n', 'smtpd_recipient_restrictions']) === ''
		&& !str_contains((string) preg_replace('/\s+/', ' ', $pc(['-M', 'smtp/inet'])), 'postscreen')
		&& !is_file($rootDir . '/etc/kloxo/mail-policy-postfix.state.json'),
		'policy off puts the original Postfix settings back and removes the state', $e2 . $o2);
	[$rc3, $o3] = $run(KloxoMailPolicy::defaults());
	check($rc3 === 0 && str_contains($o3, 'no change'), 'policy off with nothing applied is a no-op', $o3);
	[$rc4, $o4] = $run($full, 'none');
	check($rc4 === 0 && str_contains($o4, 'nothing to install'), '--mta=none installs nothing');
	[$rc5, , $e5] = $run($full, 'qmail');
	check($rc5 !== 0 && str_contains($e5, '--mta'), 'unknown MTA is refused');
}

echo "== Exim overlay (no mail.ini / hosted-mail apply)\n";
$ovOn = KloxoMailPolicy::eximOverlay($full);
check(str_contains($ovOn['main'], 'acl_smtp_rcpt = acl_kloxo_rcpt')
	&& str_contains($ovOn['main'], 'hostlist kloxo_policy_whitelist')
	&& str_contains($ovOn['acl'], 'acl_kloxo_rcpt:')
	&& str_contains($ovOn['acl'], 'accept  acl = acl_check_rcpt'),
	'Exim overlay wires SMTP protection into Debian split config and keeps acl_check_rcpt');
$ovOff = KloxoMailPolicy::eximOverlay($off);
check(!str_contains($ovOff['main'], 'acl_smtp_rcpt') && !str_contains($ovOff['acl'], 'acl_kloxo_rcpt:'),
	'policy off: Exim overlay is empty (files are removed)');
$ovRoot = $tmp . '/exim-ov';
@mkdir($ovRoot . '/etc/exim4/conf.d/main', 0755, true);
@mkdir($ovRoot . '/etc/exim4/conf.d/acl', 0755, true);
$ovPol = $tmp . '/exim-ov-policy.json';
file_put_contents($ovPol, json_encode($full));
$tool = $root . '/kloxo/bin/mail/kloxo-mail';
$phpBin = KloxoMailPolicy::phpBinary();
$p = proc_open([$phpBin, $tool, 'policy:apply', '--mta=exim', '--policy=' . $ovPol, '--root=' . $ovRoot, '--no-reload'],
	[0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
$o = is_resource($p) ? (string) stream_get_contents($pipes[1]) : '';
$e = is_resource($p) ? (string) stream_get_contents($pipes[2]) : '';
if (is_resource($p)) {
	fclose($pipes[1]);
	fclose($pipes[2]);
}
$rc = is_resource($p) ? proc_close($p) : 1;
$ovMain = $ovRoot . '/etc/exim4/conf.d/main/01_kloxo-smtp-policy';
$ovAcl = $ovRoot . '/etc/exim4/conf.d/acl/00_kloxo-smtp-policy';
check($rc === 0 && is_file($ovMain) && is_file($ovAcl) && str_contains((string) file_get_contents($ovAcl), 'acl_kloxo_connect:'),
	'policy:apply --mta=exim without mail.ini writes the Debian overlay', $e . $o);
file_put_contents($ovPol, json_encode($off));
$p = proc_open([$phpBin, $tool, 'policy:apply', '--mta=exim', '--policy=' . $ovPol, '--root=' . $ovRoot, '--no-reload'],
	[0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (is_resource($p)) {
	stream_get_contents($pipes[1]);
	stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	proc_close($p);
}
check(!is_file($ovMain) && !is_file($ovAcl), 'policy off removes the Exim overlay files');
$mpSrc = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/kloxo_mailpolicy.php');
check(str_contains($mpSrc, 'function phpBinary') && str_contains($mpSrc, 'php-fpm')
	&& str_contains($mpSrc, "rl_exec_get(null, 'localhost', array('KloxoMailPolicy', 'apply')"),
	'panel apply uses php-cli (not php-fpm) and kloxo-backend when not root');

echo "== Panel hand-off\n";
$fakeRoot = $tmp . '/panel';
@mkdir($fakeRoot . '/bin/mail', 0755, true);
$fakeLog = $tmp . '/fake-tool.log';
$fakeFail = $tmp . '/fake-fail';
file_put_contents($fakeRoot . '/bin/mail/kloxo-mail', "<?php\nfile_put_contents('$fakeLog', implode(' ', array_slice(\$argv, 1)) . \"\\n\", FILE_APPEND);\nif (is_file('$fakeFail')) { fwrite(STDERR, \"error: forced failure\\n\"); exit(1); }\necho \"done\\n\";\n");
putenv('KLOXO_ROOT=' . $fakeRoot);
$polFile = $tmp . '/saved-policy.json';
putenv('KLOXO_MAIL_POLICY=' . $polFile);
$res = KloxoMailPolicy::apply(['enabled' => 'on', 'greet_delay' => '2', 'dns_blacklists' => 'zen.spamhaus.org'], 'exim');
$saved = json_decode((string) @file_get_contents($polFile), true);
check($res['ok'] && is_array($saved) && $saved['enabled'] === true && $saved['greet_delay'] === 2
	&& str_contains((string) @file_get_contents($fakeLog), 'policy:apply --mta=exim --policy=' . $polFile), 'apply() saves the validated policy and runs the tool for Exim');
check(KloxoMailPolicy::apply(['greet_delay' => 'x'], 'exim')['ok'] === false, 'apply() refuses an invalid policy before touching anything');
check(KloxoMailPolicy::apply([], 'qmail')['error'] === 'invalid_smtp_driver', 'apply() refuses an unknown MTA');
touch($fakeFail);
$before = (string) file_get_contents($polFile);
$res = KloxoMailPolicy::apply(['enabled' => 'on', 'greet_delay' => '9'], 'postfix');
check($res['ok'] === false && str_contains($res['error'], 'forced failure') && (string) file_get_contents($polFile) === $before,
	'a failing install puts the previous saved policy back');
unlink($fakeFail);
file_put_contents($fakeLog, '');
$res = KloxoMailPolicy::reapply('postfix');
check($res['ok'] && str_contains((string) file_get_contents($fakeLog), '--mta=postfix'), 'reapply() installs the saved policy for the newly selected MTA');
unlink($polFile);
file_put_contents($fakeLog, '');
$res = KloxoMailPolicy::reapply('postfix');
check($res['ok'] && trim((string) file_get_contents($fakeLog)) === '', 'reapply() without a saved policy does nothing');
check(KloxoMailPolicy::apply(['enabled' => 'on'], 'none')['ok'] && trim((string) file_get_contents($fakeLog)) === '', 'SMTP none: the policy is only saved');
$obj = new stdClass();
$obj->spamdyke_flag = 'on';
$obj->greet_delay = '3';
$obj->graylist_flag = 'off';
$obj->dns_blacklists = "zen.spamhaus.org bl.spamcop.net";
$obj->mail_graylist_wlist_a = ['192.0.2.9' => (object) ['nname' => '192.0.2.9']];
$raw = KloxoMailPolicy::fromServerMail($obj);
$np = KloxoMailPolicy::normalize($raw);
check($np['enabled'] && $np['greet_delay'] === 3 && !$np['graylist'] && $np['whitelist_ips'] === ['192.0.2.9']
	&& $np['max_rcpnts'] === 150 && $np['reject_missing_sender_mx'] === true && $np['dns_blacklists'] === ['zen.spamhaus.org', 'bl.spamcop.net'],
	'the Mail Server Settings object becomes a policy (unsaved fields use the form defaults)');

echo "== Shipped defaults\n";
$defRbl = KloxoMailPolicy::defaultFile('default_rbl.txt', $root . '/kloxo');
check(KloxoMailPolicy::parseRbl($defRbl) !== [] && str_contains($defRbl, 'zen.spamhaus.org')
	&& str_contains($defRbl, 'bl.spamcop.net')
	&& !str_contains($defRbl, 'dsbl') && !str_contains($defRbl, 'sorbs')
	&& !str_contains($defRbl, 'uceprotect'), 'default RBL list is Spamhaus + SpamCop (no UCEPROTECT/SORBS)');
$defHdr = KloxoMailPolicy::defaultFile('default_blacklist_headers.txt', $root . '/kloxo');
$defHdrRules = KloxoMailPolicy::parseHeaders($defHdr);
check($defHdrRules !== [] && str_contains($defHdr, 'Mass Mailer') && str_contains($defHdr, 'ADV:'),
	'default header list has a few low-noise spam tokens');
check(KloxoMailPolicy::parseRbl("# comment\nzen.spamhaus.org  # inline\nbl.spamcop.net")
	=== ['zen.spamhaus.org', 'bl.spamcop.net'], 'RBL comments are skipped');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
