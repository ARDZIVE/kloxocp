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
[ 4] 443/tcp (v6)               ALLOW IN    Anywhere (v6)
TXT;

$st = KloxoUfw::parseStatus($sample, $numbered);
if ($st['active'] === true && $st['default_in'] === 'deny' && $st['default_out'] === 'allow'
	&& count($st['rules']) === 4 && $st['rules'][0]['number'] === 1
	&& $st['rules'][1]['to'] === '7777/tcp'
	&& $st['rules'][3]['to'] === '443/tcp'
	&& $st['rules'][3]['from'] === 'Anywhere') {
	ok('parseStatus reads active, defaults, and numbered rules');
} else {
	bad('parseStatus reads active, defaults, and numbered rules', var_export($st, true));
}

$off = KloxoUfw::parseStatus("Status: inactive\nDefault: deny (incoming), allow (outgoing)");
if ($off['active'] === false && $off['rules'] === []) {
	ok('parseStatus treats inactive as no rules');
} else {
	bad('parseStatus treats inactive as no rules', var_export($off, true));
}

try {
	$argv = KloxoUfw::addRuleArgv('allow', 'tcp', '443', '');
	if ($argv === ['allow', '443/tcp']) {
		ok('addRuleArgv allow 443/tcp');
	} else {
		bad('addRuleArgv allow 443/tcp', var_export($argv, true));
	}
} catch (Throwable $e) {
	bad('addRuleArgv allow 443/tcp', $e->getMessage());
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
$splitOk = false;
try {
	$splitOk = KloxoUfw::splitPorts('80,443,8080') === array('80', '443', '8080')
		&& KloxoUfw::validatePort('80-82') === '80:82'
		&& KloxoUfw::parseRuleTo('443/tcp') === array('port' => '443', 'proto' => 'tcp');
} catch (Throwable $e) {
	$splitOk = false;
}
if ($badPort && $badFrom && $badAct && $badDef && $splitOk
	&& KloxoUfw::validatePort('80:90') === '80:90'
	&& KloxoUfw::validateFrom('') === '' && KloxoUfw::validateFrom('any') === ''
	&& KloxoUfw::validateDefaultPolicy('deny') === 'deny'
	&& KloxoUfw::validateDefaultPolicy('allow') === 'allow'
	&& KloxoUfw::validateDefaultPolicy('reject') === 'reject') {
	ok('validation rejects bad port, from, action, and default policy');
} else {
	bad('validation rejects bad port, from, action, and default policy');
}

if (KloxoUfw::canDeleteRule($st['rules'], 3) === true
	&& KloxoUfw::canDeleteRule($st['rules'], 2) === false
	&& KloxoUfw::canDeleteRule($st['rules'], 9) === false
	&& in_array(7777, KloxoUfw::protectedTcpPorts(), true)
	&& in_array(7778, KloxoUfw::protectedTcpPorts(), true)
	&& in_array(8080, KloxoUfw::protectedTcpPorts(), true)
	&& in_array(110, KloxoUfw::protectedTcpPorts(), true)
	&& KloxoUfw::isPanelPort(7777) && !KloxoUfw::isPanelPort(22)
	&& method_exists('KloxoUfw', 'ensureProtectedPorts')) {
	ok('last allow for panel 7777 cannot be deleted; protected ports cover hosting stack');
} else {
	bad('last allow for panel 7777 cannot be deleted; protected ports cover hosting stack');
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
