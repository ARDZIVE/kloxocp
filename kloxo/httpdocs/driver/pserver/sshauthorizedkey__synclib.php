<?php

class sshauthorizedkey__sync extends Lxdriverclass
{
	/** Tests point every absolute path under this directory. Never set from input. */
	public static $fsPrefix = '';

	const KEY_TYPES = array(
		'ssh-ed25519',
		'ssh-rsa',
		'ssh-dss',
		'ecdsa-sha2-nistp256',
		'ecdsa-sha2-nistp384',
		'ecdsa-sha2-nistp521',
		'sk-ssh-ed25519@openssh.com',
		'sk-ecdsa-sha2-nistp256@openssh.com',
	);

	static function validUnixUser($username)
	{
		$user = trim((string) $username);
		if ($user === '' || $user === 'root') {
			return 'root';
		}
		if (str_contains($user, "\0") || str_contains($user, '/') || str_contains($user, '\\')
			|| str_contains($user, '..')) {
			self::throwInvalidUnixUser();
		}
		if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,31}$/', $user) !== 1) {
			self::throwInvalidUnixUser();
		}

		return $user;
	}

	static function homeDir($username)
	{
		$user = self::validUnixUser($username);
		$prefix = (string) self::$fsPrefix;
		if ($prefix !== '') {
			$prefix = rtrim($prefix, '/');

			return $user === 'root' ? ($prefix . '/root') : ($prefix . '/home/' . $user);
		}
		if ($user === 'root') {
			return '/root';
		}
		if (function_exists('posix_getpwnam')) {
			$p = @posix_getpwnam($user);
			if (is_array($p) && !empty($p['dir']) && is_string($p['dir'])
				&& !str_contains($p['dir'], "\0")) {
				$home = rtrim($p['dir'], '/');
				$real = @realpath($home);
				if (is_string($real) && $real !== '/' && (str_starts_with($real . '/', '/home/')
					|| $real === '/root')) {
					return $real;
				}
			}
		}

		return '/home/' . $user;
	}

	static function throwInvalidUnixUser()
	{
		if (class_exists('lxException')) {
			throw new lxException('invalid_ssh_username', 'username');
		}
		throw new InvalidArgumentException('invalid_ssh_username');
	}

	static function authorizedKeysPath($username)
	{
		return self::homeDir($username) . '/.ssh/authorized_keys';
	}

	/**
	 * OpenSSH public key already on this server (identity, else host key).
	 * Never returns a private key. Does not write ~/.ssh/config.
	 */
	static function identityPublicKey($username)
	{
		$user = self::validUnixUser($username);
		$home = self::homeDir($user);
		foreach (array('id_ed25519.pub', 'id_ecdsa.pub', 'id_rsa.pub') as $name) {
			$line = self::readPublicKeyFile($home . '/.ssh/' . $name);
			if ($line !== '') {
				return $line;
			}
		}
		if ($user === 'root' && self::$fsPrefix === '' && PHP_OS_FAMILY === 'Linux') {
			foreach (array(
				'/etc/ssh/ssh_host_ed25519_key.pub',
				'/etc/ssh/ssh_host_ecdsa_key.pub',
				'/etc/ssh/ssh_host_rsa_key.pub',
			) as $hostPub) {
				$line = self::readPublicKeyFile($hostPub);
				if ($line !== '') {
					return $line;
				}
			}
		}
		if (self::$fsPrefix !== '') {
			return '';
		}
		if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
			return '';
		}

		return self::ensureIdentityPublicKey($user);
	}

	static function readPublicKeyFile($path)
	{
		$f = (string) $path;
		if ($f === '' || str_contains($f, "\0") || !is_file($f) || !is_readable($f)) {
			return '';
		}
		$raw = file_get_contents($f);
		if (!is_string($raw) || $raw === '') {
			return '';
		}
		foreach (preg_split("/\r\n|\n|\r/", $raw) as $line) {
			$p = self::parseLine($line);
			if ($p !== null) {
				return $p['full_key'];
			}
		}

		return '';
	}

	static function ensureIdentityPublicKey($username)
	{
		$user = self::validUnixUser($username);
		$home = self::homeDir($user);
		$dir = $home . '/.ssh';
		$private = $dir . '/id_ed25519';
		$pub = $private . '.pub';
		$existing = self::readPublicKeyFile($pub);
		if ($existing !== '') {
			return $existing;
		}
		if (is_file($private) || is_file($pub)) {
			return self::readPublicKeyFile($pub);
		}
		$bin = is_executable('/usr/bin/ssh-keygen') ? '/usr/bin/ssh-keygen' : '';
		if ($bin === '') {
			return '';
		}
		if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
			return '';
		}
		@chmod($dir, 0700);
		$host = preg_replace('/[^A-Za-z0-9._-]/', '', (string) gethostname());
		if ($host === '') {
			$host = 'localhost';
		}
		$comment = $user . '@' . $host;
		$argv = array($bin, '-t', 'ed25519', '-N', '', '-q', '-f', $private, '-C', $comment);
		$pipes = array();
		$p = @proc_open($argv, array(
			0 => array('file', '/dev/null', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'w'),
		), $pipes);
		if (!is_resource($p)) {
			return '';
		}
		foreach (array(1, 2) as $i) {
			if (isset($pipes[$i]) && is_resource($pipes[$i])) {
				stream_get_contents($pipes[$i]);
				fclose($pipes[$i]);
			}
		}
		proc_close($p);
		@chmod($private, 0600);
		@chmod($pub, 0644);

		return self::readPublicKeyFile($pub);
	}

	/**
	 * @return array{type:string,key:string,hostname:string,full_key:string,nname:string}|null
	 */
	static function parseLine($line)
	{
		$l = trim(str_replace("\r", '', (string) $line));
		if ($l === '' || $l[0] === '#') {
			return null;
		}
		if (strlen($l) > 32768) {
			return null;
		}
		$types = implode('|', array_map(function ($t) {
			return preg_quote($t, '/');
		}, self::KEY_TYPES));
		if (preg_match('/(?:^|\s)(' . $types . ')\s+([A-Za-z0-9+\/=]+)(?:\s+(.*))?$/', $l, $m) !== 1) {
			return null;
		}
		$key = $m[2];
		if (strlen($key) < 32) {
			return null;
		}
		$comment = isset($m[3]) ? trim($m[3]) : '';
		$nname = fix_nname_to_be_variable_without_lowercase($key);
		if (!is_string($nname) || $nname === '') {
			return null;
		}

		return array(
			'type' => $m[1],
			'key' => $key,
			'hostname' => $comment,
			'full_key' => $l,
			'nname' => $nname,
		);
	}

	static function readAuthorizedKey($username)
	{
		$f = self::authorizedKeysPath($username);
		if (!is_file($f) || !is_readable($f)) {
			return '';
		}
		$c = file_get_contents($f);

		return is_string($c) ? $c : '';
	}

	static function writeAuthorizedKeyFile($username, $body)
	{
		$home = self::homeDir($username);
		$dir = $home . '/.ssh';
		if (!is_dir($dir)) {
			if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
				throw new lxException('could_not_save_file', '', $dir);
			}
		}
		@chmod($dir, 0700);
		$target = $dir . '/authorized_keys';
		$tmp = $target . '.kloxo-new';
		$text = (string) $body;
		if ($text !== '' && substr($text, -1) !== "\n") {
			$text .= "\n";
		}
		if (@file_put_contents($tmp, $text) === false) {
			throw new lxException('could_not_save_file', '', $target);
		}
		@chmod($tmp, 0600);
		if (!@rename($tmp, $target)) {
			@unlink($tmp);
			throw new lxException('could_not_save_file', '', $target);
		}
	}

	function writeAuthorizedKey($key)
	{
		self::writeAuthorizedKeyFile($this->main->username, $key);
	}

	function getCurrentAuthKey()
	{
		$res = self::getAuthorizedKey($this->main->username);
		if (!is_array($res)) {
			return array();
		}
		$server = isset($this->main->syncserver) ? (string) $this->main->syncserver : '';
		$want = (string) $this->main->nname;
		$output = array();
		foreach ($res as $v) {
			if (!is_array($v) || !isset($v['nname'], $v['full_key'])) {
				continue;
			}
			$id = ($server !== '') ? ($server . '___' . $v['nname']) : (string) $v['nname'];
			if ($id === $want) {
				continue;
			}
			$output[] = $v['full_key'];
		}

		return $output;
	}

	function dbactionAdd()
	{
		$username = isset($this->main->username) ? (string) $this->main->username : '';
		$new = trim((string) ($this->main->full_key ?? ''));
		$parsed = self::parseLine($new);
		if ($parsed === null) {
			throw new lxException('invalid_ssh_authorized_key', 'full_key');
		}
		$raw = self::readAuthorizedKey($username);
		$lines = preg_split("/\r\n|\n|\r/", $raw);
		if (!is_array($lines)) {
			$lines = array();
		}
		$out = array();
		foreach ($lines as $line) {
			$line = rtrim((string) $line, "\r");
			if (trim($line) === '') {
				continue;
			}
			$p = self::parseLine($line);
			if ($p !== null && $p['key'] === $parsed['key']) {
				return;
			}
			$out[] = $line;
		}
		$out[] = $parsed['full_key'];
		self::writeAuthorizedKeyFile($username, implode("\n", $out));
	}

	function dbactionDelete()
	{
		$output = $this->getCurrentAuthKey();
		self::writeAuthorizedKeyFile(
			isset($this->main->username) ? (string) $this->main->username : '',
			implode("\n", $output)
		);
	}

	static function getAuthorizedKey($username, $type = null)
	{
		unset($type);
		$v = self::readAuthorizedKey($username);
		if ($v === '') {
			return array();
		}
		$res = array();
		foreach (preg_split("/\r\n|\n|\r/", $v) as $l) {
			$r = self::parseLine($l);
			if ($r === null) {
				continue;
			}
			$r['username'] = (string) $username;
			$res[$r['nname']] = $r;
		}

		return $res;
	}
}
