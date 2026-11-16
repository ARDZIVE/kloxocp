<?php

/**
 * EasyInstaller snapshot sync — removed (security NN4; security LL1; C5).
 * Former path unserialized snapshot metadata; that call is gone.
 */
class easyinstallersnapshot__sync extends Lxdriverclass
{
	function dbactionDelete()
	{
		global $login;

		throw new lxException($login ? $login->getThrow('easyinstaller_removed') : 'easyinstaller_removed', '');
	}

	static function getSnapList($path)
	{
		return null;
	}
}
