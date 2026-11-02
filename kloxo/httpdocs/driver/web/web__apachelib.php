<?php

include_once __DIR__ . '/web__lib.php';

class web__apache extends web__
{
	function __construct()
	{
		parent::__construct();
	}

	static function uninstallMe()
	{
		parent::uninstallMeTrue('apache');
	}

	static function installMe()
	{
		parent::installMeTrue('apache');
	}
}
