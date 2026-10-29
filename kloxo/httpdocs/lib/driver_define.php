<?php

include_once "lib/html/driver_definecore.php";

$gl_class_array['autoresponder__qmail'] = "driver/mmail/autoresponder__qmaillib.php";
//--// $gl_class_array['autoresponder__mailenable'] = "driver/mmail/autoresponder__mailenablelib.php";

//--// $gl_class_array['domaintraffichistory__apache'] = "driver/web/domaintraffichistory__apachelib.php";

//--// $gl_class_array['mailinglist__mailman'] = "driver/mmail/mailinglist__mailmanlib.php";
//--// $gl_class_array['listsubscribe__mailman'] = "driver/mmail/listsubscribe__mailmanlib.php";

$gl_class_array['web__nginx'] = "driver/web/web__nginxlib.php";
$gl_class_array['web__none'] = "driver/web/web__nonelib.php";

$gl_class_array['webcache__none'] =  "driver/web/webcache__nonelib.php";

//--// $gl_class_array['webtraffic__apache'] = "driver/web/webtraffic__apachelib.php";
//--// $gl_class_array['webtraffic__lighttpd'] = "driver/web/webtraffic__lighttpdlib.php";
//--// $gl_class_array['webtraffic__nginx'] = "driver/web/webtraffic__nginxlib.php";

$gl_class_array['mailtraffic__qmail'] = "driver/mmail/mailtraffic__qmaillib.php";

$gl_class_array['easyinstallersnapshot__sync'] = "driver/web/easyinstallersnapshot__synclib.php";
$gl_class_array['davuser__none'] = "driver/web/davuser__nonelib.php";

$gl_class_array['dirprotect__nginx'] = "driver/web/dirprotect__nginxlib.php";
$gl_class_array['dirprotect__none'] = "driver/web/dirprotect__nonelib.php";

$gl_class_array['mmail__qmail'] = "driver/mmail/mmail__qmaillib.php";

$gl_class_array['webmimetype__nginx'] = "driver/web/mimehandler__nginxlib.php";
$gl_class_array['webhandler__nginx'] = "driver/web/mimehandler__nginxlib.php";

$gl_class_array['mailaccount__qmail'] = "driver/mmail/mailaccount__qmaillib.php";
$gl_class_array['mailforward__qmail'] = "driver/mmail/mailforward__qmaillib.php";

$gl_class_array['easyinstaller__linux'] = "driver/web/easyinstaller__linuxlib.php";
$gl_class_array['all_easyinstaller__linux'] = "driver/web/all_easyinstaller__linuxlib.php";

//--// $gl_class_array['autoresponder__mailenable'] = "driver/mmail/autoresponder__mailenable.php";
//--// $gl_class_array['mailinglist__mailenable'] = "driver/mmail/mailinglist__mailenablelib.php";
//--// $gl_class_array['mailaccount__mailenable'] = "driver/mmail/mailaccount__mailenablelib.php";
$gl_class_array['mailinglist__ezmlm'] = "driver/mmail/mailinglist__ezmlmlib.php";
$gl_class_array['listsubscribe__ezmlm'] = "driver/mmail/listsubscribe__ezmlmlib.php";
//--// $gl_class_array['listsubscribe__mailenable'] = "driver/mmail/listsubscribe__mailenablelib.php";
$gl_class_array['spam__spamassassin'] = "driver/mmail/spam__spamassassinlib.php";
$gl_class_array['spam__bogofilter'] = "driver/mmail/spam__bogofilterlib.php";
$gl_class_array['spam__none'] = "driver/mmail/spam__nonelib.php";
$gl_class_array['mailcontent__qmail'] = "driver/mmail/mailcontent__qmaillib.php";
$gl_class_array['mailqueue__qmail'] = "driver/mmail/mailqueue__qmaillib.php";

$gl_class_array['serverweb__nginx'] = "driver/web/serverweb__nginxlib.php";
$gl_class_array['serverweb__none'] = "driver/web/serverweb__nonelib.php";

$gl_class_array['rubyrails__linux'] = "driver/web/rubyrails__linuxlib.php";

$gl_class_array['pop3__courier'] = "driver/mmail/pop3__courierlib.php";
$gl_class_array['pop3__dovecot'] = "driver/mmail/pop3__dovecotlib.php";
$gl_class_array['pop3__none'] = "driver/mmail/pop3__nonelib.php";

//$gl_class_array['imap4__courier'] = "driver/mmail/imap4__courierlib.php";
//$gl_class_array['imap4__dovecot'] = "driver/mmail/imap4__dovecotlib.php";
//$gl_class_array['imap4__none'] = "driver/mmail/imap4__nonelib.php";

$gl_class_array['smtp__qmail'] = "driver/mmail/smtp__qmaillib.php";
$gl_class_array['smtp__none'] = "driver/mmail/smtp__nonelib.php";

