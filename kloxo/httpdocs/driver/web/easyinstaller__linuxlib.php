<?php

/**
 * EasyInstaller driver — removed (security LL1 / C5).
 * Does not download or unpack third-party archives.
 */
class easyinstaller__linux extends LxDriverclass
{
	function dbactionAdd()
	{
		global $login;

		throw new lxException($login ? $login->getThrow('easyinstaller_removed') : 'easyinstaller_removed', '');
	}

	function dbactionDelete()
	{
		global $login;

		throw new lxException($login ? $login->getThrow('easyinstaller_removed') : 'easyinstaller_removed', '');
	}

	function dbactionUpdate($subaction)
	{
		global $login;

		throw new lxException($login ? $login->getThrow('easyinstaller_removed') : 'easyinstaller_removed', '');
	}
}
