<?php

/**
 * Apache MIME/handler updates are template-driven (file/apache).
 * AddType/AddHandler writers from CentOS httpd were not ported.
 */

class mimehandler___apache extends Lxdriverclass
{
	function dbactionAdd()
	{
	}

	function dbactionDelete()
	{
	}

	function dbactionUpdate($subaction)
	{
	}
}

class webhandler__apache extends mimehandler___apache
{
}

class webmimetype__apache extends mimehandler___apache
{
}

class mimetype__apache extends mimehandler___apache
{
}
