#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * KKK-wave security unit checks (php-cli only; no live panel).
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

$html = (string) file_get_contents($root . '/kloxo/httpdocs/theme/htmllib.php');
$frame = (string) file_get_contents($root . '/kloxo/httpdocs/theme/frame_left.php');

expect(
	str_contains($html, 'security KKK1')
	&& str_contains($html, '$formName')
	&& str_contains($html, '$formUrl')
	&& str_contains($html, "check_for_needed_variables('{\$formName}')")
	&& !str_contains($html, 'name="<?= $block->form ?>" id="<?= $block->form ?>" action="<?= $block->url ?>"'),
	'KKK1 form open attrs + onsubmit escape'
);

expect(
	str_contains($html, 'security KKK2')
	&& substr_count($html, 'security KKK2') >= 3
	&& str_contains($html, '$msDescEsc')
	&& str_contains($html, '$subDescEsc')
	&& !str_contains($html, "\$variable_description = \"\$variable->desc\";"),
	'KKK2 variable desc/labels escape'
);

expect(
	str_contains($html, 'security KKK3')
	&& str_contains($html, '$dlgIdEsc')
	&& str_contains($html, '$urlEsc')
	&& str_contains($frame, 'security KKK3')
	&& str_contains($frame, 'text: <?= $treeText ?>')
	&& !str_contains($frame, "text: '<?=\$object->getId()?>'"),
	'KKK3 comments dlg + Ext tree escape'
);

expect(
	str_contains($html, 'security KKK4')
	&& str_contains($html, "preg_match('/^[0-7]$/'")
	&& str_contains($html, 'confirm(<?= $confirmJs ?>)')
	&& str_contains($html, 'setpermission(document.chmod, \'user\', <?= (int) $user ?>)'),
	'KKK4 chmod totals digit allow-list'
);

expect(
	str_contains($html, 'security KKK5')
	&& str_contains($html, '$urlHrefEsc')
	&& !str_contains($html, 'href="<?= $url ?>"> <?= $pname ?> </a>'),
	'KKK5 list href escape'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');

expect(
	str_contains($lxlib, 'security KKK6')
	&& str_contains($extSec, 'security KKK6')
	&& str_contains($toast, 'security KKK6')
	&& str_contains($lxlib, 'gated unserialize')
	&& str_contains($lib, 'JJJ5 / KKK5'),
	'KKK6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
