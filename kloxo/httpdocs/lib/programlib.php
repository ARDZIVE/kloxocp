<?php 

function get_local_application_version_list()
{
	$list = all_easyinstaller__linux::getListofApps();
	$list = get_namelist_from_arraylist($list); 

	foreach($list as $k => $v) {
		if (csb($v, "__title")) {
			continue;
		}
		$info = all_easyinstaller::getAllInformation($v);
		$ret[$v] = $info['pversion'];
	}

	$loc= new Remote();
	$loc->applist = $ret;
	return $loc;
}

function easyinstaller_data_update()
{
	print("Easy Installer is disabled and will not download software.\n");
}
