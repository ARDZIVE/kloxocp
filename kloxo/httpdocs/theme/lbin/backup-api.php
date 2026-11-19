<?php

declare(strict_types=1);

/**
 * JSON API for the modern Backup / Restore console.
 *
 * GET/POST actions: list, status, backup, restore, delete,
 * upload_init, upload_chunk, upload_complete, upload_abort,
 * fetch_url, fetch_ftp
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

chdir('../../');
include_once 'lib/html/displayinclude.php';
include_once 'lib/php/kloxo_backup_ui.php';

@ignore_user_abort(true);
@set_time_limit(0);

try {
	initProgram();
} catch (Throwable $e) {
	KloxoBackupUi::json(array('ok' => false, 'error' => 'forbidden', 'message' => 'Forbidden.'), 403);
}

global $login;
if (!is_object($login) || !method_exists($login, 'nname')) {
	KloxoBackupUi::json(array('ok' => false, 'error' => 'forbidden', 'message' => 'Forbidden.'), 403);
}

$action = '';
if (isset($_GET['action'])) {
	$action = trim((string) $_GET['action']);
} elseif (isset($_POST['action'])) {
	$action = trim((string) $_POST['action']);
}

$token = '';
if (isset($_GET['token'])) {
	$token = (string) $_GET['token'];
} elseif (isset($_POST['token'])) {
	$token = (string) $_POST['token'];
} elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
	$token = (string) $_SERVER['HTTP_X_CSRF_TOKEN'];
}

$sess = '';
if (function_exists('getCSRFToken')) {
	try {
		$sess = (string) getCSRFToken();
	} catch (Throwable $e) {
		$sess = '';
	}
}
if ($sess === '' || $token === '' || !hash_equals($sess, $token)) {
	KloxoBackupUi::json(array(
		'ok' => false,
		'error' => 'token_not_match',
		'message' => 'Session expired — refresh the page and try again.',
	), 403);
}


function kloxo_backup_api_msg(Throwable $e): string
{
	$code = $e->getMessage();
	$map = array(
		'invalid_file_name' => 'Invalid file name.',
		'invalid_account' => 'Invalid account.',
		'forbidden' => 'Not allowed.',
		'file_too_large' => 'File over 10 GB. Use scp/SFTP into __backup, then restore from the list.',
		'not_an_archive' => 'Use a .tgz, .tar.gz, .tar or .zip archive.',
		'empty_file' => 'Empty file.',
		'backup_dir_not_writable' => 'Backup folder is not writable. As root: chown -R lxlabs:lxlabs the client __backup folder.',
		'file_not_found' => 'Archive not found.',
		'file_exists' => 'File already exists.',
		'invalid_url' => 'Enter a full https:// URL including the file name.',
		'ftp_fields_required' => 'FTP server, username and remote file are required.',
		'fetch_empty' => 'Download produced an empty file.',
		'ftp_connect_failed' => 'Could not connect to the FTP server.',
		'ftp_login_failed' => 'FTP login failed.',
		'ftp_get_failed' => 'Could not download the remote FTP file.',
		'upload_not_found' => 'Upload session expired — start again.',
		'size_check_failed' => 'Uploaded size did not match — try again.',
		'cannot_delete' => 'Could not delete the archive.',
		'missing_chunk' => 'Upload incomplete — try again.',
	);

	return $map[$code] ?? ('Error: ' . $code);
}

$account = isset($_REQUEST['account']) ? trim((string) $_REQUEST['account']) : '';
if ($account === '') {
	$account = (string) $login->nname;
}

try {
	$acc = KloxoBackupUi::resolveAccount($login, $account);
} catch (InvalidArgumentException $e) {
	KloxoBackupUi::json(array('ok' => false, 'error' => $e->getMessage(), 'message' => kloxo_backup_api_msg($e)), 400);
} catch (Throwable $e) {
	$code = $e->getMessage() === 'forbidden' ? 403 : 500;
	$msg = $e->getMessage() === 'forbidden' ? 'Not allowed.' : kloxo_backup_api_msg($e);
	KloxoBackupUi::json(array('ok' => false, 'error' => $e->getMessage(), 'message' => $msg), $code);
}

$dir = $acc['dir'];
$table = $acc['table'];
$name = $acc['name'];

/**
 * Load the lxbackup row for this client.
 */
function kloxo_backup_api_load(string $table, string $clientName): ?lxbackup
{
	if (!class_exists('lxbackup', false)) {
		include_once 'lib/html/lxbackuplib.php';
	}
	$nname = $table . '-' . $clientName;
	try {
		$o = new lxbackup(null, null, $nname);
		$o->get();
		if (isset($o->nname) && (string) $o->nname !== '') {
			return $o;
		}
	} catch (Throwable $e) {
		// try create
	}
	try {
		$o = new lxbackup(null, null, $nname);
		$o->parent_clname = createParentName($table, $clientName);
		$o->dbaction = 'add';
		$o->write();
		$o->get();

		return $o;
	} catch (Throwable $e) {
		return null;
	}
}

try {
	switch ($action) {
		case 'list':
			// Soft mkdir only — never block list on rl_exec/ensureDir.
			if (!is_dir($dir)) {
				@mkdir($dir, 0755, true);
			}
			KloxoBackupUi::json(array(
				'ok' => true,
				'account' => $name,
				'dir' => $dir,
				'max' => KloxoBackupUi::MAX_BYTES,
				'archives' => KloxoBackupUi::listArchives($dir),
				'writable' => is_dir($dir) && is_writable($dir),
			));

		case 'download':
			$file = isset($_GET['file']) ? (string) $_GET['file'] : (isset($_POST['file']) ? (string) $_POST['file'] : '');
			try {
				$file = KloxoBackupUi::safeFileName($file);
			} catch (Throwable $e) {
				KloxoBackupUi::json(array('ok' => false, 'error' => 'invalid_file_name', 'message' => kloxo_backup_api_msg($e)), 400);
			}
			$path = rtrim($dir, '/') . '/' . $file;
			if (!is_file($path) || !is_readable($path)) {
				KloxoBackupUi::json(array('ok' => false, 'error' => 'file_not_found', 'message' => 'Archive not found.'), 404);
			}
			while (ob_get_level() > 0) {
				@ob_end_clean();
			}
			$size = (int) @filesize($path);
			header('Content-Type: application/octet-stream');
			header('Content-Disposition: attachment; filename="' . str_replace('"', '', $file) . '"');
			header('Content-Length: ' . (string) $size);
			header('Cache-Control: no-store');
			header('X-Content-Type-Options: nosniff');
			$fp = fopen($path, 'rb');
			if ($fp === false) {
				KloxoBackupUi::json(array('ok' => false, 'error' => 'cannot_read', 'message' => 'Could not read archive.'), 500);
			}
			fpassthru($fp);
			fclose($fp);
			exit(0);

		case 'status':
			$bak = kloxo_backup_api_load($table, $name);
			KloxoBackupUi::json(array(
				'ok' => true,
				'backupstage' => is_object($bak) && isset($bak->backupstage) ? (string) $bak->backupstage : '',
				'restorestage' => is_object($bak) && isset($bak->restorestage) ? (string) $bak->restorestage : '',
			));

		case 'backup':
			if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
				KloxoBackupUi::json(array('ok' => false, 'error' => 'method_not_allowed'), 405);
			}
			$prefix = isset($_POST['prefix']) ? trim((string) $_POST['prefix']) : '';
			$prefix = preg_replace('/[^A-Za-z0-9._-]/', '', $prefix) ?? '';
			if (strlen($prefix) > 40) {
				$prefix = substr($prefix, 0, 40);
			}
			if ($prefix === '') {
				$prefix = 'backup';
			}
			$bak = kloxo_backup_api_load($table, $name);
			if (!is_object($bak)) {
				KloxoBackupUi::json(array('ok' => false, 'error' => 'no_backup_object', 'message' => 'Could not load backup settings.'), 500);
			}
			$parent = $bak->backupParent();
			if (!is_object($parent) && class_exists($table)) {
				try {
					$parent = new $table(null, null, $name);
					$parent->get();
					$bak->__parent_o = $parent;
				} catch (Throwable $e) {
					$parent = null;
				}
			}
			if (!is_object($parent)) {
				KloxoBackupUi::json(array('ok' => false, 'error' => 'no_parent', 'message' => 'Could not find the account.'), 500);
			}
			if (isset($parent->priv) && is_object($parent->priv)
				&& method_exists($parent->priv, 'isOn') && !$parent->priv->isOn('backup_flag')) {
				KloxoBackupUi::json(array('ok' => false, 'error' => 'no_permission', 'message' => 'Backup is not allowed for this account.'), 403);
			}
			KloxoBackupUi::ensureWritableDir($dir);
			$param = array('backup_to_file_f' => $prefix);
			$bak->backupstage = 'doing';
			$bak->backuptype = 'backup';
			$bak->metadbaction = 'writeonly';
			$bak->dbaction = 'update';
			$bak->write();
			$stage = '';
			try {
				$got = rl_exec_get(null, 'localhost', array('lxbackup', 'execbackupphp'),
					array($table, $name, $param), false);
				$stage = is_string($got) ? $got : '';
			} catch (Throwable $e) {
				$stage = 'Failed due to: ' . $e->getMessage();
			}
			if ($stage === '' || $stage === 'doing') {
				$stage = 'Failed due to: backup did not finish';
			}
			$bak->backupstage = $stage;
			$bak->dbaction = 'update';
			$bak->write();
			if (trim($stage) === 'done' || str_starts_with(trim($stage), 'done')) {
				KloxoBackupUi::json(array(
					'ok' => true,
					'message' => 'Backup completed.',
					'stage' => $stage,
					'archives' => KloxoBackupUi::listArchives($dir),
				));
			}
			KloxoBackupUi::json(array(
				'ok' => false,
				'error' => 'backup_failed',
				'message' => $stage,
				'stage' => $stage,
			), 500);

		case 'restore':
			if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
				KloxoBackupUi::json(array('ok' => false, 'error' => 'method_not_allowed'), 405);
			}
			$file = isset($_POST['file']) ? (string) $_POST['file'] : '';
			try {
				$file = KloxoBackupUi::safeFileName($file);
			} catch (Throwable $e) {
				KloxoBackupUi::json(array('ok' => false, 'error' => 'invalid_file_name', 'message' => kloxo_backup_api_msg($e)), 400);
			}
			$path = rtrim($dir, '/') . '/' . $file;
			if (!is_file($path)) {
				KloxoBackupUi::json(array('ok' => false, 'error' => 'file_not_found', 'message' => 'Archive not found.'), 404);
			}
			$bak = kloxo_backup_api_load($table, $name);
			if (!is_object($bak)) {
				KloxoBackupUi::json(array('ok' => false, 'error' => 'no_backup_object', 'message' => 'Could not load backup settings.'), 500);
			}
			$param = array(
				'backup_from_file_f' => $file,
				'_accountselect' => array('all'),
			);
			$bak->restorestage = 'doing';
			$bak->backuptype = 'restore';
			$bak->metadbaction = 'writeonly';
			$bak->dbaction = 'update';
			$bak->write();
			$stage = '';
			try {
				$got = rl_exec_get(null, 'localhost', array('lxbackup', 'execrestorephp'),
					array($table, $name, $path, $param), false);
				$stage = is_string($got) ? $got : '';
			} catch (Throwable $e) {
				$stage = 'Restore failed due to ' . $e->getMessage();
			}
			if ($stage === '' || $stage === 'doing') {
				$stage = 'Restore failed due to: restore did not finish';
			}
			$bak->restorestage = $stage;
			$bak->dbaction = 'update';
			$bak->write();
			if (str_starts_with(trim($stage), 'done')) {
				KloxoBackupUi::json(array(
					'ok' => true,
					'message' => 'Restore completed.',
					'stage' => $stage,
				));
			}
			KloxoBackupUi::json(array(
				'ok' => false,
				'error' => 'restore_failed',
				'message' => $stage,
				'stage' => $stage,
			), 500);

		case 'delete':
			if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
				KloxoBackupUi::json(array('ok' => false, 'error' => 'method_not_allowed'), 405);
			}
			$file = isset($_POST['file']) ? (string) $_POST['file'] : '';
			try {
				KloxoBackupUi::deleteArchive($dir, $file);
			} catch (Throwable $e) {
				KloxoBackupUi::json(array('ok' => false, 'error' => $e->getMessage(), 'message' => kloxo_backup_api_msg($e)), 400);
			}
			KloxoBackupUi::json(array(
				'ok' => true,
				'message' => 'Archive deleted.',
				'archives' => KloxoBackupUi::listArchives($dir),
			));

		case 'upload_init':
			if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
				KloxoBackupUi::json(array('ok' => false, 'error' => 'method_not_allowed'), 405);
			}
			$file = isset($_POST['file']) ? (string) $_POST['file'] : '';
			$size = isset($_POST['size']) ? (int) $_POST['size'] : 0;
			try {
				$init = KloxoBackupUi::uploadInit($dir, $file, $size);
			} catch (Throwable $e) {
				KloxoBackupUi::json(array('ok' => false, 'error' => $e->getMessage(), 'message' => kloxo_backup_api_msg($e)), 400);
			}
			KloxoBackupUi::json(array('ok' => true) + $init);

		case 'upload_chunk':
			if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
				KloxoBackupUi::json(array('ok' => false, 'error' => 'method_not_allowed'), 405);
			}
			$uploadId = isset($_GET['upload_id']) ? (string) $_GET['upload_id']
				: (isset($_POST['upload_id']) ? (string) $_POST['upload_id'] : '');
			$index = isset($_GET['index']) ? (int) $_GET['index']
				: (isset($_POST['index']) ? (int) $_POST['index'] : -1);
			$body = (string) file_get_contents('php://input');
			try {
				$got = KloxoBackupUi::uploadChunk($dir, $uploadId, $index, $body);
			} catch (Throwable $e) {
				KloxoBackupUi::json(array('ok' => false, 'error' => $e->getMessage(), 'message' => kloxo_backup_api_msg($e)), 400);
			}
			KloxoBackupUi::json(array('ok' => true) + $got);

		case 'upload_complete':
			if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
				KloxoBackupUi::json(array('ok' => false, 'error' => 'method_not_allowed'), 405);
			}
			$uploadId = isset($_POST['upload_id']) ? (string) $_POST['upload_id'] : '';
			try {
				$got = KloxoBackupUi::uploadComplete($dir, $uploadId);
			} catch (Throwable $e) {
				KloxoBackupUi::json(array('ok' => false, 'error' => $e->getMessage(), 'message' => kloxo_backup_api_msg($e)), 400);
			}
			KloxoBackupUi::json(array(
				'ok' => true,
				'message' => 'File uploaded.',
				'name' => $got['name'],
				'size' => $got['size'],
				'archives' => KloxoBackupUi::listArchives($dir),
			));

		case 'upload_abort':
			$uploadId = isset($_POST['upload_id']) ? (string) $_POST['upload_id']
				: (isset($_GET['upload_id']) ? (string) $_GET['upload_id'] : '');
			KloxoBackupUi::uploadAbort($dir, $uploadId);
			KloxoBackupUi::json(array('ok' => true));

		case 'fetch_url':
			if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
				KloxoBackupUi::json(array('ok' => false, 'error' => 'method_not_allowed'), 405);
			}
			$url = isset($_POST['url']) ? (string) $_POST['url'] : '';
			try {
				$got = KloxoBackupUi::fetchUrl($dir, $url, true);
			} catch (Throwable $e) {
				KloxoBackupUi::json(array('ok' => false, 'error' => $e->getMessage(), 'message' => kloxo_backup_api_msg($e)), 400);
			}
			KloxoBackupUi::json(array(
				'ok' => true,
				'message' => 'Archive fetched from URL.',
				'name' => $got['name'],
				'size' => $got['size'],
				'archives' => KloxoBackupUi::listArchives($dir),
			));

		case 'fetch_ftp':
			if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
				KloxoBackupUi::json(array('ok' => false, 'error' => 'method_not_allowed'), 405);
			}
			try {
				$got = KloxoBackupUi::fetchFtp(
					$dir,
					isset($_POST['server']) ? (string) $_POST['server'] : '',
					isset($_POST['user']) ? (string) $_POST['user'] : '',
					isset($_POST['pass']) ? (string) $_POST['pass'] : '',
					isset($_POST['file']) ? (string) $_POST['file'] : '',
					true
				);
			} catch (Throwable $e) {
				KloxoBackupUi::json(array('ok' => false, 'error' => $e->getMessage(), 'message' => kloxo_backup_api_msg($e)), 400);
			}
			KloxoBackupUi::json(array(
				'ok' => true,
				'message' => 'Archive fetched from FTP.',
				'name' => $got['name'],
				'size' => $got['size'],
				'archives' => KloxoBackupUi::listArchives($dir),
			));

		default:
			KloxoBackupUi::json(array(
				'ok' => false,
				'error' => 'unknown_action',
				'message' => 'Unknown action.',
			), 400);
	}
} catch (Throwable $e) {
	KloxoBackupUi::json(array(
		'ok' => false,
		'error' => 'server_error',
		'message' => kloxo_backup_api_msg($e),
	), 500);
}
