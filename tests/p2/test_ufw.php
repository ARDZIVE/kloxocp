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
	&& str_contains($ufwUi, 'class="if16"')
	&& str_contains($ufwUi, '&#xf47c;')
	&& str_contains($ufwUi, '&#xf572;')
	&& str_contains($ufwUi, '&#xf571;')
	&& str_contains($ufwUi, '&#xf0d2;')
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

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
