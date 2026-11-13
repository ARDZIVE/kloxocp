<?php

include_once "lib/html/driver_definecore.php";

//--// $gl_class_array['autoresponder__mailenable'] = "driver/mmail/autoresponder__mailenablelib.php";

//--// $gl_class_array['domaintraffichistory__apache'] = "driver/web/domaintraffichistory__apachelib.php";

//--// $gl_class_array['mailinglist__mailman'] = "driver/mmail/mailinglist__mailmanlib.php";
//--// $gl_class_array['listsubscribe__mailman'] = "driver/mmail/listsubscribe__mailmanlib.php";

$gl_class_array['web__nginx'] = "driver/web/web__nginxlib.php";
$gl_class_array['web__apache'] = "driver/web/web__apachelib.php";
$gl_class_array['web__none'] = "driver/web/web__nonelib.php";

$gl_class_array['webcache__none'] =  "driver/web/webcache__nonelib.php";
$gl_class_array['webcache__nginx'] =  "driver/web/webcache__nginxlib.php";

//--// $gl_class_array['webtraffic__apache'] = "driver/web/webtraffic__apachelib.php";
//--// $gl_class_array['webtraffic__lighttpd'] = "driver/web/webtraffic__lighttpdlib.php";
//--// $gl_class_array['webtraffic__nginx'] = "driver/web/webtraffic__nginxlib.php";


$gl_class_array['easyinstallersnapshot__sync'] = "driver/web/easyinstallersnapshot__synclib.php";
$gl_class_array['davuser__none'] = "driver/web/davuser__nonelib.php";

$gl_class_array['dirprotect__nginx'] = "driver/web/dirprotect__nginxlib.php";
$gl_class_array['dirprotect__apache'] = "driver/web/dirprotect__apachelib.php";
$gl_class_array['dirprotect__none'] = "driver/web/dirprotect__nonelib.php";


$gl_class_array['webmimetype__nginx'] = "driver/web/mimehandler__nginxlib.php";
$gl_class_array['webhandler__nginx'] = "driver/web/mimehandler__nginxlib.php";
$gl_class_array['webmimetype__apache'] = "driver/web/mimehandler__apachelib.php";
$gl_class_array['webhandler__apache'] = "driver/web/mimehandler__apachelib.php";


$gl_class_array['easyinstaller__linux'] = "driver/web/easyinstaller__linuxlib.php";
$gl_class_array['all_easyinstaller__linux'] = "driver/web/all_easyinstaller__linuxlib.php";

//--// $gl_class_array['autoresponder__mailenable'] = "driver/mmail/autoresponder__mailenable.php";
//--// $gl_class_array['mailinglist__mailenable'] = "driver/mmail/mailinglist__mailenablelib.php";
//--// $gl_class_array['mailaccount__mailenable'] = "driver/mmail/mailaccount__mailenablelib.php";
//--// $gl_class_array['listsubscribe__mailenable'] = "driver/mmail/listsubscribe__mailenablelib.php";
$gl_class_array['spam__rspamd'] = "driver/mmail/spam__rspamdlib.php";
$gl_class_array['spam__none'] = "driver/mmail/spam__nonelib.php";
$gl_class_array['mailqueue__postfix'] = "driver/mmail/mailqueue__postfixlib.php";
$gl_class_array['mailqueue__exim'] = "driver/mmail/mailqueue__eximlib.php";

$gl_class_array['serverweb__nginx'] = "driver/web/serverweb__nginxlib.php";
$gl_class_array['serverweb__apache'] = "driver/web/serverweb__apachelib.php";
$gl_class_array['serverweb__none'] = "driver/web/serverweb__nonelib.php";

$gl_class_array['rubyrails__linux'] = "driver/web/rubyrails__linuxlib.php";

$gl_class_array['pop3__dovecot'] = "driver/mmail/pop3__dovecotlib.php";
$gl_class_array['pop3__none'] = "driver/mmail/pop3__nonelib.php";


$gl_class_array['smtp__exim'] = "driver/mmail/smtp__eximlib.php";
$gl_class_array['smtp__postfix'] = "driver/mmail/smtp__postfixlib.php";
$gl_class_array['smtp__none'] = "driver/mmail/smtp__nonelib.php";

// Mail Server Settings (SMTP protection) follows the SMTP choice of Switch Program.
$gl_class_array['servermail__exim'] = "driver/mmail/servermail__eximlib.php";
$gl_class_array['servermail__postfix'] = "driver/mmail/servermail__postfixlib.php";
$gl_class_array['servermail__none'] = "driver/mmail/servermail__nonelib.php";

