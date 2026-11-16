<?php
/**
 * Legacy WebDAV server entry — disabled (security N25; security KK2).
 * PEAR/HTTP_WebDAV bundle removed; do not restore without a PHP 8 rewrite.
 */
http_response_code(403);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
print("disabled: WebDAV not enabled\n");
exit;
