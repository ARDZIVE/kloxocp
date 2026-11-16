<?php
/**
 * Sync child worker for callInChild (security N3).
 * Job files under {program_root}/pid/; only allow-listed callables.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

declare(strict_types=1);

$httpdocs = dirname(__DIR__, 2) . '/httpdocs';
$include = $httpdocs . '/lib/html/include.php';
if (!is_file($include)) {
	fwrite(STDERR, "child: missing {$include}\n");
	exit(1);
}
if (is_dir($httpdocs)) {
	@chdir($httpdocs);
}
include_once $include;

child_main();

/** @return list<string> */
function kloxo_child_allowed_functions(): array
{
	return [
		'send_mail_to_admin',
		'lx_mail',
	];
}

function child_main(): void
{
	global $argv;

	$path = isset($argv[1]) ? (string) $argv[1] : '';
	if ($path === '' || !is_file($path)) {
		exit(1);
	}

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

	ob_start();
	$rem = @unserialize($raw, ['allowed_classes' => ['Remote', 'stdClass']]);
	if (!is_object($rem)) {
		ob_end_clean();
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
		ob_end_clean();
		exit(1);
	}

	$func = $rem->func ?? null;
	$arglist = isset($rem->arglist) && is_array($rem->arglist) ? $rem->arglist : [];
	if (!is_string($func) || !in_array($func, kloxo_child_allowed_functions(), true)
		|| !is_callable($func)) {
		ob_end_clean();
		exit(1);
	}
	$ret = call_user_func_array($func, $arglist);
	$var = base64_encode(serialize($ret));
	ob_end_clean();
	print($var);
	exit;
}
