<?php

include_once __DIR__ . '/web__lib.php';

class web__none extends web__
{
	function __construct()
	{
		parent::__construct();
	}

	static function uninstallMe()
	{
		parent::uninstallMeTrue('none');
	}

	static function installMe()
	{
		parent::installMeTrue('none');
	}
}