<?php
/**
 * Quota counters are strings ('' / 'Unlimited' / '10'). PHP 8 throws
 * "Unsupported operand types: string + int" for '' + 1, which broke
 * Add Domain (Lxclass::changeUsedFromParent, check_priv, ...).
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
	array('', 0), array(null, 0), array('Unlimited', 0), array('abc', 0), array('7', 7),
	array(5, 5), array('2.5', 2.5), array('1 MB', 1024), array(' 3 ', 3), array(true, 1),
);
$allOk = true;
foreach ($cases as [$in, $want]) {
	$got = kloxo_quota_num($in);
	if ($got != $want || !(is_int($got) || is_float($got))) {
		$allOk = false;
		bad('kloxo_quota_num(' . var_export($in, true) . ')', var_export($got, true));
	}
}
if ($allOk) {
	ok('kloxo_quota_num turns every stored quota form into a number');
}

try {
	$qp = new Client(null, 'localhost', 'mesrob');
	$qp->nname = 'mesrob';
	$qp->used = new Used(null, null, 'mesrob');
	$qp->used->maindomain_num = '';
	$qp->dbaction = 'add';
	$qp->priv = new Priv(null, null, 'mesrob');
	$qp->priv->maindomain_num = '5';

	$child = new domain(null, 'localhost', 'mesrob.com');
	$child->nname = 'mesrob.com';
	$child->changeUsedFromParent($qp, 1);
	if ($qp->used->maindomain_num == 1) {
		ok('changeUsedFromParent adds 1 to an empty used counter');
	} else {
		bad('changeUsedFromParent adds 1 to an empty used counter', var_export($qp->used->maindomain_num, true));
	}
	$child->changeUsedFromParent($qp, -1);
	if ($qp->used->maindomain_num == 0) {
		ok('changeUsedFromParent subtracts again');
	} else {
		bad('changeUsedFromParent subtracts again', var_export($qp->used->maindomain_num, true));
	}
} catch (Throwable $e) {
	bad('changeUsedFromParent with an empty used counter', get_class($e) . ': ' . $e->getMessage());
}

try {
	$qp = new Client(null, 'localhost', 'mesrob');
	$qp->nname = 'mesrob';
	$qp->used = new Used(null, null, 'mesrob');
	$qp->priv = new Priv(null, null, 'mesrob');
	$qp->priv->disk_usage = '100';
	$qp->used->disk_usage = '';
	$eff = $qp->getEffectivePriv('disk_usage', 'client');
	if ($eff == 100 || $eff === 'Unlimited') {
		ok('getEffectivePriv does not TypeError on an empty used value');
	} else {
		bad('getEffectivePriv does not TypeError on an empty used value', var_export($eff, true));
	}
} catch (Throwable $e) {
	bad('getEffectivePriv with an empty used counter', get_class($e) . ': ' . $e->getMessage());
}

$src = (string)file_get_contents($doc . '/lib/html/objectactionlib.php')
	. (string)file_get_contents($doc . '/lib/php/lxclass.php')
	. (string)file_get_contents($doc . '/lib/client/clientbaselib.php');
if (preg_match('/->used->\$\w+\s*(\+=|-=)|used->\$\w+\s*\+\s*1\b/', $src)) {
	bad('no raw arithmetic on quota used counters remains');
} else {
	ok('no raw arithmetic on quota used counters remains');
}

// Client delete: parent used counters are often '' — must not TypeError.
try {
	$admin = new Client(null, 'localhost', 'admin');
	$admin->nname = 'admin';
	$admin->cttype = 'admin';
	$admin->dbaction = 'clean';
	$admin->used = new Used(null, null, 'admin');
	$admin->priv = new Priv(null, null, 'admin');
	$admin->used->client_num = '';
	$admin->priv->client_num = 'Unlimited';
	$admin->used->__parent_o = $admin;
	$admin->priv->__parent_o = $admin;

	$child = new Client(null, 'localhost', 'todel');
	$child->nname = 'todel';
	$child->cttype = 'customer';
	$child->dbaction = 'delete';
	$child->used = new Used(null, null, 'todel');
	$child->priv = new Priv(null, null, 'todel');
	$child->__parent_o = $admin;
	$child->changeUsedFromParent($admin, -1);
	if ((int) $admin->used->client_num === -1 || (int) $admin->used->client_num === 0) {
		ok('client delete changeUsedFromParent with empty used counter');
	} else {
		bad('client delete changeUsedFromParent with empty used counter', var_export($admin->used->client_num, true));
	}
} catch (Throwable $e) {
	bad('client delete changeUsedFromParent with empty used counter', get_class($e) . ': ' . $e->getMessage());
}

$lx = (string) file_get_contents($doc . '/lib/php/lxclass.php');
if (str_contains($lx, 'changeUsedFromParentAll after delete')
	&& str_contains($lx, 'catch (Throwable $e)')) {
	ok('writeEntireObject swallows quota TypeError after delete so toast stays success');
} else {
	bad('writeEntireObject swallows quota TypeError after delete so toast stays success');
}

$sync = (string) file_get_contents($doc . '/lib/client/client__synclib.php');
if (str_contains($sync, 'kloxo_quota_num($priv)')) {
	ok('client setQuota uses kloxo_quota_num (no string * int)');
} else {
	bad('client setQuota uses kloxo_quota_num (no string * int)');
}

$web = (string) file_get_contents($doc . '/driver/web/web__lib.php');
if (str_contains($web, '50000 + (int)')
	&& str_contains($web, "dbaction !== 'delete'")) {
	ok('web sync casts uid and skips static_config on delete');
} else {
	bad('web sync casts uid and skips static_config on delete');
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
