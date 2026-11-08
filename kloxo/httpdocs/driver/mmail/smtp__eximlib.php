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
		KloxoMta::retire(KloxoMta::unitsFor('postfix'));
		// Postfix and Exim both provide mail-transport-agent: remove, then install.
		KloxoApt::remove(array('postfix'));
		// apt keeps /etc/init.d/postfix and its rc links: make sure Postfix is off at boot.
		KloxoMta::settle(KloxoMta::unitsFor('postfix'));
		KloxoApt::installOrThrow(array('exim4-daemon-heavy'));
		$unit = self::unitName();
		lxshell_return('systemctl', 'enable', $unit);
		lxshell_return('systemctl', 'start', $unit);
	}

	static function uninstallMe()
	{
		KloxoMta::turnOff(array(self::unitName()), false);
	}
}
