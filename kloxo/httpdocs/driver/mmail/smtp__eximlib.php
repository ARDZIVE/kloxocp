<?php
declare(strict_types=1);

class Smtp__exim extends lxDriverClass
{
	static function unitName()
	{
		foreach (array('/lib/systemd/system/exim4.service', '/usr/lib/systemd/system/exim4.service') as $unit) {
			if (is_file($unit)) {
				return 'exim4';
			}
		}

		return 'exim';
	}

	static function installMe()
	{
		lxshell_return('systemctl', 'stop', 'postfix');
		lxshell_return('systemctl', 'disable', 'postfix');
		lxshell_return('apt-get', 'remove', '-y', 'postfix');
		lxshell_return('apt-get', 'install', '-y', 'exim4-daemon-heavy');
		$unit = self::unitName();
		lxshell_return('systemctl', 'enable', $unit);
		lxshell_return('systemctl', 'start', $unit);
	}

	static function uninstallMe()
	{
		$unit = self::unitName();
		lxshell_return('systemctl', 'stop', $unit);
		lxshell_return('systemctl', 'disable', $unit);
	}
}
