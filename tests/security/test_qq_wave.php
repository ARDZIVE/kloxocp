#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * QQ-wave security unit checks (php-cli only; no live panel).
 */

$root = realpath(dirname(__DIR__, 2));
if ($root === false) {
	fwrite(STDERR, "cannot resolve repo root\n");
	exit(2);
}

$failed = 0;
$passed = 0;

function expect(bool $ok, string $msg): void
{
	global $failed, $passed;
	if ($ok) {
		echo "OK  {$msg}\n";
		$passed++;
	} else {
		echo "FAIL {$msg}\n";
		$failed++;
	}
}

$sql = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/sqlitelib.php');
expect(
	str_contains($sql, 'security QQ1')
	&& (bool) preg_match('/function getRows\s*\([^)]*\)[\s\S]{0,400}escapeBack/', $sql)
	&& (bool) preg_match('/function getRowsOr\s*\([^)]*\)[\s\S]{0,400}escapeBack/', $sql)
	&& (bool) preg_match('/function getRowAnd\s*\([^)]*\)[\s\S]{0,400}escapeBack/', $sql)
	&& (bool) preg_match('/function getRowsNot\s*\([^)]*\)[\s\S]{0,400}escapeBack/', $sql)
	&& !str_contains($sql, "return \$this->getRowsWhere(\"{\$field} = '{\$value}'\")"),
	'QQ1 getRows* quoted'
);

expect(
	str_contains($sql, 'security QQ2')
	&& str_contains($sql, 'delRow refused unsafe')
	&& str_contains($sql, "escapeBack(\$nname, \$value)")
	&& !str_contains($sql, "where {\$nname} = '{\$value}'"),
	'QQ2 delRow quoted'
);

$html = (string) file_get_contents($root . '/kloxo/httpdocs/theme/htmllib.php');
expect(
	str_contains($html, 'security QQ3')
	&& str_contains($html, '__kloxoWait.textContent')
	&& !str_contains($html, "innerHTML='&nbsp;{\$wait}&nbsp;'")
	&& str_contains($html, "onsubmit='\" . htmlspecialchars(\$js")
	&& !preg_match('/onsubmit="\{\$wait_text\}/', $html),
	'QQ3 wait textContent (single-quoted onsubmit)'
);

$frame = (string) file_get_contents($root . '/kloxo/httpdocs/theme/frame_left.php');
$panel = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/xpmenu/PanelBarOrig.js');
expect(
	str_contains($frame, 'security QQ4')
	&& str_contains($frame, 'kloxoEscHtml')
	&& str_contains($frame, 'safeLabel')
	&& str_contains($panel, 'security QQ4')
	&& str_contains($panel, 'kloxoEscHtml(this.label)')
	&& !str_contains($panel, 'document.write(this.label)'),
	'QQ4 menu label escape'
);

$def = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/definecore.php');
$web = (string) file_get_contents($root . '/kloxo/httpdocs/driver/web/weblib.php');
expect(
	str_contains($def, 'security QQ5')
	&& !preg_match('/^\s*\$gl_class_array\["aspnet"\]/m', $def)
	&& str_contains($web, 'security QQ5 / PP4')
	&& (bool) preg_match('/^\s*class aspnetconf_b extends Lxaclass/m', $web),
	'QQ5 aspnet unregistered (empty aspnetconf_b stub kept for legacy DB)'
);

$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$lxa = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/lxa.js');
expect(
	str_contains($lxlib, 'security QQ6')
	&& str_contains($extSec, 'security QQ6')
	&& str_contains($lxa, 'security QQ6')
	&& str_contains($lxlib, 'gated unserialize'),
	'QQ6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
