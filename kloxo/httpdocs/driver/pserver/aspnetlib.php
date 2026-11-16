<?php

/**
 * ASP.NET helpers — Windows-only; stubbed on Ubuntu (security OO5).
 * Former path ran a Windows script host against a VBS under Program Files.
 */
class globalization_b extends LxaClass
{
}

class aspnetmisc_b extends LxaClass
{
}

class aspnet extends Lxdb
{
	static $__desc = array("", "", "aspnet_version");
	static $__desc_nname = array("", "", "aspnet_version");
	static $__desc_version = array("", "", "aspnet_version");
	static $__desc_encoding = array("", "", "aspnet_version");
	static $__acdesc_update_update = array("", "", "aspnet_configuration");

	function updateform($subaction, $param)
	{
		global $login;

		throw new lxException($login ? $login->getThrow('aspnet_not_available') : 'aspnet_not_available', '');
	}

	static function createListAlist($parent, $class)
	{
		$alist[] = "a=list&c=$class";

		return $alist;
	}

	static function getAspnetVersion()
	{
		return '';
	}
}
