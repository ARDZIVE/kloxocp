<?php
/**
 * Legacy serverfile endpoint — disabled (security F7).
 * Unserialized frm_info with no login.
 */
http_response_code(403);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
print("disabled: unauthenticated serverfile RPC removed\n");
exit;
