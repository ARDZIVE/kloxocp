<?php
declare(strict_types=1);

/** SMTP is "none": nothing to configure; the settings stay in the panel database. */
class Servermail__none extends lxDriverClass
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
