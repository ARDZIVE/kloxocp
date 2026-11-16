<?php

/**
 * EasyInstaller — removed (security LL1; security NN4; C5).
 * Class map entries are commented out; this stub refuses if loaded.
 */
class easyinstallermisc_b extends Lxaclass
{
}

class easyinstaller extends Lxdb
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
