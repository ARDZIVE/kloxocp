<?php

/**
 * all_easyinstaller — removed (security NN4; security LL1; C5).
 * Class map entry is commented out; this stub refuses if loaded.
 */
class all_easyinstaller extends Lxclass
{
	function get() {}
	function write() {}

	static function initThisListRule($parent, $class)
	{
		return null;
	}

	static function getAllInformation($appname)
	{
		global $login;

		throw new lxException($login ? $login->getThrow('easyinstaller_removed') : 'easyinstaller_removed', '', (string) $appname);
	}
}
