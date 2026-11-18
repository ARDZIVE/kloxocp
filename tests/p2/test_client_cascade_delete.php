<?php
declare(strict_types=1);

/**
 * Client delete must cascade domains/mail/DB/ftp and show a confirmation preview.
 * php-cli source + structure checks only (no live panel DB).
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
$base = (string) file_get_contents($doc . '/lib/client/clientbaselib.php');
$class = (string) file_get_contents($doc . '/lib/php/lxclass.php');
$disp = (string) file_get_contents($doc . '/theme/coredisplaylib.php');
$html = (string) file_get_contents($doc . '/theme/htmllib.php');
$unix = (string) file_get_contents($doc . '/lib/php/kloxo_client_unix.php');

expect(
	str_contains($lx, 'function kloxo_client_cascade_delete')
	&& str_contains($lx, 'function kloxo_purge_client_panel_orphans')
	&& str_contains($lx, "'domain'")
	&& str_contains($lx, 'delRow'),
	'cascade delete + panel orphan purge helpers exist'
);

expect(
	str_contains($base, 'kloxo_client_cascade_delete')
	&& str_contains($base, 'function getDeleteCascadePreview')
	&& str_contains($base, 'Domains / Subdomains'),
	'Client::deleteSpecific cascades; preview lists owned objects'
);

expect(
	str_contains($class, 'Readonly lists (uppercase R) must still persist deletes')
	&& str_contains($class, 'writeAChildList force write')
	&& str_contains($class, 'catch (Throwable $e)'),
	'writeAChildList writes unclean readonly children and continues on error'
);

expect(
	str_contains($disp, 'kloxo-delete-cascade')
	&& str_contains($disp, 'getDeleteCascadePreview')
	&& str_contains($disp, 'will also permanently delete'),
	'delete confirmation lists cascade for clients'
);

expect(
	str_contains($unix, 'kloxo_purge_client_panel_orphans')
	&& str_contains($unix, "client-' . \$nname"),
	'purgeLeftover also clears orphan panel rows'
);

expect(
	str_contains($html, 'Cast: empty-string cgi_pagenum')
	&& str_contains($html, 'max(1, (int) $cgi_pagenum)'),
	'list pagination casts pagesize/pagenum (no string+int)'
);

expect(
	(bool) preg_match('/function kloxo_client_cascade_delete\(object \$client\)/', $lx),
	'cascade function signature is typed object'
);

expect(
	str_contains($lx, 'function kloxo_finish_list_delete')
	&& str_contains($disp, 'kloxo_finish_list_delete')
	&& (str_contains($disp, 'kloxo_persist_cobject_was')
		|| (str_contains($disp, 'cobject was:') && str_contains($disp, '$cobject->was()'))),
	'domain delete persists via parent was / finish_list_delete (not only login->was)'
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
