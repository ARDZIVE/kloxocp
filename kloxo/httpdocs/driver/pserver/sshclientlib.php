<?php

class sshclient extends lxclass
{
	static $__desc = array("", "", "ssh_client");
	static $__desc_nname = array("", "", "ssh_client");

	static $__acdesc_show = array("", "",  "ssh_terminal");

	function get() {}
	function write() {}

	function showRawPrint($subaction = null)
	{
		global $gbl, $sgbl, $login, $ghtml;

		if (!is_object($login) || !method_exists($login, 'isAdmin') || !$login->isAdmin()) {
			$msg = (is_object($login) && method_exists($login, 'getThrow'))
				? $login->getThrow("only_admin_can_access")
				: "only_admin_can_access";
			throw new lxException($msg);
		}

		$username = "root";
		$ip = "localhost";
		$sshport = "22";
		$parent = $this->getParentO();

		if (is_object($parent) && $parent->getClass() === 'pserver') {
			$ip = self::hostForPserver($parent);
			$port = db_get_value("sshconfig", $parent->nname, "ssh_port");
			if (is_string($port) && preg_match('/^[0-9]+$/', $port) && (int)$port > 0 && (int)$port < 65536) {
				$sshport = $port;
			}
		}

		$euser = htmlspecialchars((string)$username, ENT_QUOTES);
		$eip = htmlspecialchars((string)$ip, ENT_QUOTES);
		$eport = htmlspecialchars((string)$sshport, ENT_QUOTES);
		$cmd = 'ssh -p ' . $eport . ' ' . $euser . '@' . $eip;
?>
<div style="padding:24px; max-width:640px;">
<p>Connect from your own terminal (the in-browser SSH applet is not used):</p>
<pre style="background:#f4f4f4; border:1px solid #ddd; padding:12px; overflow:auto;"><?= $cmd ?></pre>
<table style="margin-top:12px; border-collapse:collapse;">
<tr><td style="padding:4px 12px 4px 0;">Host</td><td><?= $eip ?></td></tr>
<tr><td style="padding:4px 12px 4px 0;">Port</td><td><?= $eport ?></td></tr>
<tr><td style="padding:4px 12px 4px 0;">User</td><td><?= $euser ?></td></tr>
</table>
</div>
<?php
	}

	static function hostForPserver($parent)
	{
		$fallback = 'localhost';
		if (!is_object($parent) || !isset($parent->nname)) {
			return $fallback;
		}
		$name = (string)$parent->nname;
		if ($name !== '' && $name !== 'localhost') {
			$fallback = $name;
		}
		try {
			$fqdn = getFQDNforServer($name);
		} catch (Throwable $e) {
			return $fallback;
		}
		if (is_string($fqdn) && $fqdn !== '') {
			return $fqdn;
		}

		return $fallback;
	}

	static function initThisObjectRule($parent, $class, $name = null) { return "sshclient"; }

	static function initThisObject($parent, $class, $name = null)
	{
		$obj = new sshclient($parent->__masterserver, $parent->__readserver, 'sshclient');
		$obj->get();

		return $obj;
	}
}
