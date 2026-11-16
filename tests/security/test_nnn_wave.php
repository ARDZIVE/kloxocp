#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * NNN-wave security unit checks (php-cli only; no live panel).
 * Covers nav chrome, topbar, tree/error box, menu, schema markers, leftovers.
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

$core = (string) file_get_contents($root . '/kloxo/httpdocs/theme/coredisplaylib.php');
expect(
	str_contains($core, 'security NNN1')
	&& str_contains($core, '$logoutConfirm = json_encode')
	&& str_contains($core, '$helpKwEsc')
	&& str_contains($core, '$favKwEsc')
	&& str_contains($core, '$loginAsEsc')
	&& str_contains($core, '$nnameEsc'),
	'NNN1 nav chrome escaped'
);

$topbar = (string) file_get_contents($root . '/kloxo/httpdocs/theme/skin/simplicity/default/topbar_right.php');
expect(
	str_contains($topbar, 'security NNN2')
	&& str_contains($topbar, '$statusKwEsc')
	&& str_contains($topbar, '$showhideKwEsc')
	&& str_contains($topbar, '$clockEsc'),
	'NNN2 topbar status/showhide escaped'
);

$html = (string) file_get_contents($root . '/kloxo/httpdocs/theme/htmllib.php');
expect(
	str_contains($html, 'security NNN3')
	&& str_contains($html, "getKeywordUc('finish')")
	&& str_contains($html, "getKeywordUc('next')")
	&& str_contains($html, "getKeywordUc('click_to_show')")
	&& str_contains($html, 'security NNN5')
	&& str_contains($html, '$error_box = $esc('),
	'NNN3/NNN5 tree labels + error box escaped'
);

$menu = (string) file_get_contents($root . '/kloxo/httpdocs/theme/skin/simplicity/default/menu.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($menu, 'security NNN4')
	&& str_contains($menu, 'function kloxo_menu_kw')
	&& str_contains($menu, '$loginasEsc')
	&& str_contains($menu, '$domainidEsc')
	&& str_contains($menu, 'rawurlencode((string) $clientid)')
	&& str_contains($lxlib, 'security NNN4')
	&& str_contains($lxlib, 'htmlspecialchars((string) $out'),
	'NNN4 menu ids/keywords/titles escaped'
);

expect(
	str_contains($html, 'security NNN7')
	&& str_contains($html, '$helpTitleEsc')
	&& str_contains($html, 'document.form<?= $formNameEsc ?>')
	&& str_contains($html, '$nameSafe = htmlspecialchars')
	&& str_contains($html, '$valViewEsc'),
	'NNN7 toolbar help title + resource chrome escaped'
);

$htmlPhp = (string) file_get_contents($root . '/kloxo/httpdocs/theme/html.php');
expect(
	str_contains($htmlPhp, 'security NNN8')
	&& str_contains($htmlPhp, 'callSetSelectFolder(<?= $retJs ?>)')
	&& !str_contains($htmlPhp, "callSetSelectFolder('<?= \$ret ?>')"),
	'NNN8 select-folder path JS-safe'
);

expect(
	str_contains($core, 'security NNN8')
	&& str_contains($core, "Escaped quick-action options")
	&& str_contains($core, "Escaped fatal chrome")
	&& str_contains($core, "htmlspecialchars((string) \$string"),
	'NNN8 quickaction + display_die escaped'
);

expect(
	str_contains($html, 'security NNN9')
	&& str_contains($html, 'Escaped graph select path/period')
	&& str_contains($html, 'Escaped radio name/value/label')
	&& str_contains($html, 'name="<?= $varEsc ?>" value="">')
	&& !str_contains($html, 'name="<?= $variable ?> value="">'),
	'NNN9 graph select + radio escaped'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
expect(
	str_contains($lib, 'MMM5 / NNN5')
	&& str_contains($lxlib, 'MMM5 / NNN5'),
	'NNN5 schema residual markers'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
expect(
	str_contains($lxlib, 'security NNN6')
	&& str_contains($extSec, 'security NNN6')
	&& str_contains($toast, 'security NNN6')
	&& str_contains($lxlib, 'gated unserialize'),
	'NNN6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
