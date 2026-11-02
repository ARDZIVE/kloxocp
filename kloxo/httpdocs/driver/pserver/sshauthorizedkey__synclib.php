<?php

class sshauthorizedkey__sync extends Lxdriverclass
{
	function writeAuthorizedKey($key)
	{
		$username = $this->main->username;

	//	lxfile_mkdir($username, "/root/.ssh");
	//	lxuser_chmod($username, "/root/.ssh", "0755");

		if (!file_exists('/root/.ssh')) {
			exec("mkdir -p /root/.ssh");
		}

		$f = "/root/.ssh/{$username}.authorized_keys";

		file_put_contents($f, $key);

	//	lxuser_chmod($username, $f, "0644");

		$t = '';

		$dirs = glob("/root/.ssh/*.authorized_keys");

		foreach ($dirs as $k => $v) {
			$x = file_get_contents($v);
			$y = explode(" ", $x);
			$z = explode("@", $y[2]);

			$a = gethostname();
			$b = explode(".", $a);

			$s['user'] = $z[0];
			$s['hostname'] = $a;
			$s['host'] = $b[0];
			$s['file'] = $v;

			$t .= "Host " . $b[0] . "\n";
			$t .= "HostName " . $a . "\n";
			$t .= "IdentityFile " . str_replace("/root/", "~/", $v) . "\n";
			$t .= "User " . $z[0] . "\n\n";
		}

		file_put_contents("/root/.ssh/config", $t);
	}

	static function readAuthorizedKey($username)
	{
		$f = "/root/.ssh/{$username}.authorized_keys";

		if (file_exists($f)) {
			$c = file_get_contents($f);
			return is_string($c) ? $c : '';
		}

		return '';
	}

	static function readProtoAuthorizedKey($username)
	{
		if (!is_dir('/root') || !is_writable('/root')) {
			return '';
		}

		$s = "/root/{$username}.id.rsa";
		foreach (glob("{$s}*") ?: array() as $old) {
			if (is_file($old)) {
				@unlink($old);
			}
		}

		$r = randomString(10);
		lxshell_return("ssh-keygen", "-t", "rsa", "-b", '4096', "-N", $r, "-f", $s);

		$f = "{$s}.pub";
		if (!is_file($f)) {
			return '';
		}

		$pub = file_get_contents($f);
		if (!is_string($pub) || $pub === '') {
			return '';
		}

		if ($username !== '' && str_contains($pub, 'lxlabs@')) {
			$pub = str_replace('lxlabs@', $username . '@', $pub);
			file_put_contents($f, $pub);
		}

		return $pub;
	}

	function getCurrentAuthKey()
	{
		$res = self::getAuthorizedKey($this->main->username);
		if (!is_array($res)) {
			return array();
		}

		$output = array();
		foreach($res as $k => $v) {
			if ("{$this->main->syncserver}___{$v['nname']}" === $this->main->nname) {
				continue;
			}

			$output[] = $v['full_key'];
		}

		return $output;
	}

	function dbactionAdd()
	{
		// MR -- no need because no append
	//	$output = $this->getCurrentAuthKey();

		$output[] = $this->main->full_key;
		$output = implode("\n", $output);
		$this->writeAuthorizedKey($output);
	}

	function dbactionDelete()
	{
		$output = $this->getCurrentAuthKey();
		$output = implode("\n", $output);
		$this->writeAuthorizedKey($output);
	}

	static function getAuthorizedKey($username, $type = null)
	{
		if ($type === 'proto') {
			$v = self::readProtoAuthorizedKey($username);
		} else {
			$v = self::readAuthorizedKey($username);
		}

		if (!$v) {
			return array();
		}

		$list = explode("\n", $v);
		$res = array();

		foreach($list as $l) {
			$l = trim($l);

			if (!$l) { continue; }

			$l = trimSpaces($l);
			$vv = explode(" ", $l);
			if (!isset($vv[1])) {
				continue;
			}

			$r['nname'] = fix_nname_to_be_variable_without_lowercase($vv[1]);
			$r['full_key'] = $l;
			$r['key'] = $vv[1];
			$r['hostname'] = isset($vv[2]) ? $vv[2] : '';

			$r['username'] = $username;
			$r['type'] = $vv[0];

			$res[$r['nname']] = $r;
		}

		return $res;
	}	
}

