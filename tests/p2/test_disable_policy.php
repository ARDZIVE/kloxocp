<?php
/**
 * Disable Policy: General Disable Percentage (default 110) plus per-account
 * Disable When Usage Reaches. Fake objects only. NOT a live collectquota run.
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

$login = new class {
	public function getObject($n)
	{
		$g = new stdClass();
		$g->generalmisc_b = new stdClass();
		$g->generalmisc_b->dpercentage = '110';
		$g->generalmisc_b->npercentage = '90';

		return $g;
	}
};
$GLOBALS['login'] = $login;

if (kloxo_disable_policy_default() === 110 && kloxo_notify_policy_percent() === 90) {
	ok('General Disable Policy defaults to 110 and Notify Policy to 90');
} else {
	bad('General Disable Policy defaults to 110 and Notify Policy to 90',
		(string) kloxo_disable_policy_default() . '/' . (string) kloxo_notify_policy_percent());
}

$acct = new stdClass();
$acct->disable_per = '';
if (kloxo_disable_policy_percent($acct) === 110) {
	ok('an account with no Disable When Usage Reaches uses General Disable Percentage');
} else {
	bad('an account with no Disable When Usage Reaches uses General Disable Percentage',
		(string) kloxo_disable_policy_percent($acct));
}

$acct->disable_per = 'off';
if (kloxo_disable_policy_percent($acct) === null) {
	ok('Disable When Usage Reaches off turns auto-disable off');
} else {
	bad('Disable When Usage Reaches off turns auto-disable off');
}

$acct->disable_per = '100';
if (kloxo_disable_policy_percent($acct) === 100) {
	ok('a per-account percentage overrides General Disable Percentage');
} else {
	bad('a per-account percentage overrides General Disable Percentage');
}

$over = kloxo_quota_policy_check($acct, '111', '100');
if ($over['disable'] === true && $over['warn'] === true && (int) round((float) $over['percent']) === 111) {
	ok('111% of a 100 limit disables at 100 and warns at 90');
} else {
	bad('111% of a 100 limit disables at 100 and warns at 90', var_export($over, true));
}

$acct->disable_per = '';
$under = kloxo_quota_policy_check($acct, '109', '100');
if ($under['disable'] === false && $under['warn'] === true) {
	ok('109% with General 110 warns but does not disable');
} else {
	bad('109% with General 110 warns but does not disable', var_export($under, true));
}

$hit = kloxo_quota_policy_check($acct, '110', '100');
if ($hit['disable'] === true) {
	ok('usage that reaches Disable Percentage is disabled (>=, not only >)');
} else {
	bad('usage that reaches Disable Percentage is disabled (>=, not only >)', var_export($hit, true));
}

$unl = kloxo_quota_policy_check($acct, '999', 'Unlimited');
if ($unl['percent'] === null && $unl['disable'] === false) {
	ok('Unlimited quota is never auto-disabled');
} else {
	bad('Unlimited quota is never auto-disabled', var_export($unl, true));
}

$empty = kloxo_quota_policy_check($acct, '', '');
if ($empty['percent'] === null && $empty['disable'] === false) {
	ok('empty string quota counters do not TypeError or disable');
} else {
	bad('empty string quota counters do not TypeError or disable', var_export($empty, true));
}

$threw = false;
try {
	is_unlimited(null);
	is_unlimited(110);
	is_unlimited(array());
} catch (Throwable $e) {
	$threw = true;
}
if (!$threw && is_unlimited('Unlimited') && !is_unlimited(null) && !is_unlimited(110)) {
	ok('is_unlimited accepts non-strings');
} else {
	bad('is_unlimited accepts non-strings');
}

$src = (string) file_get_contents($doc . '/lib/php/lxclass.php');
$cli = (string) file_get_contents($doc . '/lib/client/lxclient.php');
$cq = (string) file_get_contents($root . '/bin/collectquota.php');
if (str_contains($src, 'kloxo_quota_policy_check')
	&& str_contains($src, 'kloxo_quota_notify_suppressed')
	&& str_contains($cli, 'kloxo_quota_notify_set')
	&& str_contains($cq, 'catch (Throwable')) {
	ok('collectquota applies Disable Policy and catches Throwable');
} else {
	bad('collectquota applies Disable Policy and catches Throwable');
}

$login = new class {
	public function getObject($n)
	{
		$g = new stdClass();
		$g->generalmisc_b = new stdClass();
		$g->generalmisc_b->dpercentage = '120';
		$g->generalmisc_b->npercentage = '80';

		return $g;
	}
};
$GLOBALS['login'] = $login;
$acct->disable_per = '';
$g120 = kloxo_quota_policy_check($acct, '115', '100');
if (kloxo_disable_policy_default() === 120 && $g120['disable'] === false && $g120['warn'] === true) {
	ok('a saved General Disable Percentage of 120 is used when the account has no override');
} else {
	bad('a saved General Disable Percentage of 120 is used when the account has no override',
		var_export($g120, true) . ' def=' . (string) kloxo_disable_policy_default());
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
