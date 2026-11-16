#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * III-wave security unit checks (php-cli only; no live panel).
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
	str_contains($html, 'security III1')
	&& str_contains($html, '$hidName')
	&& str_contains($html, '$hidVal')
	&& !str_contains($html, 'id="<?= $variable->name ?>" name="<?= $variable->name ?>" value="<?= $variable->value ?>"'),
	'III1 hidden input name/value escape'
);

expect(
	str_contains($html, 'security III2')
	&& str_contains($html, '$cbName')
	&& str_contains($html, '$cbVal')
	&& !str_contains($html, 'type=checkbox name="<?= $variable->name ?>"'),
	'III2 checkbox name/value escape'
);

expect(
	str_contains($html, 'security III3')
	&& str_contains($html, '$vEsc')
	&& str_contains($html, '$optEsc')
	&& !str_contains($html, '<option <?= $sel ?> value="<?= $k ?>"><?= $option ?></option>'),
	'III3 plain select name/options escape'
);

expect(
	str_contains($html, 'security III4')
	&& substr_count($html, 'security III4') >= 2
	&& str_contains($html, '$btnName')
	&& str_contains($html, 'var updateallwarning1 = <?= $w1 ?>')
	&& !str_contains($html, 'id="<?= $variable->name ?>" name="<?= $variable->name ?>" value="&nbsp;&nbsp;<?= $variable->value ?>&nbsp;&nbsp;"'),
	'III4 submit attrs + updateall JS escape'
);

expect(
	str_contains($html, 'security III5')
	&& str_contains($html, '$fnEsc')
	&& str_contains($html, '$nameEsc')
	&& str_contains($html, '$valEsc')
	&& !str_contains($html, 'name="frm_hpfilter[<?= $filtername ?>][<?= $name ?>_o_cont]" value="<?= $value ?>"')
	&& substr_count($html, 'security III5') >= 5
	&& str_contains($frame, 'security III5'),
	'III5 Advanced Search + leftover getSkinColor hex'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');

expect(
	str_contains($lxlib, 'security III6')
	&& str_contains($extSec, 'security III6')
	&& str_contains($toast, 'security III6')
	&& str_contains($lxlib, 'gated unserialize')
	&& str_contains($lib, 'HHH5 / III5'),
	'III6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
