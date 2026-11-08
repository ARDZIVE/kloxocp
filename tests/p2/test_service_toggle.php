<?php
/**
 * Services page: the State / Sb icons must decide the command from the real
 * unit state (the service table has no state columns, so a freshly loaded
 * object used to default to 'off' and a click on a running unit sent `start`).
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

$root = dirname(__DIR__, 2) . '/kloxo';
$doc = $root . '/httpdocs';
putenv('KLOXO_ROOT=' . $root);
$_SERVER['DOCUMENT_ROOT'] = $doc;
chdir($doc);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

include_once $doc . '/lib/html/include.php';

$cases = array(
	// live state, toggle, expected state after click
	array('running unit, State click -> off (stop)', array('state' => 'on', 'boot_state' => 'on'), 'updateToggle_State', 'state', 'off'),
	array('stopped unit, State click -> on (start)', array('state' => 'off', 'boot_state' => 'on'), 'updateToggle_State', 'state', 'on'),
	array('enabled unit, Sb click -> off (disable)', array('state' => 'on', 'boot_state' => 'on'), 'updateToggle_boot_state', 'boot_state', 'off'),
	array('disabled unit, Sb click -> on (enable)', array('state' => 'on', 'boot_state' => 'off'), 'updateToggle_boot_state', 'boot_state', 'on'),
);

foreach ($cases as $c) {
	list($label, $live, $method, $prop, $want) = $c;
	Service::$liveStateProbe = static function ($svc) use ($live) {
		return $live;
	};
	$o = new Service(null, 'localhost', 'nginx___localhost');
	$o->servicename = 'nginx';
	$o->syncserver = 'localhost';
	$o->state = 'off';       // stale defaults, as for an object loaded for the click
	$o->boot_state = 'off';
	$o->install_state = 'on';
	$o->dbaction = 'clean';
	try {
		$o->$method(null);
	} catch (Throwable $e) {
		// the control step needs a real systemd/backend; only the decision is under test
	}
	if ($o->$prop === $want) {
		ok($label);
	} else {
		bad($label, $prop . '=' . var_export($o->$prop, true));
	}
}

Service::$liveStateProbe = static function ($svc) {
	return array('state' => 'on', 'boot_state' => 'off');
};
$p = new Service(null, 'localhost', 'x___localhost');
$p->servicename = 'nginx';
$p->syncserver = 'localhost';
$p->state = 'off';
$p->boot_state = 'on';
if ($p->refreshLiveState() && $p->state === 'on' && $p->boot_state === 'off' && $p->install_state === 'on') {
	ok('refreshLiveState copies ActiveState / UnitFileState');
} else {
	bad('refreshLiveState copies ActiveState / UnitFileState');
}
$p->syncserver = 'other';
$p->state = 'off';
if (!$p->refreshLiveState() && $p->state === 'off') {
	ok('refreshLiveState leaves remote servers alone');
} else {
	bad('refreshLiveState leaves remote servers alone');
}
Service::$liveStateProbe = null;

// A start that systemd accepted but the daemon then failed must say why.
$msg = Service__Linux::describeStartFailure('pdns', 'failed', "a\nUnable to bind UDP socket to '0.0.0.0:53': Address already in use\nFatal error: no backends\n");
if (str_contains($msg, 'pdns is failed after start')
	&& str_contains($msg, 'Address already in use') && str_contains($msg, 'no backends')) {
	ok('describeStartFailure carries the journal tail');
} else {
	bad('describeStartFailure carries the journal tail', $msg);
}
$msg = Service__Linux::describeStartFailure('pdns', 'inactive', '');
if (str_contains($msg, 'journalctl -u pdns')) {
	ok('describeStartFailure points at journalctl when the journal is empty');
} else {
	bad('describeStartFailure points at journalctl when the journal is empty', $msg);
}
$src = (string) file_get_contents($doc . '/driver/pserver/service__linuxlib.php');
// `ok` is derived from `rc` in controlUnit (systemctlRunDetail never sets it), so a
// start that failed afterwards (rc=1) reaches the lxException in Service::runDirect.
$detail = (string) substr($src, (int) strpos($src, 'static function systemctlRunDetail'), (int) strpos($src, 'static function controlUnit') - (int) strpos($src, 'static function systemctlRunDetail'));
if (!str_contains($detail, "'ok'")
	&& str_contains($src, "\$got['ok'] = ((int) \$got['rc'] === 0);")
	&& str_contains((string) file_get_contents($doc . '/driver/pserver/servicelib.php'), "empty(\$res['ok'])")) {
	ok('a failed start (rc=1) gives ok=false and is raised as an error');
} else {
	bad('a failed start (rc=1) gives ok=false and is raised as an error');
}
if (str_contains($src, "self::journalTail(\$unit)") && str_contains($src, "'auto-restart'")) {
	ok('systemctlRunDetail checks the unit is active after start/restart');
} else {
	bad('systemctlRunDetail checks the unit is active after start/restart');
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
