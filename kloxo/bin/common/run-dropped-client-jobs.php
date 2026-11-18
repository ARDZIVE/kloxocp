<?php
/**
 * Root helper: drain log/background.queue, client-sync, domain-sync, mailname.
 * Invoked by kloxo-watchdog / kloxo_kick_dropped_client_jobs after Add Client/Domain.
 *
 * Single-flight: flock + pid file so the backend accept loop cannot spawn a
 * new PHP for every rl_exec while a drain (nginx -t) is still running.
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

$pending = false;
foreach (array(
	$root . '/log/background.queue',
	$root . '/log/client-sync.request',
	$root . '/log/client-home-purge.request',
	$root . '/log/domain-sync.request',
	$root . '/log/mailname.request',
) as $drop) {
	if (is_file($drop)) {
		$pending = true;
		break;
	}
}
if (!$pending) {
	exit(0);
}

$pidDir = $root . '/pid';
if (!is_dir($pidDir)) {
	@mkdir($pidDir, 0750, true);
}
$lockPath = $pidDir . '/dropped-client-jobs.lock';
$pidPath = $pidDir . '/dropped-client-jobs.pid';
$spawnPath = $pidDir . '/dropped-client-jobs.spawn';
$lock = @fopen($lockPath, 'c+');
if (!is_resource($lock) || !@flock($lock, LOCK_EX | LOCK_NB)) {
	exit(0);
}
@file_put_contents($pidPath, (string) getmypid());
@chmod($pidPath, 0640);
// Held the lock — spawn cooldown is no longer needed (pid/lock gate busy).
@unlink($spawnPath);

$httpdocs = $root . '/httpdocs';
$include = $httpdocs . '/lib/html/include.php';
if (!is_file($include)) {
	@unlink($pidPath);
	@flock($lock, LOCK_UN);
	@fclose($lock);
	fwrite(STDERR, "run-dropped-client-jobs: missing {$include}\n");
	exit(1);
}
if (is_dir($httpdocs)) {
	@chdir($httpdocs);
}

try {
	include_once $include;

	if (!function_exists('kloxo_run_dropped_client_jobs')) {
		fwrite(STDERR, "run-dropped-client-jobs: kloxo_run_dropped_client_jobs missing\n");
		exit(1);
	}
	kloxo_run_dropped_client_jobs();
	exit(0);
} finally {
	@unlink($pidPath);
	@unlink($spawnPath);
	if (is_resource($lock)) {
		@flock($lock, LOCK_UN);
		@fclose($lock);
	}
}
