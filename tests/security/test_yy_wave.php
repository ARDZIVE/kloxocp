#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * YY-wave security unit checks (php-cli only; no live panel).
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
$core = (string) file_get_contents($root . '/kloxo/httpdocs/theme/coredisplaylib.php');
expect(
	str_contains($html, 'security YY1 / YY4')
	&& str_contains($html, "href=\"' . \$esc(\$url) . '\"")
	&& str_contains($core, 'security YY1')
	&& str_contains($core, "href=\"' . \$esc(\$url) . '\"")
	&& !str_contains($html, '<a href=$url target=$target>')
	&& !str_contains($core, '<a href=$url target=$target>'),
	'YY1 print_favorites quoted/escaped attrs'
);
// Note: source uses single-quoted PHP string pieces; pattern matches file text.

$frame = (string) file_get_contents($root . '/kloxo/httpdocs/theme/frame_left.php');
expect(
	str_contains($frame, 'security WW2 / XX4 / YY2')
	&& str_contains($frame, "get_favorite('ndskshortcut')")
	&& str_contains($frame, 'createSubMenu(xxpFav, <?= $labelJs ?>, <?= $urlJs ?>')
	&& !str_contains($frame, 'print_favorites()'),
	'YY2 frame_left per-item favorites'
);

$sql = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/sqlitelib.php');
expect(
	str_contains($sql, 'security YY3')
	&& str_contains($sql, 'getRowsWhere refused unsafe where fragment')
	&& str_contains($sql, "preg_match('/;|--|\\/\\*|\\bunion\\b/i', \$q)"),
	'YY3 getRowsWhere free-form gate'
);

expect(
	str_contains($html, 'security YY1 / YY4')
	&& !preg_match('/foreach\s*\(\s*\$vvar_list.*?\$vvar\s*=\s*isset/s', function_body_local($html, 'print_favorites')),
	'YY4 htmllib print_favorites no \$vvar assign bug'
);

$lib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/html/lib.php');
$lxlib = (string) file_get_contents($root . '/kloxo/httpdocs/lib/php/lxlib.php');
expect(
	str_contains($lib, 'security LL3 / VV5 / WW5 / XX5 / YY5')
	&& str_contains($lib, 'security MM1 / VV5 / WW5 / XX5 / YY5')
	&& str_contains($lxlib, 'security TT1 / VV5 / WW5 / XX5 / YY5'),
	'YY5 schema rawQuery residual markers'
);

$extSec = (string) file_get_contents($root . '/kloxo/httpdocs/theme/extjs/SECURITY.kloxo');
$toast = (string) file_get_contents($root . '/kloxo/httpdocs/theme/js/kloxo-toast.js');
expect(
	str_contains($lxlib, 'security YY6')
	&& str_contains($extSec, 'security YY6')
	&& str_contains($toast, 'security YY6')
	&& str_contains($lxlib, 'gated unserialize'),
	'YY6 intentional leftovers marked'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);

/** Extract a PHP function body (best-effort brace match). */
function function_body_local(string $src, string $name): string
{
	if (!preg_match('/function\s+' . preg_quote($name, '/') . '\s*\([^)]*\)\s*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
		return '';
	}
	$start = (int) $m[0][1] + strlen($m[0][0]) - 1;
	$depth = 0;
	$len = strlen($src);
	for ($i = $start; $i < $len; $i++) {
		$ch = $src[$i];
		if ($ch === '{') {
			$depth++;
		} elseif ($ch === '}') {
			$depth--;
			if ($depth === 0) {
				return substr($src, $start, $i - $start + 1);
			}
		}
	}
	return '';
}
