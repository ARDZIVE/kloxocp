<?php

// Web cache = Nginx proxy cache in front of the Nginx or Apache site server.
// Selecting it only checks the prerequisite here; the switch itself (site server to
// loopback ports, cache on 80/443) is done by install-webcache after the site
// configs are regenerated, see Pserver::updateSwitchProgram and KloxoWebcache::apply.
class webcache__nginx extends lxDriverClass
{
	static function installMe()
	{
		global $login;

		$have = false;
		foreach (array('/usr/sbin/nginx', '/usr/bin/nginx', '/sbin/nginx') as $p) {
			if (is_executable($p)) {
				$have = true;
				break;
			}
		}
		if (!$have) {
			$base = (is_object($login) && method_exists($login, 'getThrow')) ? $login->getThrow('install_failed') : 'Install failed';
			if (is_array($base)) {
				$base = (string) ($base[0] ?? 'Install failed');
			}
			throw new lxException($base . ': nginx is not installed', '', 'nginx');
		}
	}

	static function uninstallMe()
	{
		// Nothing to remove: the cache unit stays installed; switching to none restores the side cache.
	}
}
