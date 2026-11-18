<?php
declare(strict_types=1);

/**
 * Toast-success without SQLite persist: admin mutates a non-login parent
 * (client/domain) but only $login->was() ran. Source invariants for the
 * finish helpers + cobject was() (php-cli only; no live panel).
 */

$root = dirname(__DIR__, 2);
$doc = $root . '/kloxo/httpdocs';
$failed = 0;
$passed = 0;

function expect(bool $cond, string $msg): void
{
	global $failed, $passed;
	if ($cond) {
		echo "ok - {$msg}\n";
		$passed++;
	} else {
		echo "FAIL - {$msg}\n";
		$failed++;
	}
}

$lx = (string) file_get_contents($doc . '/lib/php/lxlib.php');
$disp = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
$class = (string) file_get_contents($doc . '/lib/php/lxclass.php');

expect(
	str_contains($lx, 'function kloxo_finish_list_delete')
	&& str_contains($lx, 'function kloxo_finish_list_child_was')
	&& str_contains($lx, 'function kloxo_finish_list_adds')
	&& str_contains($lx, 'function kloxo_persist_cobject_was'),
	'persist helpers exist (delete/add child + cobject was)'
);

expect(
	str_contains($disp, 'kloxo_finish_list_delete')
	&& str_contains($disp, 'kloxo_finish_list_adds')
	&& str_contains($disp, 'kloxo_persist_cobject_was')
	&& str_contains($disp, 'kloxo_finish_list_child_was'),
	'add/delete/update + lx_frm_inc wire persist helpers'
);

expect(
	(bool) preg_match(
		'/function kloxo_finish_client_add\(string \$nname,\s*\$parent\s*=\s*null\)/',
		$lx
	)
	&& str_contains($lx, 'reseller → Add Client')
	&& str_contains($disp, 'kloxo_finish_client_add($nname, $object)'),
	'Add Client finishes from real parent (nested reseller), not only login'
);

expect(
	str_contains($class, 'Readonly lists (uppercase R) must still persist deletes')
	&& str_contains($class, 'hasUnclean')
	&& str_contains($class, 'writeAChildList force write'),
	'writeAChildList persists unclean readonly children'
);

$acAdd = '';
if (preg_match('/function __ac_desc_add\(.*?^function /sm', $disp, $m) === 1) {
	$acAdd = $m[0];
}
expect(
	$acAdd !== ''
	&& str_contains($acAdd, 'kloxo_finish_list_adds')
	&& str_contains($acAdd, "\$class !== 'dnstemplate'"),
	'__ac_desc_add persists non-domain/client list adds on the parent'
);

$acUp = '';
if (preg_match('/function __ac_desc_Update\(.*?^function /sm', $disp, $m) === 1) {
	$acUp = $m[0];
}
expect(
	$acUp !== ''
	&& str_contains($acUp, 'kloxo_finish_list_child_was')
	&& str_contains($acUp, 'Multi-select update'),
	'__ac_desc_Update persists multi-select child updates on the parent'
);

$acDel = '';
if (preg_match('/function __ac_desc_delete\(.*?^function /sm', $disp, $m) === 1) {
	$acDel = $m[0];
}
expect(
	$acDel !== ''
	&& str_contains($acDel, 'kloxo_finish_list_delete')
	&& str_contains($acDel, "\$cname !== 'dnstemplate'"),
	'__ac_desc_delete persists list deletes on the real parent'
);

$frm = '';
if (preg_match('/function lx_frm_inc\(.*?^function /sm', $disp, $m) === 1) {
	$frm = $m[0];
}
expect(
	$frm !== ''
	&& str_contains($frm, 'kloxo_persist_cobject_was')
	&& str_contains($frm, '$login->was()'),
	'lx_frm_inc was()s cobject before login when they differ'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
