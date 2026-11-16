<?php
/**
 * Legacy download endpoint that unserialized client frm_info without a session (F4).
 * Downloads now stream through the authenticated panel request (ffilelib).
 */
chdir("../../");
include_once "lib/html/displayinclude.php";

http_response_code(403);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
print("disabled: use authenticated File Manager download\n");
exit;
