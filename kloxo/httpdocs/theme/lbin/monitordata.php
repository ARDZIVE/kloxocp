<?php
/**
 * Legacy unauthenticated monitor RPC — disabled (security F2).
 * Forced initProgram('admin') + unserialize(frm_rmt) without a password check.
 */
http_response_code(403);
header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');
print("disabled: unauthenticated monitor RPC removed\n");
exit;
