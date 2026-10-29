<?php 

include_once "lib/html/include.php"; 

//if (file_exists("/home/kloxomail/mail/domains")) {
	exec("chown root:root /home/kloxomail");
	exec("chmod 775 /home/kloxomail");

	exec("chown root:root /home/kloxomail/mail");
	exec("chmod 775 /home/kloxomail/mail");

	exec("chown root:root /home/kloxomail/mail/domains");
	exec("chmod 775 /home/kloxomail/mail/domains");
//}

$pass = slave_get_db_pass();
$salt = sha1(rand());

if(isRpmInstalled('qmail-toaster')) {
	print("Using qmail-toaster - fix '/home/vpopmail/etc/vpopmail.mysql'\n");
	exec("sh ../bin/misc/vpop.sh 'root' '$pass' vpopmail " . $salt);
} else {
	print("Using qmail-lxcenter - fix '/home/kloxomail/mail/etc/vpopmail.mysql'\n");
	exec("sh ../bin/misc/lxpop.sh 'root' '$pass' vpopmail " . $salt);
}
