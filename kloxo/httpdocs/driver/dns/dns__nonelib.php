<?php

include_once __DIR__ . '/dns__lib.php';

class dns__none extends dns__
{
	function __construct()
	{
		parent::__construct();
	}

	static function unInstallMe()
	{
	}

	static function installMe()
	{
	}
}