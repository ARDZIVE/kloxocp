<?php

declare(strict_types=1);

/**
 * Backup / Restore raw upload into /home/kloxo/client/<account>/__backup/.
 * Streams php://input straight to the archive (no $_FILES / second temp copy),
 * so the browser can redirect as soon as the stream ends.
 *
 * POST body = file bytes (Content-Type: application/octet-stream)
 * Query: account, file, bytes, token, next
 * GET check=1 → JSON {ok,ready,size,partial} for the poll after 100%.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

chdir('../../');
include_once 'lib/html/displayinclude.php';

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

@ignore_user_abort(true);
@set_time_limit(0);

const KLOXO_BACKUP_UPLOAD_MAX = 10737418240; // 10 GiB (chunked console preferred)

/**
 * @return never
 */
function kloxo_backup_upload_json(array $data, int $code = 200): void
{
	while (ob_get_level() > 0) {
		@ob_end_clean();
	}
	@ini_set('zlib.output_compression', '0');
	if (!headers_sent()) {
		http_response_code($code);
		header('Content-Type: application/json; charset=UTF-8');
	}
	echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	if (function_exists('fastcgi_finish_request')) {
		@fastcgi_finish_request();
	}
	exit(0);
}

try {
	initProgram();
} catch (Throwable $e) {
	kloxo_backup_upload_json([
		'ok' => false,
		'error' => 'forbidden',
		'message' => 'Upload forbidden for this account.',
	], 403);
}

global $login;
if (!is_object($login) || !method_exists($login, 'nname')) {
	kloxo_backup_upload_json([
		'ok' => false,
		'error' => 'forbidden',
		'message' => 'Upload forbidden for this account.',
	], 403);
}

$account = isset($_GET['account']) ? trim((string) $_GET['account']) : '';
$file = isset($_GET['file']) ? (string) $_GET['file'] : '';
$bytes = isset($_GET['bytes']) ? (int) $_GET['bytes'] : 0;
$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
$next = isset($_GET['next']) ? (string) $_GET['next'] : '';
$check = isset($_GET['check']) && (string) $_GET['check'] === '1';

$file = str_replace(["'", "\0", '/', '\\'], '', basename(str_replace('\\', '/', $file)));
if ($file === '' || $file === '.' || $file === '..' || strlen($file) > 200) {
	kloxo_backup_upload_json([
		'ok' => false,
		'error' => 'invalid_file_name',
		'message' => 'Upload failed: invalid file name.',
	], 400);
}
if ($account === '' || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $account) !== 1) {
	kloxo_backup_upload_json([
		'ok' => false,
		'error' => 'invalid_account',
		'message' => 'Upload failed: invalid account.',
	], 400);
}

$loginName = (string) $login->nname;
$isAdmin = method_exists($login, 'isAdmin') && $login->isAdmin();
if (!$isAdmin && $account !== $loginName) {
	kloxo_backup_upload_json([
		'ok' => false,
		'error' => 'forbidden',
		'message' => 'Upload forbidden for this account.',
	], 403);
}

$dir = '/home/kloxo/client/' . $account . '/__backup';
$dest = $dir . '/' . $file;

/**
 * Human text for the browser toast (size is irrelevant — show the real cause).
 */
function kloxo_backup_upload_message(string $code, string $path = ''): string
{
	switch ($code) {
		case 'token_not_match':
			return 'Upload failed: session expired — refresh the page and try again.';
		case 'forbidden':
			return 'Upload forbidden for this account.';
		case 'invalid_file_name':
			return 'Upload failed: invalid file name.';
		case 'invalid_account':
			return 'Upload failed: invalid account.';
		case 'file_too_large':
			return 'File over 10 GB. Use scp/SFTP into __backup or Fetch from FTP, then restore from the list.';
		case 'size_mismatch':
			return 'Upload failed: browser size did not match the request.';
		case 'backup_dir_not_writable':
			$hint = $path !== '' ? $path : '/home/kloxo/client/<account>/__backup';

			return 'Backup folder not writable: ' . $hint
				. ' — as root: chown -R lxlabs:lxlabs ' . dirname($hint);
		case 'cannot_open_temp':
		case 'cannot_read_body':
		case 'incomplete_upload':
		case 'cannot_install_file':
		case 'size_check_failed':
			return 'Upload failed while saving the file (got '
				. str_replace('_', ' ', $code) . '). Try again.';
		default:
			return 'Upload failed (' . $code . ').';
	}
}

if ($check) {
	$size = is_file($dest) ? (int) @filesize($dest) : 0;
	$partial = false;
	if ($size < 1 && is_dir($dir)) {
		foreach ((array) @scandir($dir) as $n) {
			if (is_string($n) && str_starts_with($n, $file . '.uploading.')) {
				$psz = (int) @filesize($dir . '/' . $n);
				if ($psz > 0) {
					$partial = true;
					$size = $psz;
				}
			}
		}
	}
	$ready = $size > 0 && !$partial && ($bytes < 1 || $size === $bytes || $size >= (int) ($bytes * 0.99));
	kloxo_backup_upload_json([
		'ok' => true,
		'ready' => $ready,
		'partial' => $partial,
		'size' => $size,
		'expect' => $bytes,
		'path' => $dest,
	]);
}

// CSRF: panel token in query (FormData uploads use frm_token; raw body cannot).
$sess = '';
if (function_exists('getCSRFToken')) {
	try {
		$sess = (string) getCSRFToken();
	} catch (Throwable $e) {
		$sess = '';
	}
}
if ($sess === '' || $token === '' || !hash_equals($sess, $token)) {
	kloxo_backup_upload_json([
		'ok' => false,
		'error' => 'token_not_match',
		'message' => kloxo_backup_upload_message('token_not_match'),
	], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	kloxo_backup_upload_json([
		'ok' => false,
		'error' => 'method_not_allowed',
		'message' => 'Upload failed: method not allowed.',
	], 405);
}

if ($bytes > KLOXO_BACKUP_UPLOAD_MAX) {
	kloxo_backup_upload_json([
		'ok' => false,
		'error' => 'file_too_large',
		'message' => kloxo_backup_upload_message('file_too_large'),
	], 413);
}
$cl = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
if ($cl > KLOXO_BACKUP_UPLOAD_MAX) {
	kloxo_backup_upload_json([
		'ok' => false,
		'error' => 'file_too_large',
		'message' => kloxo_backup_upload_message('file_too_large'),
	], 413);
}
if ($bytes > 0 && $cl > 0 && $bytes !== $cl) {
	kloxo_backup_upload_json([
		'ok' => false,
		'error' => 'size_mismatch',
		'message' => kloxo_backup_upload_message('size_mismatch'),
	], 400);
}

// Prepare __backup without blocking the browser on a hung kloxo-backend.
// A full ensureDir via rl_exec can stall the XHR after progress hits 100%,
// leaving the yellow "Server is saving…" toast forever (even for 16 KB).
if (!is_dir($dir)) {
	@mkdir($dir, 0755, true);
}
$parentClient = dirname($dir);
if (!is_dir($parentClient)) {
	@mkdir($parentClient, 0755, true);
}
$writable = is_dir($dir) && is_writable($dir);
if (!$writable) {
	if (function_exists('lxfile_generic_chown')) {
		lxfile_generic_chown($parentClient, 'lxlabs');
		lxfile_generic_chown($dir, 'lxlabs');
	}
	@chmod($dir, 0755);
	$writable = is_dir($dir) && is_writable($dir);
}
if (!$writable) {
	if (!class_exists('lxbackup', false)) {
		$lb = dirname(__DIR__, 2) . '/lib/html/lxbackuplib.php';
		if (is_file($lb)) {
			include_once $lb;
		}
	}
	if (class_exists('lxbackup', false) && function_exists('rl_exec_get')) {
		try {
			// One quick root mkdir/chown only — avoid double rl_exec + probes
			// on the upload critical path.
			rl_exec_get(null, 'localhost', ['lxbackup', 'MakeSureDirectoryExists'], [$dir . '/']);
		} catch (Throwable $e) {
			if (function_exists('log_log')) {
				log_log('error', 'backup-upload MakeSureDirectoryExists: ' . $e->getMessage());
			}
		}
	}
	$writable = is_dir($dir) && is_writable($dir);
}
if (!$writable) {
	kloxo_backup_upload_json([
		'ok' => false,
		'error' => 'backup_dir_not_writable',
		'path' => $dir,
		'message' => kloxo_backup_upload_message('backup_dir_not_writable', $dir),
	], 500);
}
$dest = rtrim($dir, '/') . '/' . $file;

$part = $dest . '.uploading.' . getmypid();
@unlink($part);
$out = @fopen($part, 'wb');
if ($out === false) {
	kloxo_backup_upload_json([
		'ok' => false,
		'error' => 'cannot_open_temp',
		'message' => kloxo_backup_upload_message('cannot_open_temp'),
		'path' => $dir,
	], 500);
}
$in = @fopen('php://input', 'rb');
if ($in === false) {
	fclose($out);
	@unlink($part);
	kloxo_backup_upload_json([
		'ok' => false,
		'error' => 'cannot_read_body',
		'message' => kloxo_backup_upload_message('cannot_read_body'),
	], 500);
}

$copied = @stream_copy_to_stream($in, $out);
fclose($in);
fclose($out);

$written = is_file($part) ? (int) @filesize($part) : 0;
$expect = $bytes > 0 ? $bytes : $cl;
if ($copied === false || $written < 1 || ($expect > 0 && $written !== $expect)) {
	@unlink($part);
	kloxo_backup_upload_json([
		'ok' => false,
		'error' => 'incomplete_upload',
		'written' => $written,
		'expect' => $expect,
		'message' => kloxo_backup_upload_message('incomplete_upload'),
	], 500);
}

@chmod($part, 0640);
if (function_exists('lxfile_generic_chown')) {
	lxfile_generic_chown($part, 'lxlabs');
}
if (is_file($dest)) {
	@unlink($dest);
}
if (!@rename($part, $dest)) {
	if (!@copy($part, $dest)) {
		@unlink($part);
		kloxo_backup_upload_json([
			'ok' => false,
			'error' => 'cannot_install_file',
			'message' => kloxo_backup_upload_message('cannot_install_file'),
		], 500);
	}
	@unlink($part);
}
@chmod($dest, 0640);
if (function_exists('lxfile_generic_chown')) {
	lxfile_generic_chown($dest, 'lxlabs');
}

$final = (int) @filesize($dest);
if ($final < 1 || ($expect > 0 && $final !== $expect)) {
	@unlink($dest);
	kloxo_backup_upload_json([
		'ok' => false,
		'error' => 'size_check_failed',
		'size' => $final,
		'message' => kloxo_backup_upload_message('size_check_failed'),
	], 500);
}

// Only allow a fixed success landing (security N23) — no caller-controlled
// display.php actions that could chain CSRF.
$redirect = '/display.php?frm_action=show&frm_smessage=file_uploaded';
if (is_string($next) && preg_match('#^/display\.php\?(?:[A-Za-z0-9_.=&%-]*frm_action=show(?:&|$))#', $next) === 1
	&& !preg_match('#frm_action=(?:add|update|delete)#i', $next)
	&& !str_contains($next, "\n") && !str_contains($next, "\r")) {
	$redirect = $next;
	if (!str_contains($redirect, 'frm_smessage=')) {
		$redirect .= (str_contains($redirect, '?') ? '&' : '?') . 'frm_smessage=file_uploaded';
	}
}

kloxo_backup_upload_json([
	'ok' => true,
	'ready' => true,
	'size' => $final,
	'redirect' => $redirect,
	'path' => $dest,
]);
