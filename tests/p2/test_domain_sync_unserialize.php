<?php
declare(strict_types=1);

/**
 * Add Domain syncs domain + mmail with driverApp = LxaClass (no Class__driver
 * yet). The backend allow-list used to reject LxaClass → payload unserialize
 * failed → toast got_error_from_ur_end and no domain row.
 *
 * php-cli only; no live kloxo-backend.
 */

$root = dirname(__DIR__, 2);
$doc = $root . '/kloxo/httpdocs';
chdir($doc);

require_once $doc . '/lib/html/include.php';

$pass = 0;
$fail = 0;
function check(bool $ok, string $msg): void
{
	global $pass, $fail;
	if ($ok) {
		$pass++;
		echo "  ok   {$msg}\n";
	} else {
		$fail++;
		echo "  FAIL {$msg}\n";
	}
}

check(function_exists('kloxo_unserialize_panel_class_ok'), 'panel class helper loaded');
check(kloxo_unserialize_panel_class_ok('LxaClass'), 'LxaClass allowed (domain/mmail sync fallback)');
check(kloxo_unserialize_panel_class_ok('Lxdriverclass'), 'Lxdriverclass allowed');
check(kloxo_unserialize_panel_class_ok('Used'), 'Used allowed (declared casing; Add Domain quota)');
check(kloxo_unserialize_panel_class_ok('used'), 'used allowed case-insensitively');
check(kloxo_unserialize_panel_class_ok('mmail__exim'), 'mmail__exim allowed via Class__driver + mmail in gl');
check(kloxo_unserialize_panel_class_ok('web__nginx'), 'web__nginx still allowed');
check(!kloxo_unserialize_panel_class_ok('Phar'), 'Phar still refused');
check(!kloxo_unserialize_panel_class_ok('evilgadget'), 'unknown lowercase still refused');
check(!kloxo_unserialize_panel_class_ok('Evil\\Gadget'), 'namespaced still refused');

global $gbl, $login;
$login = new Client(null, null, 'admin');
$login->cttype = 'admin';
if (!is_object($gbl)) {
	$gbl = new Gbllib();
}

$cases = array(
	'domain' => new domain(null, 'localhost', 'testdomain.example'),
	'mmail' => new mmail(null, 'localhost', 'testdomain.example'),
	'web' => new web(null, 'localhost', 'testdomain.example'),
);

foreach ($cases as $label => $obj) {
	$obj->dbaction = 'add';
	$obj->syncserver = 'localhost';
	$obj->__masterserver = 'localhost';
	// Real Add Domain always carries quota objects; Used was the second reject.
	if (class_exists('priv', false) || class_exists('priv', true)) {
		$obj->priv = new priv(null, null, $obj->nname);
	}
	if (class_exists('Used', false) || class_exists('Used', true)) {
		$obj->used = new Used(null, null, $obj->nname);
	}
	$obj->createSyncClass();
	$clo = myclone($obj);
	lxclass::clearChildrenAndParent($clo);
	$clo->syncserver = 'localhost';
	$clo->createSyncClass();
	$rmt = new Remote();
	$rmt->action = 'set';
	$rmt->robject = $clo;
	$ser = serialize($rmt);
	$back = kloxo_unserialize_deny_gadgets($ser);
	check(is_object($back) && $back instanceof Remote,
		"{$label} Remote with priv/Used/driverApp unserializes (Add Domain sync)");
}

echo $fail === 0 ? "{$pass} passed, 0 failed\n" : "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
