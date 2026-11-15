<?php
declare(strict_types=1);

/**
 * Subdomain addform under a domain must not leave an empty parent select.
 * php-cli only; no live panel.
 */

$root = dirname(__DIR__, 2);
$src = (string) file_get_contents($root . '/kloxo/httpdocs/lib/domain/domainlib.php');
$fail = 0;
$ok = 0;

function check(bool $cond, string $msg): void
{
	global $fail, $ok;
	if ($cond) {
		echo "  ok   $msg\n";
		$ok++;
	} else {
		echo "  FAIL $msg\n";
		$fail++;
	}
}

echo "== subdomain addform parent under domain\n";
check(str_contains($src, 'parentIsDomain'), 'addform detects domain parent');
check(str_contains($src, "posttext' => '.' . \$parent->nname"),
	'under domain uses static .parent posttext');
check(str_contains($src, "getList('domain') on it (empty)"),
	'comment explains empty select poison');

printf("\n%d passed, %d failed\n", $ok, $fail);
exit($fail > 0 ? 1 : 0);
