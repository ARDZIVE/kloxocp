<?php
/**
 * Legacy phpinfo endpoint removed (security F6).
 * Use the in-panel Domain PHPinfo tile instead.
 */
http_response_code(403);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
print("disabled: use panel PHPinfo\n");
exit;
