#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * JJJ-wave security unit checks (php-cli only; no live panel).
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

expect(
	str_contains($html, 'security JJJ1')
	&& str_contains($html, '$strEsc')
	&& str_contains($html, '$nvEsc')
	&& str_contains($html, '$valEsc')
	&& !str_contains($html, 'id="<?= $str ?>" name="<?= $str ?>" value="<?= $nv ?>"')
	&& !str_contains($html, 'id="<?= $str ?>" name="<?= $str ?>" value="<?= $value ?>"'),
	'JJJ1 html_variable_inherit hidden escape'
);

expect(
	str_contains($html, 'security JJJ2')
	&& str_contains($html, '$textNameEsc')
	&& str_contains($html, 'checkBoxTextToggle(\'<?= $formJs ?>\'')
	&& !str_contains($html, 'name="<?= $variable->text->name ?>" value="<?= $variable->text->value ?>"')
	&& !str_contains($html, "checkBoxTextToggle('\$form', '{\$variable->checkbox->name}'"),
	'JJJ2 checkboxwithtext attrs/onclick escape'
);

expect(
	str_contains($html, 'security JJJ3')
	&& substr_count($html, 'security JJJ3') >= 2
	&& str_contains($html, '$tabTitle')
	&& str_contains($html, '$imgSrc')
	&& !str_contains($html, 'id="<?= $k ?>-tab" class="x-dlg-tab" title="<?= $descr[2] ?>"')
	&& !str_contains($html, 'src="<?= $variable->value ?>" width="<?= $width ?>" height="<?= $height ?>"'),
	'JJJ3 image + comments dlg tab escape'
);

expect(
	str_contains($html, 'security JJJ4')
	&& substr_count($html, 'security JJJ4') >= 2
	&& str_contains($html, 'global_need_list[<?= $needKey ?>]')
	&& str_contains($html, 'global_match_list[<?= $mName ?>]')
	&& !str_contains($html, "global_need_list['<?=\$variable->name?>'] = '<?=\$variable->desc?>';"),
	'JJJ4 global_need/match/desc json_encode'
);

expect(
	str_contains($html, 'security JJJ5')
	&& str_contains($html, '$nametitleId')
	&& str_contains($html, 'blindUpOrDown(\'<?= $lclassJs ?>\'')
	&& !str_contains($html, "blindUpOrDown('<?= \$lclass ?>', '<?= \$class ?>', '<?= \$skindir ?>', '<?= \$nametitle ?>')"),
	'JJJ5 blindUpOrDown allow-list + escape'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');

expect(
	str_contains($lxlib, 'security JJJ6')
	&& str_contains($extSec, 'security JJJ6')
	&& str_contains($toast, 'security JJJ6')
	&& str_contains($lxlib, 'gated unserialize')
	&& str_contains($lib, 'III5 / JJJ5'),
	'JJJ6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
