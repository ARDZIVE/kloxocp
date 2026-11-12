<?php
/**
 * Root helper: drain log/background.queue and log/client-sync.request.
 * Invoked by kloxo-watchdog / optionally by hand after Add Client.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

declare(strict_types=1);

$root = '/opt/kloxo';
foreach ($argv as $a) {
	if (is_string($a) && str_starts_with($a, '--root=')) {
		$root = rtrim(substr($a, 7), '/');
	}
}
$httpdocs = $root . '/httpdocs';
$include = $httpdocs . '/lib/html/include.php';
if (!is_file($include)) {
	fwrite(STDERR, "run-dropped-client-jobs: missing {$include}\n");
	exit(1);
}
if (is_dir($httpdocs)) {
	@chdir($httpdocs);
}
include_once $include;

if (!function_exists('kloxo_run_dropped_client_jobs')) {
	fwrite(STDERR, "run-dropped-client-jobs: kloxo_run_dropped_client_jobs missing\n");
	exit(1);
}
kloxo_run_dropped_client_jobs();
exit(0);
