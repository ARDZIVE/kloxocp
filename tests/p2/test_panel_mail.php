<?php
/**
 * Panel → client mail (welcome / tickets / wall): source invariants.
 * Does not talk to a live sendmail.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$doc = $root . '/kloxo/httpdocs';
$fail = 0;

function ok(string $m): void
{
	echo "  ok   {$m}\n";
}

function bad(string $m): void
{
	global $fail;
	$fail++;
	echo "  FAIL {$m}\n";
}

function check(bool $c, string $m): void
{
	$c ? ok($m) : bad($m);
}

$lib = (string) file_get_contents($doc . '/lib/html/lib.php');
$bg = (string) file_get_contents($root . '/kloxo/bin/common/background.php');
$client = (string) file_get_contents($doc . '/lib/client/lxclient.php');
$base = (string) file_get_contents($doc . '/lib/client/clientbaselib.php');

check(str_contains($lib, 'function kloxo_mail_recipients')
	&& str_contains($lib, 'function kloxo_mail_sendmail_once')
	&& str_contains($lib, 'function kloxo_mail_default_from')
	&& str_contains($lib, 'function kloxo_mail_sendmail_proc_opts')
	&& str_contains($lib, 'function lx_mail')
	&& str_contains($lib, "'-odq'")
	&& str_contains($lib, 'foreach ($recipients as $addr)'),
	'lx_mail queues via sendmail -odq and splits recipients');

check(str_contains($lib, 'function kloxo_mail_from_ok')
	&& str_contains($lib, 'function kloxo_mail_from_host_candidates')
	&& str_contains($lib, 'kloxo_mail_default_from')
	&& str_contains($lib, '/etc/mailname')
	&& str_contains($lib, 'FILTER_VALIDATE_IP')
	&& str_contains($lib, 'admin@localhost')
	&& str_contains($lib, "class_exists('Sqlite')"),
	'default From prefers /etc/mailname FQDN; Sqlite optional');

// Standalone copy of kloxo_mail_from_ok (mirrors lib.php).
function test_kloxo_mail_from_ok(string $from): bool
{
	$from = trim($from);

	return $from !== '' && (bool) preg_match('/^[^@\s]+@[^@\s]+$/', $from)
		&& !filter_var(substr(strrchr($from, '@') ?: '', 1), FILTER_VALIDATE_IP);
}

check(test_kloxo_mail_from_ok('admin@srv') && test_kloxo_mail_from_ok('admin@example.com')
	&& !test_kloxo_mail_from_ok('admin@1.2.3.4') && !test_kloxo_mail_from_ok(''),
	'kloxo_mail_from_ok accepts hostnames, rejects IP From');

check(str_contains($lib, "posix_geteuid()")
	&& str_contains($lib, "'lxlabs'")
	&& str_contains($lib, 'kloxo_mail_sendmail_proc_opts()'),
	'sendmail drops root to lxlabs (Exim never_users=root)');

$exim = (string) file_get_contents($root . '/kloxo/file/mail/exim/exim4.conf');
check(str_contains($exim, 'trusted_users = lxlabs')
	&& !str_contains($exim, 'untrusted_set_sender')
	&& str_contains($exim, 'never_users = root'),
	'Exim trusts lxlabs for panel -f From');

check(str_contains($exim, "quote_mysql:\$domain")
	&& !str_contains($exim, "quote_mysql:\$key"),
	'Exim domainlist uses $domain not $key (4.99)');

$cin = '';
if (preg_match('/function callInBackground\(.*?^function /sm', $lib, $m)) {
	$cin = $m[0];
}
check($cin !== ''
	&& str_contains($cin, "bin/common/background.php")
	&& str_contains($cin, "\$root . '/pid'")
	&& !str_contains($cin, '../bin/common/background.php')
	&& !str_contains($cin, 'tempnam("/tmp"'),
	'callInBackground uses absolute background.php; job file under program pid/');

check(str_contains($lib, "callInBackground(\"lx_mail\", array(null, \$email")
	&& str_contains($lib, 'admin contactemail empty'),
	'send_mail_to_admin uses null From and guards empty contactemail');

check(str_contains($bg, 'dirname(__DIR__, 2)')
	&& str_contains($bg, '/httpdocs')
	&& str_contains($bg, 'declare(strict_types=1)')
	&& !preg_match('/^include_once\s+"lib\/html\/include\.php"/m', $bg),
	'background.php bootstraps from __DIR__/httpdocs, not FPM cwd');

check(str_contains($client, 'lx_mail($from, $obj->contactemail')
	&& str_contains($client, 'Welcome email could not be queued')
	&& str_contains($client, 'defaultWelcomeMessage')
	&& !str_contains($client, 'callInBackground("lx_mail", array($from, $obj->contactemail'),
	'sendNotification calls lx_mail directly and warns on queue failure');

$kcp = (string) file_get_contents($root . '/kloxo/bin/kloxocp');
check(str_contains($kcp, "'test-mail'")
	&& str_contains($kcp, 'function testMail')
	&& str_contains($kcp, 'lx_mail'),
	'kloxocp test-mail queues via lx_mail');

check(str_contains($kcp, 'example.com')
	&& str_contains($kcp, 'documentation/placeholder'),
	'test-mail rejects you@example.com placeholder');

check(str_contains($base, "\$subject = (string) (\$param['wall_subject_f'] ?? '')"),
	'updateWall always sets subject (PHP 8)');

check(is_file($root . '/kloxo/bin/common/background.php'),
	'background.php is shipped under bin/common');

// Standalone copy of the recipient splitter (mirrors lib.php) for behaviour.
function test_kloxo_mail_recipients($to): array
{
	if (is_array($to)) {
		$parts = $to;
	} else {
		$parts = preg_split('/[\s,;]+/', (string) $to) ?: array();
	}
	$out = array();
	foreach ($parts as $p) {
		$p = trim((string) $p);
		if ($p === '' || !preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $p)) {
			continue;
		}
		$out[$p] = $p;
	}

	return array_values($out);
}

check(test_kloxo_mail_recipients('a@example.com, b@example.com;bad; c@example.org')
	=== array('a@example.com', 'b@example.com', 'c@example.org'),
	'recipient splitter keeps valid comma/semicolon addresses');
check(test_kloxo_mail_recipients('not-an-email') === array(),
	'recipient splitter drops invalid addresses');

echo ($fail === 0 ? "all passed\n" : "{$fail} failed\n");
exit($fail === 0 ? 0 : 1);
