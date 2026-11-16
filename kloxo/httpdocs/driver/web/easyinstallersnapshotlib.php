<?php

/**
 * EasyInstaller snapshots — removed (security NN4; security LL1; C5).
 * Class map entry is commented out; this stub refuses if loaded.
 */
class easyinstallersnapshot extends lxclass
{
	function get() {}
	function write() {}

	function updateRevert($param)
	{
		global $login;

		throw new lxException($login ? $login->getThrow('easyinstaller_removed') : 'easyinstaller_removed', '');
	}
}
