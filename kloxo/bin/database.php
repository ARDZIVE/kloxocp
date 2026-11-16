<?php
/**
 * Root-only CLI DB helper. Not network-reachable (security T6).
 * Filtered unserialize of ser_* columns; exec mode is SELECT-only.
 */

include_once "lib/html/include.php";

if (!os_isSelfSystemOrLxlabsUser()) {
	print("Must be Root \n");
	exit;
}

database_main();

function database_main()
{
	global $argc, $argv;
	global $gbl, $login, $ghtml;

	initProgram('admin');

	if (!isset($argv[1]) || $argv[1] !== 'exec') {
		print("Usage: {$argv[0]} exec <SELECT-sql>\n");
		exit(1);
	}
	if (!isset($argv[2]) || !is_string($argv[2]) || $argv[2] === '') {
		print("Missing SQL\n");
		exit(1);
	}

	$sql = trim($argv[2]);
	// Refuse anything that is not a single SELECT (no multi-statement).
	if (!preg_match('/^select\b/i', $sql) || str_contains($sql, ';')) {
		print("Only a single SELECT statement is allowed\n");
		exit(1);
	}

	$db = new Sqlite(null, 'client');
	$res = $db->rawQuery($sql);
	if (!is_array($res)) {
		print("Query failed\n");
		exit(1);
	}
	foreach ($res as &$r) {
		if (!is_array($r)) {
			continue;
		}
		foreach ($r as $k => &$__r) {
			if (csb($k, 'ser_')) {
				// Fail closed without gadget helper (security Y5).
				$__r = function_exists('kloxo_b64_unserialize')
					? kloxo_b64_unserialize((string) $__r)
					: false;
			}
		}
		unset($__r);
	}
	unset($r);
	print_r($res);
	exit;
}
