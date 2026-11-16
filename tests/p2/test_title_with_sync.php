<?php
declare(strict_types=1);

/**
 * Admin Server section title must be plain text (print_action_block
 * htmlspecialchars's it). Old getTitleWithSync returned broken <span> markup
 * that showed as literal "Server <span title=…> » linux" on the page.
 *
 * php-cli only.
 */

$root = dirname(__DIR__, 2);
$doc = $root . '/kloxo/httpdocs';
$src = (string) file_get_contents($doc . '/lib/php/lxclass.php');

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

check(str_contains($src, 'function getTitleWithSync'), 'getTitleWithSync present');
check(str_contains($src, 'Plain text only')
	&& str_contains($src, '»'),
	'getTitleWithSync documents plain-text section titles');
check(!preg_match(
	'/function getTitleWithSync\(\$class = null\)\s*\{[^}]*<span title=/s',
	$src
), 'getTitleWithSync no longer embeds <span title= markup');

chdir($doc);
require_once $doc . '/lib/html/include.php';

global $gbl, $login;
$login = new Client(null, null, 'admin');
$login->cttype = 'admin';
if (!is_object($gbl)) {
	$gbl = new Gbllib();
}

$ps = new pserver(null, 'localhost', 'localhost');
$ps->__driverappclass = 'linux';
$ps->syncserver = 'localhost';
$title = $ps->getTitleWithSync();
check(is_string($title) && $title !== '', 'pserver title is a non-empty string');
check(!str_contains($title, '<span') && !str_contains($title, 'Configured'),
	'pserver title has no HTML / Configured span text');
check(str_contains($title, '»') && str_contains($title, 'linux'),
	'pserver title shows driver after »');
$escaped = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
check($escaped === $title || !str_contains($escaped, '&lt;span'),
	'htmlspecialchars leaves a readable title (no literal tags)');

echo $fail === 0 ? "{$pass} passed, 0 failed\n" : "{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
