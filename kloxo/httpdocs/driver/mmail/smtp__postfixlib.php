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
		$exim = class_exists('Smtp__exim') ? Smtp__exim::unitName() : 'exim4';
		lxshell_return('systemctl', 'stop', $exim);
		lxshell_return('systemctl', 'disable', $exim);
		if ($exim !== 'exim4') {
			lxshell_return('systemctl', 'stop', 'exim4');
			lxshell_return('systemctl', 'disable', 'exim4');
		}
		lxshell_return('systemctl', 'enable', 'postfix');
		lxshell_return('systemctl', 'start', 'postfix');
	}

	static function uninstallMe()
	{
		lxshell_return('systemctl', 'stop', 'postfix');
		lxshell_return('systemctl', 'disable', 'postfix');
	}
}
