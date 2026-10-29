<?php

/**
 * WebDAV backend is unset. Lighttpd/Apache DAV drivers were removed in P1.
 * Nginx WebDAV is not implemented; this class exists so the UI class can load.
 */

class davuser__none extends Lxdriverclass
{
	function dbactionAdd()
	{
	}

	function dbactionUpdate($subaction)
	{
	}

	function dbactionDelete()
	{
	}
}
