<?php

class sshauthorizedkey extends lxclass
{
	static $__desc = array("", "",  "SSH_authorized_key");
	static $__desc_nname = array("n", "",  "key");
	static $__desc_key = array("StW", "",  "key");
	static $__desc_type = array("n", "",  "type");
	static $__desc_hostname = array("n", "",  "hostname");
	static $__desc_type_v_snormal = array("", "",  "authorized_key");
	static $__desc_full_key = array("t", "",  "full_key");

	function get() {}

	function write() {}

	static function unixUser($parent)
	{
		$name = 'root';
		if (is_object($parent)) {
			if (isset($parent->username) && is_string($parent->username) && $parent->username !== '') {
				$name = $parent->username;
			} else {
				$isServer = false;
				try {
					$isServer = method_exists($parent, 'getClass') && $parent->getClass() === 'pserver';
				} catch (Throwable $e) {
				}
				if (!$isServer && isset($parent->nname) && is_string($parent->nname) && $parent->nname !== '') {
					$name = $parent->nname;
				}
			}
		}

		return sshauthorizedkey__sync::validUnixUser($name);
	}

	static function createListAlist($parent, $class)
	{
		$alist[] = "a=list&c=$class";

		return $alist;
	}

	static function getTextAreaProperties($var)
	{
		return array("height" => 8, "width" => "90%");
	}

	static function addListForm($parent, $class)
	{
		return self::addform($parent, $class);
	}

	static function addform($parent, $class, $typetd = null)
	{
		$vlist['full_key'] = array("t", self::serverPublicKey($parent));
		$ret['variable'] = $vlist;
		$ret['action'] = 'add';

		return $ret;
	}

	/** This server's OpenSSH public key for the Full Key box (never the private key). */
	static function serverPublicKey($parent)
	{
		$user = self::unixUser($parent);
		$slave = 'localhost';
		try {
			if (is_object($parent) && method_exists($parent, 'getClass') && $parent->getClass() === 'client'
				&& isset($parent->websyncserver) && $parent->websyncserver !== '') {
				$slave = $parent->websyncserver;
			} elseif (is_object($parent) && isset($parent->nname) && method_exists($parent, 'getClass')
				&& $parent->getClass() === 'pserver') {
				$slave = $parent->nname;
			} elseif (is_object($parent) && isset($parent->syncserver) && $parent->syncserver !== '') {
				$slave = $parent->syncserver;
			}
		} catch (Throwable $e) {
			$slave = 'localhost';
		}
		$key = '';
		try {
			$got = rl_exec_get(null, $slave, array('sshauthorizedkey__sync', 'identityPublicKey'), array($user));
			if (is_string($got)) {
				$key = trim($got);
			}
		} catch (Throwable $e) {
			$key = '';
		}
		if ($key === '') {
			try {
				$key = trim(sshauthorizedkey__sync::identityPublicKey($user));
			} catch (Throwable $e) {
				$key = '';
			}
		}
		if ($key !== '' && sshauthorizedkey__sync::parseLine($key) === null) {
			return '';
		}

		return $key;
	}

	static function add($parent, $class, $param)
	{
		global $login;

		$param['full_key'] = trim((string) ($param['full_key'] ?? ''));
		if (sshauthorizedkey__sync::parseLine($param['full_key']) === null) {
			throw new lxException($login->getThrow('invalid_ssh_authorized_key'), 'full_key');
		}

		return $param;
	}

	function postAdd()
	{
		$parent = $this->getParentO();
		$this->username = self::unixUser($parent);
		if (is_object($parent) && isset($parent->syncserver) && $parent->syncserver !== '') {
			$this->syncserver = $parent->syncserver;
		} elseif (is_object($parent) && method_exists($parent, 'getClass')
			&& $parent->getClass() === 'pserver' && isset($parent->nname)) {
			$this->syncserver = $parent->nname;
		} else {
			$this->syncserver = 'localhost';
		}
		$parsed = sshauthorizedkey__sync::parseLine($this->full_key);
		$this->nname = is_array($parsed) ? $parsed['nname'] : fix_nname_to_be_variable_without_lowercase($this->full_key);
	}

	static function createListNlist($parent, $view)
	{
		$nlist['hostname'] = '5%';
		$nlist['type'] = '3%';
		$nlist['key'] = '100%';

		return $nlist;
	}

	static function initThisListRule($parent, $class) { return null; }

	static function initThisList($parent, $class)
	{
		$slave = 'localhost';
		try {
			if (is_object($parent) && method_exists($parent, 'getClass') && $parent->getClass() === 'client'
				&& isset($parent->websyncserver) && $parent->websyncserver !== '') {
				$slave = $parent->websyncserver;
			} elseif (is_object($parent) && isset($parent->nname) && method_exists($parent, 'getClass')
				&& $parent->getClass() === 'pserver') {
				$slave = $parent->nname;
			} elseif (is_object($parent) && isset($parent->syncserver) && $parent->syncserver !== '') {
				$slave = $parent->syncserver;
			}
		} catch (Throwable $e) {
			$slave = 'localhost';
		}

		$user = self::unixUser($parent);
		$res = array();
		try {
			$got = rl_exec_get(null, $slave, array('sshauthorizedkey__sync', 'getAuthorizedKey'), array($user));
			if (is_array($got)) {
				$res = $got;
			}
		} catch (Throwable $e) {
			$res = array();
		}

		if (!is_array($res) || $res === array()) {
			return array();
		}

		foreach ($res as &$r) {
			if (!is_array($r) || !isset($r['nname'])) {
				continue;
			}
			$r['nname'] = "{$slave}___{$r['nname']}";
			$r['syncserver'] = $slave;
			try {
				$r['parent_clname'] = is_object($parent) && method_exists($parent, 'getClName')
					? $parent->getClName() : '';
			} catch (Throwable $e) {
				$r['parent_clname'] = '';
			}
		}
		unset($r);

		return $res;
	}
}
