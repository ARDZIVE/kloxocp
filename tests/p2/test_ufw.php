<?php
/**
 * UFW parser and validation. php-cli only; does not run ufw.
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

$root = dirname(__DIR__, 2);
require $root . '/kloxo/httpdocs/lib/php/kloxo_ufw.php';

$sample = <<<'TXT'
Status: active
Logging: on (low)
Default: deny (incoming), allow (outgoing), disabled (routed)
New profiles: skip

To                         Action      From
--                         ------      ----
22/tcp                     ALLOW       Anywhere
7777/tcp                   ALLOW       Anywhere
TXT;
$numbered = <<<'TXT'
Status: active

     To                         Action      From
     --                         ------      ----
[ 1] 22/tcp                     ALLOW IN    Anywhere
[ 2] 7777/tcp                   ALLOW IN    Anywhere
[ 3] 80/tcp                     DENY IN     1.2.3.4
[ 4] 443/tcp (v6)               ALLOW IN    Anywhere (v6)              # https
TXT;

$st = KloxoUfw::parseStatus($sample, $numbered);
if ($st['active'] === true && $st['default_in'] === 'deny' && $st['default_out'] === 'allow'
	&& count($st['rules']) === 4 && $st['rules'][0]['number'] === 1
	&& $st['rules'][1]['to'] === '7777/tcp'
	&& $st['rules'][3]['to'] === '443/tcp'
	&& $st['rules'][3]['from'] === 'Anywhere'
	&& empty($st['rules'][0]['ipv6'])
	&& !empty($st['rules'][3]['ipv6'])
	&& ($st['rules'][3]['comment'] ?? '') === 'https') {
	ok('parseStatus reads active, defaults, numbered rules, and comments');
} else {
	bad('parseStatus reads active, defaults, numbered rules, and comments', var_export($st, true));
}

// Dual-stack: UFW repeats each allow for IPv6; UI collapses to one logical row.
$dual = <<<'TXT'
Status: active

     To                         Action      From
     --                         ------      ----
[ 1] 22/tcp                     ALLOW IN    Anywhere                   # ssh
[ 2] 22/tcp (v6)                ALLOW IN    Anywhere (v6)              # ssh
[ 3] 80/tcp                     DENY IN     1.2.3.4
TXT;
$dualRules = KloxoUfw::parseNumbered($dual);
$collapsed = KloxoUfw::collapseDualStack($dualRules);
if (count($dualRules) === 3
	&& count($collapsed) === 2
	&& $collapsed[0]['number'] === 1
	&& $collapsed[0]['numbers'] === array(1, 2)
	&& ($collapsed[0]['comment'] ?? '') === 'ssh'
	&& $collapsed[1]['number'] === 3
	&& $collapsed[1]['numbers'] === array(3)) {
	ok('collapseDualStack merges IPv4/IPv6 pair into one logical rule');
} else {
	bad('collapseDualStack merges IPv4/IPv6 pair into one logical rule',
		var_export(array($dualRules, $collapsed), true));
}

$ufwUi = (string) file_get_contents($root . '/kloxo/httpdocs/driver/pserver/ufwfirewalllib.php');
if (!str_contains($ufwUi, 'listaddform_ufwfirewall')
	&& !str_contains($ufwUi, 'Click Here to Add')
	&& str_contains($ufwUi, 'background:#fee')
	&& str_contains($ufwUi, 'Advanced Search')
	&& str_contains($ufwUi, 'Port Rules')
	&& !str_contains($ufwUi, "'Family'")
	&& !str_contains($ufwUi, '>Family<')
	&& str_contains($ufwUi, 'Comment')
	&& !str_contains($ufwUi, "'Actions'")
	&& !str_contains($ufwUi, ', \'Actions\'')
	&& str_contains($ufwUi, 'frm_ufw_filter')
	&& str_contains($ufwUi, 'editRuleUrl')
	&& str_contains($ufwUi, 'addRuleUrl')
	&& str_contains($ufwUi, 'a=updateform&sa=editrule')
	&& str_contains($ufwUi, 'a=updateform&sa=addrule')
	&& str_contains($ufwUi, 'Add Rule')
	&& str_contains($ufwUi, 'kloxoUfwDeleteSelected')
	&& str_contains($ufwUi, 'ufw-row-check')
	&& str_contains($ufwUi, 'suspendrule')
	&& str_contains($ufwUi, 'unsuspendrule')
	&& str_contains($ufwUi, "faIcon('pause'")
	&& str_contains($ufwUi, "faIcon('play'")
	&& str_contains($ufwUi, "faIcon('pen-to-square'")
	&& str_contains($ufwUi, "faIcon('trash'")
	&& str_contains($ufwUi, 'text-align:center;width:28px')
	&& str_contains($ufwUi, 'width:72px;max-width:72px')
	&& !str_contains($ufwUi, 'updateform_edit.gif')
	&& !str_contains($ufwUi, 'delete.gif')
	&& str_contains($ufwUi, 'function updateEditrule')
	&& str_contains($ufwUi, 'function updateSuspendrule')
	&& str_contains($ufwUi, 'collist')
	&& str_contains($ufwUi, 'filterRules')
	&& str_contains($ufwUi, 'icmp')
	&& !str_contains($ufwUi, 'Port: single')
	&& !str_contains($ufwUi, 'Blank From = anywhere')
	&& !str_contains($ufwUi, 'Save Defaults')
	&& !str_contains($ufwUi, "printActionForm('ufwenable'")
	&& !str_contains($ufwUi, 'frm_ufw_edit')) {
	ok('UFW list: Add Rule page, Comment, no Family, multi-delete, if16 icons');
} else {
	bad('UFW list: Add Rule page, Comment, no Family, multi-delete, if16 icons');
}

$src = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/kloxo_ufw.php');
if (method_exists('KloxoUfw', 'editRule')
	&& method_exists('KloxoUfw', 'collapseDualStack')
	&& method_exists('KloxoUfw', 'suspendRule')
	&& method_exists('KloxoUfw', 'unsuspendRule')
	&& method_exists('KloxoUfw', 'deleteRules')
	&& str_contains($src, 'ufw_editrule_failed')
	&& str_contains($src, 'validateComment')) {
	ok('KloxoUfw has edit/collapse/suspend/deleteRules/comment');
} else {
	bad('KloxoUfw has edit/collapse/suspend/deleteRules/comment');
}

$nft = KloxoUfw::parseIptablesVersion('iptables v1.8.10 (nf_tables)');
$leg = KloxoUfw::parseIptablesVersion('iptables v1.8.7 (legacy)');
$envShape = method_exists('KloxoUfw', 'environment');
if ($nft['id'] === 'nftables' && str_contains($nft['label'], 'nftables')
	&& $leg['id'] === 'iptables-legacy'
	&& $envShape
	&& str_contains($src, 'function environment')
	&& str_contains($src, 'firewalld')
	&& !str_contains($ufwUi, 'Managed:')
	&& !str_contains($ufwUi, 'Backend:')) {
	ok('UFW environment helpers; no Managed/Backend line on list');
} else {
	bad('UFW environment helpers; no Managed/Backend line on list',
		var_export(array($nft, $leg), true));
}

$fakeRules = array(
	array('number' => 1, 'to' => '22/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere', 'ipv6' => false, 'comment' => 'ssh'),
	array('number' => 2, 'to' => '80/tcp', 'action' => 'DENY IN', 'from' => '1.2.3.4', 'ipv6' => false, 'comment' => ''),
	array('number' => 3, 'to' => '443/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere', 'ipv6' => false, 'comment' => 'web'),
);
if (!class_exists('lxclass', false)) {
	class lxclass
	{
		function __construct($a = null, $b = null, $c = null) {}
	}
}
require_once $root . '/kloxo/httpdocs/driver/pserver/ufwfirewalllib.php';
$ufwProbe = new ufwfirewall(null, 'localhost', 'localhost');
$filtTcp = $ufwProbe->filterRules($fakeRules, array(
	'q' => '', 'number' => '', 'action' => '', 'proto' => 'TCP', 'port' => '', 'from' => '', 'comment' => '',
));
$filtPort = $ufwProbe->filterRules($fakeRules, array(
	'q' => '', 'number' => '', 'action' => 'DENY', 'proto' => '', 'port' => '80', 'from' => '', 'comment' => '',
));
$filtQ = $ufwProbe->filterRules($fakeRules, array(
	'q' => '443', 'number' => '', 'action' => '', 'proto' => '', 'port' => '', 'from' => '', 'comment' => '',
));
$filtC = $ufwProbe->filterRules($fakeRules, array(
	'q' => '', 'number' => '', 'action' => '', 'proto' => '', 'port' => '', 'from' => '', 'comment' => 'ssh',
));
if (count($filtTcp) === 3
	&& count($filtPort) === 1 && (int) $filtPort[0]['number'] === 2
	&& count($filtQ) === 1 && (int) $filtQ[0]['number'] === 3
	&& count($filtC) === 1 && (int) $filtC[0]['number'] === 1) {
	ok('ufwfirewall::filterRules matches Action/Protocol/Port/Comment/q');
} else {
	bad('ufwfirewall::filterRules matches Action/Protocol/Port/Comment/q',
		'tcp=' . count($filtTcp) . ' port=' . count($filtPort)
			. ' q=' . count($filtQ) . ' c=' . count($filtC));
}

$off = KloxoUfw::parseStatus("Status: inactive\nDefault: deny (incoming), allow (outgoing)");
if ($off['active'] === false && $off['rules'] === []) {
	ok('parseStatus treats inactive as no rules');
} else {
	bad('parseStatus treats inactive as no rules', var_export($off, true));
}

try {
	$argv = KloxoUfw::addRuleArgv('allow', 'tcp', '443', '', 'https');
	if ($argv === ['allow', '443/tcp', 'comment', 'https']) {
		ok('addRuleArgv allow 443/tcp with comment');
	} else {
		bad('addRuleArgv allow 443/tcp with comment', var_export($argv, true));
	}
} catch (Throwable $e) {
	bad('addRuleArgv allow 443/tcp with comment', $e->getMessage());
}

try {
	$argv = KloxoUfw::addRuleArgv('allow', 'icmp', '', '', 'ping');
	if ($argv === ['allow', 'proto', 'icmp', 'comment', 'ping']) {
		ok('addRuleArgv allow icmp with comment');
	} else {
		bad('addRuleArgv allow icmp with comment', var_export($argv, true));
	}
} catch (Throwable $e) {
	bad('addRuleArgv allow icmp with comment', $e->getMessage());
}

try {
	$argv = KloxoUfw::addRuleArgv('allow', 'tcp', '80', '10.0.0.1/24');
	if ($argv === ['allow', 'from', '10.0.0.1/24', 'to', 'any', 'port', '80', 'proto', 'tcp']) {
		ok('addRuleArgv allow from CIDR');
	} else {
		bad('addRuleArgv allow from CIDR', var_export($argv, true));
	}
} catch (Throwable $e) {
	bad('addRuleArgv allow from CIDR', $e->getMessage());
}

$denyPanel = false;
$rejectPanel = false;
try {
	KloxoUfw::addRuleArgv('deny', 'tcp', '7777', '');
} catch (InvalidArgumentException $e) {
	$denyPanel = $e->getMessage() === 'cannot_deny_panel_port';
}
try {
	KloxoUfw::addRuleArgv('reject', 'tcp', '7778', '');
} catch (InvalidArgumentException $e) {
	$rejectPanel = $e->getMessage() === 'cannot_deny_panel_port';
}
$rejectArgv = null;
try {
	$rejectArgv = KloxoUfw::addRuleArgv('reject', 'tcp', '25', '');
} catch (Throwable $e) {
	$rejectArgv = null;
}
if ($denyPanel && $rejectPanel && $rejectArgv === array('reject', '25/tcp')) {
	ok('deny/reject of panel ports refused; reject other ports allowed');
} else {
	bad('deny/reject of panel ports refused; reject other ports allowed');
}

$badPort = false;
try {
	KloxoUfw::validatePort('70000');
} catch (InvalidArgumentException $e) {
	$badPort = $e->getMessage() === 'invalid_ufw_port';
}
$badFrom = false;
try {
	KloxoUfw::validateFrom('10.0.0.1; rm -rf /');
} catch (InvalidArgumentException $e) {
	$badFrom = $e->getMessage() === 'invalid_ufw_from';
}
$badAct = false;
try {
	KloxoUfw::validateAction('reset');
} catch (InvalidArgumentException $e) {
	$badAct = $e->getMessage() === 'invalid_ufw_action';
}
$badDef = false;
try {
	KloxoUfw::validateDefaultPolicy('drop');
} catch (InvalidArgumentException $e) {
	$badDef = $e->getMessage() === 'invalid_ufw_default';
}
$badComment = false;
try {
	KloxoUfw::validateComment("bad;rm");
} catch (InvalidArgumentException $e) {
	$badComment = $e->getMessage() === 'invalid_ufw_comment';
}
$splitOk = false;
try {
	$splitOk = KloxoUfw::splitPorts('80,443,8080') === array('80', '443', '8080')
		&& KloxoUfw::validatePort('80-82') === '80:82'
		&& KloxoUfw::parseRuleTo('443/tcp') === array('port' => '443', 'proto' => 'tcp')
		&& KloxoUfw::parseRuleTo('icmp') === array('port' => '', 'proto' => 'icmp');
} catch (Throwable $e) {
	$splitOk = false;
}
if ($badPort && $badFrom && $badAct && $badDef && $badComment && $splitOk
	&& KloxoUfw::validatePort('80:90') === '80:90'
	&& KloxoUfw::validateFrom('') === '' && KloxoUfw::validateFrom('any') === ''
	&& KloxoUfw::validateDefaultPolicy('deny') === 'deny'
	&& KloxoUfw::validateDefaultPolicy('allow') === 'allow'
	&& KloxoUfw::validateDefaultPolicy('reject') === 'reject'
	&& KloxoUfw::validateComment('ok note') === 'ok note') {
	ok('validation rejects bad port, from, action, comment, and default policy');
} else {
	bad('validation rejects bad port, from, action, comment, and default policy');
}

$pairPanel = array(
	array('number' => 1, 'to' => '7777/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere', 'ipv6' => false, 'comment' => ''),
	array('number' => 2, 'to' => '7777/tcp', 'action' => 'ALLOW IN', 'from' => 'Anywhere', 'ipv6' => true, 'comment' => ''),
	array('number' => 3, 'to' => '80/tcp', 'action' => 'DENY IN', 'from' => '1.2.3.4', 'ipv6' => false, 'comment' => ''),
);
if (KloxoUfw::canDeleteRule($st['rules'], 3) === true
	&& KloxoUfw::canDeleteRule($st['rules'], 2) === false
	&& KloxoUfw::canDeleteRule($st['rules'], 9) === false
	&& KloxoUfw::canDeleteNumbers($pairPanel, array(1, 2)) === false
	&& KloxoUfw::canDeleteNumbers($pairPanel, array(3)) === true
	&& in_array(7777, KloxoUfw::protectedTcpPorts(), true)
	&& in_array(7778, KloxoUfw::protectedTcpPorts(), true)
	&& in_array(8080, KloxoUfw::protectedTcpPorts(), true)
	&& in_array(110, KloxoUfw::protectedTcpPorts(), true)
	&& KloxoUfw::isPanelPort(7777) && !KloxoUfw::isPanelPort(22)
	&& method_exists('KloxoUfw', 'ensureProtectedPorts')) {
	ok('last allow for panel 7777 cannot be deleted (incl. dual-stack pair)');
} else {
	bad('last allow for panel 7777 cannot be deleted (incl. dual-stack pair)');
}

$src = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/kloxo_ufw.php');
$inc = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/include.php');
if (str_contains($src, 'declare(strict_types=1)')
	&& str_contains($src, 'proc_open')
	&& !preg_match('/\bshell_exec\b|\bsystem\s*\(|\bexec\s*\(|`ufw /', $src)
	&& str_contains($src, '--force')
	&& str_contains($src, '7777')
	&& str_contains($src, 'function setDefaults')
	&& str_contains($inc, 'kloxo_ufw.php')) {
	ok('KloxoUfw uses argument-array proc_open and is loaded for the backend');
} else {
	bad('KloxoUfw uses argument-array proc_open and is loaded for the backend');
}

// ---- add / edit / suspended edit against a fake ufw binary -------------------
$tmp = sys_get_temp_dir() . '/kloxo-ufw-test-' . getmypid();
@mkdir($tmp, 0700, true);
putenv('FAKE_UFW_STATE=' . $tmp . '/state.json');
$fake = $tmp . '/ufw';
copy(__DIR__ . '/fake-ufw.php', $fake);
chmod($fake, 0700);
define('KLOXO_UFW_TEST_BIN', $fake);
$sgbl = new stdClass();
$sgbl->__path_program_etc = $tmp;
$fakeRules = static function (): array {
	return KloxoUfw::collapseDualStack(KloxoUfw::status()['rules']);
};
$find = static function (string $to) use ($fakeRules): ?array {
	foreach ($fakeRules() as $r) {
		if ($r['to'] === $to) {
			return $r;
		}
	}

	return null;
};
$e2e = true;
$why = '';
try {
	// add: every field
	$a = KloxoUfw::addRule('allow', 'tcp', '7777');
	$a = KloxoUfw::addRule('allow', 'tcp', '2222', '', 'ssh alt');
	$a = KloxoUfw::addRule('deny', 'udp', '5353', '10.1.2.3', 'mdns');
	$a = KloxoUfw::addRule('reject', 'icmp', '', '', 'no ping');
	$r1 = $find('2222/tcp');
	$r2 = $find('5353/udp');
	$r3 = $find('icmp');
	if (!$r1 || count($r1['numbers']) !== 2 || $r1['comment'] !== 'ssh alt') {
		throw new RuntimeException('add allow dual-stack rule');
	}
	if (!$r2 || count($r2['numbers']) !== 1 || $r2['from'] !== '10.1.2.3' || stripos($r2['action'], 'DENY') === false) {
		throw new RuntimeException('add deny from-ip rule');
	}
	if (!$r3 || stripos($r3['action'], 'REJECT') === false) {
		throw new RuntimeException('add icmp reject rule');
	}
	// edit: change action, proto, port, from and comment at once
	$ed = KloxoUfw::editRule((string) $r1['number'], 'deny', 'udp', '2200', '192.168.0.0/24', 'moved');
	if (empty($ed['ok'])) {
		throw new RuntimeException('edit failed: ' . json_encode($ed));
	}
	$n = $find('2200/udp');
	if ($find('2222/tcp') !== null || !$n || $n['from'] !== '192.168.0.0/24' || $n['comment'] !== 'moved'
		|| stripos($n['action'], 'DENY') === false) {
		throw new RuntimeException('edit did not apply all fields to the firewall');
	}
	// edit: port list becomes one rule per port
	$ed = KloxoUfw::editRule((string) $find('5353/udp')['number'], 'allow', 'tcp', '80,443', '', 'web');
	if (empty($ed['ok']) || !$find('80/tcp') || !$find('443/tcp') || $find('5353/udp') !== null) {
		throw new RuntimeException('edit with port list');
	}
	// edit: icmp <-> port
	$ed = KloxoUfw::editRule((string) $find('icmp')['number'], 'allow', 'tcp', '8080', '', '');
	if (empty($ed['ok']) || !$find('8080/tcp') || $find('icmp') !== null) {
		throw new RuntimeException('edit icmp to tcp');
	}
	// failed edit restores the original rule
	$before = $find('8080/tcp');
	$ed = KloxoUfw::editRule((string) $before['number'], 'allow', 'tcp', '9999', '', '');
	if (!empty($ed['ok']) || !$find('8080/tcp')) {
		throw new RuntimeException('failed edit must restore the original');
	}
	// panel port protection on edit
	$bad = false;
	try {
		KloxoUfw::editRule((string) $find('7777/tcp')['number'], 'allow', 'tcp', '7000', '', '');
	} catch (InvalidArgumentException $e) {
		$bad = $e->getMessage() === 'cannot_delete_panel_port';
	}
	if (!$bad) {
		throw new RuntimeException('last panel allow must not be moved off the panel port');
	}
	$ed = KloxoUfw::editRule((string) $find('7777/tcp')['number'], 'allow', 'tcp', '7777', '', 'panel');
	if (empty($ed['ok']) || ($find('7777/tcp')['comment'] ?? '') !== 'panel') {
		throw new RuntimeException('panel rule comment edit');
	}
	// IPv6 source
	if (KloxoUfw::validateFrom('2001:db8::1') !== '2001:db8::1' || KloxoUfw::validateFrom('2001:DB8::/32') !== '2001:db8::/32') {
		throw new RuntimeException('ipv6 from');
	}
	// suspended rule: edit keeps it out of the firewall
	$sus = KloxoUfw::suspendRule((string) $find('80/tcp')['number']);
	$sid = '';
	foreach (KloxoUfw::listSuspended() as $s) {
		$sid = $s['id'];
	}
	if (empty($sus['ok']) || $sid === '' || $find('80/tcp') !== null) {
		throw new RuntimeException('suspend');
	}
	$es = KloxoUfw::editSuspended($sid, 'deny', 'udp', '81,82', '1.1.1.1', 'susp');
	if (empty($es['ok']) || count(KloxoUfw::listSuspended()) !== 2 || $find('81/udp') !== null) {
		throw new RuntimeException('edit suspended');
	}
	$un = KloxoUfw::unsuspendRule($sid);
	if (empty($un['ok']) || !$find('81/udp') || $find('81/udp')['from'] !== '1.1.1.1') {
		throw new RuntimeException('unsuspend after edit');
	}
} catch (Throwable $e) {
	$e2e = false;
	$why = $e->getMessage();
}
if ($e2e) {
	ok('add/edit/suspended-edit apply every field to the firewall (fake ufw), failed edit rolls back');
} else {
	bad('add/edit/suspended-edit apply every field to the firewall (fake ufw)', $why);
}
foreach (glob($tmp . '/*') ?: [] as $f) {
	@unlink($f);
}
@rmdir($tmp);

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
