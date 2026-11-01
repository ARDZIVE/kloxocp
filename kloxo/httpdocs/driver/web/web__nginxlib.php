<?php

include_once __DIR__ . '/web__lib.php';

class web__nginx extends web__
{
	function __construct()
	{
		parent::__construct();
	}

	static function uninstallMe()
	{
		parent::uninstallMeTrue('nginx');
	}

	static function installMe()
	{
		parent::installMeTrue('nginx');
	}
}
