<?php
declare(strict_types=1);

class Smtp__postfix extends lxDriverClass
{
	static function unitName()
	{
		return 'postfix';
	}

	static function installMe()
	{
		$exim = array_values(array_unique(array_merge(
			array(class_exists('Smtp__exim') ? Smtp__exim::unitName() : 'exim4'),
			KloxoMta::unitsFor('exim')
		)));
		KloxoMta::retire($exim);
		// Ubuntu: Exim and Postfix conflict on mail-transport-agent.
		KloxoApt::remove(array('exim4-daemon-heavy', 'exim4-base', 'exim4-config'));
		// apt keeps /etc/init.d/exim4 and its rc links: make sure Exim is off at boot
		// (after the removal the leftover script showed up as an enabled unit).
		KloxoMta::settle($exim);
		KloxoApt::installOrThrow(array('postfix'));
		lxshell_return('systemctl', 'unmask', 'postfix');
		lxshell_return('systemctl', 'enable', 'postfix');
		lxshell_return('systemctl', 'start', 'postfix');
	}

	static function uninstallMe()
	{
		KloxoMta::turnOff(array('postfix'), false);
	}
}
