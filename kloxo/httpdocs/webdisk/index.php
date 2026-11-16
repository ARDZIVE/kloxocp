<?php
/**
 * Legacy WebDAV entry — disabled (security N25; security KK2).
 * PEAR/HTTP_WebDAV/mysql_query stack removed; not safe on PHP 8.
 */
http_response_code(403);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
print("disabled: WebDAV not enabled\n");
exit;
