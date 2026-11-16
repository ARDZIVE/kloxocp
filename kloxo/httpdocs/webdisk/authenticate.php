<?php
/**
 * Legacy WebDAV digest auth — disabled (security N25; security KK2).
 * md5 Digest stack and PEAR helpers were removed with the webdisk bundle.
 */
http_response_code(403);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
print("disabled: WebDAV auth not enabled\n");
exit;
