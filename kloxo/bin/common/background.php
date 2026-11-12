<?php
/**
 * Async worker for callInBackground / callObjectInBackground.
 *
 * Must not rely on the panel FPM cwd: pool chdir is unset and workers often
 * start in /, so relative "lib/html/include.php" never loads and welcome /
 * ticket / notify mail silently never send. Bootstrap from __DIR__.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

declare(strict_types=1);

$httpdocs = dirname(__DIR__, 2) . '/httpdocs';
$include = $httpdocs . '/lib/html/include.php';
if (!is_file($include)) {
	fwrite(STDERR, "background: missing {$include}\n");
	exit(1);
}
if (is_dir($httpdocs)) {
	@chdir($httpdocs);
}
include_once $include;

background_main();

function background_main(): void
{
	global $argv;

	$path = isset($argv[1]) ? (string) $argv[1] : '';
	if ($path === '' || !is_file($path)) {
		exit(1);
	}

	$raw = @file_get_contents($path);
	@unlink($path);
	if ($raw === false || $raw === '') {
		exit(1);
	}

	$rem = @unserialize($raw, ['allowed_classes' => true]);
	if (!is_object($rem)) {
		exit(1);
	}

	if (isset($rem->sleep) && is_numeric($rem->sleep)) {
		sleep((int) $rem->sleep);
	}

	$type = isset($rem->__type) ? (string) $rem->__type : '';
	if ($type === 'object') {
		if (!isset($rem->__exec_object) || !is_object($rem->__exec_object)) {
			exit(1);
		}
		$func = isset($rem->func) ? (string) $rem->func : '';
		if ($func === '' || !method_exists($rem->__exec_object, $func)) {
			exit(1);
		}
		$rem->__exec_object->$func();

		return;
	}

	$func = $rem->func ?? null;
	$arglist = isset($rem->arglist) && is_array($rem->arglist) ? $rem->arglist : [];
	if (is_array($func) && count($func) > 0) {
		$class = $func[0];
		if (is_string($class)) {
			class_exists($class);
		}
	}
	if (!is_callable($func)) {
		exit(1);
	}
	call_user_func_array($func, $arglist);
}
