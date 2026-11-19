<?php

declare(strict_types=1);

/**
 * Modern Backup / Restore console helpers (AJAX API + HTML shell).
 * Keeps KloxoBackup / lxbackup for pack and restore; this layer is UX + transfer.
 *
 * Copyright (C) 2026 Kloxo CP contributors
 * GNU Affero General Public License v3 or later. See LICENSE.txt.
 */

final class KloxoBackupUi
{
	/** 10 GiB — matches Hiawatha MaxUploadSize ceiling (10240 MB). */
	public const MAX_BYTES = 10737418240;

	/** Chunk size under Hiawatha MaxRequestSize (~5 MiB). */
	public const CHUNK_BYTES = 4194304;

	public const ARCHIVE_EXT = '/\.(tgz|tar\.gz|tar|gz|zip)$/i';

	/**
	 * @return never
	 */
	public static function json(array $data, int $code = 200): void
	{
		while (ob_get_level() > 0) {
			@ob_end_clean();
		}
		@ini_set('zlib.output_compression', '0');
		if (!headers_sent()) {
			http_response_code($code);
			header('Content-Type: application/json; charset=UTF-8');
			header('Cache-Control: no-store');
			header('X-Content-Type-Options: nosniff');
		}
		echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (function_exists('fastcgi_finish_request')) {
			@fastcgi_finish_request();
		}
		exit(0);
	}

	public static function safeFileName(string $name): string
	{
		$name = str_replace(["\0", "'", '/', '\\'], '', basename(str_replace('\\', '/', $name)));
		if ($name === '' || $name === '.' || $name === '..' || strlen($name) > 200) {
			throw new InvalidArgumentException('invalid_file_name');
		}

		return $name;
	}

	public static function isArchiveName(string $name): bool
	{
		return preg_match(self::ARCHIVE_EXT, $name) === 1;
	}

	/**
	 * @return array{table:string,name:string,dir:string}
	 */
	public static function resolveAccount(object $login, string $account): array
	{
		$account = trim($account);
		if ($account === '' || preg_match('/^[A-Za-z0-9._-]{1,64}$/', $account) !== 1) {
			throw new InvalidArgumentException('invalid_account');
		}
		$loginName = method_exists($login, 'nname') ? (string) $login->nname : '';
		$isAdmin = method_exists($login, 'isAdmin') && $login->isAdmin();
		if (!$isAdmin && $account !== $loginName) {
			throw new RuntimeException('forbidden');
		}
		if (!class_exists('KloxoBackup', false)) {
			include_once __DIR__ . '/kloxo_backup.php';
		}
		$dir = KloxoBackup::ensureDir('client', $account);
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}

		return array('table' => 'client', 'name' => $account, 'dir' => $dir);
	}

	public static function ensureWritableDir(string $dir): void
	{
		if (!is_dir($dir)) {
			@mkdir($dir, 0755, true);
		}
		$parent = dirname($dir);
		if (function_exists('lxfile_generic_chown')) {
			if (is_dir($parent)) {
				lxfile_generic_chown($parent, 'lxlabs');
			}
			lxfile_generic_chown($dir, 'lxlabs');
		}
		@chmod($dir, 0755);
		if (!is_dir($dir) || !is_writable($dir)) {
			if (class_exists('lxbackup', false) && function_exists('rl_exec_get')) {
				try {
					rl_exec_get(null, 'localhost', array('lxbackup', 'MakeSureDirectoryExists'), array($dir . '/'));
				} catch (Throwable $e) {
					// fall through
				}
			}
		}
		if (!is_dir($dir) || !is_writable($dir)) {
			throw new RuntimeException('backup_dir_not_writable');
		}
	}

	/**
	 * @return list<array{name:string,size:int,mtime:int,human:string}>
	 */
	public static function listArchives(string $dir): array
	{
		$out = array();
		if (!is_dir($dir)) {
			return $out;
		}
		$names = @scandir($dir);
		if (!is_array($names)) {
			return $out;
		}
		foreach ($names as $n) {
			if (!is_string($n) || $n === '.' || $n === '..') {
				continue;
			}
			if (str_starts_with($n, '.') || str_contains($n, '.uploading.') || str_contains($n, '.chunk.')) {
				continue;
			}
			$path = $dir . '/' . $n;
			if (!is_file($path) || !self::isArchiveName($n)) {
				continue;
			}
			$size = (int) @filesize($path);
			$mtime = (int) @filemtime($path);
			$out[] = array(
				'name' => $n,
				'size' => $size,
				'mtime' => $mtime,
				'human' => self::humanBytes($size),
			);
		}
		usort($out, static function (array $a, array $b): int {
			return ($b['mtime'] <=> $a['mtime']) ?: strcmp($a['name'], $b['name']);
		});

		return $out;
	}

	public static function humanBytes(int $bytes): string
	{
		if ($bytes < 1024) {
			return $bytes . ' B';
		}
		$u = array('KB', 'MB', 'GB', 'TB');
		$v = (float) $bytes;
		$i = -1;
		do {
			$v /= 1024.0;
			$i++;
		} while ($v >= 1024.0 && $i < count($u) - 1);

		return (string) (round($v, $v >= 10 ? 1 : 2)) . ' ' . $u[$i];
	}

	public static function chunkDir(string $backupDir, string $uploadId): string
	{
		$id = preg_replace('/[^a-zA-Z0-9_-]/', '', $uploadId) ?? '';
		if ($id === '' || strlen($id) > 64) {
			throw new InvalidArgumentException('invalid_upload_id');
		}

		return rtrim($backupDir, '/') . '/.chunk.' . $id;
	}

	/**
	 * @return array{upload_id:string,chunk_size:int,max:int}
	 */
	public static function uploadInit(string $dir, string $fileName, int $size): array
	{
		self::ensureWritableDir($dir);
		$fileName = self::safeFileName($fileName);
		if (!self::isArchiveName($fileName)) {
			throw new InvalidArgumentException('not_an_archive');
		}
		if ($size < 1) {
			throw new InvalidArgumentException('empty_file');
		}
		if ($size > self::MAX_BYTES) {
			throw new InvalidArgumentException('file_too_large');
		}
		$id = bin2hex(random_bytes(16));
		$cdir = self::chunkDir($dir, $id);
		if (!@mkdir($cdir, 0700, true) && !is_dir($cdir)) {
			throw new RuntimeException('cannot_create_chunk_dir');
		}
		$meta = array(
			'file' => $fileName,
			'size' => $size,
			'received' => 0,
			'chunks' => array(),
			'created' => time(),
		);
		if (@file_put_contents($cdir . '/meta.json', json_encode($meta)) === false) {
			throw new RuntimeException('cannot_write_meta');
		}

		return array(
			'upload_id' => $id,
			'chunk_size' => self::CHUNK_BYTES,
			'max' => self::MAX_BYTES,
		);
	}

	/**
	 * @return array{ok:bool,received:int,index:int}
	 */
	public static function uploadChunk(string $dir, string $uploadId, int $index, string $body): array
	{
		if ($index < 0 || $index > 100000) {
			throw new InvalidArgumentException('invalid_chunk_index');
		}
		$len = strlen($body);
		if ($len < 1 || $len > self::CHUNK_BYTES) {
			throw new InvalidArgumentException('invalid_chunk_size');
		}
		$cdir = self::chunkDir($dir, $uploadId);
		$metaPath = $cdir . '/meta.json';
		if (!is_file($metaPath)) {
			throw new RuntimeException('upload_not_found');
		}
		$meta = json_decode((string) @file_get_contents($metaPath), true);
		if (!is_array($meta) || !isset($meta['file'], $meta['size'])) {
			throw new RuntimeException('upload_meta_corrupt');
		}
		$expect = (int) $meta['size'];
		$received = (int) ($meta['received'] ?? 0);
		if (($received + $len) > $expect || ($received + $len) > self::MAX_BYTES) {
			throw new InvalidArgumentException('size_exceeded');
		}
		$part = $cdir . '/part.' . str_pad((string) $index, 6, '0', STR_PAD_LEFT);
		if (@file_put_contents($part, $body) === false) {
			throw new RuntimeException('cannot_write_chunk');
		}
		$chunks = isset($meta['chunks']) && is_array($meta['chunks']) ? $meta['chunks'] : array();
		$chunks[(string) $index] = $len;
		$meta['chunks'] = $chunks;
		$meta['received'] = $received + $len;
		@file_put_contents($metaPath, json_encode($meta));

		return array('ok' => true, 'received' => (int) $meta['received'], 'index' => $index);
	}

	/**
	 * @return array{ok:bool,name:string,size:int,path:string}
	 */
	public static function uploadComplete(string $dir, string $uploadId): array
	{
		$cdir = self::chunkDir($dir, $uploadId);
		$metaPath = $cdir . '/meta.json';
		if (!is_file($metaPath)) {
			throw new RuntimeException('upload_not_found');
		}
		$meta = json_decode((string) @file_get_contents($metaPath), true);
		if (!is_array($meta) || !isset($meta['file'], $meta['size'])) {
			throw new RuntimeException('upload_meta_corrupt');
		}
		$fileName = self::safeFileName((string) $meta['file']);
		$expect = (int) $meta['size'];
		$chunks = isset($meta['chunks']) && is_array($meta['chunks']) ? $meta['chunks'] : array();
		ksort($chunks, SORT_NUMERIC);
		$dest = rtrim($dir, '/') . '/' . $fileName;
		$part = $dest . '.uploading.' . getmypid();
		@unlink($part);
		$out = @fopen($part, 'wb');
		if ($out === false) {
			throw new RuntimeException('cannot_open_temp');
		}
		$written = 0;
		foreach ($chunks as $idx => $len) {
			$p = $cdir . '/part.' . str_pad((string) $idx, 6, '0', STR_PAD_LEFT);
			if (!is_file($p)) {
				fclose($out);
				@unlink($part);
				throw new RuntimeException('missing_chunk');
			}
			$in = @fopen($p, 'rb');
			if ($in === false) {
				fclose($out);
				@unlink($part);
				throw new RuntimeException('cannot_read_chunk');
			}
			$copied = stream_copy_to_stream($in, $out);
			fclose($in);
			if ($copied === false) {
				fclose($out);
				@unlink($part);
				throw new RuntimeException('incomplete_upload');
			}
			$written += (int) $copied;
		}
		fclose($out);
		if ($written !== $expect) {
			@unlink($part);
			throw new RuntimeException('size_check_failed');
		}
		@chmod($part, 0640);
		if (function_exists('lxfile_generic_chown')) {
			lxfile_generic_chown($part, 'lxlabs');
		}
		if (is_file($dest)) {
			@unlink($dest);
		}
		if (!@rename($part, $dest) && !(@copy($part, $dest) && @unlink($part))) {
			@unlink($part);
			throw new RuntimeException('cannot_install_file');
		}
		@chmod($dest, 0640);
		if (function_exists('lxfile_generic_chown')) {
			lxfile_generic_chown($dest, 'lxlabs');
		}
		self::rmTree($cdir);

		return array(
			'ok' => true,
			'name' => $fileName,
			'size' => $written,
			'path' => $dest,
		);
	}

	public static function uploadAbort(string $dir, string $uploadId): void
	{
		try {
			$cdir = self::chunkDir($dir, $uploadId);
			self::rmTree($cdir);
		} catch (Throwable $e) {
			// ignore
		}
	}

	public static function deleteArchive(string $dir, string $fileName): void
	{
		$fileName = self::safeFileName($fileName);
		$path = rtrim($dir, '/') . '/' . $fileName;
		if (!is_file($path)) {
			throw new RuntimeException('file_not_found');
		}
		if (!@unlink($path)) {
			throw new RuntimeException('cannot_delete');
		}
	}

	/**
	 * Fetch a remote HTTP(S) archive into __backup.
	 *
	 * @return array{ok:bool,name:string,size:int}
	 */
	public static function fetchUrl(string $dir, string $url, bool $overwrite = true): array
	{
		self::ensureWritableDir($dir);
		$url = trim($url);
		if ($url === '' || !preg_match('#^https://#i', $url)) {
			throw new InvalidArgumentException('invalid_url');
		}
		$path = parse_url($url, PHP_URL_PATH);
		$name = is_string($path) ? basename($path) : '';
		$name = self::safeFileName($name !== '' ? $name : ('url-fetch-' . time() . '.tgz'));
		if (!self::isArchiveName($name)) {
			$name .= '.tgz';
		}
		$dest = rtrim($dir, '/') . '/' . $name;
		if (is_file($dest) && !$overwrite) {
			throw new RuntimeException('file_exists');
		}
		$part = $dest . '.uploading.' . getmypid();
		@unlink($part);
		if (function_exists('download_file')) {
			download_file($url, $part);
		} else {
			self::curlToFile($url, $part);
		}
		$size = is_file($part) ? (int) @filesize($part) : 0;
		if ($size < 1) {
			@unlink($part);
			throw new RuntimeException('fetch_empty');
		}
		if ($size > self::MAX_BYTES) {
			@unlink($part);
			throw new InvalidArgumentException('file_too_large');
		}
		@chmod($part, 0640);
		if (function_exists('lxfile_generic_chown')) {
			lxfile_generic_chown($part, 'lxlabs');
		}
		if (is_file($dest)) {
			@unlink($dest);
		}
		if (!@rename($part, $dest) && !(@copy($part, $dest) && @unlink($part))) {
			@unlink($part);
			throw new RuntimeException('cannot_install_file');
		}

		return array('ok' => true, 'name' => $name, 'size' => $size);
	}

	/**
	 * Fetch from FTP into __backup.
	 *
	 * @return array{ok:bool,name:string,size:int}
	 */
	public static function fetchFtp(
		string $dir,
		string $server,
		string $user,
		string $pass,
		string $remoteFile,
		bool $overwrite = true
	): array {
		self::ensureWritableDir($dir);
		$server = trim($server);
		$user = trim($user);
		$remoteFile = trim($remoteFile);
		if ($server === '' || $user === '' || $remoteFile === '') {
			throw new InvalidArgumentException('ftp_fields_required');
		}
		if (preg_match('/[\r\n]/', $server . $user . $pass . $remoteFile) === 1) {
			throw new InvalidArgumentException('invalid_ftp_input');
		}
		$name = self::safeFileName(basename(str_replace('\\', '/', $remoteFile)));
		if (!self::isArchiveName($name)) {
			throw new InvalidArgumentException('not_an_archive');
		}
		$dest = rtrim($dir, '/') . '/' . $name;
		if (is_file($dest) && !$overwrite) {
			throw new RuntimeException('file_exists');
		}
		$part = $dest . '.uploading.' . getmypid();
		@unlink($part);
		self::ftpToFile($server, $user, $pass, $remoteFile, $part);
		$size = is_file($part) ? (int) @filesize($part) : 0;
		if ($size < 1) {
			@unlink($part);
			throw new RuntimeException('fetch_empty');
		}
		if ($size > self::MAX_BYTES) {
			@unlink($part);
			throw new InvalidArgumentException('file_too_large');
		}
		@chmod($part, 0640);
		if (function_exists('lxfile_generic_chown')) {
			lxfile_generic_chown($part, 'lxlabs');
		}
		if (is_file($dest)) {
			@unlink($dest);
		}
		if (!@rename($part, $dest) && !(@copy($part, $dest) && @unlink($part))) {
			@unlink($part);
			throw new RuntimeException('cannot_install_file');
		}

		return array('ok' => true, 'name' => $name, 'size' => $size);
	}

	private static function curlToFile(string $url, string $dest): void
	{
		if (!function_exists('curl_init')) {
			throw new RuntimeException('curl_missing');
		}
		$fp = @fopen($dest, 'wb');
		if ($fp === false) {
			throw new RuntimeException('cannot_open_temp');
		}
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_FILE => $fp,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_USERAGENT => 'KloxoCP-Backup/1.0',
		));
		$ok = curl_exec($ch);
		$err = curl_error($ch);
		$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		fclose($fp);
		if ($ok === false || $code >= 400) {
			@unlink($dest);
			throw new RuntimeException('fetch_failed' . ($err !== '' ? (': ' . $err) : ''));
		}
	}

	private static function ftpToFile(
		string $server,
		string $user,
		string $pass,
		string $remoteFile,
		string $dest
	): void {
		$host = $server;
		$port = 21;
		if (str_contains($server, ':') && preg_match('/^([^:]+):(\d+)$/', $server, $m) === 1) {
			$host = $m[1];
			$port = (int) $m[2];
		}
		$conn = @ftp_connect($host, $port, 30);
		if ($conn === false) {
			throw new RuntimeException('ftp_connect_failed');
		}
		if (!@ftp_login($conn, $user, $pass)) {
			@ftp_close($conn);
			throw new RuntimeException('ftp_login_failed');
		}
		@ftp_pasv($conn, true);
		$fp = @fopen($dest, 'wb');
		if ($fp === false) {
			@ftp_close($conn);
			throw new RuntimeException('cannot_open_temp');
		}
		$ok = @ftp_fget($conn, $fp, $remoteFile, FTP_BINARY);
		fclose($fp);
		@ftp_close($conn);
		if (!$ok) {
			@unlink($dest);
			throw new RuntimeException('ftp_get_failed');
		}
	}

	private static function rmTree(string $dir): void
	{
		if (!is_dir($dir)) {
			return;
		}
		$items = @scandir($dir);
		if (!is_array($items)) {
			@rmdir($dir);

			return;
		}
		foreach ($items as $n) {
			if ($n === '.' || $n === '..') {
				continue;
			}
			$p = $dir . '/' . $n;
			if (is_dir($p)) {
				self::rmTree($p);
			} else {
				@unlink($p);
			}
		}
		@rmdir($dir);
	}

	/**
	 * HTML + boot config for the Backup / Restore console.
	 *
	 * @param array{
	 *   account:string,
	 *   token:string,
	 *   api:string,
	 *   upload:string,
	 *   backupstage?:string,
	 *   restorestage?:string,
	 *   ftp_server?:string,
	 *   rm_username?:string,
	 *   rm_directory?:string,
	 *   has_ftp?:bool,
	 *   schedule_type?:string,
	 *   schedule_time?:string,
	 *   keep?:string
	 * } $cfg
	 */
	public static function renderConsole(array $cfg): void
	{
		$css = '/theme/css/kloxo-backup-ui.css';
		$js = '/theme/js/kloxo-backup-ui.js';
		$boot = array(
			'account' => (string) ($cfg['account'] ?? ''),
			'token' => (string) ($cfg['token'] ?? ''),
			'api' => (string) ($cfg['api'] ?? '/theme/lbin/backup-api.php'),
			'upload' => (string) ($cfg['upload'] ?? '/theme/lbin/backup-upload.php'),
			'maxBytes' => self::MAX_BYTES,
			'chunkBytes' => self::CHUNK_BYTES,
			'backupstage' => (string) ($cfg['backupstage'] ?? ''),
			'restorestage' => (string) ($cfg['restorestage'] ?? ''),
			'ftp' => array(
				'server' => (string) ($cfg['ftp_server'] ?? ''),
				'user' => (string) ($cfg['rm_username'] ?? ''),
				'dir' => (string) ($cfg['rm_directory'] ?? ''),
				'configured' => !empty($cfg['has_ftp']),
			),
		);
		$bootJson = json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (!is_string($bootJson)) {
			$bootJson = '{}';
		}
		echo '<link rel="stylesheet" type="text/css" href="'
			. htmlspecialchars($css, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" />';
		echo '<div id="kloxo-backup-ui" class="kbu" data-kloxo-nobusy="1">';
		echo '<div class="kbu-hero">';
		echo '<h2 class="kbu-title">Backup &amp; Restore</h2>';
		echo '<p class="kbu-lead">Create a backup, add an archive (computer, URL or FTP), then restore in one place. Archives up to <strong>10&nbsp;GB</strong>.</p>';
		echo '</div>';

		echo '<section class="kbu-card" id="kbu-create">';
		echo '<div class="kbu-card-h">Create backup</div>';
		echo '<div class="kbu-card-b">';
		echo '<label class="kbu-label" for="kbu-prefix">Optional name prefix</label>';
		echo '<div class="kbu-row">';
		echo '<input type="text" id="kbu-prefix" class="kbu-input" maxlength="40" placeholder="e.g. before-upgrade" autocomplete="off" />';
		echo '<button type="button" class="kbu-btn kbu-btn-primary" id="kbu-btn-backup">Backup now</button>';
		echo '</div>';
		echo '<p class="kbu-hint">Saves sites, databases, mail and panel data for this account into <code>__backup</code>.</p>';
		echo '<div class="kbu-status" id="kbu-backup-status" hidden></div>';
		echo '</div></section>';

		echo '<section class="kbu-card" id="kbu-add">';
		echo '<div class="kbu-card-h">Add archive</div>';
		echo '<div class="kbu-card-b">';
		echo '<div class="kbu-tabs" role="tablist">';
		echo '<button type="button" class="kbu-tab is-on" data-tab="file" role="tab">From computer</button>';
		echo '<button type="button" class="kbu-tab" data-tab="url" role="tab">From URL</button>';
		echo '<button type="button" class="kbu-tab" data-tab="ftp" role="tab">From FTP</button>';
		echo '</div>';

		echo '<div class="kbu-pane is-on" data-pane="file">';
		echo '<div class="kbu-drop" id="kbu-drop" tabindex="0">';
		echo '<input type="file" id="kbu-file" class="kbu-file" accept=".tgz,.tar.gz,.tar,.gz,.zip,application/gzip,application/x-gzip,application/x-tar,application/zip" />';
		echo '<p class="kbu-drop-title">Drop a backup archive here, or click to browse</p>';
		echo '<p class="kbu-hint">.tgz / .tar.gz / .zip — up to 10&nbsp;GB. Keep this tab open while uploading.</p>';
		echo '</div>';
		echo '<div class="kbu-progress" id="kbu-progress" hidden>';
		echo '<div class="kbu-progress-bar"><div class="kbu-progress-fill" id="kbu-progress-fill"></div></div>';
		echo '<div class="kbu-progress-meta"><span id="kbu-progress-pct">0%</span> <span id="kbu-progress-detail"></span></div>';
		echo '</div>';
		echo '<button type="button" class="kbu-btn kbu-btn-primary" id="kbu-btn-upload" disabled>Upload</button>';
		echo '<button type="button" class="kbu-btn kbu-btn-ghost" id="kbu-btn-cancel-upload" hidden>Cancel</button>';
		echo '</div>';

		echo '<div class="kbu-pane" data-pane="url" hidden>';
		echo '<label class="kbu-label" for="kbu-url">HTTPS URL of the archive</label>';
		echo '<input type="url" id="kbu-url" class="kbu-input kbu-input-wide" placeholder="https://example.com/backup.tgz" />';
		echo '<p class="kbu-hint">HTTPS only (plain HTTP is refused).</p>';
		echo '<button type="button" class="kbu-btn kbu-btn-primary" id="kbu-btn-url">Fetch from URL</button>';
		echo '</div>';

		echo '<div class="kbu-pane" data-pane="ftp" hidden>';
		echo '<div class="kbu-grid">';
		echo '<label class="kbu-label">FTP server<input type="text" id="kbu-ftp-server" class="kbu-input" autocomplete="off" /></label>';
		echo '<label class="kbu-label">Username<input type="text" id="kbu-ftp-user" class="kbu-input" autocomplete="off" /></label>';
		echo '<label class="kbu-label">Password<input type="password" id="kbu-ftp-pass" class="kbu-input" autocomplete="new-password" /></label>';
		echo '<label class="kbu-label">Remote file<input type="text" id="kbu-ftp-file" class="kbu-input" placeholder="backup.tgz" autocomplete="off" /></label>';
		echo '</div>';
		echo '<button type="button" class="kbu-btn kbu-btn-primary" id="kbu-btn-ftp">Fetch from FTP</button>';
		echo '</div>';

		echo '<div class="kbu-status" id="kbu-add-status" hidden></div>';
		echo '</div></section>';

		echo '<section class="kbu-card" id="kbu-list">';
		echo '<div class="kbu-card-h kbu-card-h-row"><span>Archives in __backup</span>';
		echo '<button type="button" class="kbu-btn kbu-btn-ghost" id="kbu-btn-refresh">Refresh</button></div>';
		echo '<div class="kbu-card-b">';
		echo '<div class="kbu-table-wrap"><table class="kbu-table" id="kbu-table">';
		echo '<thead><tr><th>File</th><th>Size</th><th>Modified</th><th>Actions</th></tr></thead>';
		echo '<tbody id="kbu-tbody"><tr><td colspan="4" class="kbu-empty">Loading…</td></tr></tbody>';
		echo '</table></div>';
		echo '<div class="kbu-status" id="kbu-list-status" hidden></div>';
		echo '</div></section>';

		echo '<dialog class="kbu-dialog" id="kbu-restore-dialog">';
		echo '<form method="dialog" class="kbu-dialog-inner">';
		echo '<h3>Restore archive?</h3>';
		echo '<p id="kbu-restore-msg">This replaces account data from the selected backup. Continue only if you are sure.</p>';
		echo '<menu class="kbu-dialog-actions">';
		echo '<button type="submit" value="cancel" class="kbu-btn kbu-btn-ghost">Cancel</button>';
		echo '<button type="submit" value="ok" class="kbu-btn kbu-btn-danger" id="kbu-btn-restore-confirm">Restore now</button>';
		echo '</menu></form></dialog>';

		echo '</div>';
		echo '<script type="application/json" id="kbu-boot">' . $bootJson . '</script>';
		echo '<script src="' . htmlspecialchars($js, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" defer></script>';
	}
}
