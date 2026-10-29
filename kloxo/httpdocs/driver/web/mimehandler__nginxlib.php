<?php

/**
 * Nginx MIME/handler updates are template-driven (file/nginx).
 * Apache AddType/AddHandler writers were removed in P1.
 */

class mimehandler___nginx extends Lxdriverclass
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

class webhandler__nginx extends mimehandler___nginx
{
}

class webmimetype__nginx extends mimehandler___nginx
{
}

class mimetype__nginx extends mimehandler___nginx
{
}
