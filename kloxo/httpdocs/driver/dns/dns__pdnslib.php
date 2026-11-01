<?php

include_once __DIR__ . '/dns__lib.php';

class dns__pdns extends dns__
{
	function __construct()
	{
		parent::__construct();
	}

	static function unInstallMe()
	{
		parent::unInstallMeTrue('pdns');
	}

	static function installMe()
	{
		parent::installMeTrue('pdns');
	}
}