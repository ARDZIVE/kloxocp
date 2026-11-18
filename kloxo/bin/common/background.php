<?php
/**
 * Async worker for callInBackground / callObjectInBackground.
 *
 * Must not rely on the panel FPM cwd: pool chdir is unset and workers often
 * start in /, so relative "lib/html/include.php" never loads and welcome /
 * ticket / notify mail silently never send. Bootstrap from __DIR__.
 *
 * Job files are PHP-serialized under {program_root}/pid/ (mode 0700). Only a
 * fixed allow-list of callables / object method names may run (security F12).
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

/**
 * @return list<string>
 */
function kloxo_background_allowed_functions(): array
{
	return [
		'lx_mail',
		'se_submit',
		// Add Client / Add Domain system sync (drain → background.php jobs).
		'kloxo_client_system_sync',
		'kloxo_domain_system_sync',
		'kloxo_client_home_purge',
	];
}

/**
 * @return list<string>
 */
function kloxo_background_allowed_methods(): array
{
	return [
		'send_mail_to_admin',
	];
}

function background_main(): void
{
	global $argv;

	$path = isset($argv[1]) ? (string) $argv[1] : '';
	if ($path === '' || !is_file($path)) {
		exit(1);
	}

	// Job path must stay under the program pid directory (no traversal).
	$real = realpath($path);
	$root = dirname(__DIR__, 2);
	$pidDir = realpath($root . '/pid');
	if ($real === false || $pidDir === false
		|| !str_starts_with($real, $pidDir . DIRECTORY_SEPARATOR)) {
		@unlink($path);
		exit(1);
	}

	$raw = @file_get_contents($real);
	@unlink($real);
	if ($raw === false || $raw === '') {
		exit(1);
	}

	// Fail closed without gadget helper (security Z1).
	if (function_exists('kloxo_unserialize_deny_gadgets')) {
		$rem = kloxo_unserialize_deny_gadgets($raw, ['allowed_classes' => ['Remote', 'stdClass']]);
	} else {
		$rem = false;
	}
	if (!is_object($rem)) {
		exit(1);
	}

	if (isset($rem->sleep) && is_numeric($rem->sleep)) {
		$sleep = (int) $rem->sleep;
		if ($sleep > 0 && $sleep <= 120) {
			sleep($sleep);
		}
	}

	$type = isset($rem->__type) ? (string) $rem->__type : '';
	if ($type === 'object') {
		if (!isset($rem->__exec_object) || !is_object($rem->__exec_object)) {
			exit(1);
		}
		$func = isset($rem->func) ? (string) $rem->func : '';
		if ($func === '' || !in_array($func, kloxo_background_allowed_methods(), true)
			|| !method_exists($rem->__exec_object, $func)) {
			exit(1);
		}
		$rem->__exec_object->$func();

		return;
	}

	$func = $rem->func ?? null;
	$arglist = isset($rem->arglist) && is_array($rem->arglist) ? $rem->arglist : [];
	if (!is_string($func) || !in_array($func, kloxo_background_allowed_functions(), true)
		|| !is_callable($func)) {
		exit(1);
	}
	call_user_func_array($func, $arglist);
}
