<?php

/**
 * EasyInstaller helpers — removed (security NN1 / MM4 / LL1 / C5).
 * No longer calls all_easyinstaller* (class map commented out).
 */

function get_local_application_version_list()
{
	$loc = new Remote();
	$loc->applist = array();

	return $loc;
}

function easyinstaller_data_update()
{
	print("Easy Installer is disabled and will not download software.\n");
}
