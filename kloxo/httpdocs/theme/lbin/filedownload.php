<?php 

chdir("../../");
include_once "lib/html/displayinclude.php";

$info = unserialize(base64_decode($ghtml->frm_info));

if (!$info) {
	print("No info");
	exit;
}

$filepass = $info->filepass;


/*
$ip = $_SERVER['REMOTE_ADDR'];

if ($res['ip'] !== $ip) {
	print("You are trying to access this file from a different Ip, " .
		"than the one you accessed the master with, which is prohibited <br> " .
		"Possibly an attempt to hack. \n");

	exit;
}
*/

$size = isset($filepass['size']) && is_numeric($filepass['size']) ? (int) $filepass['size'] : 0;
$fname = isset($filepass['realname']) ? (string) $filepass['realname'] : 'download';
kloxo_send_download_headers($fname, $size);
printFromFileServ('localhost', $filepass);




