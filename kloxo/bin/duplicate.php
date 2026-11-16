<?php
declare(strict_types=1);

include "lib/html/include.php";

$class = isset($argv[1]) ? (string) $argv[1] : '';
// Table/class allow-list (security UU5).
if ($class === '' || !function_exists('kloxo_sql_ident') || !kloxo_sql_ident($class)) {
	fwrite(STDERR, "duplicate.php: invalid table/class name\n");
	exit(1);
}
if (!class_exists($class, false) && !class_exists($class, true)) {
	fwrite(STDERR, "duplicate.php: class not found\n");
	exit(1);
}

$db = new Sqlite(null, $class);
$res = $db->getTable();
if (!is_array($res)) {
	exit(0);
}

$stored = array();
foreach ($res as $r) {
	if (!is_array($r) || !isset($r['nname'])) {
		continue;
	}
	$nname = (string) $r['nname'];
	if (isset($stored[$nname])) {
		print("duplicate found {$nname}\n");
		$db->delRow('nname', $nname);
		$ob = new $class(null, null, $nname);
		$ob->create($r);
		$ob->write();
		continue;
	}
	$stored[$nname] = $r;
}
