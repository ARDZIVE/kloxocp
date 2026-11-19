<?php
/**
 * Form fields must HTML-escape once at render. Pre-escaping in
 * fix_stuff_or_class made Command Center show literal &#039; for quotes.
 * php-cli only.
 */
declare(strict_types=1);

$pass = 0;
$fail = 0;
function check(bool $ok, string $d): void
{
	global $pass, $fail;
	if ($ok) {
		$pass++;
		printf("  ok   %s\n", $d);
		return;
	}
	$fail++;
	printf("  FAIL %s\n", $d);
}

$repo = dirname(__DIR__, 2);
$htmllib = (string) file_get_contents($repo . '/kloxo/httpdocs/theme/htmllib.php');

$fixBody = '';
if (preg_match('/function fix_stuff_or_class\b.*?\n\tfunction /s', $htmllib, $m)) {
	$fixBody = $m[0];
}
check($fixBody !== ''
	&& str_contains($fixBody, 'Keep the raw value')
	&& !str_contains($fixBody, 'kloxo_html($value)'),
	'fix_stuff_or_class no longer pre-escapes with kloxo_html');

check(str_contains($htmllib, '$taValue = htmlspecialchars((string) $value')
	&& str_contains($htmllib, '$valEsc = htmlspecialchars((string) $m_value'),
	'textarea and textbox still escape once at render');

check(str_contains($htmllib, 'Object/DB display values are not user script input')
	&& !preg_match(
		'/if \(\$variable->value != ""\) \{\s*\$this->checkForScript\(\$variable->value\);/s',
		$htmllib
	),
	'print_modify does not checkForScript object display values');

// Simulate the double-escape that used to show &#039; literally.
$raw = "type '/script/fixdns [--help|-h]'";
$once = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$twice = htmlspecialchars($once, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
check(str_contains($once, '&#039;') || str_contains($once, '&#39;'),
	'single escape turns quote into an entity');
check(str_contains($twice, '&amp;#') ,
	'double escape turns the entity into visible &#039; source');
check(!str_contains(htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '&amp;#'),
	'render-once output does not contain &amp;# (visible entity)');

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
