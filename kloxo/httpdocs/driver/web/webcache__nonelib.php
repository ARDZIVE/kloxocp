<?php

// Web cache is always 'none' in the Switch Program list: the Nginx webcache (8080) and
// webproxy (30080) frontends are Watchdog services (install-webfront), not drivers.
class webcache__none extends lxDriverClass
{
	static function uninstallMe()
	{
		// no action because 'none' driver
	}

	static function installMe()
	{
		// no action because 'none' driver
	}

	static function copyConfig()
	{
		// no action because 'none' driver
	}
}