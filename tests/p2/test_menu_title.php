<?php
/**
 * Server menu titles: PHP 8.5 strpos(null) aborted get_classvar_description
 * on a=show items (sshclient), which cut every page before #mmm.
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
error_reporting(E_ALL);
ini_set('display_errors', '0');

include_once $doc . '/lib/html/include.php';

try {
	$beg = csb(null, '__');
	if ($beg === false) {
		ok('csb(null) does not TypeError');
	} else {
		bad('csb(null) does not TypeError', 'returned ' . var_export($beg, true));
	}
} catch (Throwable $e) {
	bad('csb(null) does not TypeError', $e->getMessage());
}

try {
	$a = csa(null, 'N');
	if ($a === false) {
		ok('csa(null) does not TypeError');
	} else {
		bad('csa(null) does not TypeError', 'returned ' . var_export($a, true));
	}
} catch (Throwable $e) {
	bad('csa(null) does not TypeError', $e->getMessage());
}

try {
	if (class_exists('sshclient')) {
		ok('sshclient class loads on PHP 8');
	} else {
		bad('sshclient class loads on PHP 8');
	}
} catch (Throwable $e) {
	bad('sshclient class loads on PHP 8', $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

try {
	$desc = get_classvar_description('sshclient');
	if (is_array($desc) && isset($desc[2]) && $desc[2] !== '') {
		ok('sshclient __desc has a title');
	} else {
		bad('sshclient __desc has a title', var_export($desc, true));
	}
} catch (Throwable $e) {
	bad('sshclient __desc has a title', $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

try {
	$show = get_classvar_description('sshclient', '__acdesc_show');
	if (is_array($show) && isset($show[2]) && $show[2] !== '') {
		ok('sshclient show action has a title');
	} else {
		bad('sshclient show action has a title', var_export($show, true));
	}
} catch (Throwable $e) {
	bad('sshclient show action has a title', $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
}

try {
	$t = kloxo_menu_title('a=show&o=sshclient');
	if (is_string($t) && $t !== '') {
		ok('kloxo_menu_title returns a string for sshclient');
	} else {
		bad('kloxo_menu_title returns a string for sshclient', var_export($t, true));
	}
} catch (Throwable $e) {
	bad('kloxo_menu_title returns a string for sshclient', $e->getMessage());
}

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
