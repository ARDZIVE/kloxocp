<?php
/**
 * Root-only CLI password reset. Not network-reachable (security T6).
 * Slave DB load uses gadget-filtered unserialize.
 * Hashes with Argon2id like init_slave / init-slave (security Y4).
 */

include_once "lib/html/include.php";

global $sgbl, $argv;

if (!os_isSelfSystemOrLxlabsUser()) {
	print("Must be Root \n");

	exit;
}

if (!isset($argv[1]) || !isset($argv[2])) {
	print("Usage: {$argv[0]} <master/slave> <password>\n");
	exit;
}

if ($argv[1] === 'master') {
	initProgram('admin');
	$login = $GLOBALS['login'];
	$hash = function_exists('kloxo_password_hash') ? kloxo_password_hash((string) $argv[2]) : '';
	if ($hash === '') {
		print("Could not hash password\n");
		exit(1);
	}
	$login->password = $hash;
	$login->realpass = $argv[2];
	$login->setUpdateSubaction('password');
	$login->createSyncClass();
	$login->was();
} else if ($argv[1] === 'slave') {
	if (!lxfile_exists("__path_slave_db")) {
		print("Not Slave\n");

		exit;
	}
	// Filtered load only (security U2 / T6) — no bare unserialize.
	if (function_exists('kloxo_load_slave_db')) {
		$rmt = kloxo_load_slave_db();
	} elseif (function_exists('kloxo_unserialize_deny_gadgets')) {
		$raw = lfile_get_contents("__path_slave_db");
		$rmt = kloxo_unserialize_deny_gadgets((string) $raw, array('allowed_classes' => array('Remote', 'stdClass')));
	} else {
		$rmt = false;
	}
	if (!is_object($rmt)) {
		print("Could not load slave-db\n");
		exit(1);
	}
	$hash = function_exists('kloxo_password_hash') ? kloxo_password_hash((string) $argv[2]) : '';
	if ($hash === '') {
		print("Could not hash password\n");
		exit(1);
	}
	$rmt->password = $hash;
	lfile_put_contents("__path_slave_db", serialize($rmt));
} else {
	print("first argument is master/slave\n");

	exit;
}
