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

	static function homeDir($username)
	{
		$user = trim((string) $username);
		$prefix = (string) self::$fsPrefix;
		if ($prefix !== '') {
			$prefix = rtrim($prefix, '/');

			return ($user === '' || $user === 'root') ? ($prefix . '/root') : ($prefix . '/home/' . $user);
		}
		if ($user === '' || $user === 'root') {
			return '/root';
		}
		if (function_exists('posix_getpwnam')) {
			$p = @posix_getpwnam($user);
			if (is_array($p) && !empty($p['dir'])) {
				return (string) $p['dir'];
			}
		}

		return '/home/' . $user;
	}

	static function authorizedKeysPath($username)
	{
		return self::homeDir($username) . '/.ssh/authorized_keys';
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
